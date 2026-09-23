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

tisa_finish();
