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
define( 'TISA_OTP_FILE', TISA_OTP_PATH . 'tisa-otp.php' );

/*
 * The plugin's own version, read the way WordPress reads it. Tests compare it
 * with the package manifest, so a release that forgets one of the two fails
 * here rather than on somebody's site.
 */
$GLOBALS['tisa_plugin_source'] = is_readable( TISA_OTP_FILE ) ? (string) file_get_contents( TISA_OTP_FILE ) : '';
$GLOBALS['tisa_version_match'] = array();
preg_match( "/define\(\s*'TISA_OTP_VERSION',\s*'([^']+)'\s*\)/", $GLOBALS['tisa_plugin_source'], $GLOBALS['tisa_version_match'] );
define( 'TISA_OTP_VERSION', isset( $GLOBALS['tisa_version_match'][1] ) ? $GLOBALS['tisa_version_match'][1] : '0.0.0' );
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


/*
 * A `$wpdb` that answers instead of dying.
 *
 * The log store talks to MySQL through the global, and every admin screen draws
 * numbers that come from it. Rendering a screen in a test therefore needs a
 * database that returns empty rows rather than a fatal on `null->get_results()`.
 */
class Tisa_Wpdb_Stub {

	/** @var string */
	public $prefix = 'wp_';

	/** @var int */
	public $insert_id = 0;

	/** @var array<int,array<string,mixed>> */
	public $rows = array();

	/** @var array<int,array<string,mixed>> */
	public $writes = array();

	public function prepare( $query, ...$args ) {
		// WordPress accepts both `prepare( $sql, $a, $b )` and the single
		// array form `prepare( $sql, array( $a, $b ) )`; the log store uses both.
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = array_values( $args[0] );
		}

		$filled = @vsprintf( (string) $query, $args );

		return false === $filled ? (string) $query : $filled;
	}

	/** @return array<int,array<string,mixed>> */
	public function get_results( $query = '' ) {
		return $this->rows;
	}

	/** @return array<string,mixed> */
	public function get_row( $query = '' ) {
		return isset( $this->rows[0] ) ? $this->rows[0] : array();
	}

	public function get_var( $query = '' ) {
		return 0;
	}

	public function query( $query = '' ) {
		return 0;
	}

	public function insert( $table, $data ) {
		$this->writes[] = $data;

		return 1;
	}

	public function get_charset_collate(): string {
		return '';
	}

	public function esc_like( $text ): string {
		return addcslashes( (string) $text, '_%\\' );
	}

	/** @return array<int,mixed> */
	public function get_col( $query = '' ) {
		return array();
	}
}

$GLOBALS['wpdb'] = new Tisa_Wpdb_Stub();

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
	$extra = array_slice( func_get_args(), 2 );

	if ( empty( $GLOBALS['tisa_hooks'][ $tag ] ) ) {
		return $value;
	}

	foreach ( (array) $GLOBALS['tisa_hooks'][ $tag ] as $callback ) {
		$value = call_user_func_array( $callback, array_merge( array( $value ), $extra ) );
	}

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
 * The part of WordPress the admin screens touch.
 *
 * The screens are drawn, not run, in these tests: they ask who is logged in,
 * whether that person may manage the site, and for a nonce field. Stubs that
 * throw make it possible to test the handlers too — the request ends by
 * redirecting or by dying, and both are visible here.
 * ---------------------------------------------------------------------- */

function get_current_user_id(): int {
	return isset( $GLOBALS['tisa_current_user'] ) ? (int) $GLOBALS['tisa_current_user'] : 0;
}

function current_user_can( $capability ): bool {
	return ! empty( $GLOBALS['tisa_may_manage'] );
}

function add_filter( $tag, $callback, $priority = 10, $accepted = 1 ) {
	$GLOBALS['tisa_hooks'][ $tag ][] = $callback;

	return true;
}

function add_action( $tag, $callback, $priority = 10, $accepted = 1 ) {
	return add_filter( $tag, $callback, $priority, $accepted );
}

/**
 * @param string $action
 */
function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
	if ( empty( $GLOBALS['tisa_nonce_ok'] ) ) {
		throw new RuntimeException( 'nonce' );
	}

	return 1;
}

/**
 * @param string $message
 */
function wp_die( $message = '', $title = '', $args = array() ) {
	throw new RuntimeException( 'died' );
}

/**
 * @param string $location
 */
function wp_safe_redirect( $location = '', $status = 302 ) {
	throw new RuntimeException( $location );
}

/**
 * @return string
 */
function wp_get_referer() {
	return isset( $GLOBALS['tisa_referer'] ) ? $GLOBALS['tisa_referer'] : '';
}

/**
 * @param string $value
 * @return string
 */
function trailingslashit( $value ): string {
	return rtrim( (string) $value, '/\\' ) . '/';
}

/**
 * @param string $value
 * @return string
 */
function untrailingslashit( $value ): string {
	return rtrim( (string) $value, '/\\' );
}

/**
 * @param string $action
 * @return string
 */
