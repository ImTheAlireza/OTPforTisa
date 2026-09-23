<?php
/**
 * The parts of the plugin an attacker meets first.
 *
 * Everything here is a rule that was true in the code before this file existed
 * but nothing was watching:
 *
 *   1. a correct code can be spent exactly once, even when two requests arrive
 *      in the same second;
 *   2. a signed-in visitor fires `wp_login`, so the rest of WordPress can see
 *      the login;
 *   3. the site's own daily ceiling stops a flood that the per-address quotas
 *      cannot see;
 *   4. a request with neither of the form's two timing marks is refused;
 *   5. the CSV exports cannot carry a spreadsheet formula out of the admin;
 *   6. the event table has a floor under it, so a long attack cannot fill the
 *      disk with its own record.
 *
 * @package TisaOtp\Tests
 */

require __DIR__ . '/bootstrap.php';

use TisaOtp\Config\Settings;
use TisaOtp\Front\LoginBridge;
use TisaOtp\Guard\BotGuard;
use TisaOtp\Http\Request;
use TisaOtp\Log\LogStore;
use TisaOtp\Log\Report;
use TisaOtp\Otp\CodeRecord;
use TisaOtp\Otp\CodeStore;
use TisaOtp\Otp\OtpService;
use TisaOtp\Otp\VerificationResult;
use TisaOtp\State\StateStore;
use TisaOtp\Support\Rejection;
use TisaOtp\Throttle\Throttle;

/**
 * A code store that lives in memory, and can be told to lose a race.
 *
 * `CodeRecord` is immutable, which is the point of it: the real stores keep
 * attempts and the used-flag in the database and hand back fresh records. This
 * one keeps them in arrays, so a test can watch a code being spent.
 */
final class TisaFakeCodeStore implements CodeStore {

	/** @var array<string,CodeRecord> */
	public $records = array();

	/** @var array<int,int> */
	public $attempts = array();

	/** @var array<int,bool> */
	public $consumed = array();

	/** @var int */
	private $next = 1;

	/**
	 * When true, `claim()` reports failure — the other request won the race.
	 *
	 * @var bool
	 */
	public $stealClaims = false;

	public function insert( string $fingerprint, string $codeHash, string $channel, int $ttl, string $ipFingerprint ): CodeRecord {
		$now    = time();
		$record = new CodeRecord( $this->next++, $fingerprint, $channel, $codeHash, 0, $now, $now + max( 30, $ttl ), false );

		$this->records[ $fingerprint ] = $record;
		$this->consumed[ $record->id() ] = false;
		$this->attempts[ $record->id() ] = 0;

		return $record;
	}

	public function active( string $fingerprint ): ?CodeRecord {
		$record = isset( $this->records[ $fingerprint ] ) ? $this->records[ $fingerprint ] : null;

		if ( null === $record || $this->isConsumed( $record ) || $record->isExpired() ) {
			return null;
		}

		return $record;
	}

	public function registerAttempt( CodeRecord $record ): int {
		$id = $record->id();

		$this->attempts[ $id ] = ( isset( $this->attempts[ $id ] ) ? (int) $this->attempts[ $id ] : 0 ) + 1;

		return (int) $this->attempts[ $id ];
	}

	public function consume( CodeRecord $record ): void {
		$this->consumed[ $record->id() ] = true;
	}

	public function claim( CodeRecord $record ): bool {
		if ( $this->stealClaims || $this->isConsumed( $record ) ) {
			return false;
		}

		$this->consumed[ $record->id() ] = true;

		return true;
	}

	public function revoke( string $fingerprint ): void {
		if ( isset( $this->records[ $fingerprint ] ) ) {
			$this->consumed[ $this->records[ $fingerprint ]->id() ] = true;
		}
	}

	public function purge(): int {
		return 0;
	}

	private function isConsumed( CodeRecord $record ): bool {
		return ! empty( $this->consumed[ $record->id() ] );
	}
}

/* -------------------------------------------------------------------------
 * 1. One code, one sign-in
 */

