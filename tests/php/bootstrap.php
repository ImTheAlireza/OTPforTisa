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
 * @package Signa\Tests
 */

define( 'ABSPATH', __DIR__ );
define( 'SIGNA_PATH', dirname( __DIR__, 2 ) . '/signa/' );
// The plugin's public URL, which `SIGNA_URL` carries on a real install.
define( 'SIGNA_URL', 'https://example.test/wp-content/plugins/signa/' );
define( 'SIGNA_FILE', SIGNA_PATH . 'signa.php' );

/*
 * The plugin's own version, read the way WordPress reads it. Tests compare it
 * with the package manifest, so a release that forgets one of the two fails
 * here rather than on somebody's site.
 */
$GLOBALS['signa_plugin_source'] = is_readable( SIGNA_FILE ) ? (string) file_get_contents( SIGNA_FILE ) : '';
$GLOBALS['signa_version_match'] = array();
preg_match( "/define\(\s*'SIGNA_VERSION',\s*'([^']+)'\s*\)/", $GLOBALS['signa_plugin_source'], $GLOBALS['signa_version_match'] );
define( 'SIGNA_VERSION', isset( $GLOBALS['signa_version_match'][1] ) ? $GLOBALS['signa_version_match'][1] : '0.0.0' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['signa_options']    = array();
$GLOBALS['signa_transients'] = array();
$GLOBALS['signa_checks']     = 0;
$GLOBALS['signa_failures']   = 0;
$GLOBALS['signa_actions']    = array();
$GLOBALS['signa_user_meta']  = array();

/* -------------------------------------------------------------------------
 * WordPress stand-ins
 * ---------------------------------------------------------------------- */

/**
 * @param mixed $default
 * @return mixed
 */
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['signa_options'] ) ? $GLOBALS['signa_options'][ $key ] : $default;
}

/**
 * @param mixed $value
 * @return bool
 */
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['signa_options'][ $key ] = $value;

	return true;
}

function delete_option( $key ): bool {
	unset( $GLOBALS['signa_options'][ $key ] );

	return true;
}

/**
 * @param mixed $value
 */
function set_transient( $key, $value, $ttl = 0 ): bool {
	$GLOBALS['signa_transients'][ $key ] = $value;

	return true;
}

/**
 * @return mixed
 */
function get_transient( $key ) {
	return array_key_exists( $key, $GLOBALS['signa_transients'] ) ? $GLOBALS['signa_transients'][ $key ] : false;
}

function delete_transient( $key ): bool {
	unset( $GLOBALS['signa_transients'][ $key ] );

	return true;
}


/*
 * A `$wpdb` that answers instead of dying.
 *
 * The log store talks to MySQL through the global, and every admin screen draws
 * numbers that come from it. Rendering a screen in a test therefore needs a
 * database that returns empty rows rather than a fatal on `null->get_results()`.
 */
class Signa_Wpdb_Stub {

	/** @var string */
	public $prefix = 'wp_';

	/** @var string */
	public $usermeta = 'wp_usermeta';

	/** @var string */
	public $users = 'wp_users';

	/** @var int */
	public $insert_id = 0;

	/** @var array<int,array<string,mixed>> */
	public $rows = array();

	/** @var array<int,array<string,mixed>> */
	public $writes = array();

	/** @var string[] Every statement that reached the database, in order. */
	public $sql = array();

	/**
	 * Counters the stub keeps for `StateStore::bump()`.
	 *
	 * The throttle's numbers live in a table and come back through a second
	 * query, so a test that wants to see "the fourth send is refused" needs the
	 * two statements to agree with each other. This is that agreement, and
	 * nothing else: the INSERT adds one, the SELECT reads the total.
	 *
	 * @var array<string,int>
	 */
	public $counters = array();

	/** @var array<int,mixed> Answers for get_var(), in order. */
	public $varQueue = array();

	/** @var int What query() reports as "rows affected". */
	public $queryReturn = 0;

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
		$this->sql[] = (string) $query;

		if ( array() !== $this->varQueue ) {
			return array_shift( $this->varQueue );
		}

		$key = $this->stateKey( (string) $query );

