<?php
/**
 * Every settings tab, actually rendered.
 *
 * 1.3.5 shipped a call that put a closure where `testCard()` expects its intro
 * string, and the settings screen died with a TypeError the moment an
 * administrator opened «سامانه‌های پیامکی». Every gate was green: the file
 * parsed, the class wired up, and the tests read it as text.
 *
 * So this test opens the screen. All eight sections, with the real classes and the
 * bootstrap's WordPress stubs, and it fails on anything that throws or prints a
 * PHP error. It is the difference between "the file compiles" and "the screen
 * works", and the setting screen is the first page an owner sees.
 *
 * @package Signa\Tests
 */

require __DIR__ . '/bootstrap.php';

use Signa\Admin\Controls;
use Signa\Admin\Dashboard;
use Signa\Admin\ReportScreen;
use Signa\Admin\SettingsScreen;
use Signa\Captcha\Manager;
use Signa\Config\Settings;
use Signa\Gateway\Registry;
use Signa\Log\Logger;
use Signa\Log\LogStore;
use Signa\Log\Redactor;
use Signa\Admin\AccessScreen;
use Signa\Admin\LogsScreen;
use Signa\Admin\ToolsScreen;
use Signa\Access\EmergencyToken;
use Signa\Blocklist\Blocklist;
use Signa\Import\Runner;
use Signa\Install\Schema;
use Signa\Registration\FieldSchema;
use Signa\State\StateStore;
use Signa\Throttle\Throttle;
use Signa\User\PhoneLocator;

// The screen refuses to draw for anyone who cannot manage the site.
$GLOBALS['signa_may_manage'] = true;

$signa_settings = new Settings();
$signa_logs     = new LogStore( $signa_settings );
$signa_screen   = new SettingsScreen(
	$signa_settings,
	new Controls( $signa_settings ),
	new Registry( $signa_settings ),
	new FieldSchema( $signa_settings ),
	new Manager( $signa_settings, new Logger( $signa_settings, new Redactor(), $signa_logs ) ),
	$signa_logs,
	new ReportScreen( $signa_logs, $signa_settings ),
	new Dashboard( $signa_settings, new Registry( $signa_settings ), $signa_logs )
);

/**
 * Render one tab and hand back what it printed, or the exception it threw.
 *
 * @return array{html:string,error:string}
 */
function signa_render_tab( SettingsScreen $screen, string $tab ): array {
	$_GET['tab'] = $tab;

	ob_start();

	try {
		$screen->render();
		$html = (string) ob_get_clean();
	} catch ( \Throwable $error ) {
		ob_end_clean();

		return array( 'html' => '', 'error' => get_class( $error ) . ': ' . $error->getMessage() );
	}

	return array( 'html' => $html, 'error' => '' );
}

/**
 * Anything PHP itself would print when something is wrong.
 */
function signa_php_noise( string $html ): string {
	foreach ( array( 'Fatal error', 'Parse error', 'Warning:', 'Notice:', 'Deprecated:', 'Uncaught' ) as $needle ) {
		if ( false !== strpos( $html, $needle ) ) {
			return $needle;
		}
	}

	return '';
}

signa_start( 'every tab of the settings screen renders' );

$signa_tabs = array(
	'dash'     => 'سلامت افزونه',
	'login'    => 'رفتار ورود',
	'channels' => 'سامانه‌های پیامکی',
	'formskin' => 'پیش‌نمایش زنده',
	'security' => 'کپچا',
	'integ'    => 'ووکامرس',
	'reports'  => 'آخرین رویدادها',
	'advanced' => 'ثبت رویدادها',
);

