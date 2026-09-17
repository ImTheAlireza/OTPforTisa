<?php
/**
 * Contract every SMS gateway driver implements.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Gateway;

defined( 'ABSPATH' ) || exit;

interface SmsGateway {

	public function id(): string;

	public function label(): string;

	public function docsUrl(): string;

	/**
	 * Settings schema consumed by the admin screen.
	 *
	 * Each entry: array( 'label' => string, 'type' => text|password|number, 'hint' => string ).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function fields(): array;

	/**
	 * Whether the credentials required by this driver are present.
	 */
	public function ready(): bool;

	/**
	 * Keys that must be filled in before this driver can work.
	 *
	 * @return string[]
	 */
	public function missing(): array;

	public function deliver( DeliveryRequest $request ): GatewayResult;
}
