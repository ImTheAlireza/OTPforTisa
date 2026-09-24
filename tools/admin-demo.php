<?php
/**
 * Builds the admin preview pages from the plugin's real output.
 *
 * Every page under `preview/public/admin*.html` is the markup the plugin's own
 * screens print — the dashboard, the settings form with all six of its
 * sections, the reports, the three tool screens and the form preview — wrapped
 * in a stand-in for the WordPress frame. Nothing on those pages is drawn by
 * hand, so what the preview shows is what an owner will see.
 *
 * The script prints one JSON object, page name => HTML; `tools/admin-demo.js`
 * writes the files (the WebAssembly PHP in this sandbox cannot write to the
 * repository) and checks them in CI.
 *
 * @package Signa\Tools
 */

$root = dirname( __DIR__ );

require $root . '/tests/php/bootstrap.php';

use Signa\Admin\AccessScreen;
use Signa\Admin\Controls;
use Signa\Admin\Dashboard;
use Signa\Admin\FormPreview;
use Signa\Admin\LogsScreen;
use Signa\Admin\ReportScreen;
use Signa\Admin\SettingsScreen;
use Signa\Admin\ToolsScreen;
use Signa\Access\EmergencyToken;
use Signa\Blocklist\Blocklist;
use Signa\Captcha\Manager;
use Signa\Config\Settings;
use Signa\Front\Assets;
use Signa\Front\FormRenderer;
use Signa\Gateway\Registry;
use Signa\Import\Runner;
use Signa\Install\Schema;
use Signa\Log\Logger;
use Signa\Log\LogStore;
use Signa\Log\Redactor;
use Signa\Registration\FieldSchema;
use Signa\State\StateStore;
use Signa\Support\View;
use Signa\Throttle\Throttle;
use Signa\User\PhoneLocator;

$GLOBALS['signa_may_manage']                = true;
$GLOBALS['signa_options']['signa_settings'] = array(
	'sms_gateway'      => 'smsir',
	'smsir_api_key'    => 'demo-key',
	'smsir_template'   => '100000',
	'replace_wp_login' => '1',
);

/*
 * A day of traffic, so the numbers and the tables have something to say. The
 * times are fixed relative to a pinned clock-free anchor: the pages carry
 * «… پیش» strings, which are rewritten below so the files do not change from
 * one run to the next.
 */
$signa_now = 1767268800; // 2026-01-01 12:00 UTC: the log screen prints absolute times.

function signa_demo_row( int $ago, string $event, string $severity = 'info', string $code = '', string $message = '' ): object {
	global $signa_now;

	return (object) array(
		'id'         => $ago,
		'severity'   => $severity,
		'created_at' => gmdate( 'Y-m-d H:i:s', $signa_now - $ago ),
		'event'      => $event,
		'channel'    => 'sms',
		'gateway'    => 'smsir',
		'phone_mask' => '0912***' . str_pad( (string) ( 1000 + $ago % 9000 ), 4, '0' ),
		'error_code' => $code,
		'message'    => $message,
		'context'    => '{}',
		'total'      => 1,
		'ip_hash'    => '',
		'user_id'    => 0,
	);
}

/**
 * The event table, answering each of the log store's queries in its own shape.
 */
final class Signa_Demo_Wpdb extends Signa_Wpdb_Stub {

	/** @var string */
	public $usermeta = 'wp_usermeta';

	/** @var string */
	public $users = 'wp_users';

	public function get_results( $query = '' ) {
		$query = (string) $query;

		if ( false !== strpos( $query, 'GROUP BY event, error_code' ) ) {
			return array(
				(object) array( 'event' => 'code.not_sent', 'error_code' => 'gateway_http_500', 'total' => 7 ),
				(object) array( 'event' => 'guard.rejected', 'error_code' => 'captcha_missing', 'total' => 4 ),
				(object) array( 'event' => 'guard.rejected', 'error_code' => 'rate_limited', 'total' => 2 ),
			);
		}

		if ( false !== strpos( $query, 'SELECT event, COUNT(*) AS total' ) ) {
			return array(
				(object) array( 'event' => 'code.sent', 'total' => 142 ),
				(object) array( 'event' => 'code.not_sent', 'total' => 7 ),
				(object) array( 'event' => 'guard.rejected', 'total' => 6 ),
				(object) array( 'event' => 'user.created', 'total' => 23 ),
			);
		}

		if ( false !== strpos( $query, 'AS day' ) ) {
			$sent = false !== strpos( $query, "'code.sent'" );
			$rows = array();

			for ( $i = 13; $i >= 0; $i-- ) {
				$rows[] = (object) array(
					'day'   => gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS ),
					'total' => $sent ? 6 + ( ( $i * 7 ) % 9 ) : ( $i % 4 ),
				);
			}

			return $rows;
		}

