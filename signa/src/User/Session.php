<?php

namespace Signa\User;

use Signa\Config\Settings;
use Signa\Log\Logger;

defined( 'ABSPATH' ) || exit;

final class Session {
	private $redirects;
	private $logger;
	private $settings;

	public function __construct( RedirectResolver $redirects, Settings $settings, Logger $logger ) {
		$this->redirects = $redirects;
		$this->settings  = $settings;
		$this->logger    = $logger;
	}

	private function remember(): bool {
		return (bool) apply_filters( 'signa_remember_login', $this->settings->bool( 'remember_login', true ) );
	}

	public function signIn( \WP_User $user, string $context = 'login', string $requested = '', string $phone = '' ): array {
		wp_clear_auth_cookie();
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, $this->remember(), is_ssl() );

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

		do_action( 'signa_signed_in', (int) $user->ID, $phone, $context, $user );

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