$GLOBALS['tisa_options']['tisa_otp_settings'] = array(
	'code_length'     => '5',
	'code_ttl'        => '120',
	'verify_attempts' => '5',
);

$settings = new Settings();
$store    = new TisaFakeCodeStore();
$otp      = new OtpService( $settings, $store );

$record = $otp->store( '09121234567', '12345', 'sms', '203.0.113.9' );

tisa_check( 'a code is never stored as itself', false === strpos( $record->codeHash(), '12345' ) && 64 === strlen( $record->codeHash() ) );
tisa_check( 'the hash is bound to the phone', $store->records[ $record->fingerprint() ]->codeHash() !== $otp->store( '09129999999', '12345', 'sms', '203.0.113.9' )->codeHash() );
tisa_check( 'a wrong code is refused', VerificationResult::MISMATCH === $otp->verify( '09121234567', '54321' )->status() );
tisa_check( 'the right code is accepted', $otp->verify( '09121234567', '12345' )->isAccepted() );
tisa_check( 'and it is spent', $otp->verify( '09121234567', '12345' )->isAccepted() === false );

/*
 * The race: two requests arrive with the same correct code in the same second.
 * Both read the same record; only the one that wins the claim may sign in.
 */
$GLOBALS['tisa_options']['tisa_otp_settings'] = array( 'code_length' => '5', 'code_ttl' => '120', 'verify_attempts' => '5' );

$settings = new Settings();
$store    = new TisaFakeCodeStore();
$otp      = new OtpService( $settings, $store );
$otp->store( '09121234567', '12345', 'sms', '203.0.113.9' );

// The other worker got there first.
$store->stealClaims = true;

tisa_check( 'a correct code that another request already spent is not a second sign-in', false === $otp->verify( '09121234567', '12345' )->isAccepted() );
tisa_check( 'and the visitor is told the code is no longer usable', VerificationResult::MISSING === $otp->verify( '09121234567', '12345' )->status() );

/* --- the phone, not the request, owns the code --------------------------- */

$GLOBALS['tisa_options']['tisa_otp_settings'] = array( 'code_length' => '5', 'code_ttl' => '120', 'verify_attempts' => '5' );

$settings = new Settings();
$store    = new TisaFakeCodeStore();
$otp      = new OtpService( $settings, $store );
$otp->store( '09121234567', '12345', 'sms', '203.0.113.9' );

tisa_check( 'a code issued for one number does not open another', false === $otp->verify( '09129999999', '12345' )->isAccepted() );
tisa_check( 'and the original number still works', $otp->verify( '09121234567', '12345' )->isAccepted() );

tisa_check( 'a code of the wrong length never reaches the store', VerificationResult::MALFORMED === $otp->verify( '09121234567', '12' )->status() );

$persian = new TisaFakeCodeStore();
$service = new OtpService( new Settings(), $persian );
$service->store( '09121234567', '12345', 'sms', '203.0.113.9' );

tisa_check( 'a code typed in Persian digits is read as digits', $service->verify( '09121234567', '۱۲۳۴۵' )->isAccepted() );

$GLOBALS['tisa_options']['tisa_otp_settings'] = array( 'code_length' => '5', 'code_ttl' => '120', 'verify_attempts' => '3' );

$settings = new Settings();
$store    = new TisaFakeCodeStore();
$otp      = new OtpService( $settings, $store );
$otp->store( '09121234567', '12345', 'sms', '203.0.113.9' );

tisa_check( 'attempts are counted down', 2 === $otp->verify( '09121234567', '00000' )->attemptsLeft() );
tisa_check( 'and counted again', 1 === $otp->verify( '09121234567', '00000' )->attemptsLeft() );
tisa_check( 'the last wrong attempt reports nothing left', 0 === $otp->verify( '09121234567', '00000' )->attemptsLeft() );
tisa_check( 'past the limit the code is burned', VerificationResult::EXHAUSTED === $otp->verify( '09121234567', '00000' )->status() );
tisa_check( 'and the right code no longer works afterwards', false === $otp->verify( '09121234567', '12345' )->isAccepted() );