		if ( false !== strpos( $query, 'signa_log' ) ) {
			return $this->rows;
		}

		return array();
	}

	public function get_row( $query = '' ) {
		if ( false !== strpos( (string) $query, 'AS last_day' ) ) {
			return (object) array( 'total' => 1248, 'errors' => 31, 'last_day' => 64 );
		}

		return null;
	}

	public function get_col( $query = '' ) {
		return array( 'code.sent', 'code.not_sent', 'guard.rejected', 'user.created' );
	}
}

$GLOBALS['wpdb']       = new Signa_Demo_Wpdb();
$GLOBALS['wpdb']->rows = array(
	signa_demo_row( 120, 'code.sent' ),
	signa_demo_row( 540, 'guard.rejected', 'notice', 'captcha_missing', 'Captcha token missing' ),
	signa_demo_row( 1300, 'code.sent' ),
	signa_demo_row( 3900, 'code.not_sent', 'error', 'gateway_http_500', 'HTTP 500 from sms.ir' ),
	signa_demo_row( 7300, 'user.created' ),
	signa_demo_row( 9800, 'code.sent' ),
);

function signa_demo_services(): array {
	$settings = new Settings();
	$logs     = new LogStore( $settings );
	$logger   = new Logger( $settings, new Redactor(), $logs );
	$captcha  = new Manager( $settings, $logger );
	$reports  = new ReportScreen( $logs, $settings );
	$state    = new StateStore();

	return compact( 'settings', 'logs', 'logger', 'captcha', 'reports', 'state' );
}

function signa_demo_capture( callable $draw ): string {
	ob_start();
	$draw();

	return (string) ob_get_clean();
}

/**
 * Point the plugin's WordPress addresses at the preview's own pages.
 */
function signa_demo_localise( string $html ): string {
	$html = str_replace( '&amp;', '&', $html );

	$html = (string) preg_replace_callback(
		'#https://example\.test/wp-admin/admin\.php\?page=signa&tab=([a-z]+)#',
		static function ( array $m ): string {
			if ( 'dash' === $m[1] ) {
				return '/admin';
			}

			if ( 'reports' === $m[1] ) {
				return '/admin/reports';
			}

			return '/admin/settings?tab=' . $m[1];
		},
		$html
	);

	$html = str_replace(
		array(
			'https://example.test/wp-admin/admin.php?page=signa-logs',
			'https://example.test/wp-admin/admin.php?page=signa-tools',
			'https://example.test/wp-admin/admin.php?page=signa-access',
			'https://example.test/wp-admin/admin.php?page=signa-reports',
		),
		array( '/admin/logs', '/admin/tools', '/admin/access', '/admin/reports' ),
		$html
	);

	$html = (string) preg_replace( '#https://example\.test/wp-admin/admin-post\.php\?action=signa_form_preview[^"]*#', '/admin/form-preview', $html );
	$html = str_replace( '/admin/logs&', '/admin/logs?', $html );

	// Relative times and the minted-at stamp change every run; the files must not.
	$html = (string) preg_replace( '/(class="signa-(?:frow__when|muted-cell)">)[^<]*</u', '$1۵ دقیقه پیش<', $html );
	$html = (string) preg_replace( '/class="signa__rendered" value="[0-9]+"/', 'class="signa__rendered" value="0"', $html );
	$html = (string) preg_replace( '/data-form-token="[^"]*"/', 'data-form-token="demo-form-token"', $html );
	$html = (string) preg_replace( '/(<span class="signa-chart__day" dir="ltr">)[^<]*</', '$1—<', $html );
	$html = (string) preg_replace( '/(class="signa-chart__col" title=")[^"]*"/u', '$1"', $html );

	return str_replace( '&', '&amp;', str_replace( '&amp;', '&', $html ) );
}

