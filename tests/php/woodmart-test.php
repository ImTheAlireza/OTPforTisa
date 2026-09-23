<?php
/**
 * WoodMart's sign-in panel, and what happens to it in our hands.
 *
 * The theme prints that panel on its own footer hook and gives no filter for
 * the markup, so the integration wraps the hook: buffer opened one priority
 * before the theme's callback, closed one after, and the WooCommerce form
 * inside the captured panel swapped for the plugin's OTP form. What has to be
 * true for that to be safe — and is checked here — is:
 *
 *   1. only the panel is touched: any other output captured in between is
 *      echoed back byte for byte, and output without a panel is returned
 *      untouched;
 *   2. the swap is real: the theme's `woocommerce-form-login` is gone in
 *      `replace` mode, still there in `append` mode, and the plugin's own form
 *      (the same markup the shortcode prints, with its REST endpoint and
 *      captcha config) is in its place;
 *   3. the priority is read from the hook registry, so a theme update that
 *      moves the callback is followed rather than broken;
 *   4. the integration is switchable, does nothing at all without WoodMart, and
 *      writes no file anywhere inside the theme.
 *
 * @package TisaOtp\Tests
 */

require __DIR__ . '/bootstrap.php';

use TisaOtp\Captcha\Manager;
use TisaOtp\Config\Settings;
use TisaOtp\Front\Assets;
use TisaOtp\Front\FormRenderer;
use TisaOtp\Integrations\WoodMart;
use TisaOtp\Log\Logger;
use TisaOtp\Log\LogStore;
use TisaOtp\Log\Redactor;
use TisaOtp\Registration\FieldSchema;
use TisaOtp\Support\View;

/* -------------------------------------------------------------------------
 * The theme, as far as this process is concerned
 */

/*
 * These stubs are the theme's public surface: a name check, the header builder
 * settings, and the callback the integration looks for in the hook registry.
 */
define( 'WOODMART_THEMERROOT', '/themes/woodmart' );

// WoodMart is a WooCommerce theme; the integration asks for both.
if ( ! class_exists( 'WooCommerce' ) ) {
	class WooCommerce { // phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps -- WooCommerce class.
		/** @var string */
		public $version = '9.0.0';
	}
}

$GLOBALS['tisa_template'] = 'woodmart';

function woodmart_woocommerce_installed(): bool {
	return true;
}

/** Header builder settings: the side login dropdown, as WoodMart stores them. */
$GLOBALS['tisa_whb'] = array( 'account' => array( 'login_dropdown' => true, 'form_display' => 'side' ) );

function whb_get_settings(): array {
	return $GLOBALS['tisa_whb'];
}

/** The theme's own callback, registered the way WoodMart registers it. */
function woodmart_sidebar_login_form(): void {
	echo tisa_woodmart_panel();
}

/**
 * The panel markup, copied in shape from the theme (6.x): the wrapper, the
 * heading, the login form, and the "create an account" block.
 */
function tisa_woodmart_panel(): string {
	return <<<'HTML'
<div class="login-form-side wd-side-hidden wd-right">
	<div class="widget-heading">
		<span class="title">Sign in</span>
	</div>
	<form method="post" class="login woocommerce-form woocommerce-form-login hidden-form">
		<p class="form-row form-row-username"><input type="text" name="username"></p>
		<p class="form-row form-row-password"><input type="password" name="password"></p>
		<p class="form-row"><button type="submit" class="button woocommerce-form-login__submit">Log in</button></p>
	</form>
	<div class="create-account-question">
		<p>No account yet?</p>
		<a href="/my-account/?action=register" class="btn create-account-button">Create an Account</a>
	</div>
</div>
HTML;
}

/**
 * A WoodMart integration wired to fresh settings.
 */
function tisa_woodmart( array $extra = array() ): WoodMart {
	$GLOBALS['tisa_options']['tisa_otp_settings'] = array_merge(
		array(
			'captcha_provider'   => 'none',
			'captcha_site_key'   => '',
			'captcha_secret_key' => '',
			'woodmart_sidebar'   => '1',
			'woodmart_mode'      => 'replace',
			'login_redirect'     => '',
		),
		$extra
	);

	$settings = new Settings();
	$logs     = new LogStore( $settings );
	$captcha  = new Manager( $settings, new Logger( $settings, new Redactor(), $logs ) );
	$assets   = new Assets( $settings, $captcha );

	return new WoodMart(
		$settings,
		new FormRenderer( $settings, new FieldSchema( $settings ), $captcha, new View(), $assets ),
		$assets
	);
}

