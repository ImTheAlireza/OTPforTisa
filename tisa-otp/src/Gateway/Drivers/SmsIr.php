<?php
/**
 * SMS.ir driver — uses the verification template when one is configured and
 * falls back to a plain bulk send otherwise.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Gateway\Drivers;

use TisaOtp\Gateway\DeliveryRequest;
use TisaOtp\Gateway\GatewayResult;
use TisaOtp\Gateway\HttpGateway;

defined( 'ABSPATH' ) || exit;

final class SmsIr extends HttpGateway {

	const VERIFY_ENDPOINT = 'https://api.sms.ir/v1/send/verify';
	const BULK_ENDPOINT   = 'https://api.sms.ir/v1/send/bulk';

	public function id(): string {
		return 'smsir';
	}

	public function label(): string {
		return __( 'SMS.ir', 'tisa-otp' );
	}

	public function docsUrl(): string {
		return 'https://apidoc.sms.ir/';
	}

	public function fields(): array {
		return array(
			'smsir_api_key'     => array(
				'label' => __( 'کلید API', 'tisa-otp' ),
				'type'  => 'password',
			),
			'smsir_template_id' => array(
				'label' => __( 'شناسه الگوی تأیید', 'tisa-otp' ),
				'type'  => 'text',
				'hint'  => __( 'اگر خالی بماند، پیامک متنی معمولی ارسال می‌شود.', 'tisa-otp' ),
			),
			'smsir_sender'      => array(
				'label' => __( 'شماره خط', 'tisa-otp' ),
				'type'  => 'text',
				'hint'  => __( 'فقط برای ارسال متنی لازم است.', 'tisa-otp' ),
			),
		);
	}

	public function missing(): array {
		return '' === trim( $this->option( 'smsir_api_key' ) ) ? array( 'smsir_api_key' ) : array();
	}

	public function deliver( DeliveryRequest $request ): GatewayResult {
		$apiKey = $this->option( 'smsir_api_key' );

		if ( '' === $apiKey ) {
			return $this->notConfigured( __( 'کلید API سامانه SMS.ir تنظیم نشده است.', 'tisa-otp' ) );
		}

		$templateId = trim( $this->option( 'smsir_template_id' ) );

		$args = array(
			'headers' => array(
				'Content-Type' => 'application/json',
				'X-API-KEY'    => $apiKey,
				'Accept'       => 'application/json',
			),
		);

		if ( '' !== $templateId ) {
			/**
			 * Filter the parameter name used by the SMS.ir verification template.
			 *
			 * @param string $name Parameter name.
			 */
			$param        = (string) apply_filters( 'tisa_otp_smsir_param', 'CODE' );
			$args['body'] = wp_json_encode(
				array(
					'mobile'     => $request->phone(),
					'templateId' => (int) $templateId,
					'parameters' => array(
						array(
							'name'  => $param,
							'value' => $request->code(),
						),
					),
				)
			);

			return $this->evaluate( $this->post( self::VERIFY_ENDPOINT, $args ) );
		}

		$args['body'] = wp_json_encode(
			array(
				'lineNumber'  => $this->option( 'smsir_sender' ),
				'messageText' => $request->render( $this->settings->str( 'sms_template' ), $this->settings->int( 'code_ttl', 120 ) ),
				'mobiles'     => array( $request->phone() ),
			)
		);

		return $this->evaluate( $this->post( self::BULK_ENDPOINT, $args ) );
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

		if ( 200 === $status && isset( $body['status'] ) && 1 === (int) $body['status'] ) {
			return GatewayResult::sent( $this->id(), $this->referenceFrom( $body, array( 'data.messageId' ) ), $status );
		}

		return GatewayResult::failed(
			$this->id(),
			$this->codeForStatus( $status ),
			isset( $body['message'] ) ? sanitize_text_field( (string) $body['message'] ) : '',
			$status
		);
	}
}
