<?php
/**
 * Shared plumbing for HTTP based gateway drivers.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Gateway;

use TisaOtp\Config\Settings;

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

		if ( false !== strpos( $code, 'timeout' ) ) {
			return GatewayResult::failed( $this->id(), 'timeout', __( 'ارتباط با سامانه پیامکی به‌موقع برقرار نشد.', 'tisa-otp' ) );
		}

		return GatewayResult::failed( $this->id(), 'transport', __( 'خطای شبکه در ارتباط با سامانه پیامکی.', 'tisa-otp' ), 0, array( 'detail' => $code ) );
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
