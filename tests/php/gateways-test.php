<?php
/**
 * Kavenegar, MeliPayamak, IPPanel and FarazSMS against their own documentation,
 * plus the failover rules that decide whether a backup panel gets asked.
 *
 * Every request shape below is copied from the vendor's official page (the
 * URL is next to each group). Every refusal is one the vendor documents. If a
 * vendor changes its API, this file is where the difference must show first.
 *
 * @package Signa\Tests
 */

require __DIR__ . '/bootstrap.php';

use Signa\Channel\SmsChannel;
use Signa\Config\Settings;
use Signa\Gateway\DeliveryRequest;
use Signa\Gateway\Drivers\FarazSms;
use Signa\Gateway\Drivers\Ippanel;
use Signa\Gateway\Drivers\Kavenegar;
use Signa\Gateway\Drivers\MeliPayamak;
use Signa\Gateway\FailoverChain;
use Signa\Gateway\GatewayResult;
use Signa\Gateway\Health;
use Signa\Gateway\HttpGateway;
use Signa\Gateway\Registry;
use Signa\Log\LogStore;
use Signa\Log\Logger;
use Signa\Log\Redactor;

/**
 * @param array<string,mixed> $values
 */
function signa_gw_settings( array $values ): Settings {
	$GLOBALS['signa_options']['signa_settings'] = array_merge(
		array(
			'sms_template' => 'کد ورود: {code}',
			'code_ttl'     => 120,
		),
		$values
	);

	return new Settings();
}

/** @return array<string,mixed> */
function signa_gw_last(): array {
	$requests = signa_requests();

	return (array) end( $requests );
}

/** @return array<string,mixed> */
function signa_gw_json(): array {
	$last = signa_gw_last();

	return (array) json_decode( isset( $last['args']['body'] ) ? (string) $last['args']['body'] : '', true );
}

function signa_gw_ctype(): string {
	$last = signa_gw_last();

	return isset( $last['args']['headers']['Content-Type'] ) ? (string) $last['args']['headers']['Content-Type'] : '';
}

function signa_gw_send( $driver, string $phone = '09121234567', string $code = '4821' ): GatewayResult {
	signa_forget_requests();

	return $driver->deliver( DeliveryRequest::make( $phone, $code ) );
}

/* -------------------------------------------------------------------------
 * Shared plumbing
 * ---------------------------------------------------------------------- */

signa_start( 'numbers in E.164 for the panels that require it' );

signa_same( 'a normalised mobile', '+989121234567', HttpGateway::e164( '09121234567' ) );
signa_same( 'already international', '+989121234567', HttpGateway::e164( '989121234567' ) );
signa_same( 'with 0098', '+989121234567', HttpGateway::e164( '00989121234567' ) );
signa_same( 'a bare line becomes +98…', '+983000505', HttpGateway::e164Line( '3000505' ) );
signa_same( 'a line with 98 keeps it', '+983000505', HttpGateway::e164Line( '983000505' ) );
signa_same( 'a line with + stays', '+983000505', HttpGateway::e164Line( '+983000505' ) );
signa_same( 'persian digits are folded', '+983000505', HttpGateway::e164Line( '۳۰۰۰۵۰۵' ) );
signa_same( 'empty stays empty', '', HttpGateway::e164Line( '' ) );

signa_start( 'a form body still leaves when «ارسال مستقیم» sends it with cURL' );

signa_same( 'an array becomes a form string', 'receptor=09121234567&token=4821', HttpGateway::wireBody( array( 'receptor' => '09121234567', 'token' => '4821' ) ) );
signa_same( 'a JSON string is left alone', '{"a":1}', HttpGateway::wireBody( '{"a":1}' ) );

/* -------------------------------------------------------------------------
 * Kavenegar — https://kavenegar.com/rest.html
 * ---------------------------------------------------------------------- */

signa_start( 'kavenegar: verify/lookup is a form post with the documented fields' );

