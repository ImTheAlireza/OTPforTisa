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

	/**
	 * How this driver would send *right now*, and what would stop it.
	 *
	 * `missing()` only answers "is a password typed in". A panel also refuses a
	 * message when the sender line is wrong or when free text is sent to a
	 * pattern-only account — both looked identical to the visitor ("the code was
	 * not sent"). This report is what the tools screen shows to explain it.
	 *
	 * @return array{mode:string,sender:string,template:string,endpoint:string,issues:string[],notes:string[]}
	 */
	public function plan(): array;

	public function deliver( DeliveryRequest $request ): GatewayResult;
}