function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ) {
	$html = '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( 'nonce-' . $action ) . '">';

	if ( $referer ) {
		$html .= '<input type="hidden" name="_wp_http_referer" value="">';
	}

	if ( $display ) {
		echo $html;
	}

	return $html;
}

/* -------------------------------------------------------------------------
 * Outbound HTTP, recorded instead of performed
 *
 * Gateway drivers are half decision-making and half plumbing, and the decisions
 * are the half that costs money when it is wrong. These stand-ins let a test
 * answer as a panel would — a status code, a body, or a WP_Error — and then
 * look at exactly what the driver sent.
 * ---------------------------------------------------------------------- */

/**
 * @param string $code
 * @param string $message
 * @param mixed  $data
 */
class WP_Error { // phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps -- WordPress class.
	/** @var string */
	private $code;
	/** @var string */
	private $message;
	/** @var mixed */
	private $data;

	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = (string) $code;
		$this->message = (string) $message;
		$this->data    = $data;
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}

	/**
	 * @return mixed
	 */
	public function get_error_data() {
		return $this->data;
	}
}

/**
 * @param mixed $thing
 */
function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}

$GLOBALS['tisa_http_requests'] = array();
$GLOBALS['tisa_http_reply']    = null;

/**
 * The next answer any wp_remote_* call will get.
 *
 * @param mixed $reply Array with 'response' => array( 'code' => int ), 'body' => string, or a WP_Error.
 */
function tisa_reply( $reply ): void {
	$GLOBALS['tisa_http_reply'] = $reply;
}

/**
 * @return array<int,array{method:string,url:string,args:array<string,mixed>}>
 */
function tisa_requests(): array {
	return $GLOBALS['tisa_http_requests'];
}

function tisa_forget_requests(): void {
	$GLOBALS['tisa_http_requests'] = array();
}

/**
 * Drop every filter a test registered, so one group cannot change the next.
 */
function tisa_forget_filters(): void {
	$GLOBALS['tisa_hooks'] = array();
}

/**
 * @param mixed $reply
 * @return array|WP_Error
 */
function tisa_http( string $method, string $url, array $args = array() ) {
	$GLOBALS['tisa_http_requests'][] = array(
		'method' => $method,
		'url'    => $url,
		'args'   => $args,
	);

	$reply = $GLOBALS['tisa_http_reply'];

	if ( $reply instanceof WP_Error ) {
		return $reply;
	}

	if ( ! is_array( $reply ) ) {
		return new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host: api.sms.ir' );
	}

	$code = isset( $reply['code'] ) ? (int) $reply['code'] : 200;

	return array(
		'headers'  => array(),
		'body'     => isset( $reply['body'] ) ? (string) $reply['body'] : '',
		'response' => array(
			'code'    => $code,
			'message' => isset( $reply['message'] ) ? (string) $reply['message'] : 'OK',
		),
		'cookies'  => array(),
		'filename' => null,
	);
}

/**
 * @return array|WP_Error
 */
function wp_remote_post( string $url, array $args = array() ) {
	return tisa_http( 'POST', $url, $args );
}

/**
 * @return array|WP_Error
 */
function wp_remote_get( string $url, array $args = array() ) {
	return tisa_http( 'GET', $url, $args );
}

/**
 * @param array|WP_Error $response
 */
function wp_remote_retrieve_response_code( $response ): int {
	if ( is_wp_error( $response ) ) {
		return 0;
	}

	return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
}

/**
 * @param array|WP_Error $response
 */
function wp_remote_retrieve_body( $response ): string {
	if ( is_wp_error( $response ) ) {
		return '';
	}

	return isset( $response['body'] ) ? (string) $response['body'] : '';
}

/**
 * @param mixed $data
 */
function wp_json_encode( $data, int $options = 0, int $depth = 512 ) {
	return (string) json_encode( $data, $options, $depth );
}

function get_bloginfo( $show = 'name' ): string {
	return 'name' === $show ? 'نمونه سایت' : '';
}

function wp_specialchars_decode( $text, $quote_style = ENT_NOQUOTES ): string {
	return htmlspecialchars_decode( (string) $text, $quote_style === ENT_QUOTES ? ENT_QUOTES : ENT_NOQUOTES );
}

/**
 * @return mixed
 */
function wp_parse_url( string $url, int $component = -1 ) {
	return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
}

function home_url( string $path = '' ): string {
	return 'https://example.test' . $path;
}

