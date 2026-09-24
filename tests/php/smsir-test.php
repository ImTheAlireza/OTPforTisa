<?php
/**
 * The SMS.ir driver against the panel's own documentation.
 *
 * https://sms.ir/rest-api/ documents two send endpoints, a `status` field in
 * every answer, and a table of refusal codes (10 invalid key … 123 line not
 * activated). The driver used to look at none of that: it asked whether the
 * HTTP status was 200 and whether `status` was 1, and turned everything else
 * into `rejected` with whatever text came back. An owner whose line was simply
 * not activated read the same word as an owner whose key was wrong.
 *
 * These checks feed the driver the answers the panel really gives and insist
 * that each one arrives as an actionable cause — and that the request itself
 * matches the documentation (`lineNumber` is a number, the verify body is
 * `mobile`/`templateId`/`parameters`).
 *
 * @package Signa\Tests
 */

require __DIR__ . '/bootstrap.php';

use Signa\Config\Settings;
use Signa\Gateway\Drivers\SmsIr;
use Signa\Gateway\DeliveryRequest;
use Signa\Gateway\GatewayResult;

/**
 * Settings with the SMS.ir credentials filled in.
 *
 * @param array<string,mixed> $extra
 */
function signa_smsir( array $extra = array() ): SmsIr {
	$values = array_merge(
		array(
			'smsir_api_key'     => 'test-key',
			'smsir_template_id' => '123456',
			'smsir_sender'      => '30004505000017',
			'sms_template'      => 'کد ورود: {code}',
			'code_ttl'          => 120,
		),
		$extra
	);

	$GLOBALS['signa_options']['signa_settings'] = $values;

	return new SmsIr( new Settings() );
}

/**
 * The JSON body a driver sent, decoded.
 *
 * @return array<string,mixed>
 */
function signa_last_body(): array {
	$requests = signa_requests();
	$request  = end( $requests );
	$body     = isset( $request['args']['body'] ) ? (string) $request['args']['body'] : '';

	return (array) json_decode( $body, true );
}

/**
 * The body of the last request, exactly as it went on the wire.
 */
function signa_last_body_raw(): string {
	$requests = signa_requests();
	$request  = end( $requests );

	return isset( $request['args']['body'] ) ? (string) $request['args']['body'] : '';
}

/**
 * A successful panel answer.
 */
function signa_smsir_ok( string $body = '{"status":1,"message":"موفق","data":{"messageId":89545112,"cost":1.0}}' ): string {
	return $body;
}

/* -------------------------------------------------------------------------
 * The request itself: documented shape, documented types
 * ---------------------------------------------------------------------- */

signa_start( 'the verify request is the one sms.ir documents' );

signa_reply( array( 'code' => 200, 'body' => signa_smsir_ok() ) );
signa_forget_requests();

$result = signa_smsir()->deliver( DeliveryRequest::make( '09121234567', '4321' ) );
$body   = signa_last_body();
$request = signa_requests();

signa_check( 'a templated send is accepted', $result->isSent() );
signa_same( 'the reference is the panel message id', '89545112', $result->reference() );
signa_same( 'it goes to the documented verify endpoint', SmsIr::VERIFY_ENDPOINT, $request[0]['url'] );
signa_same( 'the method is POST', 'POST', $request[0]['method'] );
signa_same( 'the API key travels in X-API-KEY', 'test-key', $request[0]['args']['headers']['X-API-KEY'] );
signa_same( 'the mobile is the plain normalised number', '09121234567', $body['mobile'] );
signa_same( 'the template id is a number, not a string', 123456, $body['templateId'] );
signa_same( 'one parameter is sent', 1, count( $body['parameters'] ) );
signa_same( 'the parameter is named CODE, as the template writes', 'CODE', $body['parameters'][0]['name'] );
signa_same( 'and carries the code', '4321', $body['parameters'][0]['value'] );

signa_start( 'free text goes out as the bulk endpoint expects' );

signa_reply( array( 'code' => 200, 'body' => signa_smsir_ok() ) );
signa_forget_requests();

signa_smsir( array( 'smsir_template_id' => '' ) )->deliver( DeliveryRequest::make( '09121234567', '4321' ) );
$body    = signa_last_body();
$request = signa_requests();

