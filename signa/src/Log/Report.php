<?php
/**
 * The arithmetic behind the report screen.
 *
 * Kept apart from the database on purpose: a screen that turns counts into a
 * success rate is easy to get subtly wrong (a division by zero here, a missing
 * day there), and this way the whole thing runs in a plain PHP test.
 *
 * @package Signa
 */

namespace Signa\Log;

defined( 'ABSPATH' ) || exit;

final class Report {

	/**
	 * Make a cell safe for a spreadsheet.
	 *
	 * A log message can start with `=`, `+`, `-` or `@` — a gateway's error
	 * text, a user agent, something a visitor typed. Excel, LibreOffice and
	 * Sheets treat such a cell as a formula and will happily run `=HYPERLINK`,
	 * `=cmd|…` or a DDE call when the owner opens the export. The file leaves
	 * *our* server and executes on *their* machine, so this is the one place
	 * where escaping still matters after the data has left us. Prefixing an
	 * apostrophe is what every spreadsheet reads as "this is text".
	 *
	 * @param mixed $value Cell value.
	 */
	public static function cell( $value ): string {
		$text = (string) $value;

		if ( '' === $text ) {
			return $text;
		}

		if ( false !== strpos( "=+-@\t\r", $text[0] ) ) {
			return "'" . $text;
		}

		return $text;
	}

	/**
	 * One row, every cell made safe for a spreadsheet.
	 *
	 * @param array<int,mixed> $cells Row values.
	 * @return array<int,string>
	 */
	public static function row( array $cells ): array {
		return array_map( array( self::class, 'cell' ), $cells );
	}

	/** A code left the building. */
	const SENT = 'code.sent';

	/** A code was requested and no channel could deliver it. */
	const FAILED = 'code.not_sent';

	/** A request that never reached a channel because a guard said no. */
	const REJECTED = 'guard.rejected';

	/** A new account was created from the form. */
	const CREATED = 'user.created';

	/**
	 * Everything that counts as "somebody asked for a code".
	 *
	 * @return string[]
	 */
	public static function requestEvents(): array {
		return array( self::SENT, self::FAILED, self::REJECTED );
	}

	/**
	 * The events that explain a failure, worst first when ranked.
	 *
	 * @return string[]
	 */
	public static function failureEvents(): array {
		return array(
			self::FAILED,
			self::REJECTED,
			'gateway.failed',
			'captcha.rejected',
			'captcha.transport_failed',
			'registration.failed',
			'user.create_failed',
			'lookup.ambiguous',
		);
	}

	/**
	 * Human label for an event name, used by the report tables.
	 */
	public static function label( string $event ): string {
		$labels = array(
			self::SENT                    => __( 'کد ارسال شد', 'signa' ),
			self::FAILED                  => __( 'ارسال ناموفق', 'signa' ),
			self::REJECTED                => __( 'رد توسط محافظ‌ها', 'signa' ),
			self::CREATED                 => __( 'حساب تازه', 'signa' ),
			'gateway.failed'              => __( 'خطای سامانه پیامکی', 'signa' ),
			'captcha.rejected'            => __( 'کپچا رد شد', 'signa' ),
			'captcha.transport_failed'    => __( 'کپچا پاسخ نداد', 'signa' ),
			'registration.failed'         => __( 'عضویت ناتمام', 'signa' ),
			'user.create_failed'          => __( 'ساخت حساب ناموفق', 'signa' ),
			'lookup.ambiguous'            => __( 'شماره روی چند حساب', 'signa' ),
			'gateway.failover_used'       => __( 'استفاده از سامانه پشتیبان', 'signa' ),
			'gateway.breaker_skipped'     => __( 'سامانه کنارگذاشته‌شده', 'signa' ),
			'guard.trusted_skip'          => __( 'معافیت شماره مورد اعتماد', 'signa' ),
			'session.blocked_role'        => __( 'نقش مسدود‌شده', 'signa' ),
			'import.finished'             => __( 'واردسازی تمام شد', 'signa' ),
		);

		return isset( $labels[ $event ] ) ? $labels[ $event ] : $event;
	}

