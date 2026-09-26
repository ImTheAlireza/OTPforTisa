<?php

namespace Signa\Gateway;

use Signa\Config\Settings;
use Signa\Support\Transport;

defined( 'ABSPATH' ) || exit;

abstract class HttpGateway implements SmsGateway {
	protected $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function docsUrl(): string {
		return '';
	}

	public function fields(): array {
		return array();
	}

	public function missing(): array {
		$missing = array();

		foreach ( array_keys( $this->fields() ) as $key ) {
			if ( '' === trim( $this->option( $key ) ) ) {
				$missing[] = $key;
			}
		}

		return $missing;
	}

	public function ready(): bool {
		return array() === $this->missing();
	}

	public function plan(): array {
		$issues = array();
		$sender = trim( $this->option( 'sender' ) );

		foreach ( $this->missing() as $key ) {
			$field  = $this->fields();
			$label  = isset( $field[ $key ]['label'] ) ? (string) $field[ $key ]['label'] : $key;
			$issues[] = sprintf(
				__( 'مقدار «%s» تنظیم نشده است.', 'signa' ),
				$label
			);
		}

		return array(
			'mode'     => 'text',
			'sender'   => $sender,
			'template' => '',
			'endpoint' => '',
			'issues'   => $issues,
			'notes'    => array(),
		);
	}

	protected function option( string $key, string $default = '' ): string {
		$constant = 'SIGNA_' . strtoupper( $key );

		if ( defined( $constant ) && '' !== trim( (string) constant( $constant ) ) ) {
			return (string) constant( $constant );
		}

		return $this->settings->str( $key, $default );
	}

	protected function notConfigured( string $detail = '' ): GatewayResult {
		return GatewayResult::failed(
			$this->id(),
			'not_configured',
			'' !== $detail ? $detail : __( 'اعتبارنامه این سامانه پیامکی کامل نیست.', 'signa' )
		);
	}

	protected function post( string $url, array $args = array(), int $retries = 1 ) {
		return $this->send( 'POST', $url, $args, $retries );
	}

	protected function get( string $url, array $args = array(), int $retries = 1 ) {
		return $this->send( 'GET', $url, $args, $retries );
	}

	private function send( string $method, string $url, array $args, int $retries ) {
		$defaults = array(
			'timeout'     => (int) apply_filters( 'signa_http_timeout', 12 ),
			'redirection' => 0,
			'method'      => $method,
			'headers'     => 'GET' === $method ? array() : array( 'Content-Type' => 'application/json' ),
		);

		$args  = array_merge( $defaults, $args );
		$tries = max( 0, $retries );
		$host  = (string) wp_parse_url( $url, PHP_URL_HOST );

		if ( Transport::egressBlocked( $host ) ) {
			if ( ! $this->settings->bool( 'direct_send', false ) ) {
				return new \WP_Error( 'http_request_not_executed', Transport::blockReason( $host ) );
			}

			return $this->direct( $url, $args, $tries );
		}

		for ( $attempt = 0; $attempt <= $tries; $attempt++ ) {
			$response = 'GET' === $method ? wp_remote_get( $url, $args ) : wp_remote_post( $url, $args );

			if ( ! is_wp_error( $response ) ) {
				return $response;
			}

			if ( ! $this->retryable( $response ) || $attempt === $tries ) {
				return $response;
			}

			usleep( (int) apply_filters( 'signa_http_retry_delay', 250000 ) );
		}

		return $response;
	}

	protected function retryable( \WP_Error $error ): bool {
		return in_array( $error->get_error_code(), array( 'http_request_failed', 'timeout' ), true );
	}

