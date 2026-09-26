<?php

namespace Signa\Front;

use Signa\Bootable;
use Signa\Config\Settings;

defined( 'ABSPATH' ) || exit;

final class LoginBridge implements Bootable {
	private $settings;
	private $renderer;

	public function __construct( Settings $settings, FormRenderer $renderer ) {
		$this->settings = $settings;
		$this->renderer = $renderer;
	}

	public function boot(): void {
		add_filter( 'authenticate', array( $this, 'closePasswordLogin' ), 99, 3 );

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

	public function closePasswordLogin( $user, $username, $password ) {
		unset( $password );

		if ( ! $this->settings->bool( 'enabled', true ) || ! $this->settings->bool( 'password_login_off', false ) ) {
			return $user;
		}

		if ( '' === (string) $username ) {
			return $user;
		}

		if ( $this->isApiRequest() ) {
			return $user;
		}

		if ( (bool) apply_filters( 'signa_allow_password_login', false, (string) $username ) ) {
			return $user;
		}

		return new \WP_Error(
			'password_login_disabled',
			__( 'ورود با گذرواژه در این سایت بسته است. با شماره موبایل و کد تأیید وارد شوید.', 'signa' )
		);
	}

	private function isApiRequest(): bool {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return true;
		}

		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return true;
		}

		return defined( 'WP_CLI' ) && WP_CLI;
	}

	public function assets(): void {
		if ( ! $this->active() ) {
			return;
		}

		wp_add_inline_style( Assets::STYLE, 'body.login{background:#f6f7f9}' );
	}

	public function bodyClass( $classes = array(), $title = '' ): array {
		unset( $title );

		if ( ! is_array( $classes ) ) {
			$classes = array_values( array_filter( array_map( 'trim', explode( ' ', (string) $classes ) ) ) );
		}

		if ( $this->active() && ! in_array( 'signa-login', $classes, true ) ) {
			$classes[] = 'signa-login';
		}

		return $classes;
	}

	public function inject( string $message ): string {
		if ( ! $this->active() || is_user_logged_in() ) {
			return $message;
		}

		$redirect = admin_url();

		if ( isset( $_REQUEST['redirect_to'] ) ) {
			$requested = sanitize_text_field( wp_unslash( $_REQUEST['redirect_to'] ) );
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
