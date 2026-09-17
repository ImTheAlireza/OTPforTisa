<?php
/**
 * Iranian phone number normalisation, validation and masking.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Support;

defined( 'ABSPATH' ) || exit;

final class Phone {

	const CANONICAL = '/^09\d{9}$/';

	/** @var array<string,string> */
	private static $digitMap = array(
		'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
		'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
		'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
		'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
	);

	public static function latinDigits( string $value ): string {
		return strtr( $value, self::$digitMap );
	}

	/**
	 * Reduce any local spelling to 09xxxxxxxxx.
	 */
	public static function normalize( string $raw ): string {
		$value = preg_replace( '/[^0-9+]/', '', self::latinDigits( $raw ) );

		if ( '' === $value ) {
			return '';
		}

		$value = ltrim( $value, '+' );

		if ( preg_match( '/^0098(9\d{9})$/', $value, $m ) ) {
			return '0' . $m[1];
		}
		if ( preg_match( '/^98(9\d{9})$/', $value, $m ) ) {
			return '0' . $m[1];
		}
		if ( preg_match( '/^9(9\d{9})$/', $value, $m ) ) {
			return '0' . $m[1];
		}
		if ( preg_match( self::CANONICAL, $value ) ) {
			return $value;
		}

		return $value;
	}

	public static function isValid( string $phone ): bool {
		$normalized = self::normalize( $phone );

		/**
		 * Allow other numbering plans to opt in.
		 *
		 * @param bool   $valid Whether the number matches the Iranian mobile pattern.
		 * @param string $normalized Normalised number.
		 */
		return (bool) apply_filters( 'tisa_otp_phone_valid', 1 === preg_match( self::CANONICAL, $normalized ), $normalized );
	}

	/**
	 * Every spelling that may already exist in legacy user meta.
	 *
	 * @return string[]
	 */
	public static function variants( string $phone ): array {
		$normalized = self::normalize( $phone );

		if ( '' === $normalized ) {
			return array();
		}

		$tail = substr( $normalized, 1 );

		return array_values(
			array_unique(
				array(
					$normalized,
					$tail,
					'98' . $tail,
					'+98' . $tail,
					'0098' . $tail,
				)
			)
		);
	}

	public static function mask( string $phone ): string {
		$digits = preg_replace( '/\D/', '', self::latinDigits( $phone ) );

		if ( strlen( $digits ) < 8 ) {
			return '****';
		}

		return substr( $digits, 0, 4 ) . '***' . substr( $digits, -3 );
	}

	/**
	 * Fingerprint used as a storage key; never reversible without the pepper.
	 */
	public static function fingerprint( string $phone ): string {
		return Crypto::sign( self::normalize( $phone ), 'phone' );
	}
}
