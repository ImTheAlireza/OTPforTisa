<?php

namespace Signa\Support;

defined( 'ABSPATH' ) || exit;

final class Colour {
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

	public static function luminance( string $colour ): ?float {
		$rgba = self::rgb( $colour );

		if ( null === $rgba ) {
			return null;
		}

		list( $red, $green, $blue ) = self::flatten( $rgba );

		return 0.2126 * self::channel( $red ) + 0.7152 * self::channel( $green ) + 0.0722 * self::channel( $blue );
	}

	private static function rgb( string $colour ): ?array {
		$colour = strtolower( trim( $colour ) );

		if ( '' === $colour ) {
			return null;
		}

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

	private static function flatten( array $rgba ): array {
		return array( (float) $rgba[0], (float) $rgba[1], (float) $rgba[2] );
	}

	private static function channel( float $value ): float {
		$value = $value / 255;

		return $value <= 0.03928 ? $value / 12.92 : pow( ( $value + 0.055 ) / 1.055, 2.4 );
	}
}
