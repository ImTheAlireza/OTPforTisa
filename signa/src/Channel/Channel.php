<?php

namespace Signa\Channel;

use Signa\Gateway\DeliveryRequest;
use Signa\Gateway\GatewayResult;

defined( 'ABSPATH' ) || exit;

interface Channel {
	public function id(): string;

	public function label(): string;

	public function available(): bool;

	public function unavailableReason(): string;

	public function deliver( DeliveryRequest $request ): GatewayResult;
}
