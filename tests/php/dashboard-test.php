<?php
/**
 * The dashboard and the live form preview.
 *
 * The dashboard is the first page an owner sees after 2.0, so its promises are
 * checked with real rows: the numbers come from the event table, every error
 * has a link to the setting that fixes it, and the checklist agrees with the
 * settings. The preview is checked for the two things that make it safe: it
 * shows what was typed, cleaned the way saving cleans it, and it carries none
 * of the front-end script — a preview must never send a message.
 *
 * @package Signa\Tests
 */

require __DIR__ . '/bootstrap.php';

use Signa\Admin\Dashboard;
use Signa\Admin\FormPreview;
use Signa\Admin\ReportScreen;
use Signa\Captcha\Manager;
use Signa\Config\Settings;
use Signa\Front\Assets;
use Signa\Front\FormRenderer;
use Signa\Gateway\Registry;
use Signa\Log\Logger;
use Signa\Log\LogStore;
use Signa\Log\Redactor;
use Signa\Registration\FieldSchema;
use Signa\Support\View;

$GLOBALS['signa_may_manage'] = true;

/**
 * One row as `$wpdb->get_results()` hands it back.
 *
 * @param array<string,mixed> $fields
 */
function signa_row( array $fields ): object {
	return (object) array_merge(
		array(
			'severity'   => 'info',
			'created_at' => gmdate( 'Y-m-d H:i:s', time() - 300 ),
			'event'      => 'code.sent',
			'channel'    => 'sms',
			'gateway'    => 'smsir',
			'phone_mask' => '0912***4567',
			'error_code' => '',
			'message'    => '',
			'total'      => 1,
		),
		$fields
	);
}

function signa_dashboard( Settings $settings ): string {
	$logs = new LogStore( $settings );

	ob_start();
	( new Dashboard( $settings, new Registry( $settings ), $logs ) )->render();

	return (string) ob_get_clean();
}

/* ------------------------------------------------------------------------- */

signa_start( 'an empty site gets an honest, quiet dashboard' );

$GLOBALS['wpdb']->rows = array();
$signa_html            = signa_dashboard( new Settings() );

signa_check( 'three health tiles', 3 === substr_count( $signa_html, 'class="signa-htile ' ) );
signa_check( 'each tile says its state in words, not only in colour', 3 === substr_count( $signa_html, 'وضعیت: ' ) );
signa_check( 'four numbers for the last day', 4 === preg_match_all( '/class="signa-kpi[ "]/', $signa_html ) );
signa_check( 'no errors is said, not left blank', false !== strpos( $signa_html, 'خطای تازه‌ای ثبت نشده است.' ) );
signa_check( 'and no events is said too', false !== strpos( $signa_html, 'هنوز رویدادی ثبت نشده است.' ) );
preg_match( '/aria-valuemax="(\d+)" aria-valuenow="(\d+)"/', $signa_html, $signa_bar );
signa_check( 'the checklist starts with work to do', isset( $signa_bar[2] ) && (int) $signa_bar[2] < (int) $signa_bar[1] );
signa_check( 'and no message sent yet is one of the things left', 1 === preg_match( '/signa-ck is-todo.*?ارسال پیامک آزمایشی/su', $signa_html ) );
signa_check( 'every undone step has a way to do it', substr_count( $signa_html, 'is-todo' ) === substr_count( $signa_html, 'انجامش بده' ) );
signa_check( 'the test message is one click away', false !== strpos( $signa_html, 'data-signa-sms-test' ) );

signa_start( 'errors come with the road to their fix' );

$GLOBALS['wpdb']->rows = array(
	signa_row( array( 'event' => 'code.not_sent', 'severity' => 'error', 'error_code' => 'gateway_http_500', 'message' => 'HTTP 500' ) ),
	signa_row( array( 'event' => 'guard.rejected', 'severity' => 'notice', 'error_code' => 'captcha_missing' ) ),
	signa_row( array( 'event' => 'guard.rejected', 'severity' => 'notice', 'error_code' => 'blocked' ) ),
	signa_row( array( 'event' => 'guard.rejected', 'severity' => 'notice', 'error_code' => 'rate_limited' ) ),
);

$signa_html = signa_dashboard( new Settings() );

preg_match( '/id="signa-card-errors".*?<\/section>/su', $signa_html, $signa_card );
$signa_card = isset( $signa_card[0] ) ? $signa_card[0] : '';

signa_check( 'the card lists them', 4 === substr_count( $signa_card, 'class="signa-frow"' ) );
signa_check( 'a gateway failure points at the gateways', false !== strpos( $signa_card, 'tab=channels#signa-card-gateways' ) );
signa_check( 'a captcha failure points at the captcha card', false !== strpos( $signa_card, 'tab=security#signa-card-captcha' ) );
signa_check( 'a blocked number points at the block list', false !== strpos( $signa_card, 'page=signa-access' ) );
signa_check( 'a rate limit points at the limits', false !== strpos( $signa_card, 'tab=security#signa-card-limits' ) );
signa_check( 'the error code is shown left-to-right', false !== strpos( $signa_card, '<code class="signa-code" dir="ltr">gateway_http_500</code>' ) );
signa_check( 'and the time is relative, in Persian', false !== strpos( $signa_card, ' پیش' ) );

