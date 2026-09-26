<?php

namespace Signa\User;

use Signa\Config\Settings;
use Signa\Log\Logger;
use Signa\Support\Lock;
use Signa\Support\Phone;

defined( 'ABSPATH' ) || exit;

final class AccountFactory {
	const BILLING_MIRROR = array(
		'signa_city'     => 'billing_city',
		'signa_address'  => 'billing_address_1',
		'signa_postcode' => 'billing_postcode',
	);

	private $settings;
	private $locator;
	private $lock;
	private $logger;

	public function __construct( Settings $settings, PhoneLocator $locator, Lock $lock, Logger $logger ) {
		$this->settings = $settings;
		$this->locator  = $locator;
		$this->lock     = $lock;
		$this->logger   = $logger;
	}

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

		do_action( 'signa_user_created', $userId, $phone, $values );

		return get_user_by( 'id', $userId );
	}

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
				continue;
			}

			$metaKey = isset( $field['meta_key'] ) && '' !== $field['meta_key'] ? (string) $field['meta_key'] : $id;

			update_user_meta( $userId, $metaKey, $values[ $id ] );

			$this->mirrorBilling( $userId, $metaKey, (string) $values[ $id ] );
		}
	}

	private function mirrorBilling( int $userId, string $metaKey, string $value ): void {
		if ( ! isset( self::BILLING_MIRROR[ $metaKey ] ) || '' === trim( $value ) || ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		$billingKey = self::BILLING_MIRROR[ $metaKey ];

		if ( '' !== trim( (string) get_user_meta( $userId, $billingKey, true ) ) ) {
			return;
		}

		update_user_meta( $userId, $billingKey, $value );

		do_action( 'signa_billing_mirrored', $userId, $billingKey, $value );
	}

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

		return sanitize_key( (string) apply_filters( 'signa_default_role', $role ) );
	}

	private function welcomeEmail( int $userId, string $phone ): void {
		$user = get_user_by( 'id', $userId );

		if ( ! $user instanceof \WP_User || ! is_email( $user->user_email ) ) {
			return;
		}

		$subject = sprintf(
			__( 'به %s خوش آمدید', 'signa' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);

		$body = sprintf(
			__( "سلام %1\$s\n\nحساب شما در %2\$s با شماره %3\$s ساخته شد.\n%4\$s", 'signa' ),
			$user->display_name,
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			Phone::mask( $phone ),
			home_url( '/' )
		);

		wp_mail( $user->user_email, $subject, $body );
	}
}
