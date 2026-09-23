<?php
/**
 * Shared plumbing for HTTP based gateway drivers.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Gateway;

use TisaOtp\Config\Settings;
use TisaOtp\Support\Transport;

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
				__( 'مقدار «%s» تنظیم نشده است.', 'tisa-otp' ),
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
		$constant = 'TISA_OTP_' . strtoupper( $key );

		if ( defined( $constant ) && '' !== trim( (string) constant( $constant ) ) ) {
			return (string) constant( $constant );
		}

		return $this->settings->str( $key, $default );
	}

	protected function notConfigured( string $detail = '' ): GatewayResult {
		return GatewayResult::failed(
			$this->id(),
			'not_configured',
			'' !== $detail ? $detail : __( 'اعتبارنامه این سامانه پیامکی کامل نیست.', 'tisa-otp' )
		);
	}

	/**
	 * POST with a bounded number of retries for transport level failures.
	 *
	 * @return array|\WP_Error
	 */
	protected function post( string $url, array $args = array(), int $retries = 1 ) {
		$defaults = array(
			'timeout'     => (int) apply_filters( 'tisa_otp_http_timeout', 12 ),
			'redirection' => 0,
			'headers'     => array( 'Content-Type' => 'application/json' ),
		);

		$args  = array_merge( $defaults, $args );
		$tries = max( 0, $retries );

		for ( $attempt = 0; $attempt <= $tries; $attempt++ ) {
			$response = wp_remote_post( $url, $args );

			if ( ! is_wp_error( $response ) ) {
				return $response;
			}

			if ( ! $this->retryable( $response ) || $attempt === $tries ) {
				return $response;
			}

			usleep( (int) apply_filters( 'tisa_otp_http_retry_delay', 250000 ) );
		}

		return $response;
	}

	protected function retryable( \WP_Error $error ): bool {
		return in_array( $error->get_error_code(), array( 'http_request_failed', 'timeout' ), true );
	}

	/**
	 * Turn any transport failure into a stable GatewayResult.
	 */
	protected function transportFailure( $response ): GatewayResult {
		if ( ! is_wp_error( $response ) ) {
			return GatewayResult::failed( $this->id(), 'bad_response', __( 'پاسخ نامعتبر از سامانه پیامکی.', 'tisa-otp' ) );
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