	protected function direct( string $url, array $args, int $tries ) {
		if ( ! function_exists( 'curl_init' ) ) {
			return new \WP_Error(
				'http_request_failed',
				__( 'cURL روی این سرور فعال نیست. «ارسال مستقیم» را خاموش کنید و در wp-config.php دامنهٔ سامانه را در WP_ACCESSIBLE_HOSTS مجاز کنید.', 'signa' )
			);
		}

		$timeout = isset( $args['timeout'] ) ? max( 1, (int) $args['timeout'] ) : 12;
		$method  = isset( $args['method'] ) ? strtoupper( (string) $args['method'] ) : 'POST';
		$headers = array();

		foreach ( (array) ( isset( $args['headers'] ) ? $args['headers'] : array() ) as $name => $value ) {
			if ( is_scalar( $value ) ) {
				$headers[] = $name . ': ' . $value;
			}
		}

		for ( $attempt = 0; $attempt <= $tries; $attempt++ ) {
			$handle = curl_init();

			curl_setopt_array(
				$handle,
				array(
					CURLOPT_URL            => $url,
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_CUSTOMREQUEST  => $method,
					CURLOPT_POSTFIELDS     => self::wireBody( isset( $args['body'] ) ? $args['body'] : '' ),
					CURLOPT_HTTPHEADER     => $headers,
					CURLOPT_CONNECTTIMEOUT => min( 5, $timeout ),
					CURLOPT_TIMEOUT        => $timeout,
					CURLOPT_FOLLOWLOCATION => false,
					CURLOPT_SSL_VERIFYPEER => true,
					CURLOPT_SSL_VERIFYHOST => 2,
					CURLOPT_USERAGENT      => 'SignaOTP/' . SIGNA_VERSION . '; ' . home_url( '/' ),
				)
			);

			$body    = curl_exec( $handle );
			$errno   = (int) curl_errno( $handle );
			$error   = (string) curl_error( $handle );
			$status  = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
			$type    = (string) curl_getinfo( $handle, CURLINFO_CONTENT_TYPE );

			curl_close( $handle );

			if ( 0 === $errno && false !== $body ) {
				return array(
					'headers'  => array( 'content-type' => $type ),
					'body'     => (string) $body,
					'response' => array( 'code' => $status, 'message' => '' ),
					'cookies'  => array(),
					'filename' => null,
				);
			}

			$failure = new \WP_Error( 'http_request_failed', sprintf( 'cURL error %1$d: %2$s', $errno, $error ) );

			if ( ! $this->retryable( $failure ) || $attempt === $tries ) {
				return $failure;
			}

			usleep( (int) apply_filters( 'signa_http_retry_delay', 250000 ) );
		}

		return new \WP_Error( 'http_request_failed', 'cURL error: the request was not executed.' );
	}

	public static function wireBody( $body ): string {
		if ( is_array( $body ) || is_object( $body ) ) {
			return http_build_query( (array) $body, '', '&' );
		}

		return is_scalar( $body ) ? (string) $body : '';
	}

	protected function formHeaders(): array {
		return array(
			'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8',
			'Accept'       => 'application/json',
		);
	}

	public static function e164( string $phone ): string {
		$digits = (string) preg_replace( '/\D/', '', $phone );

		if ( 0 === strpos( $digits, '0098' ) ) {
			$digits = substr( $digits, 4 );
		} elseif ( 0 === strpos( $digits, '98' ) && 12 === strlen( $digits ) ) {
			$digits = substr( $digits, 2 );
		} elseif ( 0 === strpos( $digits, '0' ) ) {
			$digits = substr( $digits, 1 );
		}

		return '' === $digits ? '' : '+98' . $digits;
	}

	public static function e164Line( string $line ): string {
		$line = trim( \Signa\Support\Phone::latinDigits( $line ) );

		if ( '' === $line ) {
			return '';
		}

		$plus   = 0 === strpos( $line, '+' );
		$digits = (string) preg_replace( '/\D/', '', $line );

		if ( '' === $digits ) {
			return '';
		}

		if ( $plus ) {
			return '+' . $digits;
		}

		if ( 0 === strpos( $digits, '0098' ) ) {
			return '+' . substr( $digits, 2 );
		}

		if ( 0 === strpos( $digits, '98' ) && strlen( $digits ) >= 9 ) {
			return '+' . $digits;
		}

		return '+98' . ltrim( $digits, '0' );
	}

	protected function paramName( string $key, string $default ): string {
		$value = trim( (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', $this->option( $key ) ) );

		return '' !== $value ? $value : $default;
	}

	protected function transportFailure( $response ): GatewayResult {
		if ( ! is_wp_error( $response ) ) {
			return GatewayResult::failed( $this->id(), 'bad_response', __( 'پاسخ نامعتبر از سامانه پیامکی.', 'signa' ) );
		}

		$code = $response->get_error_code();

		$transport = Transport::fromError( $response );

		if ( 'timeout' === $transport['kind'] ) {
			return GatewayResult::failed( $this->id(), 'timeout', $transport['message'], 0, array( 'detail' => $code, 'reason' => $transport['reason'] ) );
		}

		return GatewayResult::failed( $this->id(), 'transport', $transport['message'], 0, array( 'detail' => $code, 'reason' => $transport['reason'] ) );
	}

	protected function decode( $response ): array {
		if ( is_wp_error( $response ) ) {
			return array();
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return is_array( $body ) ? $body : array();
	}

	protected function status( $response ): int {
		return is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
	}

	protected function codeForStatus( int $status ): string {
		if ( 401 === $status || 403 === $status ) {
			return 'unauthorized';
		}
		if ( 402 === $status ) {
			return 'no_credit';
		}
		if ( 429 === $status ) {
			return 'rate_limited';
		}
		if ( $status >= 500 ) {
			return 'upstream';
		}
		if ( 0 === $status ) {
			return 'transport';
		}

		return 'rejected';
	}

	protected function referenceFrom( array $body, array $paths ): string {
		foreach ( $paths as $path ) {
			$value = $body;
			foreach ( explode( '.', $path ) as $segment ) {
				if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
					$value = null;
					break;
				}
				$value = $value[ $segment ];
			}
			if ( is_scalar( $value ) && '' !== (string) $value ) {
				return (string) $value;
			}
		}

		return '';
	}
}
