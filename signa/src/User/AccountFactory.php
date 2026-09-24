<?php
/**
 * Creates accounts for newly verified phone numbers.
 *
 * @package Signa
 */

namespace Signa\User;

use Signa\Config\Settings;
use Signa\Log\Logger;
use Signa\Support\Lock;
use Signa\Support\Phone;

defined( 'ABSPATH' ) || exit;

final class AccountFactory {

	/**
	 * Profile keys that WooCommerce also keeps, so a shop can use the address
	 * collected at signup without asking for it twice.
	 *
	 * @var array<string,string>
	 */
	const BILLING_MIRROR = array(
		'signa_city'     => 'billing_city',
		'signa_address'  => 'billing_address_1',
		'signa_postcode' => 'billing_postcode',
	);

	/** @var Settings */
	private $settings;

	/** @var PhoneLocator */
	private $locator;

	/** @var Lock */
	private $lock;

	/** @var Logger */
	private $logger;

	public function __construct( Settings $settings, PhoneLocator $locator, Lock $lock, Logger $logger ) {
		$this->settings = $settings;
		$this->locator  = $locator;
		$this->lock     = $lock;
		$this->logger   = $logger;
	}

	/**
	 * @param array<string,mixed> $values Sanitised registration values keyed by field id.
	 * @param array<int,array<string,mixed>> $fields Field definitions used.
	 * @return \WP_User|\WP_Error
	 */
	public function create( string $phone, array $values = array(), array $fields = array() ) {
		$phone = Phone::normalize( $phone );

		if ( ! Phone::isValid( $phone ) ) {
			return new \WP_Error( 'invalid_phone', __( 'شماره موبایل معتبر نیست.', 'signa' ) );
		}

		$result = $this->lock->withLock(
			'register:' . Phone::fingerprint( $phone ),
			function () use ( $phone, $values, $fields ) {
				return $this->build( $phone, $values, $fields );
			},
			30
		);

		if ( null === $result ) {
			return new \WP_Error( 'busy', __( 'درخواست دیگری برای این شماره در حال پردازش است. چند لحظه صبر کنید.', 'signa' ) );
		}

		return $result;
	}

	/**
	 * @return \WP_User|\WP_Error
	 */
	private function build( string $phone, array $values, array $fields ) {
		$existing = $this->locator->find( $phone );

		if ( $existing instanceof \WP_User ) {
			return $existing;
		}

		if ( $this->locator->isAmbiguous( $phone ) ) {
			return new \WP_Error( 'ambiguous_phone', __( 'این شماره به بیش از یک حساب متصل است. با پشتیبانی تماس بگیرید.', 'signa' ) );
		}

		$email = $this->emailFrom( $values );

		if ( '' !== $email && email_exists( $email ) ) {
			return new \WP_Error( 'email_taken', __( 'این ایمیل پیش‌تر ثبت شده است. ایمیل دیگری وارد کنید یا با همان حساب وارد شوید.', 'signa' ) );
		}

		$username = $this->username( $phone, $email, $values );

		if ( is_wp_error( $username ) ) {
			return $username;
		}

		$args = array(
			'user_login'   => $username,
			'user_pass'    => wp_generate_password( 24, true, true ),
			'role'         => $this->role(),
			'display_name' => $this->displayName( $values, $phone ),
			'first_name'   => isset( $values['first_name'] ) ? (string) $values['first_name'] : '',
			'last_name'    => isset( $values['last_name'] ) ? (string) $values['last_name'] : '',
		);

		if ( '' !== $email ) {
			$args['user_email'] = $email;
		}

		/**
		 * Filter the arguments passed to wp_insert_user() for OTP registrations.
		 *
		 * @param array  $args   User arguments.
		 * @param string $phone  Canonical phone number.
		 * @param array  $values Sanitised field values.
		 */
		$args = (array) apply_filters( 'signa_new_user_args', $args, $phone, $values );

		$userId = wp_insert_user( $args );

		if ( is_wp_error( $userId ) ) {
			$this->logger->error( 'user.create_failed', array( 'phone' => $phone, 'error_code' => $userId->get_error_code() ) );

			return $userId;
		}

		$userId = (int) $userId;

		$this->locator->persist( $userId, $phone );
		update_user_meta( $userId, 'signa_signup_channel', 'otp' );

		$this->saveFieldValues( $userId, $values, $fields );

		if ( $this->settings->bool( 'send_welcome_email', false ) ) {
			$this->welcomeEmail( $userId, $phone );
		}

		$this->logger->info( 'user.created', array( 'phone' => $phone, 'user_id' => $userId ) );

		/**
		 * Fires right after a new account has been created through OTP.
		 *
		 * @param int    $userId New user id.
		 * @param string $phone  Canonical phone number.
		 * @param array  $values Sanitised field values.
		 */
		do_action( 'signa_user_created', $userId, $phone, $values );

		return get_user_by( 'id', $userId );
	}

