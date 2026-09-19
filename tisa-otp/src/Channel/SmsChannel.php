<?php
/**
 * SMS channel: delegates to the gateway failover chain.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Channel;

use TisaOtp\Config\Settings;
use TisaOtp\Gateway\DeliveryRequest;
use TisaOtp\Gateway\FailoverChain;
use TisaOtp\Gateway\GatewayResult;

defined( 'ABSPATH' ) || exit;

final class SmsChannel implements Channel {

	/** @var FailoverChain */
	private $chain;

	/** @var Settings */
	private $settings;

	public function __construct( FailoverChain $chain, Settings $settings ) {
		$this->chain    = $chain;
		$this->settings = $settings;
	}

	public function id(): string {
		return 'sms';
	}

	public function label(): string {
		return __( 'پیامک', 'tisa-otp' );
	}

	public function available(): bool {
		return '' === $this->unavailableReason();
	}

	public function unavailableReason(): string {
		if ( ! in_array( 'sms', $this->settings->arr( 'channels_enabled' ), true ) ) {
			return __( 'کانال پیامک غیرفعال است.', 'tisa-otp' );
		}

		$gateway = $this->settings->str( 'sms_gateway', 'smsir' );
		$keys    = $this->credentialKeys( $gateway );

		foreach ( $keys as $key ) {
			if ( '' === trim( $this->settings->str( $key ) ) && ! defined( 'TISA_OTP_' . strtoupper( $key ) ) ) {
				return __( 'اعتبارنامه سامانه پیامکی کامل نیست.', 'tisa-otp' );
			}
		}

		return '';
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

	/**
	 * @return string[]
	 */
	private function credentialKeys( string $gateway ): array {
		$map = array(
			'smsir'     => array( 'smsir_api_key' ),
			'kavenegar' => array( 'kavenegar_api_key' ),
			'meli'      => array( 'meli_username', 'meli_password' ),
			'ippanel'   => array( 'ippanel_api_key' ),
			'faraz'     => array( 'faraz_username', 'faraz_password' ),
		);

		/**
		 * Filter the credentials required by a gateway id.
		 *
		 * @param array<string,string[]> $map Gateway => option keys.
		 */
		$map = (array) apply_filters( 'tisa_otp_gateway_credentials', $map );

		return isset( $map[ $gateway ] ) ? (array) $map[ $gateway ] : array();
	}
}
