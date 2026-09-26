<?php

namespace Signa\Registration;

use Signa\Config\Settings;
use Signa\Support\Phone;

defined( 'ABSPATH' ) || exit;

final class FieldValidator {
	private $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

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

		$values = (array) apply_filters( 'signa_validated_values', $values, $errors, $raw );

		return array(
			'ok'     => array() === $errors,
			'values' => $values,
			'errors' => $errors,
		);
	}

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

		return (string) apply_filters( 'signa_validate_field_' . $field['id'], '', $value, $field );
	}
}
