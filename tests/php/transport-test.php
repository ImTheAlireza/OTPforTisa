<?php
/**
 * What the logs say when a message does not leave the server.
 *
 * The site owner sent a screenshot of their events screen: every failure read
 * `transport`. That single word cannot be acted on, and it was the *only* thing
 * kept — the cURL sentence naming the host and the cause was thrown away one
 * line before anyone could read it.
 *
 * These checks feed the classifier the sentences WordPress actually produces on
 * Iranian hosting, and insist that each one comes back as a cause, a technical
 * reason that names the host, and a Persian sentence that says what to do.
 *
 * @package TisaOtp\Tests
 */

require __DIR__ . '/bootstrap.php';

use TisaOtp\Support\Transport;

/** @var array<string,array{code:string,message:string,kind:string}> */
$cases = array(
	'a resolver that cannot see the panel'      => array(
		'code'    => 'http_request_failed',
		'message' => 'cURL error 6: Could not resolve host: api.sms.ir',
		'kind'    => Transport::DNS,
	),
	'a firewall that drops outbound 443'        => array(
		'code'    => 'http_request_failed',
		'message' => 'cURL error 7: Failed to connect to api.sms.ir port 443: Connection timed out',
		'kind'    => Transport::CONNECT,
	),
	'a refused connection'                      => array(
		'code'    => 'http_request_failed',
		'message' => 'cURL error 7: Failed to connect to api.sms.ir port 443: Connection refused',
		'kind'    => Transport::CONNECT,
	),
	'an old CA bundle'                          => array(
		'code'    => 'http_request_failed',
		'message' => 'cURL error 60: SSL certificate problem: unable to get local issuer certificate',
		'kind'    => Transport::TLS,
	),
	'a panel that never answers'                => array(
		'code'    => 'http_request_failed',
		'message' => 'cURL error 28: Operation timed out after 12000 milliseconds with 0 bytes received',
		'kind'    => Transport::TIMEOUT,
	),
	'wordpress with outbound HTTP switched off' => array(
		'code'    => 'block_external',
		'message' => 'External HTTP calls have been blocked.',
		'kind'    => Transport::BLOCKED,
	),
);

tisa_start( 'every real transport failure is recognised for what it is' );

foreach ( $cases as $name => $case ) {
	tisa_same( $name, $case['kind'], Transport::classify( $case['code'], $case['message'] ) );
}

tisa_start( 'and the reason still names the host' );

foreach ( $cases as $name => $case ) {
	$reason = Transport::reason( $case['code'], $case['message'] );

	tisa_check( $name . ': the reason says which kind it is', 0 === strpos( $reason, strtoupper( $case['kind'] ) . ':' ) );
	tisa_check( $name . ': the reason keeps the server sentence', false !== strpos( $reason, 'api.sms.ir' ) || false === strpos( $case['message'], 'api.sms.ir' ) );
}

tisa_check( 'a cURL sentence survives intact', false !== strpos( Transport::reason( $cases['a resolver that cannot see the panel']['code'], $cases['a resolver that cannot see the panel']['message'] ), 'Could not resolve host: api.sms.ir' ) );
tisa_check( 'a very long sentence is trimmed, not dumped', strlen( Transport::reason( 'http_request_failed', str_repeat( 'x', 400 ) ) ) <= 145 );
tisa_check(
	'a credential in the sentence never reaches the database',
	false === strpos( Transport::reason( 'http_request_failed', 'cURL error 7: https://api.sms.ir/send?token=SECRETVALUE failed' ), 'SECRETVALUE' )
);
tisa_same( 'an empty message falls back to the code', 'UNKNOWN: http_request_failed', Transport::reason( 'http_request_failed', '' ) );

tisa_start( 'each cause comes with something to do about it' );

foreach ( $cases as $name => $case ) {
	$advice = Transport::explain( $case['code'], $case['message'] );

	tisa_check( $name . ': the advice is a Persian sentence, not a code', mb_strlen( $advice ) > 40 && preg_match( '/[\x{0600}-\x{06FF}]/u', $advice ) === 1 );
}

tisa_check( 'the DNS case names the resolver', false !== strpos( Transport::explain( 'http_request_failed', 'cURL error 6: Could not resolve host: api.sms.ir' ), 'DNS' ) );
tisa_check( 'the firewall case says who to call', false !== strpos( Transport::explain( 'http_request_failed', 'cURL error 7: Failed to connect to api.sms.ir port 443: Connection timed out' ), 'هاست' ) );
tisa_check( 'the blocked case names the constant to change', false !== strpos( Transport::explain( 'block_external', 'blocked' ), 'WP_HTTP_BLOCK_EXTERNAL' ) );
tisa_check( 'and every case stops saying just "transport"', false === strpos( Transport::explain( 'http_request_failed', 'Connection refused' ), 'transport' ) );

tisa_start( 'the callers get one shape back' );

