<?php
/**
 * IPPanel driver — pattern delivery when a pattern code exists, otherwise bulk.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Gateway\Drivers;

use TisaOtp\Gateway\DeliveryRequest;
use TisaOtp\Gateway\GatewayResult;
use TisaOtp\Gateway\HttpGateway;

defined( 'ABSPATH' ) || exit;

final class Ippanel extends HttpGateway {

	const ENDPOINT = 'https://edge.ippanel.com/v1/api/send';

	public function id(): string {
		return 'ippanel';
	}

	public function label(): string {
		return __( 'IPPanel', 'tisa-otp' );
	}

	public function docsUrl(): string {
		return 'https://ippanel.com/';
	}

	public function fields(): array {
		return array(
			'ippanel_api_key' => array(
				'label' => __( 'کلید API', 'tisa-otp' ),
				'type'  => 'password',
			),
			'ippanel_pattern' => array(
				'label' => __( 'کد پترن', 'tisa-otp' ),
				'type'  => 'text',
			),
			'ippanel_sender'  => array(
				'label' => __( 'شماره فرستنده', 'tisa-otp' ),
				'type'  => 'text',
			),
		);
	}

	public function missing(): array {
		return '' === trim( $this->option( 'ippanel_api_key' ) ) ? array( 'ippanel_api_key' ) : array();
	}

	public function deliver( DeliveryRequest $request ): GatewayResult {
		$apiKey = $this->option( 'ippanel_api_key' );

		if ( '' === $apiKey ) {
			return $this->notConfigured( __( 'کلید API آی‌پی‌پنل تنظیم نشده است.', 'tisa-otp' ) );
		}

		$pattern = trim( $this->option( 'ippanel_pattern' ) );

		$payload = array(
			'from_number' => $this->option( 'ippanel_sender' ),
			'recipients'  => array( $request->phone() ),
		);

		if ( '' !== $pattern ) {
			$payload['sending_type'] = 'pattern';
			$payload['code']         = $pattern;
			$payload['params']       = array(
				'code' => $request->code(),
			);
		} else {
			$payload['sending_type'] = 'bulk';
			$payload['message']      = $request->render( $this->settings->str( 'sms_template' ), $this->settings->int( 'code_ttl', 120 ) );
		}

		$response = $this->post(
			self::ENDPOINT,
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => $apiKey,
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->transportFailure( $response );
		}

		$status = $this->status( $response );
		$body   = $this->decode( $response );

		if ( in_array( $status, array( 200, 201 ), true ) && empty( $body['error'] ) ) {
			return GatewayResult::sent( $this->id(), $this->referenceFrom( $body, array( 'data.id' ) ), $status );
		}

		return GatewayResult::failed(
			$this->id(),
			$this->codeForStatus( $status ),
			isset( $body['error'] ) ? sanitize_text_field( (string) $body['error'] ) : '',
			$status
		);
	}
}
