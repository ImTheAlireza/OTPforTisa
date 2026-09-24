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
 * @package Signa\Tests
 */

require __DIR__ . '/bootstrap.php';

use Signa\Captcha\Manager;
use Signa\Config\Settings;
use Signa\Front\Assets;
use Signa\Front\FormRenderer;
use Signa\Integrations\WoodMart;
use Signa\Log\Logger;
use Signa\Log\LogStore;
use Signa\Log\Redactor;
use Signa\Registration\FieldSchema;
use Signa\Support\View;

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

$GLOBALS['signa_template'] = 'woodmart';

function woodmart_woocommerce_installed(): bool {
	return true;
}

/** Header builder settings: the side login dropdown, as WoodMart stores them. */
$GLOBALS['signa_whb'] = array( 'account' => array( 'login_dropdown' => true, 'form_display' => 'side' ) );

function whb_get_settings(): array {
	return $GLOBALS['signa_whb'];
}

/** The theme's own callback, registered the way WoodMart registers it. */
function woodmart_sidebar_login_form(): void {
	echo signa_woodmart_panel();
}

/**
 * The panel markup, copied in shape from the theme (6.x): the wrapper, the
 * heading, the login form, and the "create an account" block.
 */
function signa_woodmart_panel( string $extra = '' ): string {
	return str_replace( '</div>\nHTML', $extra . '</div>\nHTML', signa_woodmart_panel_base() );
}

/**
 * The panel WoodMart 8.x prints: `login-form-side wd-side-hidden woocommerce`,
 * a `wd-heading`, the login form, and the sign-up block the owner wants gone.
 *
 * The block carries the avatar as a CSS pseudo-element on the same element, so
 * one element is all three things the eye sees.
 */
