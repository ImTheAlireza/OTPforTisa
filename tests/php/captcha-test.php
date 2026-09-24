<?php
/**
 * The captcha guard, from the two sides it can fail on.
 *
 * The owner's screen said «۲ درخواست بدون توکن کپچا رسیده است؛ ویجت در مرورگر
 * کاربران بارگذاری نشده» while the same window said, three rows above, «کپچا
 * درست بارگذاری شد». Both cannot be true, and the sentence was the one making
 * the claim: `captcha_missing` is produced by a visitor whose browser could not
 * mint a token **and** by a script posting straight to the endpoint, and nothing
 * in the record told them apart.
 *
 * What is checked here:
 *
 *   1. an empty token is rejected — always — and the rejection carries the user
 *      agent, so the next reader can tell a robot from a person;
 *   2. the browser's own report ("the challenge never became usable here") is
 *      honoured **only** while «باز ماندن ورود» is on, and is logged as a
 *      fail-open with its own reason — it is not a bypass anyone can type;
 *   3. the row that counts all this says which of the two happened, and stays
 *      quiet about browsers when no browser was involved.
 *
 * @package Signa\Tests
 */

require __DIR__ . '/bootstrap.php';

use Signa\Captcha\Manager;
use Signa\Config\Settings;
use Signa\Blocklist\Blocklist;
use Signa\Guard\BotGuard;
use Signa\Guard\CaptchaGuard;
use Signa\Guard\Pipeline;
use Signa\Http\Request;
use Signa\Log\Logger;
use Signa\Log\LogStore;
use Signa\Log\Redactor;
use Signa\Support\Rejection;
use Signa\State\StateStore;
use Signa\Throttle\Throttle;

/**
 * Settings with reCAPTCHA v3 configured and the challenge always required.
 *
 * @param array<string,mixed> $extra
 */
function signa_captcha_settings( array $extra = array() ): Settings {
	$GLOBALS['signa_options']['signa_settings'] = array_merge(
		array(
			'captcha_provider'   => 'recaptcha_v3',
			'captcha_site_key'   => '6Lch98ctAAAAAG1emWrt-CMXgJGOQ5iJkbBoe8EV',
			'captcha_secret_key' => 'secret-key',
			'captcha_trigger'    => 'always',
			'captcha_fail_open'  => '1',
			'phone_meta_key'     => 'signa_phone',
		),
		$extra
	);

	return new Settings();
}

/**
 * A captcha manager over the given settings.
 *
 * @param array<string,mixed> $extra
 */
function signa_captcha_manager( array $extra = array() ): Manager {
	$settings = signa_captcha_settings( $extra );

	return new Manager( $settings, new Logger( $settings, new Redactor(), new LogStore( $settings ) ) );
}

/**
 * A guard wired to fresh settings, logs and throttle.
 *
 * @return array{0:CaptchaGuard,1:LogStore}
 */
function signa_captcha_guard( array $extra = array() ): array {
	$settings = signa_captcha_settings( $extra );
	$logs     = new LogStore( $settings );

	return array(
		new CaptchaGuard(
			new Manager( $settings, new Logger( $settings, new Redactor(), $logs ) ),
			new Throttle( new StateStore(), $settings ),
			new Logger( $settings, new Redactor(), $logs )
		),
		$logs,
	);
}

/**
 * One request, as WordPress hands it to the plugin.
 *
 * @param array<string,mixed> $body
 */
function signa_captcha_request( array $body, string $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Safari/604.1' ): Request {
	$_SERVER['REMOTE_ADDR']     = '203.0.113.9';
	$_SERVER['HTTP_USER_AGENT'] = $ua;

	/*
	 * The marks a real form sends. Without them the bot guard refuses the
	 * request before the captcha is ever consulted — which is the behaviour of
	 * `hardening-test.php`, not the subject here. The unsigned timestamp is the
	 * right one for a fixture: it needs no waiting, while a signed token has a
	 * deliberate minimum age of one second.
	 */
	if ( ! isset( $body[ BotGuard::TIMESTAMP ] ) ) {
		$body[ BotGuard::TIMESTAMP ] = time() - 5;
	}

	return Request::make( '09121234567', '203.0.113.9', $body, null, $ua );
}