/* --- generated codes ----------------------------------------------------- */

$generator = new OtpService( new Settings(), new TisaFakeCodeStore() );
$codes     = array();

for ( $i = 0; $i < 200; $i++ ) {
	$code = $generator->generate();

	$codes[ $code ] = strlen( $code );
}

tisa_check( 'a generated code is the configured length', array( 5 ) === array_values( array_unique( $codes ) ) );
tisa_check( 'generated codes are digits', 0 === preg_match( '/\D/', $generator->generate() ) );
tisa_check( 'two hundred generated codes are not one repeated string', count( $codes ) > 100 );

/* -------------------------------------------------------------------------
 * 2. The site-wide ceiling
 */

$GLOBALS['tisa_options']['tisa_otp_settings'] = array(
	'throttle_enabled'     => '1',
	'window_minutes'       => '60',
	'limit_per_phone'      => '100',
	'limit_per_ip'         => '100',
	'limit_per_ip_daily'   => '1000',
	'limit_per_site_daily' => '3',
);

$settings = new Settings();
$throttle = new Throttle( new StateStore(), $settings );

$blocked = '';

for ( $i = 0; $i < 4; $i++ ) {
	try {
		// A different number and a different address every time, so every
		// per-address quota still has room — which is exactly the flood the
		// site-wide counter exists for.
		$throttle->chargeSend( '0912000000' . $i, '198.51.100.' . $i );
	} catch ( Rejection $rejection ) {
		$blocked = $rejection->errorCode();
	}
}

tisa_check( 'the fourth send of a three-a-day site is refused', 'site_daily_limit' === $blocked );

$GLOBALS['tisa_options']['tisa_otp_settings']['limit_per_site_daily'] = '0';
$throttle = new Throttle( new StateStore(), new Settings() );

tisa_check( 'zero means no ceiling at all', true === ( function () use ( $throttle ) {
	for ( $i = 0; $i < 30; $i++ ) {
		$throttle->chargeSend( '0912000000' . $i, '198.51.100.' . $i );
	}

	return true;
} )() );

/* -------------------------------------------------------------------------
 * 3. A request with no form marks at all
 */

$GLOBALS['tisa_options']['tisa_otp_settings'] = array();

$guard   = new BotGuard();
$missing = Request::make( '09121234567', '203.0.113.9' );
$caught  = '';

try {
	$guard->inspect( $missing, 'send' );
} catch ( Rejection $rejection ) {
	$caught = $rejection->errorCode();
}

tisa_check( 'a request carrying neither token nor timestamp is refused', 'stale_form' === $caught );

$payload = array();

try {
	$guard->inspect( $missing, 'send' );
} catch ( Rejection $rejection ) {
	$payload = (array) $rejection->payload();
}

tisa_check( 'and refused in a way the form recovers from itself', ! empty( $payload['recoverable'] ) );
tisa_check( 'the reason names the missing mark', 'no_token' === (string) $payload['reason'] );

$honeypot = Request::make( '09121234567', '203.0.113.9', array( 'tisa_hp' => 'gotcha' ) );
$caught   = '';

try {
	$guard->inspect( $honeypot, 'send' );
} catch ( Rejection $rejection ) {
	$caught = $rejection->errorCode();
}

tisa_check( 'a filled honeypot is still refused first', 'robot' === $caught );

/* -------------------------------------------------------------------------
 * 3b. The two doors into an account
 */

/**
 * A bridge over freshly-read settings.
 *
 * Fresh on purpose: `Settings` reads the option once, so a test that changes a
 * setting has to hand the next call a new one — otherwise it is asserting
 * against the value it replaced.
 */
function tisa_bridge(): LoginBridge {
	$settings = new Settings();
	$logs     = new \TisaOtp\Log\LogStore( $settings );
	$captcha  = new \TisaOtp\Captcha\Manager( $settings, new \TisaOtp\Log\Logger( $settings, new \TisaOtp\Log\Redactor(), $logs ) );
	$assets   = new \TisaOtp\Front\Assets( $settings, $captcha );

	return new LoginBridge( $settings, new \TisaOtp\Front\FormRenderer( $settings, new \TisaOtp\Registration\FieldSchema( $settings ), $captcha, new \TisaOtp\Support\View(), $assets ) );
}

