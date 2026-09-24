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
 * @package Signa\Tests
 */

require __DIR__ . '/bootstrap.php';

use Signa\Config\Settings;
use Signa\Gateway\DeliveryRequest;
use Signa\Gateway\Drivers\SmsIr;
use Signa\Gateway\FailoverChain;
use Signa\Gateway\GatewayResult;
use Signa\Gateway\Health;
use Signa\Gateway\Registry;
use Signa\Log\LogStore;
use Signa\Log\Logger;
use Signa\Log\Redactor;
use Signa\Support\Transport;

// wp-config.php on this site: outbound HTTP blocked, one host allowed.
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'WP_ACCESSIBLE_HOSTS', 'example.test' );

// The direct transport really goes out; keep the test bounded.
add_filter( 'signa_http_timeout', function () { return 4; } );

/**
 * Settings with SMS.ir configured, and a site that blocks outbound HTTP.
 *
 * @param array<string,mixed> $extra
 */
function signa_blocked_settings( array $extra = array() ): Settings {
	$GLOBALS['signa_options']['signa_settings'] = array_merge(
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

$driver = new SmsIr( signa_blocked_settings() );
$result = $driver->deliver( DeliveryRequest::make( '09121234567', '54321' ) );

signa_check( 'a blocked site is refused before anything knocks', array() === signa_requests() );
signa_check( 'and it is not dressed up as a success', false === $result->isSent() );
signa_check( 'the failure names the constant that blocks it', false !== strpos( $result->message(), 'WP_HTTP_BLOCK_EXTERNAL' ) );
signa_check( 'and the constant that allows it', false !== strpos( $result->message(), 'WP_ACCESSIBLE_HOSTS' ) );
signa_check( 'with the exact line to paste', false !== strpos( $result->message(), "define( 'WP_ACCESSIBLE_HOSTS', 'api.sms.ir' );" ) );
signa_check( 'and the host of the gateway that was configured, not a placeholder', false === strpos( $result->message(), 'دامنهٔ سامانهٔ پیامکی' ) );
signa_check( 'the two answers are offered as a choice', false !== strpos( $result->message(), '۱)' ) && false !== strpos( $result->message(), '۲)' ) );

$reason = (string) ( isset( $result->meta()['reason'] ) ? $result->meta()['reason'] : '' );

signa_check( 'the stored reason carries the kind once, not twice', 0 === strpos( $reason, 'BLOCKED: ' ) && false === strpos( $reason, 'BLOCKED: BLOCKED' ) );
signa_check( 'and it can be classified again from the text alone', Transport::isBlocked( $reason ) );
signa_check( 'the instruction names the gateway host, not the file to edit', false === strpos( $reason, 'wp-config' ) );

/* -------------------------------------------------------------------------
 * 2. The plan card is about the SMS gateway
 */

$plan = ( new Registry( signa_blocked_settings() ) )->planFor( 'smsir' );

signa_check( 'the plan card reports the block as this installation’s problem', false !== strpos( implode( ' ', $plan['issues'] ), 'WP_ACCESSIBLE_HOSTS' ) );
signa_check( 'and never as an unknown gateway', false === strpos( implode( ' ', $plan['issues'] ), 'شناخته نشده' ) );
signa_check( 'the card also names the plugin’s own switch', false !== strpos( implode( ' ', $plan['issues'] ), 'ارسال مستقیم' ) );

/* -------------------------------------------------------------------------
 * 3. The rule itself, with the list core reads
 */

signa_check( 'a host the list allows is not blocked', false === Transport::blocked( 'api.sms.ir', true, 'api.sms.ir' ) );
signa_check( 'a host it does not allow is', true === Transport::blocked( 'api.kavenegar.com', true, 'api.sms.ir' ) );
signa_check( 'a bare entry allows exactly that host, the way core matches it',
	false === Transport::allowed( 'api.sms.ir', 'sms.ir' ) && true === Transport::allowed( 'sms.ir', 'sms.ir' ) );
signa_check( 'and the wildcard forms carry the subdomains',
	false === Transport::blocked( 'api.sms.ir', true, '*.sms.ir' ) && false === Transport::blocked( 'sms.ir', true, '*.sms.ir' ) );
signa_check( 'and it is compared without case or spaces', false === Transport::blocked( 'API.SMS.IR', true, ' api.sms.ir , kavenegar.com ' ) );

/* -------------------------------------------------------------------------
 * 4. The owner’s switch, and what it changes
 */

$direct = new SmsIr( signa_blocked_settings( array( 'direct_send' => '1' ) ) );
$result = $direct->deliver( DeliveryRequest::make( '09121234567', '54321' ) );

signa_check( 'with ارسال مستقیم on, the block is no longer the answer', false === strpos( (string) $result->message(), 'WP_HTTP_BLOCK_EXTERNAL' ) );

if ( function_exists( 'curl_init' ) ) {
	signa_check( 'and the request is made by the plugin itself', false === strpos( (string) $result->message(), 'cURL روی این سرور فعال نیست' ) );
} else {
	signa_check( 'and a server without cURL is told so in Persian', false !== strpos( (string) $result->message(), 'cURL روی این سرور فعال نیست' ) );
}

$plan = ( new Registry( signa_blocked_settings( array( 'direct_send' => '1' ) ) ) )->planFor( 'smsir' );

signa_check( 'and the plan card stops mentioning the block', false === strpos( implode( ' ', $plan['issues'] ), 'WP_HTTP_BLOCK_EXTERNAL' ) );

/* -------------------------------------------------------------------------
 * 5. The site's block is never charged to the gateway
 */

$health = new Health();

// Three strikes is the breaker's own constant; five prove the point.
for ( $i = 0; $i < 5; $i++ ) {
	$health->blocked( 'smsir', 'BLOCKED: http_request_not_executed — WordPress blocks outbound HTTP: api.sms.ir is not in WP_ACCESSIBLE_HOSTS.' );
}

signa_check( 'a blocked request leaves the gateway usable', 0 === $health->blockedUntil( 'smsir' ) );
signa_check( 'so the next attempt is not benched for ten minutes', false === $health->resting( 'smsir' ) );
signa_check( 'and the row says whose problem it is', false !== strpos( $health->describe( 'smsir' ), 'خودِ سایت' ) );
signa_check( 'and it still promises the switch that fixes it', false !== strpos( $health->describe( 'smsir' ), 'ارسال مستقیم' ) );
signa_check( 'the record is kept, so the owner can see it happened', ! empty( $health->get( 'smsir' )['blocked'] ) );

// The real thing, through the chain, so it is not only the helper that is right.
$GLOBALS['signa_options'] = array();
$signa_settings = signa_blocked_settings();
$signa_logs     = new LogStore( $signa_settings );
$chain         = new FailoverChain( new Registry( $signa_settings ), $signa_settings, new Logger( $signa_settings, new Redactor(), $signa_logs ), new Health() );

for ( $i = 0; $i < 4; $i++ ) {
	$chain->deliver( DeliveryRequest::make( '09121234567', '54321' ) );
}

signa_check( 'four blocked deliveries leave the gateway configurable', false === $chain->health()->resting( 'smsir' ) );

/* Every record the plugin wrote, by event name. */
$events = array();

foreach ( $GLOBALS['wpdb']->writes as $row ) {
	if ( isset( $row['event'] ) ) {
		$events[] = (string) $row['event'];
	}
}

signa_check( 'and the event says the site blocked it, not that the gateway failed', in_array( 'gateway.blocked', $events, true ) && ! in_array( 'gateway.failed', $events, true ) );
signa_check( 'while the technical sentence is still recorded', false !== strpos( implode(' ', array_map( 'strval', array_column( $GLOBALS['wpdb']->writes, 'message' ) ) ), 'WP_ACCESSIBLE_HOSTS' ) || false !== strpos( implode(' ', array_map( 'strval', array_column( $GLOBALS['wpdb']->writes, 'reason' ) ) ), 'WP_ACCESSIBLE_HOSTS' ) );

// A gateway that really fails must still be benched.
$health->failure( 'kavenegar', 'transport', 'hide', 0 );
$health->failure( 'kavenegar', 'transport', 'hide', 0 );
$health->failure( 'kavenegar', 'transport', 'hide', 0 );

signa_check( 'a true gateway failure still opens the breaker', $health->resting( 'kavenegar' ) );

/* -------------------------------------------------------------------------
 * 6. The visitor is told a visitor's sentence
 */

$blocked = GatewayResult::failed( 'smsir', 'transport', 'خودِ وردپرس این درخواست را رد کرد … WP_HTTP_BLOCK_EXTERNAL … WP_ACCESSIBLE_HOSTS …' );

signa_check( 'the administrator’s sentence is kept for the administrator', false !== strpos( $blocked->message(), 'WP_ACCESSIBLE_HOSTS' ) );
signa_check( 'and the visitor’s sentence carries none of it', false === strpos( $blocked->visitorMessage(), 'wp-config' ) && false === strpos( $blocked->visitorMessage(), 'WP_' ) );
signa_check( 'it does not name the gateway either', false === strpos( $blocked->visitorMessage(), 'smsir' ) );
signa_check( 'and it tells them what to do next', false !== strpos( $blocked->visitorMessage(), 'دوباره تلاش کنید' ) );
signa_check( 'a rate limit is named as such, because waiting fixes it', false !== strpos( GatewayResult::failed( 'smsir', 'rate_limited', '' )->visitorMessage(), 'زیاد بود' ) );
signa_check( 'a missing configuration reads the same as a block', GatewayResult::failed( 'smsir', 'not_configured', 'کلید API را بگذارید.' )->visitorMessage() === $blocked->visitorMessage() );
signa_check( 'nobody is told a code went out when it did not', false === strpos( $blocked->visitorMessage(), 'ارسال شد' ) );

/* -------------------------------------------------------------------------
 * 7. The account probe obeys the same switch
 */

add_filter( 'signa_probe_timeout', function () { return 4; } );

$probe = ( new SmsIr( signa_blocked_settings() ) )->probe();

signa_check( 'reading the account is blocked too, and says why', false !== strpos( (string) $probe['message'], 'WP_HTTP_BLOCK_EXTERNAL' ) );
signa_check( 'and it reports a transport cause rather than a wrong key', 'transport' === $probe['error_code'] );

$probe = ( new SmsIr( signa_blocked_settings( array( 'direct_send' => '1' ) ) ) )->probe();

signa_check( 'with ارسال مستقیم on the account is actually read', false === strpos( (string) $probe['message'], 'WP_HTTP_BLOCK_EXTERNAL' ) );

/* -------------------------------------------------------------------------
 * 8. Nothing above loosened the gate for a site that allows the host
 */

$block = Transport::blockFailure( 'example.test' );

signa_check( 'an allowed host is never blocked', null === $block );
signa_check( 'and a blocked one carries the sentence before the request', null !== Transport::blockFailure( 'api.sms.ir' ) );
signa_check( 'with the host inside it', false !== strpos( (string) Transport::blockFailure( 'api.sms.ir' )['reason'], 'api.sms.ir' ) );

signa_finish();