/** Events written to the log, in order. */
function signa_captcha_events(): array {
	$events = array();

	foreach ( $GLOBALS['wpdb']->writes as $row ) {
		if ( isset( $row['event'] ) ) {
			$events[] = (string) $row['event'];
		}
	}

	return $events;
}

/** The last written context for one event. */
function signa_captcha_context( string $event ): array {
	$found = array();

	foreach ( $GLOBALS['wpdb']->writes as $row ) {
		if ( isset( $row['event'] ) && $event === (string) $row['event'] ) {
			$found = $row;
		}
	}

	return $found;
}

/* -------------------------------------------------------------------------
 * 1. No token is a rejection — with the evidence attached
 */

$GLOBALS['signa_options'] = array();
$GLOBALS['wpdb']->writes = array();

list( $guard, $logs ) = signa_captcha_guard();

try {
	$guard->inspect( signa_captcha_request( array() ), 'send' );
	signa_check( 'a request with no token is rejected', false );
} catch ( Rejection $rejection ) {
	signa_check( 'a request with no token is rejected', 'captcha_missing' === $rejection->errorCode() );
	signa_check( 'and the visitor is asked for a challenge, not accused', false !== strpos( $rejection->getMessage(), 'ربات' ) && true === $rejection->payload()['captcha_required'] );
}

signa_check( 'nothing was let through without a challenge', ! in_array( 'captcha.fail_open', signa_captcha_events(), true ) );

/* -------------------------------------------------------------------------
 * 2. The browser's report is honoured only with «باز ماندن ورود» on
 */

$GLOBALS['signa_options'] = array();
$GLOBALS['wpdb']->writes = array();

list( $guard, $logs ) = signa_captcha_guard( array( 'captcha_fail_open' => '0' ) );

$claimed = false;

try {
	$guard->inspect( signa_captcha_request( array( 'captcha_state' => 'unavailable' ) ), 'send' );
	$claimed = true;
} catch ( Rejection $rejection ) {
	$claimed = false;
}

signa_check( 'the claim is worth nothing while fail-open is off', false === $claimed );
signa_check( 'and it is not logged as an outage either', ! in_array( 'captcha.fail_open', signa_captcha_events(), true ) );

$GLOBALS['signa_options'] = array();
$GLOBALS['wpdb']->writes = array();

list( $guard, $logs ) = signa_captcha_guard();

$passed = true;

try {
	$guard->inspect( signa_captcha_request( array( 'captcha_state' => 'unavailable' ) ), 'send' );
} catch ( Rejection $rejection ) {
	$passed = false;
}

signa_check( 'with fail-open on, the browser’s outage lets the visitor through', $passed );
signa_check( 'and it is recorded as a fail-open', in_array( 'captcha.fail_open', signa_captcha_events(), true ) );
signa_check( 'with its own reason, so it is not confused with a dead service', 'browser_unavailable' === LogStore::metaOf( signa_captcha_context( 'captcha.fail_open' ), 'reason' ) );
signa_check( 'and the browser is identified in the record', false !== strpos( LogStore::metaOf( signa_captcha_context( 'captcha.fail_open' ), 'ua' ), 'Mozilla' ) );

/* -------------------------------------------------------------------------
 * 3. A real token still goes to the provider — the flag is not a shortcut
 */

$GLOBALS['signa_options'] = array();
$GLOBALS['wpdb']->writes = array();
signa_reply( array( 'response' => array( 'code' => 200 ), 'body' => '{"success":true,"score":0.9,"action":"signa_send"}' ) );

list( $guard, $logs ) = signa_captcha_guard();

$passed = true;

try {
	$guard->inspect( signa_captcha_request( array( 'captcha_token' => 'real-token-from-google' ) ), 'send' );
} catch ( Rejection $rejection ) {
	$passed = false;
}

signa_check( 'a real token is verified with the provider', $passed );
signa_check( 'and no outage is recorded for it', ! in_array( 'captcha.fail_open', signa_captcha_events(), true ) );

$urls = array_column( signa_requests(), 'url' );
signa_check( 'the verification went to the provider, not around it', 1 === count( array_filter( $urls, function ( $url ) {
	return false !== strpos( (string) $url, 'recaptcha' ) || false !== strpos( (string) $url, 'google' ) || false !== strpos( (string) $url, 'recaptcha.net' );
} ) ) );