$GLOBALS['tisa_options']['tisa_otp_settings'] = array( 'enabled' => '1', 'password_login_off' => '1' );

$blocked = tisa_bridge()->closePasswordLogin( null, 'admin', 'hunter2' );

tisa_check( 'with the switch on, a password sign-in is refused', $blocked instanceof \WP_Error );
tisa_check( 'and the refusal says what to do instead', false !== strpos( $blocked->get_error_message(), 'کد تأیید' ) );
tisa_check( 'the error code names the reason', 'password_login_disabled' === $blocked->get_error_code() );
tisa_check( 'a request with no credentials is left to WordPress', null === tisa_bridge()->closePasswordLogin( null, '', '' ) );

$GLOBALS['tisa_options']['tisa_otp_settings']['password_login_off'] = '0';

tisa_check( 'with the switch off, the password path is left alone', null === tisa_bridge()->closePasswordLogin( null, 'admin', 'hunter2' ) );

$GLOBALS['tisa_options']['tisa_otp_settings']['password_login_off'] = '1';
$GLOBALS['tisa_options']['tisa_otp_settings']['enabled'] = '0';

tisa_check( 'and a disabled plugin never touches authentication', null === tisa_bridge()->closePasswordLogin( null, 'admin', 'hunter2' ) );

$GLOBALS['tisa_options']['tisa_otp_settings']['enabled'] = '1';

add_filter(
	'tisa_otp_allow_password_login',
	static function ( $allow ) {
		return true;
	}
);

tisa_check( 'an administrator can open the door for one username', null === tisa_bridge()->closePasswordLogin( null, 'admin', 'hunter2' ) );

tisa_forget_filters();

tisa_check( 'and the door is shut again when the filter goes', tisa_bridge()->closePasswordLogin( null, 'admin', 'hunter2' ) instanceof \WP_Error );

/* The API paths keep working — application passwords and the WordPress apps. */
define( 'REST_REQUEST', true );

tisa_check( 'a REST request keeps its application passwords', null === tisa_bridge()->closePasswordLogin( null, 'admin', 'app-password' ) );

/* --- the sign-in contract ------------------------------------------------ */

$session = (string) file_get_contents( TISA_OTP_PATH . 'src/User/Session.php' );

tisa_check( 'a signed-in visitor fires wp_login, so the rest of WordPress sees it', false !== strpos( $session, "do_action( 'wp_login'" ) );
tisa_check( 'and it is fired with the login name and the user, as wp_signon does', false !== strpos( $session, '$user->user_login, $user' ) );
tisa_check( 'the session length is a decision, not a constant', false !== strpos( $session, 'tisa_otp_remember_login' ) && false !== strpos( $session, "'remember_login'" ) );
tisa_check( 'and the cookie is set with that decision', false !== strpos( $session, 'wp_set_auth_cookie( $user->ID, $this->remember(), is_ssl() )' ) );

/* -------------------------------------------------------------------------
 * 4. Nothing in an export can run on the owner's machine
 */

tisa_check( 'a leading equals sign is defused', "'=1+1" === Report::cell( '=1+1' ) );
tisa_check( 'so is a plus', "'+1" === Report::cell( '+1' ) );
tisa_check( 'so is a minus', "'-1" === Report::cell( '-1' ) );
tisa_check( 'so is an at sign', "'@SUM(A1)" === Report::cell( '@SUM(A1)' ) );
tisa_check( 'a tab or carriage return is defused too', "'\tcmd" === Report::cell( "\tcmd" ) );
tisa_check( 'an ordinary message is left alone', 'کد تأیید ارسال شد' === Report::cell( 'کد تأیید ارسال شد' ) );
tisa_check( 'an empty cell stays empty', '' === Report::cell( '' ) );
tisa_check( 'numbers are untouched', '42' === Report::cell( 42 ) );
tisa_check( 'a whole row is treated', array( "'=x", 'y' ) === Report::row( array( '=x', 'y' ) ) );