signa_reply( array( 'code' => 200, 'body' => '{"return":{"status":200,"message":"تایید شد"},"entries":[{"messageid":8792343,"status":5}]}' ) );
$kave   = new Kavenegar( signa_gw_settings( array( 'kavenegar_api_key' => 'KEY-1', 'kavenegar_template' => 'signa-login' ) ) );
$result = signa_gw_send( $kave );
$last   = signa_gw_last();

signa_check( 'accepted', $result->isSent() );
signa_same( 'the reference is entries[0].messageid', '8792343', $result->reference() );
signa_same( 'the key is in the path', 'https://api.kavenegar.com/v1/KEY-1/verify/lookup.json', $last['url'] );
signa_check( 'the body is labelled as a form, not JSON', 0 === strpos( signa_gw_ctype(), 'application/x-www-form-urlencoded' ) );
signa_same( 'receptor', '09121234567', $last['args']['body']['receptor'] );
signa_same( 'token', '4821', $last['args']['body']['token'] );
signa_same( 'template', 'signa-login', $last['args']['body']['template'] );

signa_start( 'kavenegar: the documented codes, each with its own meaning' );

$kave  = new Kavenegar( signa_gw_settings( array( 'kavenegar_api_key' => 'KEY-1', 'kavenegar_template' => 'signa-login' ) ) );
$cases = array(
	418 => 'no_credit',
	413 => 'rejected',
	403 => 'unauthorized',
	411 => 'invalid_recipient',
	424 => 'rejected',
	451 => 'rate_limited',
	409 => 'upstream',
);

foreach ( $cases as $api => $expected ) {
	signa_reply( array( 'code' => $api, 'body' => '{"return":{"status":' . $api . ',"message":"x"},"entries":null}' ) );
	signa_same( "status {$api} is {$expected}", $expected, signa_gw_send( $kave )->errorCode() );
}

signa_reply( array( 'code' => 413, 'body' => '{"return":{"status":413,"message":"x"},"entries":null}' ) );
signa_check( '413 is no longer mistaken for an empty account', 'no_credit' !== signa_gw_send( $kave )->errorCode() );

signa_reply( array( 'code' => 424, 'body' => '{"return":{"status":424,"message":"x"},"entries":null}' ) );
signa_check( '424 tells the owner the template name is the problem', false !== strpos( signa_gw_send( $kave )->message(), 'الگو' ) );

signa_start( 'kavenegar: free text without a sender uses the account default line' );

signa_reply( array( 'code' => 200, 'body' => '{"return":{"status":200,"message":"ok"},"entries":[{"messageid":1}]}' ) );
$kave = new Kavenegar( signa_gw_settings( array( 'kavenegar_api_key' => 'KEY-1' ) ) );
signa_gw_send( $kave );
$last = signa_gw_last();

signa_same( 'sms/send.json', 'https://api.kavenegar.com/v1/KEY-1/sms/send.json', $last['url'] );
signa_check( 'no empty sender is sent', ! isset( $last['args']['body']['sender'] ) );
signa_check( 'and the plan does not call a missing sender an error', array() === $kave->plan()['issues'] );

/* -------------------------------------------------------------------------
 * MeliPayamak — https://www.melipayamak.com/api/sendbybasenumber2/
 * ---------------------------------------------------------------------- */

signa_start( 'melipayamak: with a bodyId the shared service line is used' );

signa_reply( array( 'code' => 200, 'body' => '{"Value":"4861739482063217504","RetStatus":1,"StrRetStatus":"Ok"}' ) );
$meli   = new MeliPayamak( signa_gw_settings( array( 'meli_username' => 'u', 'meli_password' => 'p', 'meli_body_id' => '۱۲۳۴۵' ) ) );
$result = signa_gw_send( $meli );
$last   = signa_gw_last();

