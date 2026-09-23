<?php
/**
 * Signs users in and out after a successful verification.
 *
 * @package TisaOtp
 */

namespace TisaOtp\User;

use TisaOtp\Config\Settings;
use TisaOtp\Log\Logger;

defined( 'ABSPATH' ) || exit;

final class Session {

	/** @var RedirectResolver */
	private $redirects;

	/** @var Logger */
	private $logger;

	/** @var Settings */
	private $settings;

	public function __construct( RedirectResolver $redirects, Settings $settings, Logger $logger ) {
		$this->redirects = $redirects;
		$this->settings  = $settings;
		$this->logger    = $logger;
	}

	/**
	 * Should this sign-in be remembered for two weeks?
	 *
	 * A one-time code is a weak second factor to be trusted with a 14-day
	 * cookie, so the choice belongs to the site: the setting defaults to on
	 * (the behaviour every earlier version had) and the filter is there for
	 * sites that want a session-length cookie instead.
	 */
	private function remember(): bool {
		/**
		 * Filter whether a verified visitor is remembered for two weeks.
		 *
		 * @param bool $remember Current decision.
		 */
		return (bool) apply_filters( 'tisa_otp_remember_login', $this->settings->bool( 'remember_login', true ) );
	}

	/**
	 * @param string $context   `login` or `register`.
	 * @param string $requested Redirect requested by the form.
	 * @return array{user_id:int,redirect:string,display_name:string}
	 */
	public function signIn( \WP_User $user, string $context = 'login', string $requested = '', string $phone = '' ): array {
		// Drop any half-open session from a previous identity before issuing cookies.
		wp_clear_auth_cookie();
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, $this->remember(), is_ssl() );

		/**
		 * The WordPress sign-in contract, fired after the cookies are set.
		 *
		 * Everything that watches for a login hooks this: activity logs,
		 * session managers, "new device" notices, cache purgers that key on a
		 * user. A plugin that signs people in without firing it is invisible to
		 * all of them, and the site owner is left with a log that has a hole in
		 * exactly the place they need it. Fired exactly where `wp_signon()`
		 * fires it.
		 *
		 * @param string   $user_login The user's login name.
		 * @param \WP_User $user       The signed-in user.
		 */
		do_action( 'wp_login', (string) $user->user_login, $user );

		$redirect = $this->redirects->resolve( (int) $user->ID, $context, $requested );

		$this->logger->info(
			'session.signed_in',
			array(
				'user_id' => (int) $user->ID,
				'phone'   => $phone,
				'context' => $context,
			)
		);

		/**
		 * Fires after a user has been signed in with a one-time code.
		 *
		 * @param int      $userId   User id.
		 * @param string   $phone    Canonical phone number.
		 * @param string   $context  `login` or `register`.
		 * @param \WP_User $user     Signed-in user.
		 */
		do_action( 'tisa_otp_signed_in', (int) $user->ID, $phone, $context, $user );

		return array(
			'user_id'      => (int) $user->ID,
			'redirect'     => $redirect,
			'display_name' => (string) $user->display_name,
		);
	}

	public function signOut(): void {
		$userId = get_current_user_id();

		if ( $userId > 0 ) {
			$this->logger->info( 'session.signed_out', array( 'user_id' => $userId ) );
		}

		wp_logout();
	}

	public function redirector(): RedirectResolver {
		return $this->redirects;
	}
}
