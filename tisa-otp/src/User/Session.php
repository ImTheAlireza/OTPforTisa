<?php
/**
 * Signs users in and out after a successful verification.
 *
 * @package TisaOtp
 */

namespace TisaOtp\User;

use TisaOtp\Log\Logger;

defined( 'ABSPATH' ) || exit;

final class Session {

	/** @var RedirectResolver */
	private $redirects;

	/** @var Logger */
	private $logger;

	public function __construct( RedirectResolver $redirects, Logger $logger ) {
		$this->redirects = $redirects;
		$this->logger    = $logger;
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
		wp_set_auth_cookie( $user->ID, true, is_ssl() );

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
