<?php
/**
 * MeliPayamak driver (REST panel API).
 *
 * @package TisaOtp
 */

namespace TisaOtp\Gateway\Drivers;

use TisaOtp\Gateway\DeliveryRequest;
use TisaOtp\Gateway\GatewayResult;
use TisaOtp\Gateway\HttpGateway;

defined( 'ABSPATH' ) || exit;

final class MeliPayamak extends HttpGateway {

	const ENDPOINT = 'https://rest.payamak-panel.com/api/SendSMS/SendSMS';

	public function id(): string {
		return 'meli';
	}

	public function label(): string {
		return __( 'ملی پیامک', 'tisa-otp' );
	}

	public function docsUrl(): string {
		return 'https://www.melipayamak.com/';
	}

	public function fields(): array {
		return array(
			'meli_username' => array(
				'label' => __( 'نام کاربری', 'tisa-otp' ),
				'type'  => 'text',
			),
			'meli_password' => array(
				'label' => __( 'رمز عبور', 'tisa-otp' ),
				'type'  => 'password',
			),
			'meli_from'     => array(
				'label' => __( 'شماره فرستنده', 'tisa-otp' ),
				'type'  => 'text',
			),
		);
	}

	public function missing(): array {
		$missing = array();

		if ( '' === trim( $this->option( 'meli_username' ) ) ) {
			$missing[] = 'meli_username';
		}
		if ( '' === trim( $this->option( 'meli_password' ) ) ) {
			$missing[] = 'meli_password';
		}

		return $missing;
	}

	/**
	 * @return array{mode:string,sender:string,template:string,endpoint:string,issues:string[],notes:string[]}
	 */
	public function plan(): array {
		$sender = trim( $this->option( 'meli_from' ) );
		$issues = array();

		foreach ( $this->missing() as $key ) {
			$label    = isset( $this->fields()[ $key ]['label'] ) ? (string) $this->fields()[ $key ]['label'] : $key;
			$issues[] = sprintf( /* translators: %s: settings field label */ __( 'مقدار «%s» تنظیم نشده است.', 'tisa-otp' ), $label );
		}

		if ( '' === $sender ) {
			$issues[] = __( 'شماره فرستنده تنظیم نشده است؛ پنل ملی پیامک بدون آن پیام را رد می‌کند.', 'tisa-otp' );
		}

		return array(
			'mode'     => 'text',
			'sender'   => $sender,
			'template' => '',
			'endpoint' => self::ENDPOINT,
			'issues'   => $issues,
			'notes'    => array( __( 'اگر حساب شما فقط الگو (Pattern) دارد، ارسال متنی آزاد کار نمی‌کند.', 'tisa-otp' ) ),
		);
	}

	public function deliver( DeliveryRequest $request ): GatewayResult {
		if ( array() !== $this->missing() ) {
			return $this->notConfigured( __( 'برای ملی پیامک نام کاربری و رمز را در تنظیمات کامل کنید.', 'tisa-otp' ) );
		}

		$response = $this->post(
			self::ENDPOINT,
			array(
				'body' => wp_json_encode(
					array(
						'username' => $this->option( 'meli_username' ),
						'password' => $this->option( 'meli_password' ),
						'to'       => $request->phone(),
						'from'     => $this->option( 'meli_from' ),
						'text'     => $request->render( $this->settings->str( 'sms_template' ), $this->settings->int( 'code_ttl', 120 ) ),
						'isFlash'  => false,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->transportFailure( $response );
		}

		$status = $this->status( $response );
		$body   = $this->decode( $response );

		if ( 200 === $status && isset( $body['RetStatus'] ) && 1 === (int) $body['RetStatus'] ) {
			return GatewayResult::sent( $this->id(), $this->referenceFrom( $body, array( 'StrRetStatus' ) ), $status );
		}

		$detail = isset( $body['StrRetStatus'] ) ? sanitize_text_field( (string) $body['StrRetStatus'] ) : '';

		if ( 401 === $status || 403 === $status ) {
			return GatewayResult::failed( $this->id(), 'unauthorized', $detail, $status );
		}

		return GatewayResult::failed( $this->id(), $this->codeForStatus( $status ), $detail, $status );
	}
}