		if ( '' !== $key && false !== strpos( $query, 'SELECT hits FROM' ) ) {
			return isset( $this->counters[ $key ] ) ? $this->counters[ $key ] : 0;
		}

		return 0;
	}

	public function query( $query = '' ) {
		$this->sql[] = (string) $query;

		// The one statement whose effect a test needs to see: a counter going up.
		if ( false !== strpos( (string) $query, 'ON DUPLICATE KEY UPDATE' ) && false !== strpos( (string) $query, 'hits = IF(' ) ) {
			$key = $this->stateKey( (string) $query );

			if ( '' !== $key ) {
				$this->counters[ $key ] = ( isset( $this->counters[ $key ] ) ? (int) $this->counters[ $key ] : 0 ) + 1;
			}
		}

		return $this->queryReturn;
	}

	/**
	 * The `state_key` value a statement carries, if it carries one.
	 */
	private function stateKey( string $query ): string {
		if ( false === strpos( $query, 'signa_state' ) ) {
			return '';
		}

		$key = '';

		if ( preg_match( "/state_key = '([^']*)'/", $query, $found ) ) {
			$key = $found[1];
		} elseif ( preg_match( "/state_key = ([^\\s']+)/", $query, $found ) ) {
			// `prepare()` leaves an unquoted placeholder alone; WordPress adds
			// the quotes, this stub does not.
			$key = trim( $found[1], "'" );
		} elseif ( preg_match( '/VALUES \\(\\s*([^\\s,]+)/', $query, $found ) ) {
			$key = trim( $found[1], "'" );
		}

		return $key;
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

$GLOBALS['wpdb'] = new Signa_Wpdb_Stub();

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

	if ( empty( $GLOBALS['signa_hooks'][ $tag ] ) ) {
		return $value;
	}

	foreach ( (array) $GLOBALS['signa_hooks'][ $tag ] as $callback ) {
		$value = call_user_func_array( $callback, array_merge( array( $value ), $extra ) );
	}

	return $value;
}

function do_action( $tag ) {
	$GLOBALS['signa_actions'][] = $tag;
}

/**
 * @param string $type
 * @return string
 */
function current_time( $type, $gmt = 0 ) {
	return 'mysql' === $type ? gmdate( 'Y-m-d H:i:s' ) : time();
}

function wp_salt( $scheme = 'auth' ): string {
	return hash( 'sha256', 'signa-test-' . $scheme );
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
 * The two WordPress cleaners the settings sanitizer leans on.
 *
 * They are stubbed rather than approximated because a test about a *font*
 * value is really a test about what that cleaner lets through: `wp_strip_all_tags()`
 * removing a `<script>` tag is the security property, and `sanitize_hex_color()`
 * refusing "red" is why the surface colour has a fallback at all.
 */
function wp_strip_all_tags( $string, $remove_breaks = false ) {
	$string = strip_tags( (string) $string );

	if ( $remove_breaks ) {
		$string = preg_replace( '/[\r\n\t ]+/', ' ', $string );
	}

	return trim( (string) $string );
}

/**
 * @param string $color
 * @return string|null
 */
function sanitize_hex_color( $color ) {
	if ( ! is_string( $color ) ) {
		return null;
	}

	return preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $color ) ? $color : null;
}

/**
 * The active theme, as far as the diagnostics are concerned.
 */
class WP_Theme { // phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps -- WordPress class.

	/** @var string */
	private $name;

	public function __construct( string $name = 'Signa Test Theme' ) {
		$this->name = $name;
	}

	/**
	 * @param string $header
	 * @return string
	 */
	public function get( $header ) {
		if ( 'Name' === $header ) {
			return $this->name;
		}

		return 'Version' === $header ? '1.0.0' : '';
	}

	/**
	 * The parent theme's directory name.
	 */
	public function get_template(): string {
		return get_template();
	}
}

/**
 * @return WP_Theme
 */
/**
 * The template (parent theme) directory name, as WordPress reports it.
 */
function get_template(): string {
	return isset( $GLOBALS['signa_template'] ) ? (string) $GLOBALS['signa_template'] : 'signa-test-theme';
}