	/**
	 * Turn per-event counts into the numbers the dashboard prints.
	 *
	 * @param array<string,int> $counts event => total.
	 * @return array{requests:int,sent:int,failed:int,rejected:int,created:int,rate:float}
	 */
	public static function kpis( array $counts ): array {
		$sent     = isset( $counts[ self::SENT ] ) ? (int) $counts[ self::SENT ] : 0;
		$failed   = isset( $counts[ self::FAILED ] ) ? (int) $counts[ self::FAILED ] : 0;
		$rejected = isset( $counts[ self::REJECTED ] ) ? (int) $counts[ self::REJECTED ] : 0;
		$created  = isset( $counts[ self::CREATED ] ) ? (int) $counts[ self::CREATED ] : 0;

		$requests = $sent + $failed + $rejected;

		return array(
			'requests' => $requests,
			'sent'     => $sent,
			'failed'   => $failed + $rejected,
			'rejected' => $rejected,
			'created'  => $created,
			/*
			 * Success is measured against the requests the site could answer.
			 * A guard saying no is a failure for the user, but it is not the
			 * SMS system failing, so the two are reported separately too.
			 */
			'rate'     => $requests > 0 ? round( ( $sent / $requests ) * 100, 1 ) : 0.0,
		);
	}

	/**
	 * A tally keyed by day, padded to exactly `$days` days ending today.
	 *
	 * The chart draws one bar per day, so a quiet Tuesday has to be a zero —
	 * not a missing bar that shifts every other day left.
	 *
	 * @param array<string,int> $tally day (Y-m-d) => count.
	 * @return array<string,int>
	 */
	public static function days( array $tally, int $days, string $today = '' ): array {
		$days  = max( 1, $days );
		$today = '' !== $today ? $today : gmdate( 'Y-m-d' );
		$out   = array();

		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$day         = gmdate( 'Y-m-d', strtotime( $today . ' -' . $i . ' day' ) );
			$out[ $day ] = isset( $tally[ $day ] ) ? (int) $tally[ $day ] : 0;
		}

		return $out;
	}

	/**
	 * Merge several per-day tallies into one series per event.
	 *
	 * @param array<string,array<string,int>> $tallies event => (day => count).
	 * @return array<string,array<string,int>> day => (event => count)
	 */
	public static function series( array $tallies, int $days, string $today = '' ): array {
		$series = array();

		foreach ( $tallies as $event => $tally ) {
			foreach ( self::days( (array) $tally, $days, $today ) as $day => $count ) {
				if ( ! isset( $series[ $day ] ) ) {
					$series[ $day ] = array();
				}

				$series[ $day ][ $event ] = $count;
			}
		}

		ksort( $series );

		return $series;
	}

	/**
	 * The tallest bar in a day series, so the chart can scale to it.
	 *
	 * @param array<string,array<string,int>> $series day => (event => count).
	 */
	public static function peak( array $series ): int {
		$peak = 0;

		foreach ( $series as $counts ) {
			$peak = max( $peak, array_sum( (array) $counts ) );
		}

		return $peak;
	}

	/**
	 * Rank failure reasons, biggest first, with stable ties.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows with event, error_code and total.
	 * @return array<int,array<string,mixed>>
	 */
	public static function rank( array $rows, int $limit = 8 ): array {
		$rows = array_values(
			array_filter(
				$rows,
				static function ( $row ): bool {
					return is_array( $row ) && (int) ( $row['total'] ?? 0 ) > 0;
				}
			)
		);

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				$total = (int) $b['total'] <=> (int) $a['total'];

				if ( 0 !== $total ) {
					return $total;
				}

				return strcmp( (string) ( $a['event'] ?? '' ) . '|' . (string) ( $a['error_code'] ?? '' ), (string) ( $b['event'] ?? '' ) . '|' . (string) ( $b['error_code'] ?? '' ) );
			}
		);

		return array_slice( $rows, 0, max( 1, $limit ) );
	}
}