signa_check( 'accepted', $result->isSent() );
signa_same( 'the reference is the recId in Value, not the word Ok', '4861739482063217504', $result->reference() );
signa_same( 'BaseServiceNumber endpoint', MeliPayamak::PATTERN_ENDPOINT, $last['url'] );
signa_check( 'a form post, as in the vendor sample', 0 === strpos( signa_gw_ctype(), 'application/x-www-form-urlencoded' ) );
signa_same( 'text carries only the variable', '4821', $last['args']['body']['text'] );
signa_same( 'bodyId is the folded number', '12345', $last['args']['body']['bodyId'] );
signa_check( 'no from: the line belongs to the pattern', ! isset( $last['args']['body']['from'] ) );
signa_same( 'the plan says pattern', 'pattern', $meli->plan()['mode'] );

signa_start( 'melipayamak: HTTP 200 with a reason code is a refusal' );

$meli  = new MeliPayamak( signa_gw_settings( array( 'meli_username' => 'u', 'meli_password' => 'p', 'meli_body_id' => '12345' ) ) );
$cases = array(
	'-4'   => 'rejected',
	'2'    => 'no_credit',
	'0'    => 'unauthorized',
	'-110' => 'unauthorized',
	'18'   => 'invalid_recipient',
	'6'    => 'upstream',
);

foreach ( $cases as $value => $expected ) {
	signa_reply( array( 'code' => 200, 'body' => '{"Value":"' . $value . '","RetStatus":1,"StrRetStatus":"x"}' ) );
	$r = signa_gw_send( $meli );
	signa_check( "Value {$value} is not sent", ! $r->isSent() );
	signa_same( "Value {$value} is {$expected}", $expected, $r->errorCode() );
}

signa_reply( array( 'code' => 200, 'body' => '{"Value":"","RetStatus":2,"StrRetStatus":"InsufficientCredit"}' ) );
signa_same( 'RetStatus 2 on the text route is no credit', 'no_credit', signa_gw_send( $meli )->errorCode() );

signa_start( 'melipayamak: free text needs a sender and is a form post too' );

signa_reply( array( 'code' => 200, 'body' => '{"Value":"4861739482","RetStatus":1,"StrRetStatus":"Ok"}' ) );
$meli = new MeliPayamak( signa_gw_settings( array( 'meli_username' => 'u', 'meli_password' => 'p', 'meli_from' => '50004000' ) ) );
$r    = signa_gw_send( $meli );
$last = signa_gw_last();

signa_check( 'accepted', $r->isSent() );
signa_same( 'SendSMS endpoint', MeliPayamak::ENDPOINT, $last['url'] );
signa_same( 'from', '50004000', $last['args']['body']['from'] );

$meli = new MeliPayamak( signa_gw_settings( array( 'meli_username' => 'u', 'meli_password' => 'p' ) ) );
signa_check( 'neither bodyId nor sender is a plan issue', 1 === count( $meli->plan()['issues'] ) );

/* -------------------------------------------------------------------------
 * IPPanel Edge — https://ippanelcom.github.io/Edge-Document/docs/send/
 * ---------------------------------------------------------------------- */

signa_start( 'ippanel: pattern body exactly as documented, numbers in E.164' );

signa_reply( array( 'code' => 200, 'body' => '{"data":{"message_outbox_ids":[1123594208]},"meta":{"status":true,"message":"انجام شد","message_parameters":[],"message_code":"200-1"}}' ) );
$ipp    = new Ippanel( signa_gw_settings( array( 'ippanel_api_key' => 'IPK', 'ippanel_pattern' => 'abc123', 'ippanel_sender' => '3000505', 'ippanel_param' => 'otp' ) ) );
$result = signa_gw_send( $ipp );
$body   = signa_gw_json();
$last   = signa_gw_last();

signa_check( 'accepted', $result->isSent() );
signa_same( 'the reference is data.message_outbox_ids[0]', '1123594208', $result->reference() );
signa_same( 'raw key in Authorization', 'IPK', $last['args']['headers']['Authorization'] );
signa_same( 'sending_type', 'pattern', $body['sending_type'] );
signa_same( 'from_number in E.164', '+983000505', $body['from_number'] );
signa_same( 'recipients in E.164', array( '+989121234567' ), $body['recipients'] );
signa_same( 'the variable name comes from the settings', array( 'otp' => '4821' ), $body['params'] );

signa_start( 'ippanel: webservice puts recipients inside params' );