/* --- and the two exports really go through it ---------------------------- */

$logs   = (string) file_get_contents( TISA_OTP_PATH . 'src/Admin/LogsScreen.php' );
$report = (string) file_get_contents( TISA_OTP_PATH . 'src/Admin/ReportScreen.php' );

tisa_check( 'the event export guards its header and its rows', 2 === substr_count( $logs, 'Report::row(' ) );
tisa_check( 'and the report export guards four row-writes', 4 === substr_count( $report, 'Report::row(' ) );
$bare = static function ( string $source ): array {
	preg_match_all( '/fputcsv\(\s*\$out,\s*(.+?)\s*\)\s*;/s', $source, $found );

	$bad = array();

	foreach ( $found[1] as $argument ) {
		// `array()` alone is the blank separator line between two tables.
		if ( 0 !== strpos( trim( $argument ), 'array()' ) && false === strpos( $argument, 'Report::row(' ) ) {
			$bad[] = $argument;
		}
	}

	return $bad;
};

tisa_check( 'neither export writes a bare row', array() === $bare( $logs ) && array() === $bare( $report ) );

/* -------------------------------------------------------------------------
 * 5. The floor under the event table
 */

$GLOBALS['wpdb']->sql = array();

$capped = ( new LogStore( new Settings() ) )->cap( 0 );

tisa_check( 'a ceiling of zero deletes nothing', 0 === $capped );
tisa_check( 'and does not even count the table', array() === $GLOBALS['wpdb']->sql );

$GLOBALS['wpdb']->sql     = array();
$GLOBALS['wpdb']->varQueue = array( 5 );

tisa_check( 'a table under the ceiling is left alone', 0 === ( new LogStore( new Settings() ) )->cap( 200000 ) );

$GLOBALS['wpdb']->sql        = array();
$GLOBALS['wpdb']->varQueue   = array( 300000, 700 );
$GLOBALS['wpdb']->queryReturn = 5000;

tisa_check( 'an over-full table reports how much it removed', 5000 === ( new LogStore( new Settings() ) )->cap( 200000 ) );

$sql = strtolower( implode( "\n", (array) $GLOBALS['wpdb']->sql ) );

tisa_check( 'it finds the cut-off row at the ceiling', false !== strpos( $sql, 'order by id desc limit 1 offset 200000' ) );
tisa_check( 'and deletes oldest-first, in a bounded batch', false !== strpos( $sql, 'delete from wp_tisa_otp_logs' ) && false !== strpos( $sql, 'id <= 700' ) && false !== strpos( $sql, 'limit 5000' ) );

$cron = (string) file_get_contents( TISA_OTP_PATH . 'src/Cron/Maintenance.php' );

tisa_check( 'the ceiling is applied by the housekeeping job, not by hand', false !== strpos( $cron, '->cap(' ) );
tisa_check( 'with the configured ceiling', false !== strpos( $cron, "'logs_max_rows'" ) );

/* -------------------------------------------------------------------------
 * 6. How the panel describes all of this
 */

$screen = (string) file_get_contents( TISA_OTP_PATH . 'src/Admin/SettingsScreen.php' );
$test   = (string) file_get_contents( TISA_OTP_PATH . 'src/Diagnostics/SelfTest.php' );

tisa_check( 'the site ceiling has a control', false !== strpos( $screen, "'limit_per_site_daily'" ) );
tisa_check( 'so does the row ceiling', false !== strpos( $screen, "'logs_max_rows'" ) );
tisa_check( 'so does password login', false !== strpos( $screen, "'password_login_off'" ) );
tisa_check( 'so does session length', false !== strpos( $screen, "'remember_login'" ) );
tisa_check( 'and the security test reports all four', false !== strpos( $test, 'ورود با گذرواژه' ) && false !== strpos( $test, 'مدت نشست' ) && false !== strpos( $test, 'سقف روزانهٔ کل سایت' ) && false !== strpos( $test, 'سقف ردیف‌های رویدادها' ) );

tisa_finish();