signa_same( 'it goes to the documented bulk endpoint', SmsIr::BULK_ENDPOINT, $request[0]['url'] );

/*
 * `lineNumber` is documented as a Long. A JSON string there is refused by the
 * .NET API even when the digits are perfect, and it is the kind of thing that
 * only shows up on a live site.
 */
/*
 * Asserted on the wire, not on a decoded value: a 32-bit PHP (wasm, and some
 * shared hosts) saturates a 14-digit integer, so comparing the decoded number
 * would pass or fail for the wrong reason. What the panel receives is a JSON
 * number — that is the documented type.
 */
signa_check( 'the line number leaves as a JSON number', 1 === preg_match( '/\{"lineNumber":30004505000017,/', (string) signa_last_body_raw() ) );
signa_check( 'and not as a quoted string', false === strpos( (string) signa_last_body_raw(), '"lineNumber":"' ) );
signa_same( 'the message text keeps the template', 'کد ورود: 4321', $body['messageText'] );
signa_same( 'one recipient is sent', array( '09121234567' ), $body['mobiles'] );

signa_start( 'persian digits typed into the settings still reach the panel' );

signa_reply( array( 'code' => 200, 'body' => signa_smsir_ok() ) );
signa_forget_requests();

$driver = signa_smsir( array( 'smsir_sender' => '۳۰۰۰۴۵۰۵۰۰۰۰۱۷', 'smsir_template_id' => '' ) );
$plan   = $driver->plan();
$driver->deliver( DeliveryRequest::make( '09121234567', '4321' ) );
$body = signa_last_body();

signa_same( 'the plan reports the folded number', '30004505000017', $plan['sender'] );
signa_check( 'and no longer calls the line invalid', array() === $plan['issues'] );
signa_check( 'the panel receives latin digits', false !== strpos( (string) signa_last_body_raw(), '{"lineNumber":30004505000017,' ) );

signa_start( 'a template id that is not a number is a configuration problem, not a send attempt' );

$plan = signa_smsir( array( 'smsir_template_id' => 'الگوی من' ) )->plan();

signa_check( 'the plan names the problem', 1 === count( $plan['issues'] ) );
signa_check( 'and says what an id looks like', false !== strpos( implode( ' ', $plan['issues'] ), 'عدد' ) );

$plan = signa_smsir( array( 'smsir_template_id' => '', 'smsir_sender' => '' ) )->plan();
signa_check( 'with neither template nor line the panel would refuse everything', 1 === count( $plan['issues'] ) );
signa_check( 'and the sentence names both of them', false !== strpos( $plan['issues'][0], 'نه شناسه الگو و نه شماره خط' ) );

/* -------------------------------------------------------------------------
 * The refusal codes: each one has to arrive as something an owner can act on
 * ---------------------------------------------------------------------- */

signa_start( 'every documented refusal arrives as its own cause' );

$codes = array(
	0   => array( 'upstream', 'سامانه' ),
	10  => array( 'unauthorized', 'کلید API نامعتبر' ),
	11  => array( 'unauthorized', 'غیرفعال' ),
	12  => array( 'unauthorized', 'IP' ),
	13  => array( 'unauthorized', 'غیرفعال' ),
	14  => array( 'unauthorized', 'تعلیق' ),
	20  => array( 'rate_limited', 'سقف' ),
	101 => array( 'rejected', 'شماره خط نامعتبر' ),
	102 => array( 'no_credit', 'اعتبار' ),
	113 => array( 'rejected', 'الگو' ),
	114 => array( 'rejected', '۲۵' ),
	115 => array( 'rejected', 'لیست سیاه' ),
	117 => array( 'rejected', 'تأیید نشده' ),
	119 => array( 'rejected', 'پلن' ),
	123 => array( 'rejected', 'فعال نشده' ),
);

foreach ( $codes as $api => $expect ) {
	signa_reply(
		array(
			'code' => 400,
			'body' => (string) json_encode(
				array(
					'status'  => $api,
					'message' => 'پیام سامانه',
					'data'    => null,
				)
			),
		)
	);

	$result = signa_smsir()->deliver( DeliveryRequest::make( '09121234567', '4321' ) );

	signa_same( 'status ' . $api . ' maps to ' . $expect[0], $expect[0], $result->errorCode() );
	signa_check( 'status ' . $api . ' is explained in Persian', false !== strpos( $result->message(), $expect[1] ) );
	signa_check(
		'status ' . $api . ' keeps the panel number in the reason',
		false !== strpos( (string) $result->meta()['reason'], 'SMS.ir ' . $api )
	);
}

