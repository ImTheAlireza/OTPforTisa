<?php
/**
 * A site that blocks its own outbound HTTP.
 *
 * The screenshot that started this round: the send-test modal said
 * «پیامک ارسال نشد» (correct) and then answered the obvious next question —
 * «راه حلش چیه» — with a wall of mixed English and Persian that never said
 * which of the three possible answers was the right one for that site.
 *
 * There are exactly three, and this file checks that each one arrives where the
 * owner is looking:
 *
 *   1. the failure itself names `WP_HTTP_BLOCK_EXTERNAL` and the line that
 *      fixes it, with the host of the gateway they actually configured;
 *   2. the plan card on the same modal carries the block as a configuration
 *      problem of this installation (it used to say «سامانه پیامکی انتخاب‌شده
 *      شناخته نشده است», which was true of the email channel that carried the
 *      code and useless about the SMS that did not);
 *   3. the switch the plugin offers — «ارسال مستقیم» — takes the block out of
 *      the decision, so an owner who cannot edit wp-config.php still has an
 *      answer that works from the panel.
 *
 * The site under test allows one host only, and it is not the SMS gateway.
 *
 * @package TisaOtp\Tests
 */

require __DIR__ . '/bootstrap.php';

use TisaOtp\Config\Settings;
use TisaOtp\Gateway\DeliveryRequest;
use TisaOtp\Gateway\Drivers\SmsIr;
use TisaOtp\Gateway\Registry;
use TisaOtp\Support\Transport;

// wp-config.php on this site: outbound HTTP blocked, one host allowed.
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'WP_ACCESSIBLE_HOSTS', 'example.test' );

// The direct transport really goes out; keep the test bounded.
add_filter( 'tisa_otp_http_timeout', function () { return 4; } );

/**
 * Settings with SMS.ir configured, and a site that blocks outbound HTTP.
 *
 * @param array<string,mixed> $extra
 */
function tisa_blocked_settings( array $extra = array() ): Settings {
	$GLOBALS['tisa_options']['tisa_otp_settings'] = array_merge(
		array(
			'sms_gateway'       => 'smsir',
			'smsir_api_key'     => 'test-key',
			'smsir_template_id' => '123456',
			'smsir_sender'      => '30004505000017',
			'sms_template'      => 'کد ورود: {code}',
			'code_ttl'          => 120,
		),
		$extra
	);

	return new Settings();
}

/* -------------------------------------------------------------------------
 * 1. The failure names the constant, the host, and the line
 */

$driver = new SmsIr( tisa_blocked_settings() );
$result = $driver->deliver( DeliveryRequest::make( '09121234567', '54321' ) );

tisa_check( 'a blocked site is refused before anything knocks', array() === tisa_requests() );
tisa_check( 'and it is not dressed up as a success', false === $result->isSent() );
tisa_check( 'the failure names the constant that blocks it', false !== strpos( $result->message(), 'WP_HTTP_BLOCK_EXTERNAL' ) );
tisa_check( 'and the constant that allows it', false !== strpos( $result->message(), 'WP_ACCESSIBLE_HOSTS' ) );
tisa_check( 'with the exact line to paste', false !== strpos( $result->message(), "define( 'WP_ACCESSIBLE_HOSTS', 'api.sms.ir' );" ) );
tisa_check( 'and the host of the gateway that was configured, not a placeholder', false === strpos( $result->message(), 'دامنهٔ سامانهٔ پیامکی' ) );
tisa_check( 'the two answers are offered as a choice', false !== strpos( $result->message(), '۱)' ) && false !== strpos( $result->message(), '۲)' ) );

/* -------------------------------------------------------------------------
 * 2. The plan card is about the SMS gateway
 */

$plan = ( new Registry( tisa_blocked_settings() ) )->planFor( 'smsir' );

