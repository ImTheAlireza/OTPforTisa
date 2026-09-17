<?php
/**
 * Imports numbers already collected by WooCommerce checkout.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Import;

defined( 'ABSPATH' ) || exit;

final class WooBillingSource extends MetaQuerySource {

	public function id(): string {
		return 'woo_billing';
	}

	public function label(): string {
		return __( 'شماره صورتحساب ووکامرس', 'tisa-otp' );
	}

	protected function keys(): array {
		return array( 'billing_phone', 'billing_mobile', 'shipping_phone' );
	}
}