	/**
	 * @param array<int,array<string,mixed>> $fields
	 */
	private function saveFieldValues( int $userId, array $values, array $fields ): void {
		if ( array() === $fields ) {
			return;
		}

		foreach ( $fields as $field ) {
			$id = isset( $field['id'] ) ? (string) $field['id'] : '';

			if ( '' === $id || ! array_key_exists( $id, $values ) ) {
				continue;
			}

			$target = isset( $field['target'] ) ? (string) $field['target'] : 'meta';

			if ( 'core' === $target ) {
				continue; // Already handled by wp_insert_user().
			}

			$metaKey = isset( $field['meta_key'] ) && '' !== $field['meta_key'] ? (string) $field['meta_key'] : $id;

			update_user_meta( $userId, $metaKey, $values[ $id ] );

			$this->mirrorBilling( $userId, $metaKey, (string) $values[ $id ] );
		}
	}

	/**
	 * Copy a collected value into the WooCommerce billing field, once.
	 *
	 * Only when WooCommerce is there, only for the three keys it shares with
	 * us, and never over a value the customer already has — an address typed at
	 * checkout must not be replaced by an older answer from the signup form.
	 */
	private function mirrorBilling( int $userId, string $metaKey, string $value ): void {
		if ( ! isset( self::BILLING_MIRROR[ $metaKey ] ) || '' === trim( $value ) || ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		$billingKey = self::BILLING_MIRROR[ $metaKey ];

		if ( '' !== trim( (string) get_user_meta( $userId, $billingKey, true ) ) ) {
			return;
		}

		update_user_meta( $userId, $billingKey, $value );

		/**
		 * Fires after a signup value was mirrored into a WooCommerce billing field.
		 *
		 * @param int    $userId     User id.
		 * @param string $billingKey Billing meta key.
		 * @param string $value      Value that was copied.
		 */
		do_action( 'signa_billing_mirrored', $userId, $billingKey, $value );
	}

	/**
	 * @return string|\WP_Error
	 */
	private function username( string $phone, string $email, array $values ) {
		$strategy = $this->settings->str( 'username_from', 'phone' );

		if ( 'email' === $strategy && '' !== $email ) {
			$base = sanitize_user( strtolower( substr( $email, 0, strpos( $email, '@' ) ) ), true );
		} elseif ( 'phone_prefixed' === $strategy ) {
			$base = 'user' . substr( $phone, -8 );
		} else {
			$base = 'u' . substr( $phone, -8 );
		}

		$base = '' !== $base ? $base : 'user';

		/**
		 * Filter the base username before uniqueness suffixes are added.
		 *
		 * @param string $base   Candidate username.
		 * @param string $phone  Canonical phone.
		 * @param array  $values Field values.
		 */
		$base = sanitize_user( (string) apply_filters( 'signa_username_base', $base, $phone, $values ), true );

		$username = $base;
		$suffix   = 1;

		while ( username_exists( $username ) ) {
			$username = $base . $suffix;
			$suffix++;

			if ( $suffix > 500 ) {
				return new \WP_Error( 'username_exhausted', __( 'امکان ساخت نام کاربری یکتا وجود ندارد.', 'signa' ) );
			}
		}

		return $username;
	}

	private function displayName( array $values, string $phone ): string {
		$strategy = $this->settings->str( 'display_name_from', 'full_name' );

		$first = isset( $values['first_name'] ) ? (string) $values['first_name'] : '';
		$last  = isset( $values['last_name'] ) ? (string) $values['last_name'] : '';
		$full  = trim( $first . ' ' . $last );

		switch ( $strategy ) {
			case 'first_name':
				$name = '' !== $first ? $first : Phone::mask( $phone );
				break;
			case 'phone':
				$name = Phone::mask( $phone );
				break;
			case 'full_name':
			default:
				$name = '' !== $full ? $full : Phone::mask( $phone );
		}

		return mb_substr( $name, 0, 60 );
	}

	private function emailFrom( array $values ): string {
		foreach ( array( 'user_email', 'email', 'billing_email' ) as $key ) {
			if ( ! empty( $values[ $key ] ) && is_email( $values[ $key ] ) ) {
				return sanitize_email( (string) $values[ $key ] );
			}
		}

		return '';
	}

	public function role(): string {
		$role = $this->settings->str( 'default_role', 'subscriber' );

		if ( ! get_role( $role ) ) {
			$role = 'subscriber';
		}

		/**
		 * Filter the role assigned to accounts created through OTP.
		 *
		 * @param string $role Role slug.
		 */
		return sanitize_key( (string) apply_filters( 'signa_default_role', $role ) );
	}

	private function welcomeEmail( int $userId, string $phone ): void {
		$user = get_user_by( 'id', $userId );

		if ( ! $user instanceof \WP_User || ! is_email( $user->user_email ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: site name */
			__( 'به %s خوش آمدید', 'signa' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);

		$body = sprintf(
			/* translators: 1: display name, 2: site name, 3: masked phone number, 4: site URL */
			__( "سلام %1\$s\n\nحساب شما در %2\$s با شماره %3\$s ساخته شد.\n%4\$s", 'signa' ),
			$user->display_name,
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			Phone::mask( $phone ),
			home_url( '/' )
		);

		wp_mail( $user->user_email, $subject, $body );
	}
}
