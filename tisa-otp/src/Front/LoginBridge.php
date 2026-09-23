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
		/*
		 * The password door is a separate decision from the form swap: one is
		 * how the login page *looks*, the other is whether the classic
		 * username/password path still opens at all. A site can want either,
		 * both or neither.
		 */
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

	/**
	 * Refuse username/password sign-ins when the site asked for code-only.
	 *
	 * Hiding the classic form with CSS is a suggestion, not a rule: anybody can
	 * POST the fields straight to `wp-login.php` and walk in with a password.
	 * With «ورود فقط با کد» on, the `authenticate` filter returns an error
	 * before WordPress checks the credentials, so the password path is shut
	 * rather than merely hidden.
	 *
	 * Four things are deliberately left alone, because breaking them would
	 * break the site rather than harden it:
	 *
	 *  - requests that carry no password (an OTP sign-in never goes through
	 *    this filter with one, and other plugins' social logins have none);
	 *  - REST and XML-RPC requests, where application passwords and the
	 *    WordPress apps sign in — the setting is about the browser form;
	 *  - WP-CLI and cron, where an administrator may have to get in;
	 *  - anything an administrator explicitly allows through the
	 *    `tisa_otp_allow_password_login` filter.
	 *
	 * The way back in when SMS is down is the emergency code on the access
	 * screen, which never touches this path.
	 *
	 * @param \WP_User|\WP_Error|null $user     What earlier filters decided.
	 * @param string                  $username Submitted login.
	 * @param string                  $password Submitted password.
	 * @return \WP_User|\WP_Error|null
	 */
	public function closePasswordLogin( $user, $username, $password ) {
		unset( $password );

		if ( ! $this->settings->bool( 'enabled', true ) || ! $this->settings->bool( 'password_login_off', false ) ) {
			return $user;
		}

		// No credentials at all: this is a probe, not a sign-in attempt.
		if ( '' === (string) $username ) {
			return $user;
		}

		if ( $this->isApiRequest() ) {
			return $user;
		}

		/**
		 * Allow a username/password sign-in even when the site closed the door.
		 *
		 * @param bool   $allow    Default false.
		 * @param string $username Submitted login.
		 */
		if ( (bool) apply_filters( 'tisa_otp_allow_password_login', false, (string) $username ) ) {
			return $user;
		}

		return new \WP_Error(
			'password_login_disabled',
			__( 'ورود با گذرواژه در این سایت بسته است. با شماره موبایل و کد تأیید وارد شوید.', 'tisa-otp' )
		);
	}

	/**
	 * REST, XML-RPC, WP-CLI and cron are not the login form.
	 */
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