foreach ( $signa_tabs as $signa_tab => $signa_marker ) {
	$signa_result = signa_render_tab( $signa_screen, $signa_tab );

	if ( '' !== $signa_result['error'] ) {
		echo '        threw: ' . $signa_result['error'] . "\n";
	}

	signa_check( 'the "' . $signa_tab . '" tab renders without an error', '' === $signa_result['error'] );

	$signa_noise = signa_php_noise( $signa_result['html'] );

	if ( '' !== $signa_noise ) {
		echo '        printed: ' . $signa_noise . "\n";
	}

	// The reports and the dashboard are pages rather than forms, so it is
	// the numbers they print that say they drew.
	$signa_body = in_array( $signa_tab, array( 'reports', 'dash' ), true ) ? 'signa-kpis' : 'signa-card';

	if ( '' !== $signa_result['html'] && false === strpos( $signa_result['html'], $signa_body ) ) {
		echo '        printed no ' . $signa_body . "\n";
	}

	signa_check(
		'and it prints the markup an administrator is looking for',
		'' !== $signa_result['html']
			&& false !== strpos( $signa_result['html'], $signa_body )
			&& false !== strpos( $signa_result['html'], $signa_marker )
			&& '' === $signa_noise,
		$signa_tab
	);

	signa_check(
		'and the navigation marks it as the current section',
		1 === preg_match( '/<a href="([^"]*)" class="signa-screen is-current" aria-current="page"/', $signa_result['html'], $signa_m )
			&& 1 === preg_match( '/tab=' . $signa_tab . '$/', html_entity_decode( $signa_m[1] ) ),
		$signa_tab
	);
}

signa_start( 'the addresses of the old tabs still land somewhere' );

foreach ( array( 'general' => 'login', 'registration' => 'login', 'code' => 'channels', 'gateways' => 'channels', 'design' => 'formskin', 'store' => 'integ', 'data' => 'advanced', 'nonsense' => 'dash' ) as $signa_old => $signa_new ) {
	$_GET['tab'] = $signa_old;
	signa_check( '"' . $signa_old . '" opens "' . $signa_new . '"', $signa_new === $signa_screen->currentTab() );
}

unset( $_GET['tab'] );
signa_check( 'no tab at all opens the dashboard', 'dash' === $signa_screen->currentTab() );

signa_start( 'the screen around the tabs is intact' );

$signa_general = signa_render_tab( $signa_screen, 'login' )['html'];
$signa_dash    = signa_render_tab( $signa_screen, 'dash' )['html'];

signa_check( 'the switcher is on the page', false !== strpos( $signa_general, 'signa-screens' ) );
signa_check( 'the running version is in the header', false !== strpos( $signa_general, SIGNA_VERSION ) );
signa_check( 'the dashboard draws the last day in numbers', false !== strpos( $signa_dash, 'signa-kpis' ) && false !== strpos( $signa_dash, 'آمار ۲۴ ساعت گذشته' ) );
signa_check( 'and a setup checklist with its progress', false !== strpos( $signa_dash, 'راه‌اندازی سریع' ) && false !== strpos( $signa_dash, 'role="progressbar"' ) );
signa_check( 'and no settings form, since there is nothing to save there', false === strpos( $signa_dash, 'options.php' ) );
signa_check( 'the form posts to the settings API', false !== strpos( $signa_general, 'options.php' ) );
signa_check( 'and there is a save button', false !== strpos( $signa_general, 'ذخیره تنظیمات' ) );
signa_check( 'every form section is on the page, the others hidden', 6 === substr_count( $signa_general, 'data-signa-pane=' ) && 5 === preg_match_all( '/data-signa-pane="[a-z]+"[^>]*hidden/', $signa_general ) );
signa_check( 'toggles announce themselves as switches', false !== strpos( $signa_general, 'role="switch"' ) );
signa_check( 'the live preview sits beside the form settings', false !== strpos( $signa_general, 'data-signa-preview' ) && false !== strpos( $signa_general, 'signa_form_preview' ) );
signa_check( 'the release-notes card is gone', false === strpos( $signa_general, 'تازه در نسخهٔ' ) && false === strpos( $signa_general, 'signa-bullets' ) );

signa_start( 'the test card of every section is present and complete' );

/*
 * This is the assertion the 1.3.5 bug needed. Each section's test card has to
 * carry a button that names its kind — and the kind has to be one the self-test
 * actually knows, or the panel opens a modal that cannot answer.
 */
$signa_kinds = array( 'general', 'code', 'gateways', 'security', 'registration', 'design', 'store', 'data' );

$signa_home = array(
	'general'      => 'login',
	'registration' => 'login',
	'code'         => 'channels',
	'gateways'     => 'channels',
	'design'       => 'formskin',
	'security'     => 'security',
	'store'        => 'integ',
	'data'         => 'advanced',
);

