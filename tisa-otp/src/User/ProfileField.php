<?php
/**
 * Phone number field on the profile screens.
 *
 * Works on both "Your Profile" and the admin user editor, plus the
 * "Add New User" form where no user id exists yet.
 *
 * @package TisaOtp
 */

namespace TisaOtp\User;

use TisaOtp\Bootable;
use TisaOtp\Config\Settings;
use TisaOtp\Support\Phone;

defined( 'ABSPATH' ) || exit;

final class ProfileField implements Bootable {

	const NONCE = 'tisa_otp_profile';

	/** @var Settings */
	private $settings;

	/** @var PhoneLocator */
	private $locator;

	public function __construct( Settings $settings, PhoneLocator $locator ) {
		$this->settings = $settings;
		$this->locator  = $locator;
	}

	public function boot(): void {
		if ( ! $this->settings->bool( 'enabled', true ) ) {
			return;
		}

		add_action( 'show_user_profile', array( $this, 'render' ) );
		add_action( 'edit_user_profile', array( $this, 'render' ) );
		add_action( 'user_new_form', array( $this, 'renderNew' ) );

		add_action( 'personal_options_update', array( $this, 'save' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save' ) );
		add_action( 'user_register', array( $this, 'saveNew' ) );
	}

	public function render( \WP_User $user ): void {
		if ( ! $this->canSee( $user->ID ) ) {
			return;
		}

		$value  = $this->locator->phoneFor( $user->ID );
		$source = $this->locator->sourceKeyFor( $user->ID );

		echo '<h2>' . esc_html__( 'شماره موبایل', 'tisa-otp' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody><tr>';
		echo '<th scope="row"><label for="tisa-phone">' . esc_html__( 'شماره موبایل', 'tisa-otp' ) . '</label></th><td>';

		printf(
			'<input type="tel" name="tisa_phone" id="tisa-phone" value="%s" class="regular-text" dir="ltr" inputmode="numeric" autocomplete="tel" placeholder="09xxxxxxxxx">',
			esc_attr( $value )
		);

		wp_nonce_field( self::NONCE, self::NONCE, false );

		echo '<p class="description">' . esc_html__( 'این شماره برای ورود با کد یکبارمصرف استفاده می‌شود و باید بین کاربران یکتا باشد.', 'tisa-otp' ) . '</p>';

		if ( '' !== $source && $source !== $this->settings->str( 'phone_meta_key', 'tisa_phone' ) ) {
			printf(
				'<p class="description">%s</p>',
				esc_html( sprintf( /* translators: %s: meta key */ __( 'شماره فعلی از کلید «%s» خوانده شده و پس از ذخیره به کلید اصلی منتقل می‌شود.', 'tisa-otp' ), $source ) )
			);
		}

		echo '</td></tr></tbody></table>';
	}

	/**
	 * @param string $type 'add-new-user' on the Add New User screen.
	 */
	public function renderNew( string $type ): void {
		if ( 'add-new-user' !== $type ) {
			return;
		}

		echo '<table class="form-table" role="presentation"><tbody><tr>';
		echo '<th scope="row"><label for="tisa-phone">' . esc_html__( 'شماره موبایل', 'tisa-otp' ) . '</label></th><td>';
		echo '<input type="tel" name="tisa_phone" id="tisa-phone" value="" class="regular-text" dir="ltr" inputmode="numeric" autocomplete="tel" placeholder="09xxxxxxxxx">';
		wp_nonce_field( self::NONCE, self::NONCE, false );
		echo '<p class="description">' . esc_html__( 'اختیاری؛ برای ورود با کد یکبارمصرف.', 'tisa-otp' ) . '</p>';
		echo '</td></tr></tbody></table>';
	}

	public function canSee( int $userId ): bool {
		return current_user_can( 'edit_user', $userId );
	}

	public function save( int $userId ): void {
		if ( ! $this->canSee( $userId ) ) {
			return;
		}

		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}

		$raw = isset( $_POST['tisa_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['tisa_phone'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$this->store( $userId, $raw );
	}

	public function saveNew( int $userId ): void {
		if ( ! current_user_can( 'create_users' ) ) {
			return;
		}

		$raw = isset( $_POST['tisa_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['tisa_phone'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$this->store( $userId, $raw );
	}

	private function store( int $userId, string $raw ): void {
		if ( '' === trim( $raw ) ) {
			$this->locator->clear( $userId );

			return;
		}

		$phone = Phone::normalize( $raw );

		if ( ! Phone::isValid( $phone ) ) {
			add_action( 'admin_notices', static function () {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html__( 'شماره موبایل واردشده معتبر نیست (نمونه درست: 09121234567).', 'tisa-otp' )
				);
			} );

			return;
		}

		$owner = $this->locator->find( $phone );

		if ( $owner instanceof \WP_User && (int) $owner->ID !== $userId ) {
			add_action(
				'admin_notices',
				static function () {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html__( 'این شماره پیش‌تر به حساب کاربری دیگری متصل شده است.', 'tisa-otp' )
					);
				}
			);

			return;
		}

		$this->locator->persist( $userId, $phone );

		/**
		 * Fires after a phone number was changed from a profile screen.
		 *
		 * @param int    $userId User id.
		 * @param string $phone  Canonical phone number.
		 */
		do_action( 'tisa_otp_phone_updated', $userId, $phone );
	}
}