signa_start( 'the panel is read even when it hides a refusal behind HTTP 200' );

signa_reply( array( 'code' => 200, 'body' => '{"status":0,"message":"مشکل سامانه","data":null}' ) );

$result = signa_smsir()->deliver( DeliveryRequest::make( '09121234567', '4321' ) );

signa_check( 'a 200 with a failing status is not a success', ! $result->isSent() );
signa_same( 'it is classified from the body', 'upstream', $result->errorCode() );

signa_reply( array( 'code' => 400, 'body' => '{"status":999,"message":"چیز عجیبی","data":null}' ) );

$result = signa_smsir()->deliver( DeliveryRequest::make( '09121234567', '4321' ) );

signa_same( 'an undocumented status falls back to the HTTP status', 'rejected', $result->errorCode() );
signa_same( 'and the panel sentence is kept', 'چیز عجیبی', $result->message() );

/* -------------------------------------------------------------------------
 * What the failover chain is allowed to do with each failure
 * ---------------------------------------------------------------------- */

signa_start( 'an empty account fails over; a wrong key does not' );

$noCredit = GatewayResult::failed( 'smsir', 'no_credit', 'اعتبار تمام', 400 );
$badKey   = GatewayResult::failed( 'smsir', 'unauthorized', 'کلید نامعتبر', 400 );
$limited  = GatewayResult::failed( 'smsir', 'rate_limited', 'سقف', 429 );

signa_check( 'an empty account is worth retrying on the backup gateway', $noCredit->isTransient() && ! $noCredit->isConfigurationProblem() );
signa_check( 'a rate limit is worth retrying too', $limited->isTransient() && ! $limited->isConfigurationProblem() );
signa_check( 'a wrong key is not a transient problem', ! $badKey->isTransient() );
signa_check( 'and it stops the chain instead of burning the backup quota', $badKey->isConfigurationProblem() );
signa_check(
	'a template error reported with a 400 still stops the chain',
	GatewayResult::failed( 'smsir', 'rejected', 'الگو نیست', 400 )->isConfigurationProblem()
);
signa_check(
	'a 5xx is still a transient problem',
	GatewayResult::failed( 'smsir', 'upstream', 'خطا', 503 )->isTransient()
);

/* -------------------------------------------------------------------------
 * Guards that keep a wasted request from being made at all
 * ---------------------------------------------------------------------- */

signa_start( 'the request is checked before it is sent' );

add_filter(
	'signa_smsir_param',
	static function () {
		return '   ';
	}
);

signa_forget_requests();
$result = signa_smsir()->deliver( DeliveryRequest::make( '09121234567', '4321' ) );

signa_check( 'an empty parameter name is refused', ! $result->isSent() );
signa_check( 'with the number the panel would have answered (116)', false !== strpos( $result->message(), 'نام پارامتر' ) );
signa_check( 'and nothing was sent', array() === signa_requests() );

signa_start( 'a code longer than the documented parameter limit is caught here' );

signa_forget_filters();
signa_forget_requests();
$result = signa_smsir()->deliver( DeliveryRequest::make( '09121234567', str_repeat( '9', SmsIr::PARAM_MAX + 1 ) ) );

signa_check( 'the long value is refused', ! $result->isSent() );
signa_check( 'and the reason names the panel code 114', false !== strpos( (string) $result->meta()['reason'], '114' ) );
signa_check( 'again without spending a request', array() === signa_requests() );

signa_start( 'a broken connection to the panel is still a transport failure' );

