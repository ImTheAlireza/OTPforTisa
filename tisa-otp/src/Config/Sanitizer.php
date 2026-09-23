<?php
/**
 * Schema-driven sanitisation of submitted settings.
 *
 * Each key declares how it must be cleaned, so adding an option never means
 * touching a wall of if/else statements.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Config;

defined( 'ABSPATH' ) || exit;

final class Sanitizer {

	/**
	 * Keys whose stored value is kept when the browser sends back the masked placeholder.
	 *
	 * @return string[]
	 */
	public static function secretKeys(): array {
		return array(
			'smsir_api_key',
			'kavenegar_api_key',
			'meli_password',
			'ippanel_api_key',
			'faraz_password',
			'captcha_secret_key',
		);
	}

	public static function placeholder(): string {
		return '••••••••';
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public static function spec(): array {
		return array(
			'enabled'              => array( 'type' => 'bool' ),
			'auth_mode'            => array( 'type' => 'enum', 'choices' => array( 'smart', 'login_only', 'register_only' ) ),
			'auto_register'        => array( 'type' => 'bool' ),
			'default_role'         => array( 'type' => 'role' ),
			'login_redirect'       => array( 'type' => 'url' ),
			'register_redirect'    => array( 'type' => 'url' ),
			'replace_wp_login'     => array( 'type' => 'bool' ),
			'cache_mode'           => array( 'type' => 'enum', 'choices' => array( 'auto', 'inline' ) ),
			'prevent_enumeration'  => array( 'type' => 'bool' ),
			'guard_roles'          => array( 'type' => 'bool' ),
			'guarded_roles'        => array( 'type' => 'csv_keys' ),

			'code_length'          => array( 'type' => 'int', 'min' => 4, 'max' => 8 ),
			'code_ttl'             => array( 'type' => 'int', 'min' => 30, 'max' => 3600 ),
			'code_store'           => array( 'type' => 'enum', 'choices' => array( 'database', 'cache' ) ),
			'verify_attempts'      => array( 'type' => 'int', 'min' => 2, 'max' => 15 ),
			'resend_delay'         => array( 'type' => 'int', 'min' => 10, 'max' => 1800 ),
			'auto_verify'          => array( 'type' => 'bool' ),
			'webotp_enabled'       => array( 'type' => 'bool' ),
			'request_timeout'      => array( 'type' => 'int', 'min' => 5, 'max' => 60 ),

			'channel'              => array( 'type' => 'enum', 'choices' => array( 'sms', 'email' ) ),
			'channels_enabled'     => array( 'type' => 'list', 'choices' => array( 'sms', 'email' ) ),
			'failover_enabled'     => array( 'type' => 'bool' ),
			'sms_template'         => array( 'type' => 'text' ),
			'email_subject'        => array( 'type' => 'text' ),
			'email_body'           => array( 'type' => 'textarea' ),
			'email_from'           => array( 'type' => 'email' ),

			'direct_send'          => array( 'type' => 'bool' ),
			'sms_gateway'          => array( 'type' => 'key' ),
			'sms_backup_gateway'   => array( 'type' => 'key' ),
			'smsir_api_key'        => array( 'type' => 'secret' ),
			'smsir_template_id'    => array( 'type' => 'text' ),
			'smsir_sender'         => array( 'type' => 'text' ),
			'kavenegar_api_key'    => array( 'type' => 'secret' ),
			'kavenegar_template'   => array( 'type' => 'key' ),
			'kavenegar_sender'     => array( 'type' => 'text' ),
			'meli_username'        => array( 'type' => 'text' ),
			'meli_password'        => array( 'type' => 'secret' ),
			'meli_from'            => array( 'type' => 'text' ),
			'ippanel_api_key'      => array( 'type' => 'secret' ),
			'ippanel_pattern'      => array( 'type' => 'text' ),
			'ippanel_sender'       => array( 'type' => 'text' ),
			'faraz_username'       => array( 'type' => 'text' ),
			'faraz_password'       => array( 'type' => 'secret' ),
			'faraz_from'           => array( 'type' => 'text' ),
			'faraz_pattern'        => array( 'type' => 'text' ),

			'throttle_enabled'     => array( 'type' => 'bool' ),
			'window_minutes'       => array( 'type' => 'int', 'min' => 1, 'max' => 1440 ),
			'limit_per_phone'      => array( 'type' => 'int', 'min' => 1, 'max' => 100 ),
			'limit_per_ip'         => array( 'type' => 'int', 'min' => 1, 'max' => 500 ),
			'limit_per_ip_daily'   => array( 'type' => 'int', 'min' => 1, 'max' => 5000 ),
			'limit_verify_per_ip'  => array( 'type' => 'int', 'min' => 5, 'max' => 1000 ),
			'proxy_mode'           => array( 'type' => 'enum', 'choices' => array( 'none', 'cloudflare', 'forwarded', 'real_ip' ) ),
			'trusted_proxies'      => array( 'type' => 'text' ),
			'trusted_enabled'      => array( 'type' => 'bool' ),
			'trusted_numbers'      => array( 'type' => 'textarea' ),
			'trusted_skip'         => array( 'type' => 'text' ),

			'captcha_provider'     => array( 'type' => 'enum', 'choices' => array( 'none', 'recaptcha_v3', 'hcaptcha', 'arcaptcha' ) ),
			'captcha_site_key'     => array( 'type' => 'text' ),
			'captcha_secret_key'   => array( 'type' => 'secret' ),
			'captcha_score'        => array( 'type' => 'float', 'min' => 0, 'max' => 1 ),
			'captcha_trigger'      => array( 'type' => 'enum', 'choices' => array( 'always', 'after_limit' ) ),
			'captcha_fail_open'    => array( 'type' => 'bool' ),
			'captcha_timeout'      => array( 'type' => 'int', 'min' => 3000, 'max' => 20000 ),
			'captcha_script_override' => array( 'type' => 'url' ),
			'captcha_arcaptcha_v3' => array( 'type' => 'bool' ),

			'registration_enabled' => array( 'type' => 'bool' ),
			'registration_flow'    => array( 'type' => 'enum', 'choices' => array( 'fields_then_code', 'code_then_fields' ) ),
			'field_preset'         => array( 'type' => 'enum', 'choices' => array( 'minimal', 'identity', 'woocommerce', 'custom' ) ),
			'fields'               => array( 'type' => 'fields' ),
			'username_from'        => array( 'type' => 'enum', 'choices' => array( 'phone', 'phone_prefixed', 'email' ) ),
			'display_name_from'    => array( 'type' => 'enum', 'choices' => array( 'full_name', 'first_name', 'phone' ) ),
			'email_mode'           => array( 'type' => 'enum', 'choices' => array( 'off', 'optional', 'required' ) ),
			'woo_account_form'     => array( 'type' => 'bool' ),
			'woo_checkout_gate'    => array( 'type' => 'bool' ),
			'woo_checkout_notice'  => array( 'type' => 'textarea' ),
			'woo_checkout_page'    => array( 'type' => 'url' ),
			'sync_billing_phone'   => array( 'type' => 'bool' ),
			'link_guest_orders'    => array( 'type' => 'bool' ),
			'send_welcome_email'   => array( 'type' => 'bool' ),
			'form_heading'         => array( 'type' => 'text' ),
			'form_subheading'      => array( 'type' => 'textarea' ),
			'register_heading'     => array( 'type' => 'text' ),
			'register_subheading'  => array( 'type' => 'textarea' ),

			'skin'                 => array( 'type' => 'enum', 'choices' => array( 'line', 'card', 'glass', 'slate', 'pill' ) ),
			'form_font'            => array( 'type' => 'enum', 'choices' => array( 'vazirmatn', 'theme', 'custom' ) ),
			'form_font_custom'     => array( 'type' => 'font' ),
			'accent'               => array( 'type' => 'color' ),
			'surface'              => array( 'type' => 'color' ),
			'radius'               => array( 'type' => 'int', 'min' => 0, 'max' => 40 ),
			'width'                => array( 'type' => 'int', 'min' => 280, 'max' => 900 ),
			'align'                => array( 'type' => 'enum', 'choices' => array( 'center', 'start', 'end' ) ),
			'show_brand'           => array( 'type' => 'bool' ),
			'brand_logo'           => array( 'type' => 'url' ),
			'brand_width'          => array( 'type' => 'int', 'min' => 32, 'max' => 320 ),
			'code_input'           => array( 'type' => 'enum', 'choices' => array( 'boxes', 'single' ) ),
			'label_send'           => array( 'type' => 'text' ),
			'label_verify'         => array( 'type' => 'text' ),
			'label_resend'         => array( 'type' => 'text' ),
			'label_edit_phone'     => array( 'type' => 'text' ),
			'terms_enabled'        => array( 'type' => 'bool' ),
			'terms_text'           => array( 'type' => 'text' ),
			'terms_url'            => array( 'type' => 'url' ),
			'custom_css'           => array( 'type' => 'code' ),
			'custom_js'            => array( 'type' => 'code' ),
			'custom_code_scope'    => array( 'type' => 'enum', 'choices' => array( 'form_pages', 'everywhere' ) ),

			'logs_enabled'         => array( 'type' => 'bool' ),
			'logs_keep_days'       => array( 'type' => 'int', 'min' => 1, 'max' => 90 ),
			'debug'                => array( 'type' => 'bool' ),
			'phone_meta_key'       => array( 'type' => 'meta_key' ),
			'lookup_meta_keys'     => array( 'type' => 'csv_keys' ),
			'wipe_on_uninstall'    => array( 'type' => 'bool' ),
		);
	}

	public static function sanitize( array $input, array $current ): array {
		$spec    = self::spec();
		$default = Settings::defaults();
		$output  = $current;

		foreach ( $spec as $key => $rule ) {
			if ( ! array_key_exists( $key, $input ) ) {
				// Checkboxes arrive only when ticked.
				if ( 'bool' === $rule['type'] && self::isSubmittable( $input, $key ) ) {
					$output[ $key ] = '0';
				}
				continue;
			}

			$fallback = array_key_exists( $key, $current ) ? $current[ $key ] : ( isset( $default[ $key ] ) ? $default[ $key ] : '' );
			$output[ $key ] = self::clean( $key, $input[ $key ], $rule, $fallback );
		}

		// Never allow a channel list without the primary channel.
		$channels = is_array( $output['channels_enabled'] ) ? $output['channels_enabled'] : array();
		if ( ! in_array( $output['channel'], $channels, true ) ) {
			array_unshift( $channels, $output['channel'] );
			$output['channels_enabled'] = array_values( array_unique( $channels ) );
		}

		if ( $output['sms_backup_gateway'] === $output['sms_gateway'] ) {
			$output['sms_backup_gateway'] = '';
		}

		return $output;
	}

	/**
	 * @param mixed $value
	 * @param mixed $fallback
	 * @return mixed
	 */
	private static function clean( string $key, $value, array $rule, $fallback ) {
		$type = isset( $rule['type'] ) ? $rule['type'] : 'text';

		switch ( $type ) {
			case 'bool':
				return in_array( (string) $value, array( '1', 'yes', 'on', 'true' ), true ) ? '1' : '0';

			case 'int':
				$number = is_numeric( $value ) ? (int) $value : (int) $fallback;
				$min    = isset( $rule['min'] ) ? (int) $rule['min'] : PHP_INT_MIN;
				$max    = isset( $rule['max'] ) ? (int) $rule['max'] : PHP_INT_MAX;
				return (string) max( $min, min( $max, $number ) );

			case 'float':
				$number = is_numeric( $value ) ? (float) $value : (float) $fallback;
				$min    = isset( $rule['min'] ) ? (float) $rule['min'] : 0.0;
				$max    = isset( $rule['max'] ) ? (float) $rule['max'] : 1.0;
				return (string) max( $min, min( $max, $number ) );

			case 'enum':
				$choices = isset( $rule['choices'] ) ? (array) $rule['choices'] : array();
				return in_array( $value, $choices, true ) ? $value : (string) $fallback;

			case 'list':
				$choices = isset( $rule['choices'] ) ? (array) $rule['choices'] : array();
				$items   = is_array( $value ) ? $value : explode( ',', (string) $value );
				$items   = array_values( array_unique( array_filter( array_map( 'sanitize_key', $items ) ) ) );
				return empty( $choices ) ? $items : array_values( array_intersect( $choices, $items ) );

			case 'csv_keys':
				$parts = is_array( $value ) ? $value : explode( ',', (string) $value );
				$parts = array_map( 'sanitize_key', $parts );
				return implode( ',', array_values( array_unique( array_filter( $parts ) ) ) );

			case 'key':
			case 'meta_key':
				$clean = sanitize_key( (string) $value );
				return '' !== $clean ? $clean : (string) $fallback;

			case 'color':
				$color = sanitize_hex_color( (string) $value );
				return $color ? $color : (string) $fallback;

			case 'url':
				return '' === trim( (string) $value ) ? '' : esc_url_raw( (string) $value );

			case 'email':
				$email = sanitize_email( (string) $value );
				return is_email( $email ) ? $email : '';

			case 'textarea':
				return sanitize_textarea_field( (string) $value );

			case 'code':
				return self::canEditCode() ? (string) $value : (string) $fallback;

			case 'secret':
				$plain = trim( (string) $value );
				if ( '' === $plain || self::placeholder() === $plain ) {
					return (string) $fallback;
				}
				return $plain;

			case 'fields':
				return is_array( $value ) ? self::sanitizeFields( $value ) : (array) $fallback;

			case 'font':
				return self::fontFamily( $value );

			case 'text':
			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/**
	 * A CSS `font-family` list, and nothing else.
	 *
	 * The value is printed inside a `style` attribute, so the whole grammar is
	 * kept to what a font list can contain: family names, quotes, commas and
	 * spaces. Braces, semicolons and angle brackets — the characters that would
	 * let one setting escape its declaration and rewrite the rest of the page —
	 * are dropped rather than escaped, and an empty result falls back to the
	 * default font.
	 */
	public static function fontFamily( $value ): string {
		$value = wp_strip_all_tags( (string) $value );
		$value = preg_replace( '/[^A-Za-z0-9 ,\'"\-_\.]/', '', $value );
		$value = trim( (string) $value, " \t\n\r\0\x0B," );

		return substr( $value, 0, 180 );
	}

	/**
	 * Registration field definitions are the only nested structure in settings.
	 */
	private static function sanitizeFields( array $raw ): array {
		$fields = array();

		foreach ( array_values( $raw ) as $index => $field ) {
			if ( ! is_array( $field ) || $index > 40 ) {
				continue;
			}

			$id = sanitize_key( isset( $field['id'] ) ? (string) $field['id'] : '' );

			if ( '' === $id ) {
				continue;
			}

			$type = isset( $field['type'] ) ? sanitize_key( (string) $field['type'] ) : 'text';
			if ( ! in_array( $type, array( 'text', 'textarea', 'email', 'tel', 'number', 'select', 'checkbox', 'date' ), true ) ) {
				$type = 'text';
			}

			$options = array();
			if ( isset( $field['options'] ) && is_array( $field['options'] ) ) {
				foreach ( array_slice( $field['options'], 0, 30 ) as $option ) {
					$label = sanitize_text_field( (string) $option );
					if ( '' !== $label ) {
						$options[] = $label;
					}
				}
			}

			$fields[] = array(
				'id'          => $id,
				'label'       => sanitize_text_field( isset( $field['label'] ) ? (string) $field['label'] : $id ),
				'type'        => $type,
				'target'      => isset( $field['target'] ) ? sanitize_key( (string) $field['target'] ) : 'meta',
				'meta_key'    => isset( $field['meta_key'] ) ? sanitize_key( (string) $field['meta_key'] ) : '',
				'required'    => ! empty( $field['required'] ) ? '1' : '0',
				'enabled'     => array_key_exists( 'enabled', $field ) && empty( $field['enabled'] ) ? '0' : '1',
				'placeholder' => sanitize_text_field( isset( $field['placeholder'] ) ? (string) $field['placeholder'] : '' ),
				'width'       => in_array( isset( $field['width'] ) ? $field['width'] : 'full', array( 'half', 'full' ), true ) ? $field['width'] : 'full',
				'priority'    => isset( $field['priority'] ) ? max( 0, min( 999, (int) $field['priority'] ) ) : ( $index + 1 ) * 10,
				'options'     => $options,
			);
		}

		return $fields;
	}

	private static function canEditCode(): bool {
		return current_user_can( 'unfiltered_html' ) || ( is_multisite() && current_user_can( 'manage_network_options' ) );
	}

	/**
	 * Detect whether a checkbox row was rendered in the submitted form even
	 * though the box itself was left unchecked.
	 */
	private static function isSubmittable( array $input, string $key ): bool {
		return array_key_exists( '_fields', $input ) && is_array( $input['_fields'] ) && in_array( $key, $input['_fields'], true );
	}
}