function get_locale(): string {
	return 'fa_IR';
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

/* --- the rest of the function surface an admin screen touches ------------- */

function number_format_i18n( $number, $decimals = 0 ): string {
	return number_format( (float) $number, (int) $decimals );
}

function wp_kses_post( $text ): string {
	return (string) $text;
}

/**
 * @param array<string,array<string,bool>> $allowed
 */
function wp_kses( $text, $allowed = array() ): string {
	return (string) $text;
}

function esc_textarea( $text ): string {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function size_format( $bytes, $decimals = 0 ): string {
	return number_format( (float) $bytes, (int) $decimals ) . ' B';
}

function is_rtl(): bool {
	return true;
}

function absint( $value ): int {
	return abs( (int) $value );
}

function wp_rand( $min = 0, $max = 0 ) {
	return $max > $min ? random_int( (int) $min, (int) $max ) : 4;
}

function wp_create_nonce( $action = -1 ): string {
	return substr( md5( 'nonce' . (string) $action ), 0, 10 );
}

function wp_verify_nonce( $nonce, $action = -1 ) {
	return 1;
}

/**
 * @param mixed $selected
 * @param mixed $current
 */
function selected( $selected, $current = true, $echo = true ): string {
	$out = (string) $selected === (string) $current ? " selected='selected'" : '';

	if ( $echo ) {
		echo $out;
	}

	return $out;
}

/**
 * @param mixed $checked
 * @param mixed $current
 */
function checked( $checked, $current = true, $echo = true ): string {
	$out = (string) $checked === (string) $current ? " checked='checked'" : '';

	if ( $echo ) {
		echo $out;
	}

	return $out;
}

function submit_button( $text = '', $type = 'primary', $name = 'submit', $wrap = true, $other = '' ): void {
	echo '<button type="submit" class="button">' . esc_html( (string) $text ) . '</button>';
}

function settings_fields( $group ): void {
	echo '<input type="hidden" name="option_page" value="' . esc_attr( (string) $group ) . '">';
}

function add_settings_error( $setting, $code, $message, $type = 'error' ): void {
	$GLOBALS['tisa_settings_errors'][] = array( $setting, $code, $message, $type );
}

function date_i18n( $format, $timestamp = null, $gmt = false ): string {
	return gmdate( (string) $format, null === $timestamp ? time() : (int) $timestamp );
}

/**
 * @param mixed $args
 * @param mixed $defaults
 */
function wp_parse_args( $args, $defaults = array() ): array {
	return array_merge( (array) $defaults, (array) $args );
}

function wp_enqueue_script( ...$args ): void {}
function wp_enqueue_style( ...$args ): void {}
function wp_localize_script( ...$args ): void {}
function wp_add_inline_script( ...$args ): void {}

function get_current_screen() {
	return null;
}

function wp_mail( ...$args ): bool {
	return true;
}

function wp_get_attachment_image_url( $attachment_id, $size = 'thumbnail' ) {
	return '';
}

function wp_dropdown_roles( $selected = '' ): void {}

/**
 * @return array<string,array<string,mixed>>
 */
function get_editable_roles(): array {
	return array();
}

/* --- a role list, a login URL and an escaped query ------------------------ */

class Tisa_Wp_Roles_Stub {

	/** @var array<string,string> */
	private $names = array(
		'administrator' => 'مدیر',
		'editor'        => 'ویرایشگر',
		'subscriber'    => 'مشترک',
	);

	/** @return array<string,string> */
	public function get_names(): array {
		return $this->names;
	}
}

function wp_roles(): Tisa_Wp_Roles_Stub {
	return new Tisa_Wp_Roles_Stub();
}

function wp_login_url( $redirect = '' ): string {
	return 'https://example.test/wp-login.php';
}

function esc_sql( $text ): string {
	return addslashes( (string) $text );
}

/**
 * @param array<string,mixed> $args
 */
function add_query_arg( $args, string $url = '' ): string {
	if ( is_array( $args ) ) {
		$query = http_build_query( $args );

		return '' === $url ? '?' . $query : $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $query;
	}

	return (string) $url;
}

function remove_query_arg( $keys, string $url = '' ): string {
	$parts = explode( '?', $url );

	return $parts[0];
}
function esc_url_raw( $url ): string {
	return (string) $url;
}

function wp_safe_remote_get( $url, $args = array() ) {
	return wp_remote_get( $url, $args );
}

function wp_nonce_url( string $url, $action = -1, string $name = '_wpnonce' ): string {
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $name . '=' . wp_create_nonce( $action );
}

function wp_using_ext_object_cache(): bool {
	return false;
}

function wp_cache_get( $key, $group = '' ) {
	return false;
}

function wp_cache_set( $key, $value, $group = '', $ttl = 0 ): bool {
	return true;
}

function wp_cache_delete( $key, $group = '' ): bool {
	return true;
}

function wp_debug_backtrace_summary( $ignore_class = null, $skip_frames = 0, $pretty = true ): string {
	return '';
}

function wp_next_scheduled( $hook, $args = array() ) {
	return false;
}

function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array() ): bool {
	return true;
}

function wp_clear_scheduled_hook( $hook, $args = array() ): int {
	return 0;
}

function wp_get_schedules(): array {
	return array();
}

function wp_timezone_string(): string {
	return 'UTC';
}

function get_woocommerce_currency(): string {
	return 'IRR';
}

function wc_get_page_id( $page ): int {
	return 0;
}

function wc_get_page_permalink( $page ): string {
	return '';
}