tisa_check( 'the plan card reports the block as this installation’s problem', false !== strpos( implode( ' ', $plan['issues'] ), 'WP_ACCESSIBLE_HOSTS' ) );
tisa_check( 'and never as an unknown gateway', false === strpos( implode( ' ', $plan['issues'] ), 'شناخته نشده' ) );
tisa_check( 'the card also names the plugin’s own switch', false !== strpos( implode( ' ', $plan['issues'] ), 'ارسال مستقیم' ) );

/* -------------------------------------------------------------------------
 * 3. The rule itself, with the list core reads
 */

tisa_check( 'a host the list allows is not blocked', false === Transport::blocked( 'api.sms.ir', true, 'api.sms.ir' ) );
tisa_check( 'a host it does not allow is', true === Transport::blocked( 'api.kavenegar.com', true, 'api.sms.ir' ) );
tisa_check( 'a bare entry allows exactly that host, the way core matches it',
	false === Transport::allowed( 'api.sms.ir', 'sms.ir' ) && true === Transport::allowed( 'sms.ir', 'sms.ir' ) );
tisa_check( 'and the wildcard forms carry the subdomains',
	false === Transport::blocked( 'api.sms.ir', true, '*.sms.ir' ) && false === Transport::blocked( 'sms.ir', true, '*.sms.ir' ) );
tisa_check( 'and it is compared without case or spaces', false === Transport::blocked( 'API.SMS.IR', true, ' api.sms.ir , kavenegar.com ' ) );

/* -------------------------------------------------------------------------
 * 4. The owner’s switch, and what it changes
 */

$direct = new SmsIr( tisa_blocked_settings( array( 'direct_send' => '1' ) ) );
$result = $direct->deliver( DeliveryRequest::make( '09121234567', '54321' ) );

tisa_check( 'with ارسال مستقیم on, the block is no longer the answer', false === strpos( (string) $result->message(), 'WP_HTTP_BLOCK_EXTERNAL' ) );

if ( function_exists( 'curl_init' ) ) {
	tisa_check( 'and the request is made by the plugin itself', false === strpos( (string) $result->message(), 'cURL روی این سرور فعال نیست' ) );
} else {
	tisa_check( 'and a server without cURL is told so in Persian', false !== strpos( (string) $result->message(), 'cURL روی این سرور فعال نیست' ) );
}

$plan = ( new Registry( tisa_blocked_settings( array( 'direct_send' => '1' ) ) ) )->planFor( 'smsir' );

tisa_check( 'and the plan card stops mentioning the block', false === strpos( implode( ' ', $plan['issues'] ), 'WP_HTTP_BLOCK_EXTERNAL' ) );

/* -------------------------------------------------------------------------
 * 5. The account probe obeys the same switch
 */

add_filter( 'tisa_otp_probe_timeout', function () { return 4; } );

$probe = ( new SmsIr( tisa_blocked_settings() ) )->probe();

tisa_check( 'reading the account is blocked too, and says why', false !== strpos( (string) $probe['message'], 'WP_HTTP_BLOCK_EXTERNAL' ) );
tisa_check( 'and it reports a transport cause rather than a wrong key', 'transport' === $probe['error_code'] );

$probe = ( new SmsIr( tisa_blocked_settings( array( 'direct_send' => '1' ) ) ) )->probe();

tisa_check( 'with ارسال مستقیم on the account is actually read', false === strpos( (string) $probe['message'], 'WP_HTTP_BLOCK_EXTERNAL' ) );

/* -------------------------------------------------------------------------
 * 6. Nothing above loosened the gate for a site that allows the host
 */

$block = Transport::blockFailure( 'example.test' );

tisa_check( 'an allowed host is never blocked', null === $block );
tisa_check( 'and a blocked one carries the sentence before the request', null !== Transport::blockFailure( 'api.sms.ir' ) );
tisa_check( 'with the host inside it', false !== strpos( (string) Transport::blockFailure( 'api.sms.ir' )['reason'], 'api.sms.ir' ) );

tisa_finish();
