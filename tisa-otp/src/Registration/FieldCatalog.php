<?php
/**
 * Ready-made registration field presets.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Registration;

defined( 'ABSPATH' ) || exit;

final class FieldCatalog {

	/**
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	public static function presets(): array {
		$presets = array(
			'minimal'     => self::minimal(),
			'identity'    => self::identity(),
			'woocommerce' => self::woocommerce(),
			'custom'      => array(),
		);

		/**
		 * Register additional field presets.
		 *
		 * @param array $presets preset id => field list.
		 */
		return (array) apply_filters( 'tisa_otp_field_presets', $presets );
	}

	public static function labels(): array {
		return array(
			'minimal'     => __( 'کوچک (نام و نام خانوادگی)', 'tisa-otp' ),
			'identity'    => __( 'هویت (نام، ایمیل، شهر، آدرس، کد پستی)', 'tisa-otp' ),
			'woocommerce' => __( 'فروشگاهی (فیلدهای صورتحساب)', 'tisa-otp' ),
			'custom'      => __( 'دلخواه (فیلدهای تعریف‌شده در پایین)', 'tisa-otp' ),
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function minimal(): array {
		return array(
			self::field( 'first_name', __( 'نام', 'tisa-otp' ), 'text', 'core', 'first_name', true, 10, 'half' ),
			self::field( 'last_name', __( 'نام خانوادگی', 'tisa-otp' ), 'text', 'core', 'last_name', true, 20, 'half' ),
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function identity(): array {
		return array(
			self::field( 'first_name', __( 'نام', 'tisa-otp' ), 'text', 'core', 'first_name', true, 10, 'half' ),
			self::field( 'last_name', __( 'نام خانوادگی', 'tisa-otp' ), 'text', 'core', 'last_name', true, 20, 'half' ),
			self::field( 'user_email', __( 'ایمیل', 'tisa-otp' ), 'email', 'core', 'user_email', false, 30, 'full', '', __( 'example@mail.com', 'tisa-otp' ) ),
			self::field( 'city', __( 'شهر', 'tisa-otp' ), 'text', 'meta', 'tisa_city', false, 40, 'half' ),
			/*
			 * These two land in the user's own profile record. When WooCommerce
			 * is installed the same values are mirrored into the billing fields,
			 * but only where nothing is stored yet — see AccountFactory.
			 */
			self::field( 'postcode', __( 'کد پستی', 'tisa-otp' ), 'postcode', 'meta', 'tisa_postcode', false, 50, 'half', '', '1234567890' ),
			self::field( 'address', __( 'آدرس', 'tisa-otp' ), 'textarea', 'meta', 'tisa_address', false, 60, 'full', __( 'خیابان، کوچه، پلاک و واحد را کامل بنویسید.', 'tisa-otp' ) ),
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function woocommerce(): array {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return self::identity();
		}

		return array(
			self::field( 'billing_first_name', __( 'نام', 'tisa-otp' ), 'text', 'wc', 'billing_first_name', true, 10, 'half' ),
			self::field( 'billing_last_name', __( 'نام خانوادگی', 'tisa-otp' ), 'text', 'wc', 'billing_last_name', true, 20, 'half' ),
			self::field( 'billing_email', __( 'ایمیل', 'tisa-otp' ), 'email', 'wc', 'billing_email', false, 30, 'full' ),
			self::field( 'billing_address_1', __( 'آدرس', 'tisa-otp' ), 'textarea', 'wc', 'billing_address_1', false, 40, 'full' ),
			self::field( 'billing_city', __( 'شهر', 'tisa-otp' ), 'text', 'wc', 'billing_city', false, 50, 'half' ),
			self::field( 'billing_postcode', __( 'کد پستی', 'tisa-otp' ), 'text', 'wc', 'billing_postcode', false, 60, 'half' ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function field(
		string $id,
		string $label,
		string $type = 'text',
		string $target = 'meta',
		string $metaKey = '',
		bool $required = false,
		int $priority = 10,
		string $width = 'full',
		string $hint = '',
		string $placeholder = '',
		array $options = array()
	): array {
		return array(
			'id'          => $id,
			'label'       => $label,
			'type'        => $type,
			'target'      => $target,
			'meta_key'    => $metaKey,
			'required'    => $required ? '1' : '0',
			'enabled'     => '1',
			'placeholder' => $placeholder,
			'hint'        => $hint,
			'width'       => $width,
			'priority'    => $priority,
			'options'     => $options,
		);
	}

	/**
	 * Field types the renderer and validator understand.
	 *
	 * @return string[]
	 */
	public static function types(): array {
		return array( 'text', 'textarea', 'email', 'tel', 'number', 'postcode', 'select', 'checkbox', 'date' );
	}
}
