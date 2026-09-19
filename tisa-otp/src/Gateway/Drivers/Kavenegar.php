<?php
/**
 * Kavenegar driver — verify/lookup with a template, or plain sms/send.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Gateway\Drivers;

use TisaOtp\Gateway\DeliveryRequest;
use TisaOtp\Gateway\GatewayResult;
use TisaOtp\Gateway\HttpGateway;

defined( 'ABSPATH' ) || exit;

final class Kavenegar extends HttpGateway {

	const API_BASE = 'https://api.kavenegar.com/v1/';

	public function id(): string {
		return 'kavenegar';
	}

	public function label(): string {
		return __( 'کاوه‌نگار', 'tisa-otp' );
	}

	public function docsUrl(): string {
		return 'https://doc.kavenegar.com/';
	}

	public function fields(): array {
		return array(
			'kavenegar_api_key'  => array(
				'label' => __( 'کلید API', 'tisa-otp' ),
				'type'  => 'password',
			),
			'kavenegar_template' => array(
				'label' => __( 'نام الگوی تأیید', 'tisa-otp' ),
				'type'  => 'text',
				'hint'  => __( 'در صورت تنظیم، از سرویس Verify استفاده می‌شود.', 'tisa-otp' ),
			),
			'kavenegar_sender'   => array(
				'label' => __( 'شماره فرستنده', 'tisa-otp' ),
				'type'  => 'text',
			),
		);
	}

	public function missing(): array {
		return '' === trim( $this->option( 'kavenegar_api_key' ) ) ? array( 'kavenegar_api_key' ) : array();
	}

	public function deliver( DeliveryRequest $request ): GatewayResult {
		$apiKey = $this->option( 'kavenegar_api_key' );

		if ( '' === $apiKey ) {
			return $this->notConfigured( __( 'برای کاوه‌نگار کلید API را در تنظیمات کامل کنید.', 'tisa-otp' ) );
		}

		$template = trim( $this->option( 'kavenegar_template' ) );

		if ( '' !== $template ) {
			$url  = self::API_BASE . rawurlencode( $apiKey ) . '/verify/lookup.json';
			$body = array(
				'receptor' => $request->phone(),
				'token'    => $request->code(),
				'template' => $template,
			);

			return $this->evaluate( $this->post( $url, array( 'body' => $body ) ) );
		}

		$url  = self::API_BASE . rawurlencode( $apiKey ) . '/sms/send.json';
		$body = array(
			'receptor' => $request->phone(),
			'message'  => $request->render( $this->settings->str( 'sms_template' ), $this->settings->int( 'code_ttl', 120 ) ),
		);

		$sender = trim( $this->option( 'kavenegar_sender' ) );
		if ( '' !== $sender ) {
			$body['sender'] = $sender;
		}

		return $this->evaluate( $this->post( $url, array( 'body' => $body ) ) );
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

		if ( isset( $body['return']['status'] ) && 200 === (int) $body['return']['status'] ) {
			return GatewayResult::sent( $this->id(), $this->referenceFrom( $body, array( 'entries.0.messageid' ) ), $status );
		}

		$message = isset( $body['return']['message'] ) ? sanitize_text_field( (string) $body['return']['message'] ) : '';
		$code    = $this->codeForStatus( $status );

		if ( isset( $body['return']['status'] ) ) {
			$apiStatus = (int) $body['return']['status'];
			if ( in_array( $apiStatus, array( 401, 403 ), true ) ) {
				$code = 'unauthorized';
			} elseif ( in_array( $apiStatus, array( 402, 413 ), true ) ) {
				$code = 'no_credit';
			} elseif ( 429 === $apiStatus ) {
				$code = 'rate_limited';
			} elseif ( $apiStatus >= 500 ) {
				$code = 'upstream';
			}
		}

		return GatewayResult::failed( $this->id(), $code, $message, $status );
	}
}
