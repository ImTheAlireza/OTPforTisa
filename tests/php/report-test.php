<?php
/**
 * The report arithmetic: requests, success rate, day padding and ranking.
 *
 * These numbers end up in front of the site owner as "did OTP work today?", so
 * the test is about the edges: no requests at all, a day with nothing in it, a
 * tie between two failure reasons.
 *
 * @package Signa\Tests
 */

require __DIR__ . '/bootstrap.php';

use Signa\Log\Report;

signa_start( 'a report with no traffic says zero, not an error' );

$empty = Report::kpis( array() );

signa_same( 'no requests', 0, $empty['requests'] );
signa_same( 'no sends', 0, $empty['sent'] );
signa_same( 'no failures', 0, $empty['failed'] );
signa_same( 'and a rate of exactly zero, not NAN', 0.0, $empty['rate'] );

signa_start( 'requests, successes and failures add up' );

$counts = array(
	Report::SENT     => 80,
	Report::FAILED   => 15,
	Report::REJECTED => 5,
	Report::CREATED  => 40,
);

$kpis = Report::kpis( $counts );

signa_same( 'requests are sends plus failures plus rejections', 100, $kpis['requests'] );
signa_same( 'successes are the sends', 80, $kpis['sent'] );
signa_same( 'failures include what the guards refused', 20, $kpis['failed'] );
signa_same( 'rejections are still shown on their own', 5, $kpis['rejected'] );
signa_same( 'new accounts are counted separately', 40, $kpis['created'] );
signa_same( 'the success rate is a percentage with one decimal', 80.0, $kpis['rate'] );

$thin = Report::kpis( array( Report::SENT => 2, Report::FAILED => 1 ) );
signa_same( 'two of three is 66.7, not 67', 66.7, $thin['rate'] );

$partial = Report::kpis( array( Report::REJECTED => 4 ) );
signa_same( 'a day of pure rejection is 0% success', 0.0, $partial['rate'] );
signa_same( 'and four failed requests', 4, $partial['failed'] );

signa_start( 'a quiet day is a zero bar, not a missing one' );

$tally = array(
	'2026-09-20' => 3,
	'2026-09-18' => 7,
);

$padded = Report::days( $tally, 4, '2026-09-20' );

signa_same( 'four days come back', 4, count( $padded ) );
signa_same( 'in order, oldest first', array( '2026-09-17', '2026-09-18', '2026-09-19', '2026-09-20' ), array_keys( $padded ) );
signa_same( 'the day with no traffic is a zero', 0, $padded['2026-09-17'] );
signa_same( 'and the counted days keep their numbers', 3, $padded['2026-09-20'] );
signa_same( 'even out of order input lands in the right slot', 7, $padded['2026-09-18'] );

signa_start( 'two series share one set of days' );

$series = Report::series(
	array(
		Report::SENT   => array( '2026-09-20' => 5 ),
		Report::FAILED => array( '2026-09-19' => 2 ),
	),
	3,
	'2026-09-20'
);

signa_same( 'the series has three days', 3, count( $series ) );
signa_same( 'the send lands on the last day', 5, $series['2026-09-20'][ Report::SENT ] );
signa_same( 'the failure lands on the day before', 2, $series['2026-09-19'][ Report::FAILED ] );
signa_same( 'a day with one event still carries the other as zero', 0, $series['2026-09-19'][ Report::SENT ] );
signa_same( 'the chart scales to the busiest day', 5, Report::peak( $series ) );

signa_start( 'failure reasons are ranked, and ties do not shuffle' );

$ranked = Report::rank(
	array(
		array( 'event' => 'code.not_sent', 'error_code' => 'gateway_error', 'total' => 9 ),
		array( 'event' => 'guard.rejected', 'error_code' => 'throttled', 'total' => 4 ),
		array( 'event' => 'captcha.rejected', 'error_code' => 'invalid-input-response', 'total' => 0 ),
		array( 'event' => 'gateway.failed', 'error_code' => 'timeout', 'total' => 4 ),
	),
	3
);

signa_same( 'the worst reason is first', 'gateway_error', $ranked[0]['error_code'] );
signa_same( 'zero rows are dropped, not printed', 3, count( $ranked ) );
signa_same( 'a tie keeps a stable alphabetical order', 'timeout', $ranked[1]['error_code'] );
signa_same( 'and the next tie-break follows it', 'throttled', $ranked[2]['error_code'] );

$single = Report::rank( array( array( 'event' => 'code.not_sent', 'error_code' => '', 'total' => 3 ) ) );
signa_same( 'a failure with no error code is still counted', 1, count( $single ) );

signa_start( 'every event the dashboard prints has a Persian label' );

foreach ( array_merge( Report::requestEvents(), Report::failureEvents(), array( Report::CREATED ) ) as $event ) {
	signa_check( $event . ' reads as a sentence, not as a slug', Report::label( $event ) !== $event );
}

signa_same( 'and an unknown event falls back to its own name', 'something.new', Report::label( 'something.new' ) );

signa_finish();
