<?php
/**
 * Cryptographic helpers: code generation, keyed hashing and constant-time compare.
 *
 * A per-installation pepper is generated on activation so exported database
 * dumps cannot be brute-forced offline against stored code hashes.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Support;

defined( 'ABSPATH' ) || exit;

final class Crypto {

	const PEPPER_OPTION = 'tisa_otp_pepper';

	/** @var string|null */
	private static $pepper;

	public static function digits( int $length ): string {
		$length = max( 4, min( 8, $length ) );
		$code   = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$code .= (string) random_int( 0, 9 );
		}

		return $code;
	}

	public static function token( int $bytes = 16 ): string {
		return substr( bin2hex( random_bytes( max( 8, $bytes ) ) ), 0, max( 8, $bytes ) * 2 );
	}

	/**
	 * Keyed hash used for identifiers, codes and log fingerprints.
	 */
	public static function sign( string $value, string $context = 'general' ): string {
		return hash_hmac( 'sha256', $context . '|' . $value, self::pepper() );
	}

	public static function match( string $known, string $candidate ): bool {
		return hash_equals( $known, $candidate );
	}

	public static function ensurePepper(): string {
		$pepper = get_option( self::PEPPER_OPTION );

		if ( ! is_string( $pepper ) || strlen( $pepper ) < 32 ) {
			$pepper = self::token( 32 );
			update_option( self::PEPPER_OPTION, $pepper, false );
		}

		self::$pepper = $pepper;

		return $pepper;
	}

	private static function pepper(): string {
		if ( null === self::$pepper ) {
			$stored = get_option( self::PEPPER_OPTION );
			self::$pepper = is_string( $stored ) && strlen( $stored ) >= 32
				? $stored
				: wp_salt( 'auth' );
		}

		return self::$pepper;
	}
}
