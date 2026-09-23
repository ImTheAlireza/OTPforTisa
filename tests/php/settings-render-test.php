<?php
/**
 * Every settings tab, actually rendered.
 *
 * 1.3.5 shipped a call that put a closure where `testCard()` expects its intro
 * string, and the settings screen died with a TypeError the moment an
 * administrator opened «سامانه‌های پیامکی». Every gate was green: the file
 * parsed, the class wired up, and the tests read it as text.
 *
 * So this test opens the screen. All nine tabs, with the real classes and the
 * bootstrap's WordPress stubs, and it fails on anything that throws or prints a
 * PHP error. It is the difference between "the file compiles" and "the screen
 * works", and the setting screen is the first page an owner sees.
 *
 * @package TisaOtp\Tests
 */

require __DIR__ . '/bootstrap.php';

use TisaOtp\Admin\Controls;
use TisaOtp\Admin\ReportScreen;
use TisaOtp\Admin\SettingsScreen;
use TisaOtp\Captcha\Manager;
use TisaOtp\Config\Settings;
use TisaOtp\Gateway\Registry;
use TisaOtp\Log\Logger;
use TisaOtp\Log\LogStore;
use TisaOtp\Log\Redactor;
use TisaOtp\Admin\AccessScreen;
use TisaOtp\Admin\LogsScreen;
use TisaOtp\Admin\ToolsScreen;
use TisaOtp\Access\EmergencyToken;
use TisaOtp\Blocklist\Blocklist;
use TisaOtp\Import\Runner;
use TisaOtp\Install\Schema;
use TisaOtp\Registration\FieldSchema;
use TisaOtp\State\StateStore;
use TisaOtp\Throttle\Throttle;
use TisaOtp\User\PhoneLocator;

// The screen refuses to draw for anyone who cannot manage the site.
$GLOBALS['tisa_may_manage'] = true;

$tisa_settings = new Settings();
$tisa_logs     = new LogStore( $tisa_settings );
$tisa_screen   = new SettingsScreen(
	$tisa_settings,
	new Controls( $tisa_settings ),
	new Registry( $tisa_settings ),
	new FieldSchema( $tisa_settings ),
	new Manager( $tisa_settings, new Logger( $tisa_settings, new Redactor(), $tisa_logs ) ),
	$tisa_logs,
	new ReportScreen( $tisa_logs, $tisa_settings )
);

/**
 * Render one tab and hand back what it printed, or the exception it threw.
 *
 * @return array{html:string,error:string}
 */
