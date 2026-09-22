<?php
/**
 * Just enough colour maths to answer one question honestly: can this be read?
 *
 * The design tab lets an administrator pick their own accent and surface, and
 * a picked colour can quietly make the form unreadable. The front-end tests
 * measure the shipped palette; this class measures whatever the site owner
 * typed in, with the same formula (WCAG 2.1 relative luminance) so the admin
 * test and the CI test can never disagree about what 4.5:1 means.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Support;

defined( 'ABSPATH' ) || exit;

final class Colour {

	/**
	 * Contrast ratio between two colours, or null when one cannot be parsed.
	 *
	 * Translucent colours are flattened onto white first, which is the worst
	 * case for a form card: if the pair passes there, it passes on any lighter
	 * background too.
	 *
	 * @return float|null 1.0 to 21.0.
	 */
	public static function ratio( string $first, string $second ): ?float {
		$one = self::luminance( $first );
		$two = self::luminance( $second );

		if ( null === $one || null === $two ) {
			return null;
		}

		$light = max( $one, $two );
		$dark  = min( $one, $two );

		return round( ( $light + 0.05 ) / ( $dark + 0.05 ), 2 );
	}

	/**
	 * Relative luminance, 0 (black) to 1 (white).
	 */
	public static function luminance( string $colour ): ?float {
		$rgba = self::rgb( $colour );

		if ( null === $rgba ) {
			return null;
		}

		list( $red, $green, $blue ) = self::flatten( $rgba );

		return 0.2126 * self::channel( $red ) + 0.7152 * self::channel( $green ) + 0.0722 * self::channel( $blue );
	}

	/**
	 * @return array<int,int>|null RGB channels, 0-255.
	 */
	private static function rgb( string $colour ): ?array {
		$colour = strtolower( trim( $colour ) );

		if ( '' === $colour ) {
			return null;
		}

		// #rgb / #rrggbb
		if ( '#' === $colour[0] ) {
			$hex = substr( $colour, 1 );

			if ( 3 === strlen( $hex ) || 4 === strlen( $hex ) ) {
				$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
			}

			if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
				return null;
			}

			return array(
				(int) hexdec( substr( $hex, 0, 2 ) ),
				(int) hexdec( substr( $hex, 2, 2 ) ),
				(int) hexdec( substr( $hex, 4, 2 ) ),
			);
		}

		// rgb() / rgba()
		if ( 0 === strpos( $colour, 'rgb' ) && preg_match( '/^rgba?\(([^)]+)\)$/', $colour, $matches ) ) {
			$parts = array_map( 'trim', explode( ',', $matches[1] ) );

			if ( count( $parts ) < 3 ) {
				return null;
			}

			$channels = array();

			foreach ( array_slice( $parts, 0, 3 ) as $part ) {
				$number = (float) rtrim( $part, '%' );

				if ( false !== strpos( $part, '%' ) ) {
					$number = $number * 255 / 100;
				}

				$channels[] = (int) max( 0, min( 255, round( $number ) ) );
			}

			return $channels;
		}

		return null;
	}

	/**
	 * @param array<int,int> $rgba
	 * @return array<int,float>
	 */
	private static function flatten( array $rgba ): array {
		return array( (float) $rgba[0], (float) $rgba[1], (float) $rgba[2] );
	}

	/**
	 * Gamma-corrected channel value, 0 to 1.
	 */
	private static function channel( float $value ): float {
		$value = $value / 255;

		return $value <= 0.03928 ? $value / 12.92 : pow( ( $value + 0.055 ) / 1.055, 2.4 );
	}
}
