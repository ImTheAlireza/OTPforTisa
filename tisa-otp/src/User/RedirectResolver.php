<?php
/**
 * Picks a safe destination after a successful sign-in.
 *
 * @package TisaOtp
 */

namespace TisaOtp\User;

use TisaOtp\Config\Settings;

defined( 'ABSPATH' ) || exit;

final class RedirectResolver {

	/** @var Settings */
	private $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * @param string $context   `login` or `register`.
	 * @param string $requested Value supplied by the form.
	 */
	public function resolve( int $userId, string $context = 'login', string $requested = '' ): string {
		$candidates = array(
			$this->fromRequest(),
			$requested,
			$this->settings->str( 'register' === $context ? 'register_redirect' : 'login_redirect' ),
		);

		if ( 'register' === $context ) {
			$candidates[] = $this->settings->str( 'login_redirect' );
		}

		foreach ( $candidates as $candidate ) {
			$safe = $this->internal( (string) $candidate );

			if ( '' !== $safe ) {
				return $this->filter( $safe, $userId, $context );
			}
		}

		return $this->filter( $this->fallback(), $userId, $context );
	}

	public function fallback(): string {
		if ( class_exists( 'WooCommerce' ) && function_exists( 'wc_get_page_permalink' ) ) {
			$account = (string) wc_get_page_permalink( 'myaccount' );

			if ( '' !== $account ) {
				return $account;
			}
		}

		return home_url( '/' );
	}

	/**
	 * Accept only same-host relative URLs (or absolute ones on this host).
	 */
	public function internal( string $url ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		$validated = wp_validate_redirect( esc_url_raw( rawurldecode( $url ) ), '' );

		return is_string( $validated ) ? $validated : '';
	}

	private function fromRequest(): string {
		$raw = '';

		if ( isset( $_REQUEST['redirect_to'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$raw = sanitize_text_field( wp_unslash( $_REQUEST['redirect_to'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		return $this->internal( $raw );
	}

	private function filter( string $url, int $userId, string $context ): string {
		/**
		 * Filter the post sign-in destination.
		 *
		 * @param string $url     Resolved URL.
		 * @param int    $userId  Signed-in user.
		 * @param string $context `login` or `register`.
		 */
		$filtered = (string) apply_filters( 'tisa_otp_redirect', $url, $userId, $context );

		return '' !== $this->internal( $filtered ) ? $filtered : $this->fallback();
	}
}