signa_reply( array( 'code' => 200, 'body' => '{"data":{"message_outbox_ids":[1123544244]},"meta":{"status":true,"message":"انجام شد","message_code":"200-1"}}' ) );
$ipp = new Ippanel( signa_gw_settings( array( 'ippanel_api_key' => 'IPK', 'ippanel_sender' => '+983000505' ) ) );
signa_check( 'accepted', signa_gw_send( $ipp )->isSent() );
$body = signa_gw_json();

signa_same( 'sending_type', 'webservice', $body['sending_type'] );
signa_same( 'params.recipients', array( '+989121234567' ), $body['params']['recipients'] );
signa_check( 'and not at the top level', ! isset( $body['recipients'] ) );
signa_same( 'the message', 'کد ورود: 4821', $body['message'] );

signa_start( 'ippanel: meta.status false is a refusal, whatever the HTTP status' );

$ipp = new Ippanel( signa_gw_settings( array( 'ippanel_api_key' => 'IPK', 'ippanel_sender' => '+983000505' ) ) );
signa_reply( array( 'code' => 401, 'body' => '{"data":null,"meta":{"status":false,"message":"اطلاعات وارد شده صحیح نمی باشد","message_code":"400-1","errors":{}}}' ) );
$r = signa_gw_send( $ipp );
signa_same( 'a bad key is unauthorized', 'unauthorized', $r->errorCode() );
signa_check( 'with the panel sentence', false !== strpos( $r->message(), 'صحیح' ) );

signa_reply( array( 'code' => 200, 'body' => '{"data":null,"meta":{"status":false,"message":"خط نامعتبر","message_code":"400-5"}}' ) );
signa_check( 'a 200 with status false is not sent', ! signa_gw_send( $ipp )->isSent() );

/* -------------------------------------------------------------------------
 * FarazSMS — https://docs.iranpayamak.com/send-pattern-based-sms-13925177e0
 * ---------------------------------------------------------------------- */

signa_start( 'farazsms: with an API key the current web service is used' );

signa_reply( array( 'code' => 201, 'body' => '{"status":"success","data":98765,"messages":null}' ) );
$faraz  = new FarazSms( signa_gw_settings( array( 'faraz_api_key' => 'FK', 'faraz_pattern' => 'SJ3FgPrE0C', 'faraz_from' => '50002178584000' ) ) );
$result = signa_gw_send( $faraz );
$body   = signa_gw_json();
$last   = signa_gw_last();

signa_check( 'no username or password is needed', array() === $faraz->missing() );
signa_check( 'accepted (201)', $result->isSent() );
signa_same( 'the reference is data', '98765', $result->reference() );
signa_same( 'pattern endpoint', FarazSms::API_PATTERN_ENDPOINT, $last['url'] );
signa_same( 'Api-Key header', 'FK', $last['args']['headers']['Api-Key'] );
signa_same( 'code', 'SJ3FgPrE0C', $body['code'] );
signa_same( 'attributes use the default name code', array( 'code' => '4821' ), $body['attributes'] );
signa_same( 'recipient', '09121234567', $body['recipient'] );
signa_same( 'line_number', '50002178584000', $body['line_number'] );
signa_same( 'number_format', 'english', $body['number_format'] );

signa_start( 'farazsms: free text on the current web service' );

signa_reply( array( 'code' => 201, 'body' => '{"status":"success","data":1,"messages":null}' ) );
$faraz = new FarazSms( signa_gw_settings( array( 'faraz_api_key' => 'FK', 'faraz_from' => '2191307530' ) ) );
signa_check( 'accepted', signa_gw_send( $faraz )->isSent() );
$body = signa_gw_json();
signa_same( 'simple endpoint', FarazSms::API_SIMPLE_ENDPOINT, signa_gw_last()['url'] );
signa_same( 'recipients list', array( '09121234567' ), $body['recipients'] );
signa_same( 'text', 'کد ورود: 4821', $body['text'] );

