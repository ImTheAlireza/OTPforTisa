<?php

namespace Signa\User;

use Signa\Config\Settings;

defined( 'ABSPATH' ) || exit;

final class RedirectResolver {
	private $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

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

		if ( isset( $_REQUEST['redirect_to'] ) ) {
			$raw = sanitize_text_field( wp_unslash( $_REQUEST['redirect_to'] ) );
		}

		return $this->internal( $raw );
	}

	private function filter( string $url, int $userId, string $context ): string {
		$filtered = (string) apply_filters( 'signa_redirect', $url, $userId, $context );

		return '' !== $this->internal( $filtered ) ? $filtered : $this->fallback();
	}
}
