<?php
/**
 * The gateway circuit breaker.
 *
 * The rule under test is the one that decides whether a site keeps trying a
 * gateway that has been failing, and — more importantly — whether a local
 * mistake in this rule could ever stop a site from sending SMS at all.
 *
 * `Health` needs `get_option`/`update_option`/`human_time_diff`, and
 * `FailoverChain::usable()` is a pure function, so both run here without
 * WordPress or MySQL.
 *
 * Note that `tisa_start()` wipes the option store, so each scenario seeds its
 * own gateway state; a health record must not leak between sections.
 *
 * @package TisaOtp\Tests
 */

require __DIR__ . '/bootstrap.php';

use TisaOtp\Gateway\FailoverChain;
use TisaOtp\Gateway\Health;

tisa_start( 'resting after repeated failures' );

$health = new Health();

tisa_check( 'a gateway with no history is used', ! $health->resting( 'smsir' ) );
tisa_same( 'and has nothing to report', 0, $health->blockedUntil( 'smsir' ) );

$health->failure( 'smsir', 'unauthorized', 'کلید API نامعتبر است.', 401 );
tisa_check( 'one failure is not enough to rest a gateway', ! $health->resting( 'smsir' ) );

$health->failure( 'smsir', 'unauthorized', 'کلید API نامعتبر است.', 401 );
tisa_check( 'two failures are still a bad day, not an outage', ! $health->resting( 'smsir' ) );

$health->failure( 'smsir', 'unauthorized', 'کلید API نامعتبر است.', 401 );
tisa_check( 'the third consecutive failure rests the gateway', $health->resting( 'smsir' ) );

$until = $health->blockedUntil( 'smsir' );
tisa_check( 'the rest window ends in the future', $until > time() );
tisa_check( 'and lasts ten minutes', $until - time() <= Health::REST && $until - time() > Health::REST - 5 );

tisa_check( 'the admin sentence names the failure count', false !== strpos( $health->describe( 'smsir' ), 'شکست پیاپی' ) );

tisa_start( 'recovery clears the rest' );

$health = new Health();
$health->failure( 'kavenegar', 'timeout', '', 0 );
$health->failure( 'kavenegar', 'timeout', '', 0 );
$health->failure( 'kavenegar', 'timeout', '', 0 );
tisa_check( 'three timeouts rest the gateway', $health->resting( 'kavenegar' ) );

$health->success( 'kavenegar', 'ref-1', 200 );
tisa_check( 'one successful send clears the rest', ! $health->resting( 'kavenegar' ) );

$health->failure( 'kavenegar', 'credit', '', 402 );
tisa_same( 'and the counter starts again from one', 1, (int) $health->get( 'kavenegar' )['count'] );

tisa_start( 'failures of different gateways do not mix' );

$health = new Health();
$health->failure( 'smsir', 'a', '', 0 );
$health->failure( 'smsir', 'a', '', 0 );
$health->failure( 'smsir', 'a', '', 0 );
$health->failure( 'meli', 'b', '', 0 );

tisa_check( 'the failing gateway rests', $health->resting( 'smsir' ) );
tisa_check( 'a single failure elsewhere does not', ! $health->resting( 'meli' ) );

tisa_start( 'an administrator can clear the history' );

$health = new Health();
$health->failure( 'smsir', 'a', '', 0 );
$health->failure( 'smsir', 'a', '', 0 );
$health->failure( 'smsir', 'a', '', 0 );
$health->failure( 'meli', 'b', '', 0 );

$health->reset( 'smsir' );
tisa_check( 'the reset gateway is used again', ! $health->resting( 'smsir' ) );
tisa_same( 'the other gateway keeps its history', 1, (int) $health->get( 'meli' )['count'] );

$health->forget();
tisa_same( 'forget() clears everything', array(), $health->all() );

tisa_start( 'the chain skips resting gateways' );

$order = array( 'smsir', 'kavenegar', 'meli' );

tisa_same(
	'nothing rested means the configured order runs unchanged',
	$order,
	FailoverChain::usable( $order, array( 'smsir' => false, 'kavenegar' => false, 'meli' => false ) )
);

tisa_same(
	'a resting primary is skipped, the backup takes over',
	array( 'kavenegar', 'meli' ),
	FailoverChain::usable( $order, array( 'smsir' => true, 'kavenegar' => false, 'meli' => false ) )
);

tisa_same(
	'gaps in the middle are skipped too',
	array( 'meli' ),
	FailoverChain::usable( $order, array( 'smsir' => true, 'kavenegar' => true, 'meli' => false ) )
);

/*
 * The important one. A breaker that turns "the primary is broken" into "nobody
 * can log in" would be worse than the problem it solves, so when every gateway
 * is resting the chain runs anyway — that attempt is the half-open probe.
 */
tisa_same(
	'when every gateway rests, all of them are tried anyway',
	$order,
	FailoverChain::usable( $order, array( 'smsir' => true, 'kavenegar' => true, 'meli' => true ) )
);

tisa_same(
	'missing entries in the map count as usable',
	$order,
	FailoverChain::usable( $order, array() )
);

tisa_same(
	'an empty chain stays empty',
	array(),
	FailoverChain::usable( array(), array( 'smsir' => true ) )
);

tisa_finish();