function signa_woodmart_panel_base(): string {
	return <<<'HTML'
<div class="login-form-side wd-side-hidden woocommerce wd-right color-scheme-light">
	<div class="wd-heading">
		<span class="title">Sign in</span>
		<div class="close-side-widget"><a href="#" rel="nofollow">Close</a></div>
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
function signa_woodmart( array $extra = array() ): WoodMart {
	$GLOBALS['signa_options']['signa_settings'] = array_merge(
		array(
			'captcha_provider'       => 'none',
			'captcha_site_key'       => '',
			'captcha_secret_key'     => '',
			'woodmart_sidebar'       => '1',
			'woodmart_mode'          => 'replace',
			'woodmart_account_block' => '1',
			'registration_enabled'   => '1',
			'auth_mode'              => 'smart',
			'login_redirect'         => '',
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

/**
 * A `Settings` object built from these values.
 */
function signa_woodmart_settings( array $values ): Settings {
	$GLOBALS['signa_options']['signa_settings'] = array_merge(
		array(
			'woodmart_account_block' => '1',
			'registration_enabled'   => '1',
			'auth_mode'              => 'smart',
		),
		$values
	);

	return new Settings();
}

/** Not signed in, not in the admin: the state the panel exists in. */
function signa_woodmart_visitor(): void {
	$GLOBALS['signa_current_user'] = 0;
	$GLOBALS['signa_admin_screen'] = false;
}

/* -------------------------------------------------------------------------
 * 1. Detection, and doing nothing without the theme
 */

$GLOBALS['signa_options'] = array();
signa_woodmart_visitor();

$woodmart = signa_woodmart();

signa_check( 'WoodMart is detected through its own functions', WoodMart::detected() );
signa_check( 'and the panel is wanted by default', true === $woodmart->active() );
signa_check( 'the integration can be switched off', false === signa_woodmart( array( 'woodmart_sidebar' => '0' ) )->active() );
signa_check( 'and follows the plugin switch as well', false === signa_woodmart( array( 'enabled' => '0' ) )->active() );
signa_check( 'replace is the default mode', 'replace' === $woodmart->mode() );
signa_check( 'append is the other mode', 'append' === signa_woodmart( array( 'woodmart_mode' => 'append' ) )->mode() );
signa_check( 'a nonsense mode falls back to replace', 'replace' === signa_woodmart( array( 'woodmart_mode' => 'delete' ) )->mode() );

/* -------------------------------------------------------------------------
 * 2. Only the panel is touched
 */

$panel  = signa_woodmart_panel();
$before = "<!-- somebody else's footer output -->\n";

$GLOBALS['wpdb']->writes = array();
$GLOBALS['signa_options']['signa_settings'] = array( 'woodmart_sidebar' => '1' );

$integration = signa_woodmart();
$swapped     = $integration->swap( $before . $panel . '<div id="cookie-banner">cookies</div>' );

signa_check( 'output that is not the panel is returned untouched', $before . '<div id="cookie-banner">cookies</div>' === $integration->swap( $before . '<div id="cookie-banner">cookies</div>' ) );
signa_check( 'a second pass does not inject twice', $integration->swap( $panel ) === $panel );
signa_check( 'and the untouched case keeps every byte', false !== strpos( $swapped, '<div id="cookie-banner">cookies</div>' ) && 0 === strpos( $swapped, $before ) );
signa_check( 'the panel itself survives', false !== strpos( $swapped, 'login-form-side' ) && false !== strpos( $swapped, 'wd-heading' ) );
signa_check( 'and so does the theme\'s close button', false !== strpos( $swapped, 'close-side-widget' ) );
signa_check( 'the theme\'s sign-up block is gone', false === strpos( $swapped, 'create-account-question' ) );
signa_check( 'with it, the avatar, the question and the link it carries', false === strpos( $swapped, 'create-account-button' ) && false === strpos( $swapped, 'No account yet' ) );

/* -------------------------------------------------------------------------
 * 3. The swap is real: their form out, ours in
 */

signa_check( 'the theme\'s username/password form is gone', false === strpos( $swapped, 'woocommerce-form-login' ) );
signa_check( 'and no password field is left behind', false === strpos( $swapped, 'name="password"' ) );
signa_check( 'the plugin\'s form is in its place', false !== strpos( $swapped, 'data-signa-form' ) );
signa_check( 'where the theme\'s form was — after the heading, inside the panel', strpos( $swapped, 'wd-heading' ) < strpos( $swapped, 'data-signa-form' ) && strpos( $swapped, 'data-signa-form' ) < strrpos( $swapped, '</div>' ) );
signa_check( 'it is the same form the shortcode renders', false !== strpos( $swapped, 'signa signa-skin' ) && false !== strpos( $swapped, 'signa__form' ) );
signa_check( 'carrying the sidebar class, so its CSS can be scoped', false !== strpos( $swapped, 'signa--woodmart' ) );
signa_check( 'it talks to the same REST route as everywhere else', false !== strpos( $swapped, 'data-endpoint=' ) );
signa_check( 'with the same form token and nonce fields', false !== strpos( $swapped, 'data-form-token=' ) && false !== strpos( $swapped, 'data-nonce=' ) );
signa_check( 'and the honeypot the guards read', false !== strpos( $swapped, 'signa_hp' ) );
signa_check( 'a marker says where the block came from', false !== strpos( $swapped, WoodMart::MARKER ) );
signa_check( 'the panel is a single panel, not two', 1 === substr_count( $swapped, 'login-form-side' ) );

/* --- append mode keeps password login available -------------------------- */

$appended = signa_woodmart( array( 'woodmart_mode' => 'append' ) )->swap( $panel );

signa_check( 'in append mode the theme\'s form stays', false !== strpos( $appended, 'woocommerce-form-login' ) );
signa_check( 'and the OTP form is added where the sign-up block used to be', strpos( $appended, 'woocommerce-form-login' ) < strpos( $appended, 'data-signa-form' ) );
signa_check( 'the sign-up block is gone in append mode too', false === strpos( $appended, 'create-account-question' ) );
signa_check(
	'so nothing nests a form inside a form',
	2 === substr_count( $appended, '<form' ) && 2 === substr_count( $appended, '</form>' ) && strpos( $appended, '</form>' ) < strpos( $appended, 'data-signa-form' )
);

/* -------------------------------------------------------------------------
 * 3b. The theme's sign-up block: when it goes, and when it stays
 *
 * The rule is one line of plain sense: two ways to sign up in one drawer is one
 * too many, and the block is the only way to sign up when the plugin's own
 * registration is switched off.
 */

$panel = signa_woodmart_panel();

signa_check( 'the block goes by default', false === strpos( signa_woodmart()->swap( $panel ), 'create-account-question' ) );
signa_check( 'and the decision reads the same from outside', true === WoodMart::hidesAccountBlock( new Settings() ) );

signa_check(
	'it stays when the owner asked for it',
	false === WoodMart::hidesAccountBlock( signa_woodmart_settings( array( 'woodmart_account_block' => '0' ) ) )
);
signa_check(
	'and the block is really still there',
	false !== strpos( signa_woodmart( array( 'woodmart_account_block' => '0' ) )->swap( $panel ), 'create-account-question' )
);
signa_check(
	'it stays when the plugin cannot register at all',
	false === WoodMart::hidesAccountBlock( signa_woodmart_settings( array( 'registration_enabled' => '0' ) ) )
);
signa_check(
	'and when the form is login-only',
	false === WoodMart::hidesAccountBlock( signa_woodmart_settings( array( 'auth_mode' => 'login_only' ) ) )
);
signa_check(
	'so registration is never locked away behind a removed link',
	false !== strpos( signa_woodmart( array( 'registration_enabled' => '0' ) )->swap( $panel ), 'create-account-button' )
);
signa_check(
	'a form that only registers is the clearest case of all',
	true === WoodMart::hidesAccountBlock( signa_woodmart_settings( array( 'auth_mode' => 'register_only' ) ) )
);
signa_check(
	'and the form itself is still placed in the panel',
	false !== strpos( signa_woodmart( array( 'registration_enabled' => '0' ) )->swap( $panel ), 'data-signa-form' )
);

/* -------------------------------------------------------------------------
 * 4. A panel rendered differently is not mangled
 */

$noForm = '<div class="login-form-side"><div class="wd-heading">Sign in</div><div class="create-account-question">x</div></div>';
$handled = signa_woodmart()->swap( $noForm );

signa_check( 'a panel without the theme\'s form still gets the OTP form', false !== strpos( $handled, 'data-signa-form' ) );
signa_check( 'in the right place', strpos( $handled, 'wd-heading' ) < strpos( $handled, 'data-signa-form' ) );

$noAnchor = '<div class="login-form-side"><p>nothing to hook onto</p></div>';

signa_check( 'a panel with no anchor at all is left exactly as it was', $noAnchor === signa_woodmart()->swap( $noAnchor ) );
signa_check( 'and its own markup is not half-emptied either', false === strpos( signa_woodmart()->swap( $noAnchor ), WoodMart::MARKER ) );

/* --- a sign-up block with something inside it ---------------------------- */

$nested = '<div class="login-form-side"><div class="create-account-question"><p>No account yet?</p><div class="wd-icon"><span>x</span></div><a href="#" class="create-account-button">Create</a></div><p id="after-block">end</p></div>';
$cut    = signa_woodmart()->swap( $nested );

signa_check( 'a block with a nested element goes whole, not half', false === strpos( $cut, 'No account yet' ) && false === strpos( $cut, 'create-account-button' ) && false === strpos( $cut, 'wd-icon' ) );
signa_check( 'and what came after it stays exactly where it was', false !== strpos( $cut, '<p id="after-block">end</p>' ) );

/* -------------------------------------------------------------------------
 * 5. Registering the buffer: the priority comes from the theme, not from us
 */

/**
 * WordPress' hook registry, in the shape the integration reads it.
 *
 * @param array<string,int> $callbacks Hook => priority.
 */
function signa_woodmart_registry( array $callbacks ): void {
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
function signa_woodmart_wraps( string $hook, int $priority ): bool {
	$opened = false;
	$closed = false;

	foreach ( (array) $GLOBALS['signa_hook_priorities'][ $hook ] as $entry ) {
		if ( ! is_array( $entry['callback'] ) ) {
			continue;
		}

		$opened = $opened || ( 'open' === $entry['callback'][1] && $priority - 1 === $entry['priority'] );
		$closed = $closed || ( 'close' === $entry['callback'][1] && $priority + 1 === $entry['priority'] );
	}

	return $opened && $closed;
}

/**
 * How many windows were opened on a hook — one per priority we cover.
 */
function signa_woodmart_windows( string $hook ): int {
	$count = 0;

	foreach ( (array) $GLOBALS['signa_hook_priorities'][ $hook ] as $entry ) {
		if ( is_array( $entry['callback'] ) && 'open' === $entry['callback'][1] ) {
			$count++;
		}
	}

	return $count;
}

signa_forget_filters();

/* WoodMart 7.x/8.x: the panel is printed on woodmart_before_wp_footer at 200. */
signa_woodmart_registry( array( 'woodmart_before_wp_footer' => 200 ) );
signa_woodmart()->wrap();

signa_check( 'the buffer opens one priority before the theme prints', signa_woodmart_wraps( 'woodmart_before_wp_footer', 200 ) );

/* WoodMart 6.x: the very same callback on wp_footer at 160. */
signa_forget_filters();
signa_woodmart_registry( array( 'wp_footer' => 160 ) );
signa_woodmart()->wrap();

signa_check( 'an older theme prints it on wp_footer, and is wrapped there instead', signa_woodmart_wraps( 'wp_footer', 160 ) );
signa_check( 'and nothing is hooked on the hook the theme does not use', ! isset( $GLOBALS['signa_hooks']['woodmart_before_wp_footer'] ) );

/* A future release that moves the priority again. */
signa_forget_filters();
signa_woodmart_registry( array( 'woodmart_before_wp_footer' => 215 ) );
signa_woodmart()->wrap();

signa_check( 'a theme update that moves the priority is followed, not broken', signa_woodmart_wraps( 'woodmart_before_wp_footer', 215 ) );

/* No callback in the registry: the known windows are used as a fallback. */
signa_forget_filters();
$GLOBALS['wp_filter'] = array();
signa_woodmart()->wrap();

signa_check(
	'with nothing to discover, the known windows are still covered',
	signa_woodmart_wraps( 'wp_footer', 160 ) && signa_woodmart_wraps( 'wp_footer', 200 ) && signa_woodmart_wraps( 'woodmart_before_wp_footer', 160 ) && signa_woodmart_wraps( 'woodmart_before_wp_footer', 200 )
);
signa_check( 'four windows, no more', 2 === signa_woodmart_windows( 'wp_footer' ) && 2 === signa_woodmart_windows( 'woodmart_before_wp_footer' ) );

/* A logged-in visitor, or a switched-off integration: no hooks at all. */
signa_forget_filters();
signa_woodmart( array( 'woodmart_sidebar' => '0' ) )->wrap();

signa_check( 'a switched-off integration hooks nothing', array() === $GLOBALS['signa_hooks'] );

$GLOBALS['signa_current_user'] = 7;
signa_forget_filters();
signa_woodmart()->wrap();

signa_check( 'and a signed-in visitor gets no sidebar at all', array() === $GLOBALS['signa_hooks'] );
signa_woodmart_visitor();

/* -------------------------------------------------------------------------
 * 6. The header builder decides: no side form, no work
 */

$GLOBALS['signa_whb'] = array( 'account' => array( 'login_dropdown' => true, 'form_display' => 'page' ) );

signa_check( 'a header that does not use the side form is left alone', false === signa_woodmart()->willRender() );

signa_forget_filters();
signa_woodmart()->wrap();

signa_check( 'and nothing is buffered for it', array() === $GLOBALS['signa_hooks'] );

$GLOBALS['signa_whb'] = array( 'account' => array( 'login_dropdown' => true, 'form_display' => 'side' ) );

signa_check( 'with the side form chosen, the integration runs', true === signa_woodmart()->willRender() );

/* -------------------------------------------------------------------------
 * 6b. The account page, where the theme itself prints no panel
 */

$GLOBALS['signa_is_account_page'] = true;

signa_check( 'on the account page the theme prints nothing, so we do nothing', false === signa_woodmart()->willRender() );

$GLOBALS['signa_hooks'] = array();
signa_woodmart()->wrap();

signa_check( 'and nothing is buffered for a panel that will not be there', array() === $GLOBALS['signa_hooks'] );

$GLOBALS['signa_is_account_page'] = false;

signa_check( 'off that page, the panel is served again', true === signa_woodmart()->willRender() );

/* -------------------------------------------------------------------------
 * 7. The redirect, and the promise that no theme file is written
 */

signa_check(
	'the theme\'s redirect is replaced when one is configured',
	'https://example.test/my-account/' === signa_woodmart( array( 'login_redirect' => 'https://example.test/my-account/' ) )->redirect( 'https://example.test/shop/' )
);

signa_check( 'and left alone when none is', 'https://example.test/shop/' === signa_woodmart()->redirect( 'https://example.test/shop/' ) );

signa_check(
	'a switched-off integration does not touch the redirect either',
	'https://example.test/shop/' === signa_woodmart( array( 'woodmart_sidebar' => '0', 'login_redirect' => 'https://example.test/my-account/' ) )->redirect( 'https://example.test/shop/' )
);

/* --- the promise --------------------------------------------------------- */

$source = (string) file_get_contents( SIGNA_PATH . 'src/Integrations/WoodMart.php' );

$stylesheet = (string) file_get_contents( SIGNA_PATH . 'assets/css/front.css' );

signa_check( 'the theme\'s sign-up block is removed by markup, not hidden with CSS', false === strpos( $stylesheet, 'create-account-question' ) );
signa_check( 'the integration never writes into the theme', false === strpos( $source, 'file_put_contents' ) && false === strpos( $source, 'WP_Filesystem' ) && false === strpos( $source, 'fopen(' ) );
signa_check( 'and never reaches for a theme template path', false === strpos( $source, 'get_template_directory' ) && false === strpos( $source, 'get_stylesheet_directory' ) );
signa_check( 'it calls the theme only through functions that exist', 3 <= substr_count( $source, 'function_exists(' ) );
signa_check( 'it never calls the theme\'s own login form', false === strpos( $source, 'woodmart_login_form(' ) && false === strpos( $source, 'require WOODMART' ) );
signa_check( 'it overrides no theme function', false === strpos( $source, 'function woodmart_' ) );

/* -------------------------------------------------------------------------
 * 8. The panel and the test say what is true
 */

$woodmart = signa_woodmart( array( 'woodmart_sidebar' => '1' ) );
$screen   = (string) file_get_contents( SIGNA_PATH . 'src/Admin/SettingsScreen.php' );
$selfTest = (string) file_get_contents( SIGNA_PATH . 'src/Diagnostics/SelfTest.php' );

signa_check( 'the settings screen has the switch', false !== strpos( $screen, "'woodmart_sidebar'" ) );
signa_check( 'and the mode control', false !== strpos( $screen, "'woodmart_mode'" ) );
signa_check( 'and says when the theme is not there', false !== strpos( $screen, 'WoodMart::detected()' ) );
signa_check( 'the store test reports the panel state', false !== strpos( $selfTest, 'سایدبار ورود وودمارت' ) );
signa_check( 'and the sign-up block decision', false !== strpos( $selfTest, 'بخش «ساخت حساب» وودمارت' ) && false !== strpos( $selfTest, 'WoodMart::hidesAccountBlock' ) );
signa_check( 'the settings screen can turn the removal off', false !== strpos( $screen, "'woodmart_account_block'" ) );
signa_check( 'and the theme\'s version with it', false !== strpos( $selfTest, 'WoodMart::detected()' ) && false !== strpos( $selfTest, 'themeVersion' ) );

$handled = signa_woodmart()->swap( signa_woodmart_panel() );
$selfTestNeedsTheForm = false !== strpos( $handled, 'signa__form' ) && false === strpos( $handled, 'login woocommerce-form' );

signa_check( 'and the swap does what the panel claims it does', $selfTestNeedsTheForm );

signa_finish();
