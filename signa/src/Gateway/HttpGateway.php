<?php
/**
 * Shared plumbing for HTTP based gateway drivers.
 *
 * @package Signa
 */

namespace Signa\Gateway;

use Signa\Config\Settings;
use Signa\Support\Transport;

defined( 'ABSPATH' ) || exit;

abstract class HttpGateway implements SmsGateway {

	/** @var Settings */
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

	/**
	 * Default delivery plan: a plain text message. Drivers that also support
	 * pattern/verify templates override this.
	 *
	 * @return array{mode:string,sender:string,template:string,endpoint:string,issues:string[],notes:string[]}
	 */
	public function plan(): array {
		$issues = array();
		$sender = trim( $this->option( 'sender' ) );

		foreach ( $this->missing() as $key ) {
			$field  = $this->fields();
			$label  = isset( $field[ $key ]['label'] ) ? (string) $field[ $key ]['label'] : $key;
			$issues[] = sprintf(
				/* translators: %s: settings field label */
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

	/**
	 * Read an option, allowing wp-config.php constants to win.
	 */
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

	/**
	 * POST with a bounded number of retries for transport level failures.
	 *
	 * @return array|\WP_Error
	 */
	protected function post( string $url, array $args = array(), int $retries = 1 ) {
		return $this->send( 'POST', $url, $args, $retries );
	}

	/**
	 * GET through the same door: same block check, same direct option, same
	 * retries. A driver that reached for `wp_remote_get()` itself would be the
	 * one gateway the «ارسال مستقیم» switch silently did not cover.
	 *
	 * @return array|\WP_Error
	 */
	protected function get( string $url, array $args = array(), int $retries = 1 ) {
		return $this->send( 'GET', $url, $args, $retries );
	}

	/**
	 * @return array|\WP_Error
	 */
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

		/*
		 * A site that blocked outbound HTTP gets no further than this line.
		 *
		 * WordPress refuses the request before cURL is reached, so there is no
		 * cURL sentence to classify and the owner used to read «نامشخص» with
		 * advice about DNS. Two answers, in this order: if the owner turned on
		 * «ارسال مستقیم» the plugin sends the request itself (their site, their
		 * gateway, their decision — the switch says what it does); otherwise
		 * the failure is built here so the reason names the constant and the
		 * exact line that fixes it.
		 */
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

	/**
	 * The request WordPress refuses to make, made by the plugin instead.
	 *
	 * Only reached when the owner turned «ارسال مستقیم» on — which is why that
	 * switch has to say what it does. It bypasses `WP_HTTP_BLOCK_EXTERNAL`, and
	 * it is off by default: the honest order is wp-config.php first, because
	 * every other plugin on the site needs that line changed too.
	 *
	 * The answer is shaped like a WP_HTTP response, so `wp_remote_retrieve_*`
	 * and every driver above this line keep working without knowing.
	 *
	 * @param string              $url    Request URL.
	 * @param array<string,mixed> $args   WP_HTTP style arguments.
	 * @param int                 $tries  Extra attempts for transport failures.
	 * @return array|\WP_Error
	 */
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
					CURLOPT_POSTFIELDS     => isset( $args['body'] ) && is_scalar( $args['body'] ) ? (string) $args['body'] : '',
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

			/*
			 * The same sentence WP_HTTP would have produced, so a failure that
			 * happens this way is classified and explained exactly like one that
			 * happened through WordPress.
			 */
			$failure = new \WP_Error( 'http_request_failed', sprintf( 'cURL error %1$d: %2$s', $errno, $error ) );

			if ( ! $this->retryable( $failure ) || $attempt === $tries ) {
				return $failure;
			}

			usleep( (int) apply_filters( 'signa_http_retry_delay', 250000 ) );
		}

		return new \WP_Error( 'http_request_failed', 'cURL error: the request was not executed.' );
	}

	/**
	 * Turn any transport failure into a stable GatewayResult.
	 */
	protected function transportFailure( $response ): GatewayResult {
		if ( ! is_wp_error( $response ) ) {
			return GatewayResult::failed( $this->id(), 'bad_response', __( 'پاسخ نامعتبر از سامانه پیامکی.', 'signa' ) );
		}

		$code = $response->get_error_code();

		/*
		 * The sentence WP_Error carries is the whole answer — it names the host
		 * and the cause ("cURL error 6: Could not resolve host: api.sms.ir").
		 * Reducing it to the word `transport` is what made "the SMS does not
		 * arrive" unfixable from the admin: the administrator saw the same word
		 * whether the DNS was down, the firewall was shut or the site had
		 * blocked outbound HTTP. Now the word stays, and the reason travels with
		 * it into the log row, the health card and the self-test.
		 */
		$transport = Transport::fromError( $response );

		if ( 'timeout' === $transport['kind'] ) {
			return GatewayResult::failed( $this->id(), 'timeout', $transport['message'], 0, array( 'detail' => $code, 'reason' => $transport['reason'] ) );
		}

		return GatewayResult::failed( $this->id(), 'transport', $transport['message'], 0, array( 'detail' => $code, 'reason' => $transport['reason'] ) );
	}

	/**
	 * @param array|\WP_Error $response
	 */
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

	/**
	 * Map an HTTP status onto a gateway error code.
	 */
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
