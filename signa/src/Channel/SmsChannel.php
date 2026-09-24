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

		$gateway = $this->settings->str( 'sms_gateway', 'smsir' );
		$keys    = $this->credentialKeys( $gateway );

		foreach ( $keys as $key ) {
			if ( '' === trim( $this->settings->str( $key ) ) && ! defined( 'SIGNA_' . strtoupper( $key ) ) ) {
				return __( 'اعتبارنامه سامانه پیامکی کامل نیست.', 'signa' );
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
		$map = (array) apply_filters( 'signa_gateway_credentials', $map );

		return isset( $map[ $gateway ] ) ? (array) $map[ $gateway ] : array();
	}
}
