<?php
/**
 * Client IP resolution that only trusts explicitly whitelisted proxies.
 *
 * @package Signa
 */

namespace Signa\Support;

defined( 'ABSPATH' ) || exit;

final class ClientIp {

	const FALLBACK = '0.0.0.0';

	/** @var array<string,string> */
	private static $headers = array(
		'cloudflare' => 'HTTP_CF_CONNECTING_IP',
		'forwarded'  => 'HTTP_X_FORWARDED_FOR',
		'real_ip'    => 'HTTP_X_REAL_IP',
	);

	public static function current( string $mode = 'none', string $trustedList = '' ): string {
		$remote = self::valid( isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '' );

		if ( '' === $remote ) {
			$remote = self::FALLBACK;
		}

		$trusted = self::parseRanges( $trustedList );
		$header  = isset( self::$headers[ $mode ] ) ? self::$headers[ $mode ] : '';

		if ( '' === $header || empty( $trusted ) || ! self::inRanges( $remote, $trusted ) ) {
			return self::publish( $remote, $remote );
		}

		$raw = isset( $_SERVER[ $header ] ) ? (string) $_SERVER[ $header ] : '';

		if ( '' === trim( $raw ) ) {
			return self::publish( $remote, $remote );
		}

		if ( 'forwarded' === $mode ) {
			$candidate = self::fromForwardedChain( $raw, $trusted );
		} else {
			$parts     = explode( ',', $raw );
			$candidate = self::valid( trim( $parts[0] ) );
		}

		return self::publish( '' !== $candidate ? $candidate : $remote, $remote );
	}

	/**
	 * Walk the X-Forwarded-For chain from the closest hop outwards and stop at
	 * the first address that is not one of our own trusted proxies.
	 */
	private static function fromForwardedChain( string $raw, array $trusted ): string {
		$hops = array();

		foreach ( explode( ',', $raw ) as $hop ) {
			$ip = self::valid( trim( $hop ) );
			if ( '' !== $ip ) {
				$hops[] = $ip;
			}
		}

		$found = '';

		for ( $i = count( $hops ) - 1; $i >= 0; $i-- ) {
			$found = $hops[ $i ];
			if ( ! self::inRanges( $found, $trusted ) ) {
				break;
			}
		}

		return $found;
	}

	private static function publish( string $ip, string $remote ): string {
		/**
		 * Filter the resolved client IP.
		 *
		 * @param string $ip     Resolved IP.
		 * @param string $remote Raw REMOTE_ADDR.
		 */
		return (string) apply_filters( 'signa_client_ip', $ip, $remote );
	}

	public static function fingerprint( string $ip ): string {
		return Crypto::sign( strtolower( trim( $ip ) ), 'ip' );
	}

	/**
	 * @return string[]
	 */
	private static function parseRanges( string $list ): array {
		$ranges = array();

		foreach ( explode( ',', $list ) as $entry ) {
			$entry = trim( $entry );
			if ( '' === $entry ) {
				continue;
			}
			$ranges[] = $entry;
		}

		return $ranges;
	}

	private static function inRanges( string $ip, array $ranges ): bool {
		foreach ( $ranges as $range ) {
			if ( self::inRange( $ip, $range ) ) {
				return true;
			}
		}

		return false;
	}

	private static function inRange( string $ip, string $range ): bool {
		if ( false === strpos( $range, '/' ) ) {
			return strtolower( $ip ) === strtolower( $range );
		}

		list( $network, $bits ) = array_pad( explode( '/', $range, 2 ), 2, '0' );

		$ipBin      = inet_pton( $ip );
		$networkBin = inet_pton( trim( $network ) );
		$bits       = (int) $bits;

		if ( false === $ipBin || false === $networkBin || strlen( $ipBin ) !== strlen( $networkBin ) ) {
			return false;
		}

		$maxBits = strlen( $ipBin ) * 8;

		if ( $bits < 0 || $bits > $maxBits ) {
			return false;
		}

		$fullBytes = intdiv( $bits, 8 );
		$remainder = $bits % 8;

		if ( $fullBytes > 0 && substr( $ipBin, 0, $fullBytes ) !== substr( $networkBin, 0, $fullBytes ) ) {
			return false;
		}

		if ( 0 === $remainder ) {
			return true;
		}

		$mask = ( 0xFF << ( 8 - $remainder ) ) & 0xFF;

		return ( ord( $ipBin[ $fullBytes ] ) & $mask ) === ( ord( $networkBin[ $fullBytes ] ) & $mask );
	}

	private static function valid( string $ip ): string {
		$ip = trim( $ip );

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
