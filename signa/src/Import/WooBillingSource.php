<?php
/**
 * Imports numbers already collected by WooCommerce checkout.
 *
 * @package Signa
 */

namespace Signa\Import;

defined( 'ABSPATH' ) || exit;

final class WooBillingSource extends MetaQuerySource {

	public function id(): string {
		return 'woo_billing';
	}

	public function label(): string {
		return __( 'شماره صورتحساب ووکامرس', 'signa' );
	}

	protected function keys(): array {
		return array( 'billing_phone', 'billing_mobile', 'shipping_phone' );
	}
}