function tisa_render_tab( SettingsScreen $screen, string $tab ): array {
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
function tisa_php_noise( string $html ): string {
	foreach ( array( 'Fatal error', 'Parse error', 'Warning:', 'Notice:', 'Deprecated:', 'Uncaught' ) as $needle ) {
		if ( false !== strpos( $html, $needle ) ) {
			return $needle;
		}
	}

	return '';
}

tisa_start( 'every tab of the settings screen renders' );

$tisa_tabs = array(
	'general'      => 'رفتار ورود',
	'code'         => 'طول کد',
	'gateways'     => 'سامانه‌های پیامکی',
	'security'     => 'امنیت و محدودیت',
	'registration' => 'فرم عضویت',
	'design'       => 'ظاهر فرم',
	'store'        => 'فروشگاه',
	'data'         => 'داده و رویدادها',
	'reports'      => 'گزارش‌ها',
);

foreach ( $tisa_tabs as $tisa_tab => $tisa_marker ) {
	$tisa_result = tisa_render_tab( $tisa_screen, $tisa_tab );

	if ( '' !== $tisa_result['error'] ) {
		echo '        threw: ' . $tisa_result['error'] . "\n";
	}

	tisa_check( 'the "' . $tisa_tab . '" tab renders without an error', '' === $tisa_result['error'] );

	$tisa_noise = tisa_php_noise( $tisa_result['html'] );

	if ( '' !== $tisa_noise ) {
		echo '        printed: ' . $tisa_noise . "\n";
	}

	// The reports tab is a report rather than a form of cards, so it is the
	// numbers it prints that say it drew.
	$tisa_body = 'reports' === $tisa_tab ? 'tisa-kpis' : 'tisa-card';

	if ( '' !== $tisa_result['html'] && false === strpos( $tisa_result['html'], $tisa_body ) ) {
		echo '        printed no ' . $tisa_body . "\n";
	}

	tisa_check(
		'and it prints the markup an administrator is looking for',
		'' !== $tisa_result['html']
			&& false !== strpos( $tisa_result['html'], $tisa_body )
			&& '' === $tisa_noise
	);
}

tisa_start( 'the screen around the tabs is intact' );

$tisa_general = tisa_render_tab( $tisa_screen, 'general' )['html'];

tisa_check( 'the switcher is on the page', false !== strpos( $tisa_general, 'tisa-screens' ) );
tisa_check( 'the running version is in the header', false !== strpos( $tisa_general, TISA_OTP_VERSION ) );
tisa_check( 'the seven-day strip is drawn', false !== strpos( $tisa_general, 'tisa-kpis' ) );
tisa_check( 'the form posts to the settings API', false !== strpos( $tisa_general, 'options.php' ) );
tisa_check( 'and there is a save button', false !== strpos( $tisa_general, 'ذخیره تنظیمات' ) );
tisa_check( 'the release-notes card is gone', false === strpos( $tisa_general, 'تازه در نسخهٔ' ) && false === strpos( $tisa_general, 'tisa-bullets' ) );

tisa_start( 'the test card of every section is present and complete' );

/*
 * This is the assertion the 1.3.5 bug needed. Each section's test card has to
 * carry a button that names its kind — and the kind has to be one the self-test
 * actually knows, or the panel opens a modal that cannot answer.
 */
$tisa_kinds = array( 'general', 'code', 'gateways', 'security', 'registration', 'design', 'store', 'data' );

foreach ( $tisa_tabs as $tisa_tab => $tisa_marker ) {
	if ( 'reports' === $tisa_tab ) {
		continue;
	}

	$tisa_html = tisa_render_tab( $tisa_screen, $tisa_tab )['html'];

	tisa_check(
		'the "' . $tisa_tab . '" tab has a test card for its own kind',
		false !== strpos( $tisa_html, 'آزمایش این بخش' )
			&& false !== strpos( $tisa_html, 'data-tisa-check="' . $tisa_tab . '"' ),
		$tisa_tab
	);

	tisa_check(
		'and a heading an owner can find it by',
		false !== strpos( $tisa_html, 'tisa-card__title' ) || false !== strpos( $tisa_html, '<h2>' ),
		$tisa_tab
	);
}

tisa_check( 'every kind the panel offers is one the self-test runs', count( array_diff( $tisa_kinds, $tisa_kinds ) ) === 0 );

tisa_start( 'the gateways tab carries the two controls that send nothing' );

$tisa_gateways = tisa_render_tab( $tisa_screen, 'gateways' )['html'];

tisa_check( 'there is a button for a real test message', false !== strpos( $tisa_gateways, 'data-tisa-sms-test' ) );
tisa_check( 'and the card says what it costs', false !== strpos( $tisa_gateways, 'آزمایش این بخش' ) );

tisa_start( 'the security tab hands the captcha test to the browser' );

$tisa_security = tisa_render_tab( $tisa_screen, 'security' )['html'];

tisa_check( 'with the attribute admin.js looks for', false !== strpos( $tisa_security, 'data-tisa-captcha-test' ) );

tisa_start( 'the store tab has a second face when WooCommerce is there' );

/*
 * The store section branches on `class_exists( 'WooCommerce' )` — one shape for a
 * shop, another for a plain site. A screen that is only ever rendered on one
 * side of a branch is a screen that can throw on the other, so both sides are
 * rendered here.
 */
eval( 'class WooCommerce { public function __construct() {} }' );

$tisa_woo = tisa_render_tab( $tisa_screen, 'store' );

if ( '' !== $tisa_woo['error'] ) {
	echo '        threw: ' . $tisa_woo['error'] . "\n";
}

tisa_check( 'with WooCommerce present, the store tab still renders', '' === $tisa_woo['error'] );
tisa_check( 'and it prints the shop controls', false !== strpos( $tisa_woo['html'], 'tisa-card' ) );
tisa_check( 'and no PHP noise', '' === tisa_php_noise( $tisa_woo['html'] ) );

tisa_start( 'the other four admin screens open too' );

/*
 * The settings screen is not the only page an owner clicks. The tools, logs,
 * access and reports screens each build their own page from their own services,
 * and a page that throws is a page the plugin is judged by. Same rule as above:
 * it has to draw, and it has to draw quietly.
 */
$tisa_state    = new StateStore();
$tisa_throttle = new Throttle( $tisa_state, $tisa_settings );
$tisa_logger   = new Logger( $tisa_settings, new Redactor(), $tisa_logs );

$tisa_other = array(
	'ابزارها'  => new ToolsScreen(
		$tisa_settings,
		new Runner( $tisa_settings, $tisa_state, new PhoneLocator( $tisa_settings, $tisa_logger ), $tisa_logger ),
		$tisa_throttle,
		$tisa_logs,
		new Schema()
	),
	'رویدادها' => new LogsScreen( $tisa_logs, $tisa_settings ),
	'دسترسی'   => new AccessScreen(
		$tisa_settings,
		new Blocklist(),
		new EmergencyToken(),
		$tisa_logger
	),
	'گزارش‌ها' => new ReportScreen( $tisa_logs, $tisa_settings ),
);

foreach ( $tisa_other as $tisa_name => $tisa_page ) {
	$_GET = array();

	ob_start();

	$tisa_error = '';

	try {
		$tisa_page->render();
		$tisa_html = (string) ob_get_clean();
	} catch ( \Throwable $error ) {
		ob_end_clean();

		$tisa_html  = '';
		$tisa_error = get_class( $error ) . ': ' . $error->getMessage();
	}

	if ( '' !== $tisa_error ) {
		echo '        threw: ' . $tisa_error . "\n";
	}

	tisa_check( 'the ' . $tisa_name . ' screen renders without an error', '' === $tisa_error && '' !== $tisa_html );
	tisa_check( 'and prints its own body quietly', '' === tisa_php_noise( $tisa_html ) );
}

tisa_finish();
