<?php

namespace Signa\Woo;

use Signa\Bootable;
use Signa\Config\Settings;
use Signa\Front\FormRenderer;
use Signa\Support\Phone;

defined( 'ABSPATH' ) || exit;

final class Bridge implements Bootable {
	private $settings;
	private $renderer;

	public function __construct( Settings $settings, FormRenderer $renderer ) {
		$this->settings = $settings;
		$this->renderer = $renderer;
	}

	public function boot(): void {
		if ( ! $this->installed() ) {
			return;
		}

		add_action( 'before_woocommerce_init', array( $this, 'declareCompat' ) );

		if ( $this->settings->bool( 'woo_account_form', true ) ) {
			add_filter( 'woocommerce_locate_template', array( $this, 'locateTemplate' ), 20, 3 );
			add_action( 'woocommerce_before_customer_login_form', array( $this, 'renderAccountForm' ) );
		}

		if ( $this->settings->bool( 'woo_checkout_gate', false ) ) {
			add_action( 'template_redirect', array( $this, 'checkoutGate' ), 1 );
			add_action( 'woocommerce_before_checkout_form', array( $this, 'checkoutNotice' ), 5 );
		}

		if ( $this->settings->bool( 'link_guest_orders', true ) ) {
			add_action( 'signa_user_created', array( $this, 'linkGuestOrders' ), 20, 3 );
		}
	}

	public function installed(): bool {
		return class_exists( 'WooCommerce' );
	}

	public function declareCompat(): void {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SIGNA_FILE, true );

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', SIGNA_FILE, false );
	}

	public function locateTemplate( string $template, string $templateName, string $templatePath ): string {
		if ( 'myaccount/form-login.php' !== $templateName ) {
			return $template;
		}

		$override = SIGNA_PATH . 'templates/woocommerce/form-login.php';

		return is_readable( $override ) ? $override : $template;
	}

	public function renderAccountForm(): void {
		if ( is_user_logged_in() ) {
			return;
		}

		echo '<div class="signa-woo-login">';
		echo $this->renderer->render( array( 'redirect' => $this->accountUrl() ) );
		echo '</div>';
	}

	public function checkoutGate(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_user_logged_in() || ( function_exists( 'is_cart' ) && is_cart() ) ) {
			return;
		}

		$target = $this->loginPageUrl();

		if ( '' === $target ) {
			return;
		}

		$return = add_query_arg( 'redirect_to', rawurlencode( $this->currentUrl() ), $target );

		wp_safe_redirect( $return );
		exit;
	}

	public function checkoutNotice(): void {
		if ( is_user_logged_in() || ! function_exists( 'wc_print_notice' ) ) {
			return;
		}

		$notice = $this->settings->str( 'woo_checkout_notice' );

		if ( '' !== trim( $notice ) ) {
			wc_print_notice( $notice, 'notice' );
		}
	}

	public function linkGuestOrders( int $userId, string $phone, array $values = array() ): void {
		unset( $values );

		if ( ! function_exists( 'wc_get_orders' ) || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$variants = Phone::variants( $phone );

		if ( array() === $variants ) {
			return;
		}

		$orderIds = wc_get_orders(
			array(
				'limit'      => 50,
				'return'     => 'ids',
				'meta_query' => array(
					array(
						'key'     => '_billing_phone',
						'value'   => $variants,
						'compare' => 'IN',
					),
				),
			)
		);

		foreach ( (array) $orderIds as $orderId ) {
			$order = wc_get_order( $orderId );

			if ( ! $order || 0 !== (int) $order->get_customer_id() ) {
				continue;
			}

			$order->set_customer_id( $userId );
			$order->save();

			do_action( 'signa_order_linked', (int) $orderId, $userId );
		}
	}

	public function accountUrl(): string {
		return function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'myaccount' ) : home_url( '/' );
	}

	public function loginPageUrl(): string {
		$configured = $this->settings->str( 'woo_checkout_page' );

		if ( '' !== $configured ) {
			return $configured;
		}

		return wp_login_url();
	}

	private function currentUrl(): string {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';

		return home_url( $path );
	}
}
