<?php

namespace Signa\Log;

defined( 'ABSPATH' ) || exit;

final class Report {
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

	public static function row( array $cells ): array {
		return array_map( array( self::class, 'cell' ), $cells );
	}

	const SENT = 'code.sent';
	const FAILED = 'code.not_sent';
	const REJECTED = 'guard.rejected';
	const CREATED = 'user.created';

	public static function requestEvents(): array {
		return array( self::SENT, self::FAILED, self::REJECTED );
	}

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
			'rate'     => $requests > 0 ? round( ( $sent / $requests ) * 100, 1 ) : 0.0,
		);
	}

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

	public static function peak( array $series ): int {
		$peak = 0;

		foreach ( $series as $counts ) {
			$peak = max( $peak, array_sum( (array) $counts ) );
		}

		return $peak;
	}

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