signa_reply( new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host: api.sms.ir' ) );

$result = signa_smsir()->deliver( DeliveryRequest::make( '09121234567', '4321' ) );

signa_same( 'the code stays transport', 'transport', $result->errorCode() );
signa_check( 'and the reason names the host', false !== strpos( (string) $result->meta()['reason'], 'api.sms.ir' ) );

/* -------------------------------------------------------------------------
 * Asking about the account instead of about a message
 * ---------------------------------------------------------------------- */

signa_start( 'the account probe answers without sending anything' );

signa_forget_requests();
signa_reply( array( 'code' => 200, 'body' => '{"status":1,"message":"موفق","data":165.3}' ) );

$probe = signa_smsir()->probe();
$urls  = array_column( signa_requests(), 'url' );

signa_check( 'the key is accepted', $probe['ok'] );
signa_same( 'and the credit comes back as a number', 165.3, $probe['credit'] );
signa_same( 'the credit endpoint is the documented one', SmsIr::CREDIT_ENDPOINT, $urls[0] );
signa_check( 'nothing was posted anywhere', false === array_search( SmsIr::BULK_ENDPOINT, $urls, true ) && false === array_search( SmsIr::VERIFY_ENDPOINT, $urls, true ) );

signa_start( 'the probe reports an account whose credit is gone' );

signa_reply( array( 'code' => 400, 'body' => '{"status":102,"message":"اعتبار کافی نمیباشد","data":null}' ) );

$probe = signa_smsir()->probe();

signa_check( 'it does not claim all is well', ! $probe['ok'] );
signa_same( 'the cause is the empty account', 'no_credit', $probe['error_code'] );
signa_check( 'and the owner is told to charge it', false !== strpos( $probe['message'], 'شارژ' ) );

signa_start( 'the probe reports a key the panel refuses' );

signa_reply( array( 'code' => 401, 'body' => '{"status":10,"message":"کلید نامعتبر","data":null}' ) );

$probe = signa_smsir()->probe();

signa_same( 'the cause is the key itself', 'unauthorized', $probe['error_code'] );
signa_check( 'with the panel number in the reason', false !== strpos( $probe['reason'], 'SMS.ir 10' ) );
signa_check( 'and a sentence about the key, not about the network', false !== strpos( $probe['message'], 'کلید' ) );

signa_start( 'the probe reports a line that belongs to somebody else' );

$GLOBALS['signa_http_reply'] = null;

/*
 * The credit call answers first, then the line list — so the reply is swapped
 * between the two reads by a filter on the probe timeout, which the driver
 * evaluates on every call.
 */
signa_reply( array( 'code' => 200, 'body' => '{"status":1,"message":"موفق","data":12}' ) );

$GLOBALS['signa_http_queue'] = array(
	array( 'code' => 200, 'body' => '{"status":1,"message":"موفق","data":12}' ),
	array( 'code' => 200, 'body' => '{"status":1,"message":"موفق","data":[10002155613464,30004505000017]}' ),
);

add_filter(
	'signa_probe_timeout',
	static function () {
		if ( ! empty( $GLOBALS['signa_http_queue'] ) ) {
			$GLOBALS['signa_http_reply'] = array_shift( $GLOBALS['signa_http_queue'] );
		}

		return 8;
	}
);

$probe = signa_smsir()->probe();

signa_check( 'a line in the account is recognised', true === $probe['sender_ok'] );
signa_check( 'the probe stays green', $probe['ok'] );
signa_check( 'and the account lines are listed for the admin', in_array( '10002155613464', $probe['lines'], true ) );

$GLOBALS['signa_http_reply'] = null;
$GLOBALS['signa_http_queue'] = array(
	array( 'code' => 200, 'body' => '{"status":1,"message":"موفق","data":12}' ),
	array( 'code' => 200, 'body' => '{"status":1,"message":"موفق","data":[10002155613464]}' ),
);

$probe = signa_smsir()->probe();

signa_check( 'a line that is not in the account is a warning, not a green tick', 'warn' === $probe['status'] && false === $probe['sender_ok'] );
signa_check( 'and the owner is told to copy the number from the panel', false !== strpos( $probe['message'], 'پنل SMS.ir' ) );

signa_start( 'the probe says so when there is no key to check' );

$probe = signa_smsir( array( 'smsir_api_key' => '' ) )->probe();

signa_same( 'the missing key is named', 'not_configured', $probe['error_code'] );
signa_check( 'and it says no key is stored', false !== strpos( $probe['reason'], 'no API key' ) );

signa_start( 'a drive with no key never sends' );

signa_forget_requests();
$result = signa_smsir( array( 'smsir_api_key' => '' ) )->deliver( DeliveryRequest::make( '09121234567', '4321' ) );

signa_same( 'the failure is a configuration one', 'not_configured', $result->errorCode() );
signa_check( 'and nothing left the server', array() === signa_requests() );

signa_finish();