/*
 * Google's v3 advice: a token made for another action (a comment form on the
 * same key) must not unlock an SMS, and an answer with no score means v2 keys.
 */
foreach (
	array(
		'another action is refused' => '{"success":true,"score":0.9,"action":"comment"}',
		'an answer with no score is refused' => '{"success":true,"action":"signa_send"}',
	) as $label => $answer
) {
	$GLOBALS['signa_options'] = array();
	signa_reply( array( 'response' => array( 'code' => 200 ), 'body' => $answer ) );
	list( $guard, $logs ) = signa_captcha_guard();

	$passed = true;

	try {
		$guard->inspect( signa_captcha_request( array( 'captcha_token' => 'real-token-from-google' ) ), 'send' );
	} catch ( Rejection $rejection ) {
		$passed = false;
	}

	signa_check( $label, ! $passed );
}

/* -------------------------------------------------------------------------
 * 4. ARCaptcha's contract, which this plugin has had wrong before
 */

signa_start( 'ARCaptcha is offered every host the vendor documents' );

/*
 * The widget bundle has lived on three hosts over the years, and which one a
 * visitor can reach depends on their network rather than on their browser. The
 * browser walks the list in order until the library appears, so the list has to
 * carry the host the vendor's current docs use — not only the one this plugin
 * started with. The two kinds never cross: a v3 site key cannot render a v2
 * widget.
 */
$widget = signa_captcha_manager(
	array(
		'captcha_provider'     => 'arcaptcha',
		'captcha_site_key'     => 'ARC-SITE',
		'captcha_secret_key'   => 'ARC-SECRET',
		'captcha_arcaptcha_v3' => '0',
	)
)->clientBundle();

$widgetScripts = implode( "\n", (array) $widget['scripts'] );

signa_check( 'the host the current docs use is offered', false !== strpos( $widgetScripts, 'nwidget.arcaptcha.ir/1/api.js' ) );
signa_check( 'and the host this plugin used first is still offered', false !== strpos( $widgetScripts, 'widget.arcaptcha.ir/1/api.js' ) );
signa_check( 'and the outside-Iran mirror', false !== strpos( $widgetScripts, 'widget.arcaptcha.co/1/api.js' ) );
signa_check( 'a v2 key is never sent a v3 bundle', false === strpos( $widgetScripts, '/3/api.js' ) );
signa_check( 'the widget is asked for in Persian, right to left', 'fa' === $widget['config']['lang'] && 'rtl' === $widget['config']['dir'] );

$score = signa_captcha_manager(
	array(
		'captcha_provider'     => 'arcaptcha',
		'captcha_site_key'     => 'ARC-SITE',
		'captcha_secret_key'   => 'ARC-SECRET',
		'captcha_arcaptcha_v3' => '1',
	)
)->clientBundle();

$scoreScripts = implode( "\n", (array) $score['scripts'] );

signa_check( 'a v3 key gets the score bundle, with the key in the URL', false !== strpos( $scoreScripts, '/3/api.js?render=ARC-SITE' ) );
signa_check( 'and never the widget bundle', false === strpos( $scoreScripts, '/1/api.js' ) );
signa_check( 'the score kind travels with it', 'score' === $score['kind'] );

$front = (string) file_get_contents( SIGNA_PATH . 'assets/js/front.js' );

signa_check( 'the token is read the way the library documents it', false !== strpos( $front, 'getArcToken' ) );
signa_check( 'the documented hidden field is read as well', false !== strpos( $front, 'arcaptcha-token' ) );
signa_check( 'and the object shape from the docs is unwrapped', false !== strpos( $front, 'arcaptcha_token' ) );

$GLOBALS['signa_options'] = array();

/* -------------------------------------------------------------------------
 * 4. The words the administrator reads
 */

/*
 * 5. The row the administrator reads is written by the pipeline, so the
 * pipeline is the thing that has to be exercised — a guard called on its own
 * would leave the claim untested.
 */
$GLOBALS['signa_options'] = array();
$GLOBALS['wpdb']->writes = array();
/*
 * The throttle is switched off for these two runs: its reservation is a lock in
 * the state table, and this test is about which guard speaks and what it says —
 * a `cooldown` from the guard before it would hide the answer.
 */