function signa_demo_page( string $title, string $body, bool $settings = false ): string {
	// The words are the plugin's own; only the addresses point at the mock API.
	$admin = array_merge(
		$GLOBALS['signa_demo_admin'],
		array(
			'restUrl' => '/mock/signa/v1/',
			'nonce'   => 'demo-nonce',
			'myPhone' => '09120000000',
			'demo'    => true,
		)
	);

	return '<!DOCTYPE html>' . "\n"
		. '<html lang="fa" dir="rtl">' . "\n"
		. '<head>' . "\n"
		. '<meta charset="utf-8">' . "\n"
		. '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
		. '<title>' . $title . ' — سیگنا (پیش‌نمایش)</title>' . "\n"
		. '<!-- Generated by tools/admin-demo.php from the plugin\'s real screens. Do not edit by hand. -->' . "\n"
		. '<link rel="stylesheet" href="/plugin-assets/css/admin.css">' . "\n"
		. '<style>body{margin:0;background:#eef1f4}#wpcontent{padding:0 20px 0 0}.demo-bar{padding:8px 20px;background:#1d2327;color:#c3c4c7;font:13px/1.8 Tahoma,sans-serif}.demo-bar a{color:#72aee6}body.signa-app .demo-bar{display:none}</style>' . "\n"
		. '</head>' . "\n"
		. '<body class="wp-admin signa-admin">' . "\n"
		. '<div class="demo-bar">پیش‌نمایش پیشخوان سیگنا — همان خروجی واقعی صفحه‌های افزونه؛ داده‌ها نمونه‌اند. <a href="/">فرم ورود</a> · <a href="/woodmart">وودمارت</a></div>' . "\n"
		. '<div id="wpcontent">' . "\n"
		. $body . "\n"
		. '</div>' . "\n"
		. '<script>window.signaOtpAdmin = ' . json_encode( $admin, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';</script>' . "\n"
		. '<script src="/plugin-assets/js/admin.js"></script>' . "\n"
		// The app-mode switch posts to admin-post.php in WordPress; here it only flips the class.
		. '<script>document.addEventListener("submit",function(e){if(e.target.classList.contains("signa-appmode")){e.preventDefault();document.body.classList.toggle("signa-app");document.documentElement.classList.toggle("signa-app");}});</script>' . "\n"
		. '</body>' . "\n"
		. '</html>' . "\n";
}

$pages = array();
$s     = signa_demo_services();
$assets = new Assets( $s['settings'], $s['captcha'] );

$GLOBALS['signa_demo_admin'] = $assets->adminConfig();

$screen = new SettingsScreen(
	$s['settings'],
	new Controls( $s['settings'] ),
	new Registry( $s['settings'] ),
	new FieldSchema( $s['settings'] ),
	$s['captcha'],
	$s['logs'],
	$s['reports'],
	new Dashboard( $s['settings'], new Registry( $s['settings'] ), $s['logs'] )
);

$_GET = array( 'tab' => 'dash' );
$pages['admin'] = signa_demo_page( 'داشبورد', signa_demo_capture( array( $screen, 'render' ) ) );

$_GET = array( 'tab' => 'login' );
$pages['admin-settings'] = signa_demo_page( 'تنظیمات', signa_demo_capture( array( $screen, 'render' ) ), true );

$_GET = array( 'tab' => 'reports' );
$pages['admin-reports'] = signa_demo_page( 'گزارش‌ها', signa_demo_capture( array( $screen, 'render' ) ) );

$_GET = array();
$pages['admin-logs'] = signa_demo_page( 'رویدادها', signa_demo_capture( array( new LogsScreen( $s['logs'], $s['settings'] ), 'render' ) ) );

$tools = new ToolsScreen(
	$s['settings'],
	new Runner( $s['settings'], $s['state'], new PhoneLocator( $s['settings'], $s['logger'] ), $s['logger'] ),
	new Throttle( $s['state'], $s['settings'] ),
	$s['logs'],
	new Schema()
);
$pages['admin-tools'] = signa_demo_page( 'ابزارها', signa_demo_capture( array( $tools, 'render' ) ) );

$access = new AccessScreen( $s['settings'], new Blocklist(), new EmergencyToken(), $s['logger'] );
$pages['admin-access'] = signa_demo_page( 'دسترسی', signa_demo_capture( array( $access, 'render' ) ) );

$preview = new FormPreview(
	$s['settings'],
	new FormRenderer( $s['settings'], new FieldSchema( $s['settings'] ), $s['captcha'], new View(), $assets ),
	$assets
);

foreach ( FormPreview::STEPS as $step ) {
	$html = $preview->page( array(), $step );
	$html = str_replace( 'https://example.test/wp-content/plugins/signa/', '/plugin-assets/../', $html );
	$html = str_replace( '/plugin-assets/../assets/', '/plugin-assets/', $html );

	$pages[ 'admin-preview-' . $step ] = signa_demo_localise( $html );
}

foreach ( $pages as $name => $html ) {
	if ( 0 !== strpos( $name, 'admin-preview-' ) ) {
		$pages[ $name ] = signa_demo_localise( $html );
	}
}

echo json_encode( $pages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