/** Not signed in, not in the admin: the state the panel exists in. */
function tisa_woodmart_visitor(): void {
	$GLOBALS['tisa_current_user'] = 0;
	$GLOBALS['tisa_admin_screen'] = false;
}

/* -------------------------------------------------------------------------
 * 1. Detection, and doing nothing without the theme
 */

$GLOBALS['tisa_options'] = array();
tisa_woodmart_visitor();

$woodmart = tisa_woodmart();

tisa_check( 'WoodMart is detected through its own functions', WoodMart::detected() );
tisa_check( 'and the panel is wanted by default', true === $woodmart->active() );
tisa_check( 'the integration can be switched off', false === tisa_woodmart( array( 'woodmart_sidebar' => '0' ) )->active() );
tisa_check( 'and follows the plugin switch as well', false === tisa_woodmart( array( 'enabled' => '0' ) )->active() );
tisa_check( 'replace is the default mode', 'replace' === $woodmart->mode() );
tisa_check( 'append is the other mode', 'append' === tisa_woodmart( array( 'woodmart_mode' => 'append' ) )->mode() );
tisa_check( 'a nonsense mode falls back to replace', 'replace' === tisa_woodmart( array( 'woodmart_mode' => 'delete' ) )->mode() );

/* -------------------------------------------------------------------------
 * 2. Only the panel is touched
 */

$panel  = tisa_woodmart_panel();
$before = "<!-- somebody else's footer output -->\n";

$GLOBALS['wpdb']->writes = array();
$GLOBALS['tisa_options']['tisa_otp_settings'] = array( 'woodmart_sidebar' => '1' );

$integration = tisa_woodmart();
$swapped     = $integration->swap( $before . $panel . '<div id="cookie-banner">cookies</div>' );

tisa_check( 'output that is not the panel is returned untouched', $before . '<div id="cookie-banner">cookies</div>' === $integration->swap( $before . '<div id="cookie-banner">cookies</div>' ) );
tisa_check( 'a second pass does not inject twice', $integration->swap( $panel ) === $panel );
tisa_check( 'and the untouched case keeps every byte', false !== strpos( $swapped, '<div id="cookie-banner">cookies</div>' ) && 0 === strpos( $swapped, $before ) );
tisa_check( 'the panel itself survives', false !== strpos( $swapped, 'login-form-side' ) && false !== strpos( $swapped, 'widget-heading' ) );
tisa_check( 'and so does the theme\'s create-an-account block', false !== strpos( $swapped, 'create-account-question' ) );

/* -------------------------------------------------------------------------
 * 3. The swap is real: their form out, ours in
 */

tisa_check( 'the theme\'s username/password form is gone', false === strpos( $swapped, 'woocommerce-form-login' ) );
tisa_check( 'and no password field is left behind', false === strpos( $swapped, 'name="password"' ) );
tisa_check( 'the plugin\'s form is in its place', false !== strpos( $swapped, 'data-tisa-form' ) );
tisa_check( 'where the theme\'s form was — between heading and create-account block', strpos( $swapped, 'widget-heading' ) < strpos( $swapped, 'data-tisa-form' ) && strpos( $swapped, 'data-tisa-form' ) < strpos( $swapped, 'create-account-question' ) );
tisa_check( 'it is the same form the shortcode renders', false !== strpos( $swapped, 'tisa-otp tisa-skin' ) && false !== strpos( $swapped, 'tisa-otp__form' ) );
tisa_check( 'carrying the sidebar class, so its CSS can be scoped', false !== strpos( $swapped, 'tisa-otp--woodmart' ) );
tisa_check( 'it talks to the same REST route as everywhere else', false !== strpos( $swapped, 'data-endpoint=' ) );
tisa_check( 'with the same form token and nonce fields', false !== strpos( $swapped, 'data-form-token=' ) && false !== strpos( $swapped, 'data-nonce=' ) );
tisa_check( 'and the honeypot the guards read', false !== strpos( $swapped, 'tisa_hp' ) );
tisa_check( 'a marker says where the block came from', false !== strpos( $swapped, WoodMart::MARKER ) );
tisa_check( 'the panel is a single panel, not two', 1 === substr_count( $swapped, 'login-form-side' ) );

/* --- append mode keeps password login available -------------------------- */

$appended = tisa_woodmart( array( 'woodmart_mode' => 'append' ) )->swap( $panel );

