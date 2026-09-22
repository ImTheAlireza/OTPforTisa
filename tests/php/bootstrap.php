<?php
/**
 * Runs the plugin's pure-logic classes outside WordPress.
 *
 * The rule engine, the blocklist and the emergency token talk to WordPress
 * through a very small surface (options, transients, a couple of filters), so
 * they can be exercised with a handful of stubs. That keeps the tests honest:
 * they run the real classes, not a copy.
 *
 * Usage:  php tests/php/blocklist-test.php
 *
 * @package TisaOtp\Tests
 */

define( 'ABSPATH', __DIR__ );
define( 'TISA_OTP_PATH', dirname( __DIR__, 2 ) . '/tisa-otp/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['tisa_options']    = array();
$GLOBALS['tisa_transients'] = array();
$GLOBALS['tisa_checks']     = 0;
$GLOBALS['tisa_failures']   = 0;
$GLOBALS['tisa_actions']    = array();
$GLOBALS['tisa_user_meta']  = array();

/* -------------------------------------------------------------------------
 * WordPress stand-ins
 * ---------------------------------------------------------------------- */

/**
 * @param mixed $default
 * @return mixed
 */
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['tisa_options'] ) ? $GLOBALS['tisa_options'][ $key ] : $default;
}

/**
 * @param mixed $value
 * @return bool
 */
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['tisa_options'][ $key ] = $value;

	return true;
}

function delete_option( $key ): bool {
	unset( $GLOBALS['tisa_options'][ $key ] );

	return true;
}

/**
 * @param mixed $value
 */
function set_transient( $key, $value, $ttl = 0 ): bool {
	$GLOBALS['tisa_transients'][ $key ] = $value;

	return true;
}

/**
 * @return mixed
 */
function get_transient( $key ) {
	return array_key_exists( $key, $GLOBALS['tisa_transients'] ) ? $GLOBALS['tisa_transients'][ $key ] : false;
}

function delete_transient( $key ): bool {
	unset( $GLOBALS['tisa_transients'][ $key ] );

	return true;
}

/**
 * @param string $text
 * @return string
 */
function __( $text, $domain = null ) {
	return $text;
}

/**
 * @param string $text
 * @return string
 */
function esc_html__( $text, $domain = null ) {
	return $text;
}

/**
 * @param mixed $value
 * @return mixed
 */
/**
 * Escaping and admin URLs, so screens can be rendered in tests.
 */
function esc_html( $text ): string {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ): string {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( $url ): string {
	return (string) $url;
}

function admin_url( $path = '', $scheme = 'admin' ): string {
	unset( $scheme );

	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}

function esc_attr__( $text, $domain = null ) {
	unset( $domain );

	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function apply_filters( $tag, $value ) {
	return $value;
}

function do_action( $tag ) {
	$GLOBALS['tisa_actions'][] = $tag;
}

/**
 * @param string $type
 * @return string
 */
function current_time( $type, $gmt = 0 ) {
	return 'mysql' === $type ? gmdate( 'Y-m-d H:i:s' ) : time();
}

function wp_salt( $scheme = 'auth' ): string {
	return hash( 'sha256', 'tisa-otp-test-' . $scheme );
}

/**
 * Persian-friendly stand-in; only the shape of the return value matters here.
 *
 * @param int      $from
 * @param int|null $to
 * @return string
 */
function human_time_diff( $from, $to = null ) {
	$diff = abs( ( null === $to ? time() : (int) $to ) - (int) $from );

	if ( $diff < 60 ) {
		return $diff . ' ثانیه';
	}

	if ( $diff < 3600 ) {
		return floor( $diff / 60 ) . ' دقیقه';
	}

	if ( $diff < 86400 ) {
		return floor( $diff / 3600 ) . ' ساعت';
	}

	return floor( $diff / 86400 ) . ' روز';
}

function wp_date( $format, $timestamp = null ) {
	return gmdate( $format, null === $timestamp ? time() : (int) $timestamp );
}

/**
 * @param string $key
 * @return string
 */
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

/**
 * @param string $text
 * @return string
 */
function sanitize_text_field( $text ) {
	return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( (string) $text ) ) );
}

/**
 * @param string $text
 * @return string
 */
function sanitize_textarea_field( $text ) {
	return trim( strip_tags( (string) $text ) );
}

/**
 * @param string $email
 * @return string
 */
function sanitize_email( $email ) {
	$email = trim( (string) $email );

	return preg_match( '/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email ) ? $email : '';
}

/**
 * @param string $email
 * @return bool
 */
function is_email( $email ) {
	return '' !== sanitize_email( $email );
}

/**
 * User meta, kept in one array so a test can read what a method wrote.
 *
 * @param int    $userId
 * @param string $key
 * @param mixed  $default
 * @return mixed
 */
function get_user_meta( $userId, $key, $single = false ) {
	$bag = isset( $GLOBALS['tisa_user_meta'][ (int) $userId ] ) ? $GLOBALS['tisa_user_meta'][ (int) $userId ] : array();

	return array_key_exists( $key, $bag ) ? $bag[ $key ] : '';
}

/**
 * @param int    $userId
 * @param string $key
 * @param mixed  $value
 */
function update_user_meta( $userId, $key, $value ) {
	$GLOBALS['tisa_user_meta'][ (int) $userId ][ $key ] = $value;

	return true;
}

/**
 * @param mixed $value
 * @return mixed
 */
function wp_unslash( $value ) {
	return $value;
}

/* -------------------------------------------------------------------------
 * Minimal autoloader, mirroring src/Autoloader.php
 * ---------------------------------------------------------------------- */

spl_autoload_register(
	static function ( string $class ): void {
		if ( 0 !== strpos( $class, 'TisaOtp\\' ) ) {
			return;
		}

		$file = TISA_OTP_PATH . 'src/' . str_replace( '\\', '/', substr( $class, 8 ) ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

/* -------------------------------------------------------------------------
 * Tiny assertions
 * ---------------------------------------------------------------------- */

function tisa_check( string $label, bool $ok ): void {
	++$GLOBALS['tisa_checks'];

	if ( $ok ) {
		echo "  ok    {$label}\n";
		return;
	}

	++$GLOBALS['tisa_failures'];
	echo "  FAIL  {$label}\n";
}

/**
 * @param mixed $expected
 * @param mixed $actual
 */
function tisa_same( string $label, $expected, $actual ): void {
	tisa_check(
		$label,
		$expected === $actual
	);

	if ( $expected !== $actual ) {
		echo '        expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
	}
}

function tisa_start( string $name ): void {
	echo "\n== {$name} ==\n";
	$GLOBALS['tisa_options']    = array();
	$GLOBALS['tisa_transients'] = array();
}

function tisa_finish(): void {
	$checks   = (int) $GLOBALS['tisa_checks'];
	$failures = (int) $GLOBALS['tisa_failures'];

	echo "\n{$checks} checks, {$failures} failed\n";

	exit( $failures > 0 ? 1 : 0 );
}
