<?php
/**
 * A delivery channel (SMS, email, …) that can hand a code to a user.
 *
 * @package Signa
 */

namespace Signa\Channel;

use Signa\Gateway\DeliveryRequest;
use Signa\Gateway\GatewayResult;

defined( 'ABSPATH' ) || exit;

interface Channel {

	public function id(): string;

	public function label(): string;

	/**
	 * Enabled in settings *and* correctly configured.
	 */
	public function available(): bool;

	/**
	 * Reason the channel cannot be used right now (empty when available).
	 */
	public function unavailableReason(): string;

	public function deliver( DeliveryRequest $request ): GatewayResult;
}
