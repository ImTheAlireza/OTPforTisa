<?php

namespace Signa\User;

use Signa\Bootable;
use Signa\Config\Settings;
use Signa\Support\Phone;

defined( 'ABSPATH' ) || exit;

final class ProfileField implements Bootable {
	const NONCE = 'signa_profile';
	const PROFILE_KEYS = array(
		'signa_city'     => array(
			'label' => 'شهر',
			'type'  => 'text',
		),
		'signa_postcode' => array(
			'label' => 'کد پستی',
			'type'  => 'postcode',
		),
		'signa_address'  => array(
			'label' => 'آدرس',
			'type'  => 'textarea',
		),
	);

	private $settings;
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

		echo '<h2>' . esc_html__( 'شماره موبایل', 'signa' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody><tr>';
		echo '<th scope="row"><label for="signa-phone">' . esc_html__( 'شماره موبایل', 'signa' ) . '</label></th><td>';

		printf(
			'<input type="tel" name="signa_phone" id="signa-phone" value="%s" class="regular-text" dir="ltr" inputmode="numeric" autocomplete="tel" placeholder="09xxxxxxxxx">',
			esc_attr( $value )
		);

		wp_nonce_field( self::NONCE, self::NONCE, false );

		echo '<p class="description">' . esc_html__( 'این شماره برای ورود با کد یکبارمصرف استفاده می‌شود و باید بین کاربران یکتا باشد.', 'signa' ) . '</p>';

		if ( '' !== $source && $source !== $this->settings->str( 'phone_meta_key', 'signa_phone' ) ) {
			printf(
				'<p class="description">%s</p>',
				esc_html( sprintf(  __( 'شماره فعلی از کلید «%s» خوانده شده و پس از ذخیره به کلید اصلی منتقل می‌شود.', 'signa' ), $source ) )
			);
		}

		echo '</td></tr></tbody></table>';

		$this->renderProfileFields( $user->ID );
	}

	private function renderProfileFields( int $userId ): void {
		$rows = array();

		foreach ( self::PROFILE_KEYS as $key => $spec ) {
			$value = (string) get_user_meta( $userId, $key, true );

			if ( '' === trim( $value ) ) {
				continue;
			}

			$rows[ $key ] = array( $spec, $value );
		}

		if ( array() === $rows ) {
			return;
		}

		echo '<h2>' . esc_html__( 'اطلاعات ثبت‌نام', 'signa' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( $rows as $key => $row ) {
			$spec  = $row[0];
			$value = $row[1];
			$id    = 'signa-' . str_replace( '_', '-', $key );

			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $spec['label'] ) . '</label></th><td>';

			if ( 'textarea' === $spec['type'] ) {
				printf(
					'<textarea name="%1$s" id="%2$s" rows="3" class="regular-text">%3$s</textarea>',
					esc_attr( $key ),
					esc_attr( $id ),
					esc_textarea( $value )
				);
			} else {
				printf(
					'<input type="text" name="%1$s" id="%2$s" value="%3$s" class="regular-text"%4$s>',
					esc_attr( $key ),
					esc_attr( $id ),
					esc_attr( $value ),
					'postcode' === $spec['type'] ? ' dir="ltr" inputmode="numeric" maxlength="10"' : ''
				);
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}
	public function renderNew( string $type ): void {
		if ( 'add-new-user' !== $type ) {
			return;
		}

		echo '<table class="form-table" role="presentation"><tbody><tr>';
		echo '<th scope="row"><label for="signa-phone">' . esc_html__( 'شماره موبایل', 'signa' ) . '</label></th><td>';
		echo '<input type="tel" name="signa_phone" id="signa-phone" value="" class="regular-text" dir="ltr" inputmode="numeric" autocomplete="tel" placeholder="09xxxxxxxxx">';
		wp_nonce_field( self::NONCE, self::NONCE, false );
		echo '<p class="description">' . esc_html__( 'اختیاری؛ برای ورود با کد یکبارمصرف.', 'signa' ) . '</p>';
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

		$raw = isset( $_POST['signa_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['signa_phone'] ) ) : '';

		$this->store( $userId, $raw );
		$this->storeProfileFields( $userId );
	}

	public function saveNew( int $userId ): void {
		if ( ! current_user_can( 'create_users' ) ) {
			return;
		}

		$raw = isset( $_POST['signa_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['signa_phone'] ) ) : '';

		$this->store( $userId, $raw );
		$this->storeProfileFields( $userId );
	}

	private function storeProfileFields( int $userId ): void {
		foreach ( self::PROFILE_KEYS as $key => $spec ) {
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}

			if ( '' === trim( (string) get_user_meta( $userId, $key, true ) ) ) {
				continue;
			}

			$value = wp_unslash( $_POST[ $key ] );
			$value = 'textarea' === $spec['type']
				? sanitize_textarea_field( (string) $value )
				: sanitize_text_field( (string) $value );

			if ( 'postcode' === $spec['type'] ) {
				$value = preg_replace( '/[^0-9]/', '', Phone::latinDigits( $value ) );
				$value = substr( (string) $value, 0, 10 );
			}

			update_user_meta( $userId, $key, $value );
		}
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
					esc_html__( 'شماره موبایل واردشده معتبر نیست (نمونه درست: 09121234567).', 'signa' )
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
						esc_html__( 'این شماره پیش‌تر به حساب کاربری دیگری متصل شده است.', 'signa' )
					);
				}
			);

			return;
		}

		$this->locator->persist( $userId, $phone );

		do_action( 'signa_phone_updated', $userId, $phone );
	}
}