function wp_get_theme( $stylesheet = '' ) {
	unset( $stylesheet );

	return new WP_Theme();
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
	$bag = isset( $GLOBALS['signa_user_meta'][ (int) $userId ] ) ? $GLOBALS['signa_user_meta'][ (int) $userId ] : array();

	return array_key_exists( $key, $bag ) ? $bag[ $key ] : '';
}

/**
 * @param int    $userId
 * @param string $key
 * @param mixed  $value
 */
function update_user_meta( $userId, $key, $value ) {
	$GLOBALS['signa_user_meta'][ (int) $userId ][ $key ] = $value;

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

/**
 * Is somebody signed in? Read from the same global the other stubs use.
 */
function is_user_logged_in(): bool {
	return isset( $GLOBALS['signa_current_user'] ) && (int) $GLOBALS['signa_current_user'] > 0;
}

function get_current_user_id(): int {
	return isset( $GLOBALS['signa_current_user'] ) ? (int) $GLOBALS['signa_current_user'] : 0;
}

function current_user_can( $capability ): bool {
	return ! empty( $GLOBALS['signa_may_manage'] );
}

function add_filter( $tag, $callback, $priority = 10, $accepted = 1 ) {
	$GLOBALS['signa_hooks'][ $tag ][] = $callback;

	// Recorded alongside, so a test can ask *when* something runs.
	$GLOBALS['signa_hook_priorities'][ $tag ][] = array(
		'priority' => (int) $priority,
		'callback' => $callback,
	);

	return true;
}

function add_action( $tag, $callback, $priority = 10, $accepted = 1 ) {
	return add_filter( $tag, $callback, $priority, $accepted );
}

/**
 * @param string $action
 */
function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
	if ( empty( $GLOBALS['signa_nonce_ok'] ) ) {
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
	return isset( $GLOBALS['signa_referer'] ) ? $GLOBALS['signa_referer'] : '';
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

$GLOBALS['signa_http_requests'] = array();
$GLOBALS['signa_http_reply']    = null;

/**
 * The next answer any wp_remote_* call will get.
 *
 * @param mixed $reply Array with 'response' => array( 'code' => int ), 'body' => string, or a WP_Error.
 */
function signa_reply( $reply ): void {
	$GLOBALS['signa_http_reply'] = $reply;
}

/**
 * @return array<int,array{method:string,url:string,args:array<string,mixed>}>
 */
function signa_requests(): array {
	return $GLOBALS['signa_http_requests'];
}

function signa_forget_requests(): void {
	$GLOBALS['signa_http_requests'] = array();
}

/**
 * Drop every filter a test registered, so one group cannot change the next.
 */
function signa_forget_filters(): void {
	$GLOBALS['signa_hooks']           = array();
	$GLOBALS['signa_hook_priorities'] = array();
}

/**
 * @param mixed $reply
 * @return array|WP_Error
 */
function signa_http( string $method, string $url, array $args = array() ) {
	$GLOBALS['signa_http_requests'][] = array(
		'method' => $method,
		'url'    => $url,
		'args'   => $args,
	);

	$reply = $GLOBALS['signa_http_reply'];

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
	return signa_http( 'POST', $url, $args );
}

/**
 * @return array|WP_Error
 */
function wp_remote_get( string $url, array $args = array() ) {
	return signa_http( 'GET', $url, $args );
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
		if ( 0 !== strpos( $class, 'Signa\\' ) ) {
			return;
		}

		$file = SIGNA_PATH . 'src/' . str_replace( '\\', '/', substr( $class, 6 ) ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

/* -------------------------------------------------------------------------
 * Tiny assertions
 * ---------------------------------------------------------------------- */

function signa_check( string $label, bool $ok ): void {
	++$GLOBALS['signa_checks'];

	if ( $ok ) {
		echo "  ok    {$label}\n";
		return;
	}

	++$GLOBALS['signa_failures'];
	echo "  FAIL  {$label}\n";
}

/**
 * @param mixed $expected
 * @param mixed $actual
 */
function signa_same( string $label, $expected, $actual ): void {
	signa_check(
		$label,
		$expected === $actual
	);

	if ( $expected !== $actual ) {
		echo '        expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
	}
}

function signa_start( string $name ): void {
	echo "\n== {$name} ==\n";
	$GLOBALS['signa_options']    = array();
	$GLOBALS['signa_transients'] = array();
}

function signa_finish(): void {
	$checks   = (int) $GLOBALS['signa_checks'];
	$failures = (int) $GLOBALS['signa_failures'];

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
	$GLOBALS['signa_settings_errors'][] = array( $setting, $code, $message, $type );
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
/**
 * Late CSS, collected the way `wp_add_inline_style()` collects it.
 */
function wp_add_inline_style( $handle, $data ): bool {
	$GLOBALS['signa_inline_styles'][ $handle ][] = (string) $data;

	return true;
}

/** Echoing translator, as templates use it. */
function esc_html_e( $text, $domain = 'default' ) {
	unset( $domain );

	echo esc_html( $text );
}

/** No theme provides template overrides in these tests. */
function locate_template( $templates, $load = false, $require_once = true ) {
	unset( $templates, $load, $require_once );

	return '';
}

/**
 * A counter, as `wp_unique_id()` keeps one — enough to make ids unique per page.
 */
function wp_unique_id( $prefix = '' ) {
	static $id = 0;

	return (string) $prefix . ( ++$id );
}

/**
 * The queried object, when a test sets one.
 */
function get_queried_object() {
	return isset( $GLOBALS['signa_queried_object'] ) ? $GLOBALS['signa_queried_object'] : null;
}

/** Does the queried content contain a shortcode? */
function has_shortcode( $content, $tag ) {
	unset( $tag );

	return is_string( $content ) && '' !== $content;
}

/** WooCommerce page checks — no WooCommerce pages exist in these tests. */
function is_account_page(): bool {
	return ! empty( $GLOBALS['signa_is_account_page'] );
}

function is_checkout(): bool {
	return ! empty( $GLOBALS['signa_is_checkout'] );
}

/** A signed-in user, as far as templates are concerned. */
function wp_get_current_user() {
	$user = new \stdClass();
	$user->ID         = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
	$user->display_name = 'Test User';

	return $user;
}

/** `wp_validate_redirect()` — the same host only, as WordPress does it. */
function wp_validate_redirect( $location, $fallback = '' ) {
	if ( ! is_string( $location ) || '' === $location ) {
		return $fallback;
	}

	return 0 === strpos( $location, 'https://example.test' ) ? $location : $fallback;
}

/** Media modal: not opened in any request these tests model. */
function wp_enqueue_media( $args = array() ) {
	unset( $args );
}

/**
 * `sanitize_html_class()` — enough of it for class names built from settings.
 */
function sanitize_html_class( $class, $fallback = '' ) {
	$class = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $class );

	return '' === $class ? (string) $fallback : $class;
}

/** Is this an admin request? Nothing in these tests is. */
function is_admin(): bool {
	return ! empty( $GLOBALS['signa_admin_screen'] );
}

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

class Signa_Wp_Roles_Stub {

	/** @var array<string,array{name:string}> What `WP_Roles::$roles` holds. */
	public $roles = array(
		'administrator' => array( 'name' => 'Administrator' ),
		'editor'        => array( 'name' => 'Editor' ),
		'subscriber'    => array( 'name' => 'Subscriber' ),
	);

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

function translate_user_role( $name ): string {
	return (string) $name;
}

function wp_roles(): Signa_Wp_Roles_Stub {
	return new Signa_Wp_Roles_Stub();
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
/**
 * WordPress' own signature: `( key, value, url )` or `( array, url )`.
 *
 * The single-key form used to fall through and return the URL untouched, which
 * is worse than a missing stub: the plugin asked for a cache-busting version
 * and the test quietly agreed there was none.
 */
function add_query_arg( $args, $value = '', string $url = '' ): string {
	if ( is_array( $args ) ) {
		$url   = (string) $value;
		$query = http_build_query( $args );
	} elseif ( '' === $url ) {
		// Two arguments: key, url.
		$url   = (string) $value;
		$query = '';
	} else {
		$query = rawurlencode( (string) $args ) . '=' . rawurlencode( (string) $value );
	}

	if ( '' === $query ) {
		return $url;
	}

	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $query;
}

/**
 * The REST root, as `rest_url()` builds it.
 */
function rest_url( string $path = '' ): string {
	return 'https://example.test/wp-json/' . ltrim( $path, '/' );
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
