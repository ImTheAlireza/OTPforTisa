<?php
/**
 * FarazSMS driver (IPPanel infrastructure with basic auth).
 *
 * @package TisaOtp
 */

namespace TisaOtp\Gateway\Drivers;

use TisaOtp\Gateway\DeliveryRequest;
use TisaOtp\Gateway\GatewayResult;
use TisaOtp\Gateway\HttpGateway;

defined( 'ABSPATH' ) || exit;

final class FarazSms extends HttpGateway {

	const PATTERN_ENDPOINT = 'https://ippanel.com/patterns/pattern';
	const SEND_ENDPOINT    = 'https://ippanel.com/api/select';

	public function id(): string {
		return 'faraz';
	}

	public function label(): string {
		return __( 'فراز اس‌ام‌اس', 'tisa-otp' );
	}

	public function docsUrl(): string {
		return 'https://farazsms.com/';
	}

	public function fields(): array {
		return array(
			'faraz_username' => array(
				'label' => __( 'نام کاربری', 'tisa-otp' ),
				'type'  => 'text',
			),
			'faraz_password' => array(
				'label' => __( 'رمز عبور', 'tisa-otp' ),
				'type'  => 'password',
			),
			'faraz_from'     => array(
				'label' => __( 'شماره فرستنده', 'tisa-otp' ),
				'type'  => 'text',
			),
			'faraz_pattern'  => array(
				'label' => __( 'کد پترن', 'tisa-otp' ),
				'type'  => 'text',
				'hint'  => __( 'در صورت تنظیم، ارسال از مسیر پترن انجام می‌شود.', 'tisa-otp' ),
			),
		);
	}

	public function missing(): array {
		$missing = array();

		if ( '' === trim( $this->option( 'faraz_username' ) ) ) {
			$missing[] = 'faraz_username';
		}
		if ( '' === trim( $this->option( 'faraz_password' ) ) ) {
			$missing[] = 'faraz_password';
		}

		return $missing;
	}

	public function deliver( DeliveryRequest $request ): GatewayResult {
		if ( array() !== $this->missing() ) {
			return $this->notConfigured( __( 'برای فراز اس‌ام‌اس نام کاربری و رمز را در تنظیمات کامل کنید.', 'tisa-otp' ) );
		}

		$username = $this->option( 'faraz_username' );
		$password = $this->option( 'faraz_password' );
		$pattern  = trim( $this->option( 'faraz_pattern' ) );

		$args = array(
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Basic ' . base64_encode( $username . ':' . $password ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			),
		);

		if ( '' !== $pattern ) {
			/**
			 * Filter the pattern input key used by FarazSMS.
			 *
			 * @param string $key Input key.
			 */
			$inputKey     = (string) apply_filters( 'tisa_otp_faraz_pattern_key', 'verification-code' );
			$args['body'] = wp_json_encode(
				array(
					'op'          => 'pattern',
					'user'        => $username,
					'pass'        => $password,
					'fromNum'     => '' !== trim( $this->option( 'faraz_from' ) ) ? $this->option( 'faraz_from' ) : '+983000505',
					'toNum'       => $request->phone(),
					'patternCode' => $pattern,
					'inputData'   => array( $inputKey => $request->code() ),
				)
			);

			return $this->evaluate( $this->post( self::PATTERN_ENDPOINT, $args ) );
		}

		$args['body'] = wp_json_encode(
			array(
				'op'      => 'send',
				'user'    => $username,
				'pass'    => $password,
				'fromNum' => $this->option( 'faraz_from' ),
				'toNum'   => $request->phone(),
				'message' => $request->render( $this->settings->str( 'sms_template' ), $this->settings->int( 'code_ttl', 120 ) ),
			)
		);

		return $this->evaluate( $this->post( self::SEND_ENDPOINT, $args ) );
	}

	/**
	 * @param array|\WP_Error $response
	 */
	private function evaluate( $response ): GatewayResult {
		if ( is_wp_error( $response ) ) {
			return $this->transportFailure( $response );
		}

		$status = $this->status( $response );
		$body   = $this->decode( $response );

		if ( 200 === $status && isset( $body['status'] ) && 'OK' === strtoupper( (string) $body['status'] ) ) {
			return GatewayResult::sent( $this->id(), $this->referenceFrom( $body, array( 'data.refId' ) ), $status );
		}

		return GatewayResult::failed(
			$this->id(),
			$this->codeForStatus( $status ),
			isset( $body['message'] ) ? sanitize_text_field( (string) $body['message'] ) : '',
			$status
		);
	}
}
