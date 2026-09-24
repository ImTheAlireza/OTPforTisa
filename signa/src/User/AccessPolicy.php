<?php
/**
 * Decides who is allowed to sign in with a one-time code.
 *
 * Privileged roles are locked out by default: an OTP bypass of the password
 * would otherwise weaken every admin account on the site.
 *
 * @package Signa
 */

namespace Signa\User;

use Signa\Config\Settings;

defined( 'ABSPATH' ) || exit;

final class AccessPolicy {

	/** @var Settings */
	private $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * @return string[]
	 */
	public function guardedRoles(): array {
		$roles = $this->settings->items( 'guarded_roles' );

		if ( array() === $roles ) {
			$roles = array( 'administrator', 'editor', 'shop_manager' );
		}

		/**
		 * Filter the roles that may not use OTP sign-in.
		 *
		 * @param string[] $roles Role slugs.
		 */
		return array_values( array_unique( (array) apply_filters( 'signa_guarded_roles', $roles ) ) );
	}

	public function allows( \WP_User $user ): bool {
		if ( ! $this->settings->bool( 'guard_roles', true ) ) {
			return $this->filter( true, $user );
		}

		if ( is_multisite() && is_super_admin( $user->ID ) ) {
			return $this->filter( false, $user );
		}

		if ( user_can( $user, 'manage_options' ) ) {
			return $this->filter( false, $user );
		}

		$overlap = array_intersect( $this->guardedRoles(), (array) $user->roles );

		return $this->filter( array() === $overlap, $user );
	}

	public function denialMessage(): string {
		return __( 'ورود با کد یکبارمصرف برای این نقش کاربری غیرفعال است. لطفاً از رمز عبور استفاده کنید.', 'signa' );
	}

	/**
	 * Roles offered in the admin screen.
	 *
	 * @return array<string,string>
	 */
	public static function selectableRoles(): array {
		$roles = array();

		foreach ( wp_roles()->roles as $slug => $role ) {
			$roles[ $slug ] = isset( $role['name'] ) ? translate_user_role( $role['name'] ) : $slug;
		}

		/**
		 * Filter the roles an administrator may pick as the default sign-up role.
		 *
		 * @param array<string,string> $roles slug => label.
		 */
		return (array) apply_filters( 'signa_selectable_roles', $roles );
	}

	private function filter( bool $allowed, \WP_User $user ): bool {
		/**
		 * Override the OTP access decision for one user (useful for 2FA plugins).
		 *
		 * @param bool     $allowed Whether OTP sign-in is allowed.
		 * @param \WP_User $user    Account being checked.
		 */
		return (bool) apply_filters( 'signa_allows_user', $allowed, $user );
	}
}
