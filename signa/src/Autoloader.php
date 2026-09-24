<?php
/**
 * PSR-4 style autoloader for the Signa namespace.
 *
 * @package Signa
 */

namespace Signa;

defined( 'ABSPATH' ) || exit;

final class Autoloader {

	const PREFIX = 'Signa\\';

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
		$file     = SIGNA_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}

	/**
	 * Map a class name to its expected file (used by diagnostics).
	 */
	public static function pathFor( string $class ): string {
		return SIGNA_PATH . 'src/' . str_replace( '\\', '/', substr( $class, strlen( self::PREFIX ) ) ) . '.php';
	}
}