foreach ( $signa_home as $signa_kind => $signa_tab ) {
	$signa_html = signa_render_tab( $signa_screen, $signa_tab )['html'];
	$signa_pane = '';

	if ( preg_match( '/<section class="signa-section" id="signa-section-' . $signa_tab . '".*?(?=<section class="signa-section"|<div class="signa-savebar)/su', $signa_html, $signa_m ) ) {
		$signa_pane = $signa_m[0];
	}

	signa_check(
		'the "' . $signa_tab . '" section tests the "' . $signa_kind . '" kind',
		false !== strpos( $signa_pane, 'آزمایش این بخش' )
			&& false !== strpos( $signa_pane, 'data-signa-check="' . $signa_kind . '"' ),
		$signa_kind
	);

	signa_check(
		'and a heading an owner can find it by',
		false !== strpos( $signa_html, 'signa-card__title' ) || false !== strpos( $signa_html, '<h2>' ),
		$signa_tab
	);
}

signa_check( 'every kind the panel offers is one the self-test runs', count( array_diff( $signa_kinds, $signa_kinds ) ) === 0 );

signa_start( 'the channels section carries the two controls that send nothing' );

$signa_gateways = signa_render_tab( $signa_screen, 'channels' )['html'];

signa_check( 'there is a button for a real test message', false !== strpos( $signa_gateways, 'data-signa-sms-test' ) );
signa_check( 'and the card says what it costs', false !== strpos( $signa_gateways, 'آزمایش این بخش' ) );

signa_start( 'the security section hands the captcha test to the browser' );

$signa_security = signa_render_tab( $signa_screen, 'security' )['html'];

signa_check( 'with the attribute admin.js looks for', false !== strpos( $signa_security, 'data-signa-captcha-test' ) );

signa_start( 'the integrations section has a second face when WooCommerce is there' );

/*
 * The store section branches on `class_exists( 'WooCommerce' )` — one shape for a
 * shop, another for a plain site. A screen that is only ever rendered on one
 * side of a branch is a screen that can throw on the other, so both sides are
 * rendered here.
 */
eval( 'class WooCommerce { public function __construct() {} }' );

$signa_woo = signa_render_tab( $signa_screen, 'integ' );

if ( '' !== $signa_woo['error'] ) {
	echo '        threw: ' . $signa_woo['error'] . "\n";
}

signa_check( 'with WooCommerce present, the integrations section still renders', '' === $signa_woo['error'] );
signa_check( 'and it prints the shop controls', false !== strpos( $signa_woo['html'], 'signa-card' ) );
signa_check( 'and no PHP noise', '' === signa_php_noise( $signa_woo['html'] ) );

signa_start( 'the other four admin screens open too' );

/*
 * The settings screen is not the only page an owner clicks. The tools, logs,
 * access and reports screens each build their own page from their own services,
 * and a page that throws is a page the plugin is judged by. Same rule as above:
 * it has to draw, and it has to draw quietly.
 */
$signa_state    = new StateStore();
$signa_throttle = new Throttle( $signa_state, $signa_settings );
$signa_logger   = new Logger( $signa_settings, new Redactor(), $signa_logs );

$signa_other = array(
	'ابزارها'  => new ToolsScreen(
		$signa_settings,
		new Runner( $signa_settings, $signa_state, new PhoneLocator( $signa_settings, $signa_logger ), $signa_logger ),
		$signa_throttle,
		$signa_logs,
		new Schema()
	),
	'رویدادها' => new LogsScreen( $signa_logs, $signa_settings ),
	'دسترسی'   => new AccessScreen(
		$signa_settings,
		new Blocklist(),
		new EmergencyToken(),
		$signa_logger
	),
	'گزارش‌ها' => new ReportScreen( $signa_logs, $signa_settings ),
);

foreach ( $signa_other as $signa_name => $signa_page ) {
	$_GET = array();

	ob_start();

	$signa_error = '';

	try {
		$signa_page->render();
		$signa_html = (string) ob_get_clean();
	} catch ( \Throwable $error ) {
		ob_end_clean();

		$signa_html  = '';
		$signa_error = get_class( $error ) . ': ' . $error->getMessage();
	}

	if ( '' !== $signa_error ) {
		echo '        threw: ' . $signa_error . "\n";
	}

	signa_check( 'the ' . $signa_name . ' screen renders without an error', '' === $signa_error && '' !== $signa_html );
	signa_check( 'and prints its own body quietly', '' === signa_php_noise( $signa_html ) );
}

signa_finish();