foreach ( array( 'channels' => 'signa-card-gateways', 'security' => 'signa-card-captcha' ) as $signa_tab => $signa_id ) {
	$GLOBALS['wpdb']->rows = array();
	$_GET['tab']           = $signa_tab;

	$signa_settings = new Settings();
	$signa_logs     = new LogStore( $signa_settings );
	$signa_screen   = new \Signa\Admin\SettingsScreen(
		$signa_settings,
		new \Signa\Admin\Controls( $signa_settings ),
		new Registry( $signa_settings ),
		new FieldSchema( $signa_settings ),
		new Manager( $signa_settings, new Logger( $signa_settings, new Redactor(), $signa_logs ) ),
		$signa_logs,
		new ReportScreen( $signa_logs, $signa_settings ),
		new Dashboard( $signa_settings, new Registry( $signa_settings ), $signa_logs )
	);

	ob_start();
	$signa_screen->render();
	$signa_page = (string) ob_get_clean();

	signa_check( 'and #' . $signa_id . ' is a card that exists', false !== strpos( $signa_page, 'id="' . $signa_id . '"' ) );
}

foreach ( array( 'signa-card-limits', 'signa-card-roles', 'signa-card-registration', 'signa-card-behaviour', 'signa-card-direct' ) as $signa_id ) {
	signa_check( 'and #' . $signa_id . ' exists too', false !== strpos( $signa_page, 'id="' . $signa_id . '"' ) );
}

unset( $_GET['tab'] );

signa_start( 'the relative time reads like a person wrote it' );

signa_same( 'a few seconds ago is «همین حالا»', 'همین حالا', ReportScreen::ago( gmdate( 'Y-m-d H:i:s', time() - 5 ) ) );
signa_check( 'older ends in «پیش»', ' پیش' === substr( ReportScreen::ago( gmdate( 'Y-m-d H:i:s', time() - 7200 ) ), -strlen( ' پیش' ) ) );
signa_same( 'nonsense comes back unchanged', 'not a date', ReportScreen::ago( 'not a date' ) );

/* ------------------------------------------------------------------------- */

signa_start( 'the preview draws the real form with the unsaved values' );

$GLOBALS['wpdb']->rows = array();

$signa_settings = new Settings();
$signa_captcha  = new Manager( $signa_settings, new Logger( $signa_settings, new Redactor(), new LogStore( $signa_settings ) ) );
$signa_assets   = new Assets( $signa_settings, $signa_captcha );
$signa_preview  = new FormPreview(
	$signa_settings,
	new FormRenderer( $signa_settings, new FieldSchema( $signa_settings ), $signa_captcha, new View(), $signa_assets ),
	$signa_assets
);

$signa_saved = $signa_preview->page( array(), 'phone' );

signa_check( 'it is a whole page', 0 === strpos( $signa_saved, '<!doctype html>' ) && false !== strpos( $signa_saved, '</html>' ) );
signa_check( 'with the real template in it', false !== strpos( $signa_saved, 'data-signa-form' ) && false !== strpos( $signa_saved, 'data-signa-step="phone"' ) );
signa_check( 'and the real stylesheet', false !== strpos( $signa_saved, 'assets/css/front.css' ) );
signa_check( 'but not the front-end script, so nothing can be sent from it', false === strpos( $signa_saved, 'front.js' ) && false === strpos( $signa_saved, '<script src' ) );
signa_check( 'and it is kept out of search engines', false !== strpos( $signa_saved, 'noindex' ) );

$signa_draft = $signa_preview->page( array( 'skin' => 'glass', 'form_heading' => 'سلام دوباره' ), 'code' );

signa_check( 'a skin chosen but not saved is the skin drawn', false !== strpos( $signa_draft, 'signa-skin-glass' ) );
signa_check( 'a heading typed but not saved is the heading shown', false !== strpos( $signa_draft, 'سلام دوباره' ) );
signa_check( 'and the step asked for is the step shown', false !== strpos( $signa_draft, 'data-step="code"' ) );
signa_same( 'nothing was written to the database', array(), isset( $GLOBALS['signa_options']['signa_settings'] ) ? array_intersect_key( (array) $GLOBALS['signa_options']['signa_settings'], array( 'skin' => 1 ) ) : array() );

signa_start( 'the preview is guarded like the settings it previews' );

$_POST['signa_settings'] = array( 'skin' => '<script>alert(1)</script>', 'form_heading' => '<img src=x onerror=alert(1)>' );
$signa_method            = new ReflectionMethod( FormPreview::class, 'draft' );
$signa_method->setAccessible( true );
$signa_clean = (array) $signa_method->invoke( $signa_preview );

signa_check( 'an unknown skin does not survive the sanitizer', ! isset( $signa_clean['skin'] ) || in_array( $signa_clean['skin'], array( 'line', 'card', 'glass', 'slate', 'pill' ), true ) );
signa_check( 'and markup in a heading is stripped', ! isset( $signa_clean['form_heading'] ) || false === strpos( (string) $signa_clean['form_heading'], '<img' ) );

$_POST = array();

signa_same( 'the action it answers is the one the screen posts to', 'signa_form_preview', FormPreview::ACTION );

signa_finish();
