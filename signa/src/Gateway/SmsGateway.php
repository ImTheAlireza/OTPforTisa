<?php

namespace Signa\Gateway;

defined( 'ABSPATH' ) || exit;

interface SmsGateway {
	public function id(): string;

	public function label(): string;

	public function docsUrl(): string;

	public function fields(): array;

	public function ready(): bool;

	public function missing(): array;

	public function plan(): array;

	public function deliver( DeliveryRequest $request ): GatewayResult;
}
