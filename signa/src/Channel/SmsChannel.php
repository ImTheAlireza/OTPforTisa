<?php

namespace Signa\Channel;

use Signa\Config\Settings;
use Signa\Gateway\DeliveryRequest;
use Signa\Gateway\FailoverChain;
use Signa\Gateway\GatewayResult;

defined( 'ABSPATH' ) || exit;

final class SmsChannel implements Channel {
	private $chain;
	private $settings;

	public function __construct( FailoverChain $chain, Settings $settings ) {
		$this->chain    = $chain;
		$this->settings = $settings;
	}

	public function id(): string {
		return 'sms';
	}

	public function label(): string {
		return __( 'پیامک', 'signa' );
	}

	public function available(): bool {
		return '' === $this->unavailableReason();
	}

	public function unavailableReason(): string {
		$enabled = $this->settings->arr( 'channels_enabled' );

		if ( is_string( $enabled ) ) {
			$enabled = array_filter( array_map( 'trim', explode( ',', $enabled ) ) );
		}

		if ( ! in_array( 'sms', $enabled, true ) ) {
			return __( 'کانال پیامک غیرفعال است.', 'signa' );
		}

		$order = $this->chain->order();

		if ( array() === $order ) {
			$order = array( $this->settings->str( 'sms_gateway', 'smsir' ) );
		}

		$first = '';

		foreach ( $order as $gateway ) {
			$reason = $this->gatewayReason( (string) $gateway );

			if ( '' === $reason ) {
				return '';
			}

			if ( '' === $first ) {
				$first = $reason;
			}
		}

		return $first;
	}

	private function gatewayReason( string $gateway ): string {
		foreach ( $this->credentialKeys( $gateway ) as $key ) {
			if ( '' === trim( $this->settings->str( $key ) ) && ! defined( 'SIGNA_' . strtoupper( $key ) ) ) {
				return sprintf(
					__( 'اعتبارنامه سامانه پیامکی کامل نیست: «%s» ذخیره نشده است. آن را وارد کنید و «ذخیره تنظیمات» را بزنید.', 'signa' ),
					$this->fieldLabel( $key )
				);
			}
		}

		$plan = $this->chain->planFor( $gateway );

		if ( array() !== $plan['issues'] ) {
			return __( 'پیکربندی سامانه پیامکی کامل نیست:', 'signa' ) . ' ' . (string) $plan['issues'][0];
		}

		return '';
	}

	public function trace(): array {
		return $this->chain->trace();
	}

	public function health(): \Signa\Gateway\Health {
		return $this->chain->health();
	}

	public function deliver( DeliveryRequest $request ): GatewayResult {
		$request = $request->with(
			array(
				'channel' => $this->id(),
				'webotp'  => $this->settings->bool( 'webotp_enabled', false ),
			)
		);

		return $this->chain->deliver( $request );
	}

	private function fieldLabel( string $key ): string {
		$labels = array(
			'smsir_api_key'     => __( 'کلید API سامانه sms.ir', 'signa' ),
			'kavenegar_api_key' => __( 'کلید API کاوه‌نگار', 'signa' ),
			'meli_username'     => __( 'نام کاربری ملی پیامک', 'signa' ),
			'meli_password'     => __( 'رمز عبور ملی پیامک', 'signa' ),
			'ippanel_api_key'   => __( 'کلید API آی‌پی‌پنل', 'signa' ),
		);

		return isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
	}

	private function credentialKeys( string $gateway ): array {
		$map = array(
			'smsir'     => array( 'smsir_api_key' ),
			'kavenegar' => array( 'kavenegar_api_key' ),
			'meli'      => array( 'meli_username', 'meli_password' ),
			'ippanel'   => array( 'ippanel_api_key' ),
			'faraz'     => array(),
		);

		$map = (array) apply_filters( 'signa_gateway_credentials', $map );

		return isset( $map[ $gateway ] ) ? (array) $map[ $gateway ] : array();
	}
}
