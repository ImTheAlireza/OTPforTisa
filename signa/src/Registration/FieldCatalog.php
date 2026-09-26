<?php

namespace Signa\Registration;

defined( 'ABSPATH' ) || exit;

final class FieldCatalog {
	public static function presets(): array {
		$presets = array(
			'minimal'     => self::minimal(),
			'identity'    => self::identity(),
			'woocommerce' => self::woocommerce(),
			'custom'      => array(),
		);

		return (array) apply_filters( 'signa_field_presets', $presets );
	}

	public static function labels(): array {
		return array(
			'minimal'     => __( 'کوچک (نام و نام خانوادگی)', 'signa' ),
			'identity'    => __( 'هویت (نام، ایمیل، شهر، آدرس، کد پستی)', 'signa' ),
			'woocommerce' => __( 'فروشگاهی (فیلدهای صورتحساب)', 'signa' ),
			'custom'      => __( 'دلخواه (فیلدهای تعریف‌شده در پایین)', 'signa' ),
		);
	}

	private static function minimal(): array {
		return array(
			self::field( 'first_name', __( 'نام', 'signa' ), 'text', 'core', 'first_name', true, 10, 'half' ),
			self::field( 'last_name', __( 'نام خانوادگی', 'signa' ), 'text', 'core', 'last_name', true, 20, 'half' ),
		);
	}

	private static function identity(): array {
		return array(
			self::field( 'first_name', __( 'نام', 'signa' ), 'text', 'core', 'first_name', true, 10, 'half' ),
			self::field( 'last_name', __( 'نام خانوادگی', 'signa' ), 'text', 'core', 'last_name', true, 20, 'half' ),
			self::field( 'user_email', __( 'ایمیل', 'signa' ), 'email', 'core', 'user_email', false, 30, 'full', '', __( 'example@mail.com', 'signa' ) ),
			self::field( 'city', __( 'شهر', 'signa' ), 'text', 'meta', 'signa_city', false, 40, 'half' ),
			self::field( 'postcode', __( 'کد پستی', 'signa' ), 'postcode', 'meta', 'signa_postcode', false, 50, 'half', '', '1234567890' ),
			self::field( 'address', __( 'آدرس', 'signa' ), 'textarea', 'meta', 'signa_address', false, 60, 'full', __( 'خیابان، کوچه، پلاک و واحد را کامل بنویسید.', 'signa' ) ),
		);
	}

	private static function woocommerce(): array {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return self::identity();
		}

		return array(
			self::field( 'billing_first_name', __( 'نام', 'signa' ), 'text', 'wc', 'billing_first_name', true, 10, 'half' ),
			self::field( 'billing_last_name', __( 'نام خانوادگی', 'signa' ), 'text', 'wc', 'billing_last_name', true, 20, 'half' ),
			self::field( 'billing_email', __( 'ایمیل', 'signa' ), 'email', 'wc', 'billing_email', false, 30, 'full' ),
			self::field( 'billing_address_1', __( 'آدرس', 'signa' ), 'textarea', 'wc', 'billing_address_1', false, 40, 'full' ),
			self::field( 'billing_city', __( 'شهر', 'signa' ), 'text', 'wc', 'billing_city', false, 50, 'half' ),
			self::field( 'billing_postcode', __( 'کد پستی', 'signa' ), 'text', 'wc', 'billing_postcode', false, 60, 'half' ),
		);
	}

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

	public static function types(): array {
		return array( 'text', 'textarea', 'email', 'tel', 'number', 'postcode', 'select', 'checkbox', 'date' );
	}
}