$from = Transport::from( 'http_request_failed', 'cURL error 6: Could not resolve host: api.sms.ir' );

tisa_same( 'kind', Transport::DNS, $from['kind'] );
tisa_check( 'reason', false !== strpos( $from['reason'], 'Could not resolve host' ) );
tisa_check( 'message', false !== strpos( $from['message'], 'DNS' ) );

/*
 * WordPress hands the gateway a WP_Error, so that is the shape the drivers
 * pass on. The bootstrap has no WP_Error, so this stands in for it.
 */
$error = new class() {
	public function get_error_code() {
		return 'http_request_failed';
	}

	public function get_error_message() {
		return 'cURL error 7: Failed to connect to api.sms.ir port 443: Connection refused';
	}
};

$from = Transport::fromError( $error );

tisa_same( 'a WP_Error is read the same way', Transport::CONNECT, $from['kind'] );
tisa_check( 'and its sentence is kept', false !== strpos( $from['reason'], 'Connection refused' ) );

tisa_same( 'a plain string still works', Transport::TIMEOUT, Transport::fromError( 'cURL error 28: timed out' )['kind'] );
tisa_same( 'and nothing at all does not throw', Transport::UNKNOWN, Transport::fromError( null )['kind'] );

/*
 * The block WordPress performs itself.
 *
 * `WP_HTTP_BLOCK_EXTERNAL` in wp-config.php (or a security plugin that defines
 * it) makes WordPress answer every outbound request with
 * `http_request_not_executed` before cURL is reached. The owner's log showed
 * exactly this: the gateway failed with the word `transport`, and the test card
 * said the cause was unknown — while the remedy was one constant away.
 */
tisa_start( 'a site that blocked outbound HTTP is diagnosed, not guessed at' );

$blocked = Transport::from( 'http_request_not_executed', 'User has blocked requests through HTTP.' );

tisa_same( 'the kind is blocked', Transport::BLOCKED, $blocked['kind'] );
tisa_check( 'the reason keeps the sentence WordPress wrote', false !== strpos( $blocked['reason'], 'blocked requests' ) );
tisa_check( 'and it names the constant to change', false !== strpos( $blocked['message'], 'WP_HTTP_BLOCK_EXTERNAL' ) );
tisa_check( 'and the constant that fixes it', false !== strpos( $blocked['message'], 'WP_ACCESSIBLE_HOSTS' ) );

// The same failure, translated: a Persian WordPress says «بوکله نمود».
tisa_same( 'the Persian wording is the same cause', Transport::BLOCKED, Transport::from( 'http_request_not_executed', 'کاربر درخواست HTTP را بوکله نمود.' )['kind'] );
tisa_same( 'and so is the older spelling', Transport::BLOCKED, Transport::from( '', 'کاربر درخواست HTTP را بلوکه کرد.' )['kind'] );

tisa_start( 'the whitelist is read the way WordPress reads it' );

$rules = 'api.sms.ir, kavenegar.com,*.example.com,.panel.ir';

tisa_check( 'an exact host is allowed', Transport::allowed( 'api.sms.ir', $rules ) );
tisa_check( 'a host that is not listed is not', ! Transport::allowed( 'api.sms.ir.evil.test', $rules ) );
tisa_check( 'a wildcard suffix covers subdomains', Transport::allowed( 'a.example.com', $rules ) && Transport::allowed( 'example.com', $rules ) );
tisa_check( 'a dot suffix does too', Transport::allowed( 'panel.ir', $rules ) && Transport::allowed( 'app.panel.ir', $rules ) );
tisa_check( 'and `*` allows everything', Transport::allowed( 'anything.test', '*' ) );
tisa_check( 'an empty list allows nothing', ! Transport::allowed( 'api.sms.ir', '' ) );
tisa_check( 'blocked() needs the block to be on', ! Transport::blocked( 'api.sms.ir', false, '' ) && Transport::blocked( 'api.sms.ir', true, '' ) );
tisa_check( 'and an allowed host is never blocked', ! Transport::blocked( 'api.sms.ir', true, 'api.sms.ir' ) );

tisa_start( 'the failure is built before the request is made' );

define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'WP_ACCESSIBLE_HOSTS', 'kavenegar.com' );

$pre     = Transport::blockFailure( 'api.sms.ir' );
$allowed = Transport::blockFailure( 'kavenegar.com' );

tisa_same( 'an unlisted host is a blocked failure', Transport::BLOCKED, $pre['kind'] );
tisa_check( 'the reason names the host', false !== strpos( $pre['reason'], 'api.sms.ir' ) );
tisa_check( 'and the sentence is the actionable one', false !== strpos( $pre['message'], 'WP_ACCESSIBLE_HOSTS' ) );
tisa_check( 'a listed host is left alone', null === $allowed );
tisa_check( 'and the same question answered for the row', Transport::egressBlocked( 'api.sms.ir' ) && ! Transport::egressBlocked( 'kavenegar.com' ) );

tisa_finish();
