<?php
/**
 * Validates and sanitises submitted registration values against the schema.
 *
 * @package Signa
 */

namespace Signa\Registration;

use Signa\Config\Settings;
use Signa\Support\Phone;

defined( 'ABSPATH' ) || exit;

final class FieldValidator {

	/** @var Settings */
	private $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * @param array<string,mixed> $raw
	 * @param array<int,array<string,mixed>> $fields
	 * @return array{ok:bool,values:array<string,mixed>,errors:array<string,string>}
	 */
	public function validate( array $raw, array $fields ): array {
		$values = array();
		$errors = array();

		foreach ( $fields as $field ) {
			$id    = $field['id'];
			$value = array_key_exists( $id, $raw ) ? $raw[ $id ] : '';

			if ( 'checkbox' === $field['type'] ) {
				$value = empty( $value ) ? '' : '1';
			}

			$clean = $this->sanitizeValue( $field, $value );

			if ( '1' === $field['required'] && '' === $clean ) {
				$errors[ $id ] = sprintf(
					/* translators: %s: field label */
					__( 'وارد کردن «%s» الزامی است.', 'signa' ),
					$field['label']
				);
				continue;
			}

			$error = $this->validateValue( $field, $clean );

			if ( '' !== $error ) {
				$errors[ $id ] = $error;
				continue;
			}

			$values[ $id ] = $clean;
		}

		/**
		 * Filter validation results before the controller acts on them.
		 *
		 * @param array $values Sanitised values.
		 * @param array $errors field id => message.
		 * @param array $raw    Raw input.
		 */
		$values = (array) apply_filters( 'signa_validated_values', $values, $errors, $raw );

		return array(
			'ok'     => array() === $errors,
			'values' => $values,
			'errors' => $errors,
		);
	}

	/**
	 * @param array<string,mixed> $field
	 * @param mixed $value
	 * @return mixed
	 */
	private function sanitizeValue( array $field, $value ) {
		switch ( $field['type'] ) {
			case 'email':
				$email = sanitize_email( is_scalar( $value ) ? (string) $value : '' );
				return is_email( $email ) ? $email : ( '' === $email ? '' : $email );

			case 'number':
			case 'tel':
				$digits = preg_replace( '/[^0-9]/', '', Phone::latinDigits( is_scalar( $value ) ? (string) $value : '' ) );
				return '' === $digits ? '' : substr( (string) $digits, 0, 20 );

			case 'postcode':
				$raw    = is_scalar( $value ) ? (string) $value : '';
				$digits = preg_replace( '/[^0-9]/', '', Phone::latinDigits( $raw ) );

				/*
				 * Punctuation is dropped ("12345-67890" is one postal code),
				 * but nothing else is trimmed here: an eleven-digit number has
				 * to come back as an error, not as its first ten digits.
				 */
				if ( '' === $digits ) {
					return substr( sanitize_text_field( $raw ), 0, 20 );
				}

				return (string) $digits;

			case 'textarea':
				return sanitize_textarea_field( is_scalar( $value ) ? (string) $value : '' );

			case 'checkbox':
				return empty( $value ) ? '' : '1';

			case 'date':
				$date = sanitize_text_field( is_scalar( $value ) ? (string) $value : '' );
				return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '';

			case 'select':
				$selected = sanitize_text_field( is_scalar( $value ) ? (string) $value : '' );
				return in_array( $selected, (array) $field['options'], true ) ? $selected : '';

			case 'text':
			default:
				return mb_substr( sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ), 0, 120 );
		}
	}

	/**
	 * @param array<string,mixed> $field
	 * @param mixed $value
	 */
	private function validateValue( array $field, $value ): string {
		if ( '' === $value ) {
			return '';
		}

		switch ( $field['type'] ) {
			case 'email':
				if ( ! is_email( (string) $value ) ) {
					return __( 'قالب ایمیل درست نیست.', 'signa' );
				}
				break;

			case 'tel':
				$digits = preg_replace( '/\D/', '', (string) $value );
				if ( strlen( $digits ) < 6 || strlen( $digits ) > 15 ) {
					return __( 'شماره تماس معتبر نیست.', 'signa' );
				}
				break;

			case 'number':
				if ( ! is_numeric( (string) $value ) ) {
					return __( 'مقدار باید عددی باشد.', 'signa' );
				}
				break;

			case 'postcode':
				if ( 1 !== preg_match( '/^\d{10}$/', (string) $value ) ) {
					return __( 'کد پستی باید ۱۰ رقم باشد.', 'signa' );
				}
				break;

			case 'date':
				if ( '' === $value ) {
					return __( 'تاریخ معتبر نیست.', 'signa' );
				}
				break;

			case 'select':
				if ( ! in_array( (string) $value, (array) $field['options'], true ) ) {
					return __( 'گزینه انتخاب‌شده معتبر نیست.', 'signa' );
				}
				break;
		}

		/**
		 * Add custom validation per field id.
		 *
		 * @param string $error Empty when the value is acceptable.
		 * @param mixed  $value Sanitised value.
		 * @param array  $field Field definition.
		 */
		return (string) apply_filters( 'signa_validate_field_' . $field['id'], '', $value, $field );
	}
}