$settings = signa_captcha_settings( array( 'throttle_enabled' => '0' ) );

$pipeline = new Pipeline(
	$settings,
	new Throttle( new StateStore(), $settings ),
	new Manager( $settings, new Logger( $settings, new Redactor(), $logs ) ),
	new Logger( $settings, new Redactor(), $logs ),
	new Blocklist()
);

try {
	$pipeline->run( Pipeline::STAGE_SEND, signa_captcha_request( array() ) );
	signa_check( 'the pipeline stops a token-less request', false );
} catch ( Rejection $rejection ) {
	signa_check( 'the pipeline stops a token-less request', 'captcha_missing' === $rejection->errorCode() );
}

$row = signa_captcha_context( 'guard.rejected' );

signa_check( 'the rejection is recorded', array() !== $row );
signa_check( 'with the user agent, so a robot can be told from a person', false !== strpos( LogStore::metaOf( $row, 'ua' ), 'Mozilla' ) );

$GLOBALS['signa_options'] = array();
$GLOBALS['wpdb']->writes = array();
$settings = signa_captcha_settings( array( 'throttle_enabled' => '0' ) );

$pipeline = new Pipeline( $settings, new Throttle( new StateStore(), $settings ), new Manager( $settings, new Logger( $settings, new Redactor(), $logs ) ), new Logger( $settings, new Redactor(), $logs ), new Blocklist() );

try {
	$pipeline->run( Pipeline::STAGE_SEND, signa_captcha_request( array(), 'curl/8.4.0' ) );
} catch ( Rejection $rejection ) {
	// expected: no token
}

$row = signa_captcha_context( 'guard.rejected' );

signa_check( 'and a script is recorded as a script', 0 === strpos( LogStore::metaOf( $row, 'ua' ), 'curl/' ) );
signa_check( 'and nothing in the guard blames the visitor for an outage', strpos( file_get_contents( dirname( __DIR__, 2 ) . '/signa/src/Guard/CaptchaGuard.php' ), 'ویجت در مرورگر' ) === false );

/*
 * The captcha must stand in front of the quota. A request it refuses must not
 * spend the phone's send quota (or a bot that never solves a challenge locks a
 * stranger's number out), and must not keep the twenty-second in-flight lock
 * (or the visitor who retries reads «درخواست موازی دیگری در جریان است»).
 */
$GLOBALS['signa_options'] = array();
$GLOBALS['wpdb']->writes = array();
$settings = signa_captcha_settings( array( 'throttle_enabled' => '1', 'captcha_fail_open' => '0' ) );
$throttle = new Throttle( new StateStore(), $settings );
$pipeline = new Pipeline( $settings, $throttle, new Manager( $settings, new Logger( $settings, new Redactor(), $logs ) ), new Logger( $settings, new Redactor(), $logs ), new Blocklist() );

signa_reply( array( 'response' => array( 'code' => 200 ), 'body' => '{"success":false,"error-codes":["invalid-input-response"]}' ) );

for ( $i = 0; $i < 3; $i++ ) {
	try {
		$pipeline->run( Pipeline::STAGE_SEND, signa_captcha_request( array( 'captcha_token' => 'bad-token' ) ) );
	} catch ( Rejection $rejection ) {
		$last = $rejection->errorCode();
	}
}

$usage = $throttle->usage( '09121234567', '203.0.113.9' );

signa_check( 'every retry is judged by the captcha, not by a leftover lock', 'captcha_rejected' === $last );
signa_same( 'and three refused requests spent no quota', 0, (int) $usage['phone'] );

signa_reply( array( 'response' => array( 'code' => 200 ), 'body' => '{"success":true,"score":0.9,"action":"signa_send"}' ) );

$passed = true;

try {
	$pipeline->run( Pipeline::STAGE_SEND, signa_captcha_request( array( 'captcha_token' => 'good-token' ) ) );
} catch ( Rejection $rejection ) {
	$passed = false;
}

signa_check( 'a solved challenge goes through', $passed );
signa_same( 'and only that request is charged', 1, (int) $throttle->usage( '09121234567', '203.0.113.9' )['phone'] );

signa_finish();