signa_reply( array( 'code' => 422, 'body' => '{"status":"error","data":null,"messages":{"code":["الگو یافت نشد"]}}' ) );
$r = signa_gw_send( $faraz );
signa_check( 'status error is a refusal', ! $r->isSent() );
signa_check( 'and the nested panel sentence reaches the owner', false !== strpos( $r->message(), 'الگو یافت نشد' ) );

signa_start( 'farazsms: legacy panel — a short number is an error code, not a tracking id' );

$faraz = new FarazSms( signa_gw_settings( array( 'faraz_username' => 'u', 'faraz_password' => 'p', 'faraz_pattern' => 'pat' ) ) );
signa_check( 'username + password is still a complete configuration', array() === $faraz->missing() );

signa_reply( array( 'code' => 200, 'body' => '2' ) );
signa_check( '"2" is not sent', ! signa_gw_send( $faraz )->isSent() );

signa_reply( array( 'code' => 200, 'body' => '-1' ) );
signa_check( '"-1" is not sent', ! signa_gw_send( $faraz )->isSent() );

signa_reply( array( 'code' => 200, 'body' => '783214569' ) );
signa_check( 'a long tracking id is sent', signa_gw_send( $faraz )->isSent() );
signa_same( 'legacy pattern keeps its old default variable', array( 'verification-code' => '4821' ), signa_gw_last()['args']['body'] );

$faraz = new FarazSms( signa_gw_settings( array() ) );
signa_same( 'nothing set asks for the API key', array( 'faraz_api_key' ), $faraz->missing() );

/* -------------------------------------------------------------------------
 * Failover — the backup panel exists so that logins survive a broken primary
 * ---------------------------------------------------------------------- */

signa_start( 'failover: every refusal except a bad recipient asks the backup' );

$worth = array(
	'unauthorized'      => true,
	'not_configured'    => true,
	'rejected'          => true,
	'no_credit'         => true,
	'transport'         => true,
	'invalid_recipient' => false,
);

foreach ( $worth as $code => $expected ) {
	signa_same( "{$code} → backup " . ( $expected ? 'asked' : 'not asked' ), $expected, FailoverChain::continues( GatewayResult::failed( 'smsir', $code, 'x', 400 ), 0, 2, true ) );
}

signa_check( 'never past the last gateway', ! FailoverChain::continues( GatewayResult::failed( 'smsir', 'transport' ), 1, 2, true ) );
signa_check( 'never when failover is off', ! FailoverChain::continues( GatewayResult::failed( 'smsir', 'transport' ), 0, 2, false ) );

signa_start( 'failover: a primary with no key hands over to a configured backup' );

$settings = signa_gw_settings(
	array(
		'channels_enabled'   => array( 'sms' ),
		'sms_gateway'        => 'smsir',
		'sms_backup_gateway' => 'kavenegar',
		'failover_enabled'   => true,
		'kavenegar_api_key'  => 'KEY-1',
		'kavenegar_template' => 'signa-login',
	)
);
$chain    = new FailoverChain( new Registry( $settings ), $settings, new Logger( $settings, new Redactor(), new LogStore( $settings ) ), new Health() );
$channel  = new SmsChannel( $chain, $settings );

signa_check( 'the SMS channel stays available', $channel->available() );

signa_reply( array( 'code' => 200, 'body' => '{"return":{"status":200,"message":"ok"},"entries":[{"messageid":55}]}' ) );
signa_forget_requests();
$r = $chain->deliver( DeliveryRequest::make( '09121234567', '4821' ) );

signa_check( 'and the code is delivered', $r->isSent() );
signa_same( 'by the backup', 'kavenegar', $r->gateway() );

$settings = signa_gw_settings( array( 'channels_enabled' => array( 'sms' ), 'sms_gateway' => 'smsir', 'sms_backup_gateway' => '' ) );
$chain    = new FailoverChain( new Registry( $settings ), $settings, new Logger( $settings, new Redactor(), new LogStore( $settings ) ), new Health() );
signa_check( 'with no usable gateway at all, the channel says so', ! ( new SmsChannel( $chain, $settings ) )->available() );

signa_finish();