tisa_check( 'in append mode the theme\'s form stays', false !== strpos( $appended, 'woocommerce-form-login' ) );
tisa_check( 'and the OTP form is added above the create-account block', strpos( $appended, 'data-tisa-form' ) < strpos( $appended, 'create-account-question' ) );
tisa_check( 'after the theme\'s own form, not inside it', strpos( $appended, 'woocommerce-form-login' ) < strpos( $appended, 'data-tisa-form' ) );
tisa_check(
	'so nothing nests a form inside a form',
	2 === substr_count( $appended, '<form' ) && 2 === substr_count( $appended, '</form>' ) && strpos( $appended, '</form>' ) < strpos( $appended, 'data-tisa-form' )
);

/* -------------------------------------------------------------------------
 * 4. A panel rendered differently is not mangled
 */

$noForm = '<div class="login-form-side"><div class="wd-heading">Sign in</div><div class="create-account-question">x</div></div>';
$handled = tisa_woodmart()->swap( $noForm );

tisa_check( 'a panel without the theme\'s form still gets the OTP form', false !== strpos( $handled, 'data-tisa-form' ) );
tisa_check( 'in the right place', strpos( $handled, 'wd-heading' ) < strpos( $handled, 'data-tisa-form' ) );

$noAnchor = '<div class="login-form-side"><p>nothing to hook onto</p></div>';

tisa_check( 'a panel with no anchor at all is left exactly as it was', $noAnchor === tisa_woodmart()->swap( $noAnchor ) );

/* -------------------------------------------------------------------------
 * 5. Registering the buffer: the priority comes from the theme, not from us
 */

/**
 * WordPress' hook registry, in the shape the integration reads it.
 *
 * @param array<string,int> $callbacks Hook => priority.
 */
function tisa_woodmart_registry( array $callbacks ): void {
	$GLOBALS['wp_filter'] = array();

	foreach ( $callbacks as $hook => $priority ) {
		$registry            = new \stdClass();
		$registry->callbacks = array(
			(int) $priority => array(
				'woodmart_sidebar_login_form' => array( 'function' => 'woodmart_sidebar_login_form', 'accepted_args' => 0 ),
			),
		);

		$GLOBALS['wp_filter'][ $hook ] = $registry;
	}
}

/**
 * Did the integration register `open`/`close` around the theme's callback?
 *
 * @param string $hook     Hook to look at.
 * @param int    $priority The priority the theme prints the panel at.
 * @return bool
 */
function tisa_woodmart_wraps( string $hook, int $priority ): bool {
	$wanted = array( $priority - 1, $priority + 1 );
	$seen   = array();

	foreach ( (array) $GLOBALS['tisa_hook_priorities'][ $hook ] as $entry ) {
		if ( is_array( $entry['callback'] ) && 'close' === $entry['callback'][1] ) {
			$seen['close'] = $entry['priority'];
		}

		if ( is_array( $entry['callback'] ) && 'open' === $entry['callback'][1] ) {
			$seen['open'] = $entry['priority'];
		}
	}

	return isset( $seen['open'], $seen['close'] ) && $seen['open'] === $wanted[0] && $seen['close'] === $wanted[1];
}

tisa_forget_filters();

/* WoodMart 7.x/8.x: the panel is printed on woodmart_before_wp_footer at 200. */
tisa_woodmart_registry( array( 'woodmart_before_wp_footer' => 200 ) );
tisa_woodmart()->wrap();

tisa_check( 'the buffer opens one priority before the theme prints', tisa_woodmart_wraps( 'woodmart_before_wp_footer', 200 ) );

/* WoodMart 6.x: the very same callback on wp_footer at 160. */
tisa_forget_filters();
tisa_woodmart_registry( array( 'wp_footer' => 160 ) );
tisa_woodmart()->wrap();

tisa_check( 'an older theme prints it on wp_footer, and is wrapped there instead', tisa_woodmart_wraps( 'wp_footer', 160 ) );
tisa_check( 'and nothing is hooked on the hook the theme does not use', ! isset( $GLOBALS['tisa_hooks']['woodmart_before_wp_footer'] ) );

/* A future release that moves the priority again. */
tisa_forget_filters();
tisa_woodmart_registry( array( 'woodmart_before_wp_footer' => 215 ) );
tisa_woodmart()->wrap();

tisa_check( 'a theme update that moves the priority is followed, not broken', tisa_woodmart_wraps( 'woodmart_before_wp_footer', 215 ) );

/* No callback in the registry: the known windows are used as a fallback. */
tisa_forget_filters();
$GLOBALS['wp_filter'] = array();
tisa_woodmart()->wrap();

