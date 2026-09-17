<?php
/**
 * PSR-4 style autoloader for the TisaOtp namespace.
 *
 * @package TisaOtp
 */

namespace TisaOtp;

defined( 'ABSPATH' ) || exit;

final class Autoloader {

	const PREFIX = 'TisaOtp\\';

	/** @var bool */
	private static $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		spl_autoload_register( array( __CLASS__, 'resolve' ) );
	}

	public static function resolve( string $class ): void {
		if ( strpos( $class, self::PREFIX ) !== 0 ) {
			return;
		}

		$relative = substr( $class, strlen( self::PREFIX ) );
		$file     = TISA_OTP_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}

	/**
	 * Map a class name to its expected file (used by diagnostics).
	 */
	public static function pathFor( string $class ): string {
		return TISA_OTP_PATH . 'src/' . str_replace( '\\', '/', substr( $class, strlen( self::PREFIX ) ) ) . '.php';
	}
}
