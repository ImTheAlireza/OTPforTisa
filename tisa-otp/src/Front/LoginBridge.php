<?php
/**
 * Puts the OTP form on wp-login.php instead of the classic username form.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Front;

use TisaOtp\Bootable;
use TisaOtp\Config\Settings;

defined( 'ABSPATH' ) || exit;

final class LoginBridge implements Bootable {

	/** @var Settings */
	private $settings;

	/** @var FormRenderer */
	private $renderer;

	public function __construct( Settings $settings, FormRenderer $renderer ) {
		$this->settings = $settings;
		$this->renderer = $renderer;
	}

	public function boot(): void {
		if ( ! $this->active() ) {
			return;
		}

		add_action( 'login_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'login_message', array( $this, 'inject' ), 5 );
		add_filter( 'login_headerurl', array( $this, 'headerUrl' ) );
		add_filter( 'login_body_class', array( $this, 'bodyClass' ) );
	}

	public function active(): bool {
		return $this->settings->bool( 'enabled', true ) && $this->settings->bool( 'replace_wp_login', false );
	}

	public function assets(): void {
		if ( ! $this->active() ) {
			return;
		}

		// The class-based rules live in front.css; only page-level polish goes here.
		wp_add_inline_style( Assets::STYLE, 'body.login{background:#f6f7f9}' );
	}

	/**
	 * Tag the login screen so front.css can hide the classic form.
	 *
	 * Accepts whatever the filter hands us — an array or a space-separated string.
	 *
	 * @param string[]|string $classes Body classes.
	 * @param string          $title   Unused, keeps the filter signature.
	 * @return string[]
	 */
	public function bodyClass( $classes = array(), $title = '' ): array {
		unset( $title );

		if ( ! is_array( $classes ) ) {
			$classes = array_values( array_filter( array_map( 'trim', explode( ' ', (string) $classes ) ) ) );
		}

		if ( $this->active() && ! in_array( 'tisa-otp-login', $classes, true ) ) {
			$classes[] = 'tisa-otp-login';
		}

		return $classes;
	}

	public function inject( string $message ): string {
		if ( ! $this->active() || is_user_logged_in() ) {
			return $message;
		}

		$redirect = admin_url();

		if ( isset( $_REQUEST['redirect_to'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$requested = sanitize_text_field( wp_unslash( $_REQUEST['redirect_to'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$validated = wp_validate_redirect( $requested, '' );

			if ( is_string( $validated ) && '' !== $validated ) {
				$redirect = $validated;
			}
		}

		return $message . $this->renderer->render(
			array(
				'redirect' => $redirect,
				'title'    => $this->settings->str( 'form_heading' ),
			)
		);
	}

	public function headerUrl(): string {
		return home_url( '/' );
	}
}