tisa_check( 'with nothing to discover, the known windows are still covered', tisa_woodmart_wraps( 'wp_footer', 160 ) && tisa_woodmart_wraps( 'woodmart_before_wp_footer', 200 ) );

/* A logged-in visitor, or a switched-off integration: no hooks at all. */
tisa_forget_filters();
tisa_woodmart( array( 'woodmart_sidebar' => '0' ) )->wrap();

tisa_check( 'a switched-off integration hooks nothing', array() === $GLOBALS['tisa_hooks'] );

$GLOBALS['tisa_current_user'] = 7;
tisa_forget_filters();
tisa_woodmart()->wrap();

tisa_check( 'and a signed-in visitor gets no sidebar at all', array() === $GLOBALS['tisa_hooks'] );
tisa_woodmart_visitor();

/* -------------------------------------------------------------------------
 * 6. The header builder decides: no side form, no work
 */

$GLOBALS['tisa_whb'] = array( 'account' => array( 'login_dropdown' => true, 'form_display' => 'page' ) );

tisa_check( 'a header that does not use the side form is left alone', false === tisa_woodmart()->willRender() );

tisa_forget_filters();
tisa_woodmart()->wrap();

tisa_check( 'and nothing is buffered for it', array() === $GLOBALS['tisa_hooks'] );

$GLOBALS['tisa_whb'] = array( 'account' => array( 'login_dropdown' => true, 'form_display' => 'side' ) );

tisa_check( 'with the side form chosen, the integration runs', true === tisa_woodmart()->willRender() );

/* -------------------------------------------------------------------------
 * 7. The redirect, and the promise that no theme file is written
 */

tisa_check(
	'the theme\'s redirect is replaced when one is configured',
	'https://example.test/my-account/' === tisa_woodmart( array( 'login_redirect' => 'https://example.test/my-account/' ) )->redirect( 'https://example.test/shop/' )
);

tisa_check( 'and left alone when none is', 'https://example.test/shop/' === tisa_woodmart()->redirect( 'https://example.test/shop/' ) );

tisa_check(
	'a switched-off integration does not touch the redirect either',
	'https://example.test/shop/' === tisa_woodmart( array( 'woodmart_sidebar' => '0', 'login_redirect' => 'https://example.test/my-account/' ) )->redirect( 'https://example.test/shop/' )
);

/* --- the promise --------------------------------------------------------- */

$source = (string) file_get_contents( TISA_OTP_PATH . 'src/Integrations/WoodMart.php' );

tisa_check( 'the integration never writes into the theme', false === strpos( $source, 'file_put_contents' ) && false === strpos( $source, 'WP_Filesystem' ) && false === strpos( $source, 'fopen(' ) );
tisa_check( 'and never reaches for a theme template path', false === strpos( $source, 'get_template_directory' ) && false === strpos( $source, 'get_stylesheet_directory' ) );
tisa_check( 'it calls the theme only through functions that exist', 3 <= substr_count( $source, 'function_exists(' ) );
tisa_check( 'it never calls the theme\'s own login form', false === strpos( $source, 'woodmart_login_form(' ) && false === strpos( $source, 'require WOODMART' ) );
tisa_check( 'it overrides no theme function', false === strpos( $source, 'function woodmart_' ) );

/* -------------------------------------------------------------------------
 * 8. The panel and the test say what is true
 */

$woodmart = tisa_woodmart( array( 'woodmart_sidebar' => '1' ) );
$screen   = (string) file_get_contents( TISA_OTP_PATH . 'src/Admin/SettingsScreen.php' );
$selfTest = (string) file_get_contents( TISA_OTP_PATH . 'src/Diagnostics/SelfTest.php' );

tisa_check( 'the settings screen has the switch', false !== strpos( $screen, "'woodmart_sidebar'" ) );
tisa_check( 'and the mode control', false !== strpos( $screen, "'woodmart_mode'" ) );
tisa_check( 'and says when the theme is not there', false !== strpos( $screen, 'WoodMart::detected()' ) );
tisa_check( 'the store test reports the panel state', false !== strpos( $selfTest, 'سایدبار ورود وودمارت' ) );
tisa_check( 'and the theme\'s version with it', false !== strpos( $selfTest, 'WoodMart::detected()' ) && false !== strpos( $selfTest, 'themeVersion' ) );

$handled = tisa_woodmart()->swap( tisa_woodmart_panel() );
$selfTestNeedsTheForm = false !== strpos( $handled, 'tisa-otp__form' ) && false === strpos( $handled, 'login woocommerce-form' );

tisa_check( 'and the swap does what the panel claims it does', $selfTestNeedsTheForm );

tisa_finish();
