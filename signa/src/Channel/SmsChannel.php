<?php
/**
 * SMS channel: delegates to the gateway failover chain.
 *
 * @package Signa
 */

namespace Signa\Channel;

use Signa\Config\Settings;
use Signa\Gateway\DeliveryRequest;
use Signa\Gateway\FailoverChain;
use Signa\Gateway\GatewayResult;

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
		return __( 'پیامک', 'signa' );
	}

	public function available(): bool {
		return '' === $this->unavailableReason();
	}

	public function unavailableReason(): string {
		$enabled = $this->settings->arr( 'channels_enabled' );

		// A list saved by an older version (or by a filter) may still be a string.
		if ( is_string( $enabled ) ) {
			$enabled = array_filter( array_map( 'trim', explode( ',', $enabled ) ) );
		}

		if ( ! in_array( 'sms', $enabled, true ) ) {
			return __( 'کانال پیامک غیرفعال است.', 'signa' );
		}

		/*
		 * The channel is usable when at least one gateway in the delivery
		 * order can send. Until 2.0.1 only the primary was checked: a primary
		 * with an expired key made the whole channel "unavailable", and the
		 * fully configured backup was never asked. The first gateway's reason
		 * is still the one reported when none of them can send.
		 */
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

	/**
	 * Why one gateway cannot send right now, or '' when it can.
	 */
	private function gatewayReason( string $gateway ): string {
		foreach ( $this->credentialKeys( $gateway ) as $key ) {
			if ( '' === trim( $this->settings->str( $key ) ) && ! defined( 'SIGNA_' . strtoupper( $key ) ) ) {
				return sprintf(
					/* translators: %s: the empty field */
					__( 'اعتبارنامه سامانه پیامکی کامل نیست: «%s» ذخیره نشده است. آن را وارد کنید و «ذخیره تنظیمات» را بزنید.', 'signa' ),
					$this->fieldLabel( $key )
				);
			}
		}

		/*
		 * Credentials alone do not make a delivery possible: a panel also
		 * refuses a message with no sender line, and free text on a
		 * pattern-only account never leaves the queue. Catching that here turns
		 * an opaque upstream error into a sentence the administrator can act on.
		 */
		$plan = $this->chain->planFor( $gateway );

		if ( array() !== $plan['issues'] ) {
			return __( 'پیکربندی سامانه پیامکی کامل نیست:', 'signa' ) . ' ' . (string) $plan['issues'][0];
		}

		return '';
	}

	/**
	 * Gateway-by-gateway outcome of the last attempt.
	 *
	 * @return array<int,array<string,mixed>>
	 */
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

	/**
	 * The label an administrator sees for a credential option.
	 */
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

	/**
	 * @return string[]
	 */
	private function credentialKeys( string $gateway ): array {
		$map = array(
			'smsir'     => array( 'smsir_api_key' ),
			'kavenegar' => array( 'kavenegar_api_key' ),
			'meli'      => array( 'meli_username', 'meli_password' ),
			'ippanel'   => array( 'ippanel_api_key' ),
			// API key (new platform) or username + password (legacy panel):
			// the driver's own plan() reports which one is missing.
			'faraz'     => array(),
		);

		/**
		 * Filter the credentials required by a gateway id.
		 *
		 * @param array<string,string[]> $map Gateway => option keys.
		 */
		$map = (array) apply_filters( 'signa_gateway_credentials', $map );

		return isset( $map[ $gateway ] ) ? (array) $map[ $gateway ] : array();
	}
}
