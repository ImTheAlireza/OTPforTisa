<?php
/**
 * Reports: requests, successes, failures and the reasons between them.
 *
 * The events screen answers "what happened at 14:03?". This one answers "is
 * this thing working?" — the numbers a site owner actually looks at after a
 * change, over a range they can pick.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Admin;

use TisaOtp\Bootable;
use TisaOtp\Config\Settings;
use TisaOtp\Log\LogStore;
use TisaOtp\Log\Report;

defined( 'ABSPATH' ) || exit;

final class ReportScreen implements Bootable {

	const SLUG = 'tisa-otp-reports';

	/** Ranges the screen offers, in days. Anything else falls back to 14. */
	const RANGES = array( 7, 14, 30 );

	/** @var LogStore */
	private $logs;

	/** @var Settings */
	private $settings;

	public function __construct( LogStore $logs, Settings $settings ) {
		$this->logs     = $logs;
		$this->settings = $settings;
	}

	public function boot(): void {
		add_action( 'admin_post_tisa_otp_export_report', array( $this, 'export' ) );
	}

	public function range(): int {
		$requested = isset( $_GET['range'] ) ? (int) $_GET['range'] : 14; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return in_array( $requested, self::RANGES, true ) ? $requested : 14;
	}

	/**
	 * Everything the screen and the REST summary need, in one array.
	 *
	 * @return array<string,mixed>
	 */
	public function data( int $days ): array {
		$days = in_array( $days, self::RANGES, true ) ? $days : 14;

		$counts = $this->logs->countByEvent(
			array_merge( Report::requestEvents(), array( Report::CREATED ) ),
			$days
		);

		$series = Report::series(
			$this->logs->series( array( Report::SENT, Report::FAILED ), $days ),
			$days
		);

		return array(
			'days'     => $days,
			'kpis'     => Report::kpis( $counts ),
			'totals'   => $this->logs->totals(),
			'series'   => $series,
			'peak'     => Report::peak( $series ),
			'failures' => Report::rank( $this->logs->reasons( Report::failureEvents(), $days, 20 ), 8 ),
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$days = $this->range();
		$data = $this->data( $days );

		echo '<div class="wrap tisa-wrap" dir="rtl"><div class="tisa-header"><div class="tisa-header__title"><h1>' . esc_html__( 'گزارش‌ها', 'tisa-otp' ) . '</h1></div><div class="tisa-header__actions">';
		$this->rangeBar( $days );
		echo '</div></div>';

		$this->kpis( $data['kpis'], $days );
		$this->chart( $data['series'], (int) $data['peak'], $days );
		$this->failureTable( $data['failures'] );

		echo '<div class="tisa-panel tisa-panel--foot"><p>';

		printf(
			/* translators: 1: number of events, 2: how many are errors, 3: days of retention */
			esc_html__( '%1$s رویداد ثبت شده است؛ %2$s موردش خطا بوده. رویدادها %3$s روز نگه داشته می‌شوند.', 'tisa-otp' ),
			esc_html( number_format_i18n( (int) $data['totals']['total'] ) ),
			esc_html( number_format_i18n( (int) $data['totals']['errors'] ) ),
			esc_html( number_format_i18n( $this->settings->int( 'logs_keep_days', 7 ) ) )
		);

		echo ' <a href="' . esc_url( admin_url( 'admin.php?page=' . LogsScreen::SLUG ) ) . '">' . esc_html__( 'دیدن تک‌تک رویدادها', 'tisa-otp' ) . '</a></p>';

		printf(
			'<p><a class="button" href="%s">%s</a></p>',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tisa_otp_export_report&range=' . $days ), 'tisa_otp_export_report' ) ),
			esc_html__( 'دانلود گزارش این بازه (CSV)', 'tisa-otp' )
		);

		echo '</div></div>';
	}

	private function rangeBar( int $days ): void {
		$base = admin_url( 'admin.php?page=' . self::SLUG );

		echo '<div class="tisa-range">';

		foreach ( self::RANGES as $range ) {
			printf(
				'<a class="tisa-range__item%1$s" href="%2$s">%3$s</a>',
				$range === $days ? ' is-active' : '',
				esc_url( add_query_arg( 'range', $range, $base ) ),
				esc_html( sprintf( /* translators: %d: number of days */ __( '%d روز', 'tisa-otp' ), $range ) )
			);
		}

		echo '</div>';
	}

	/**
	 * @param array<string,mixed> $kpis
	 */
	private function kpis( array $kpis, int $days ): void {
		$cards = array(
			array( __( 'درخواست‌ها', 'tisa-otp' ), number_format_i18n( (int) $kpis['requests'] ), __( 'هر بار که کاربر کد خواست', 'tisa-otp' ), '' ),
			array( __( 'موفق', 'tisa-otp' ), number_format_i18n( (int) $kpis['sent'] ), __( 'کد ارسال شد', 'tisa-otp' ), 'is-good' ),
			array( __( 'ناموفق', 'tisa-otp' ), number_format_i18n( (int) $kpis['failed'] ), __( 'ارسال نشد یا رد شد', 'tisa-otp' ), (int) $kpis['failed'] > 0 ? 'is-bad' : '' ),
			array( __( 'نرخ موفقیت', 'tisa-otp' ), number_format_i18n( (float) $kpis['rate'], 1 ) . '٪', __( 'از درخواست‌ها', 'tisa-otp' ), 'is-rate' ),
			array( __( 'حساب تازه', 'tisa-otp' ), number_format_i18n( (int) $kpis['created'] ), __( 'عضویت کامل‌شده', 'tisa-otp' ), '' ),
			array( __( 'رد محافظ‌ها', 'tisa-otp' ), number_format_i18n( (int) $kpis['rejected'] ), __( 'محدودیت، ربات، مسدودی', 'tisa-otp' ), '' ),
		);

		echo '<div class="tisa-kpis">';

		foreach ( $cards as $card ) {
			printf(
				'<div class="tisa-kpi %1$s"><span class="tisa-kpi__value">%2$s</span><span class="tisa-kpi__label">%3$s</span><span class="tisa-kpi__hint">%4$s</span></div>',
				esc_attr( $card[3] ),
				esc_html( $card[1] ),
				esc_html( $card[0] ),
				esc_html( $card[2] )
			);
		}

		echo '</div>';

		printf(
			'<p class="tisa-muted">%s</p>',
			esc_html( sprintf( /* translators: %d: number of days */ __( 'بازه: %d روز گذشته.', 'tisa-otp' ), $days ) )
		);
	}

	/**
	 * A bar per day, two segments each: what went out and what did not.
	 *
	 * Bars, not a canvas chart: the numbers are small, and a stack of divs
	 * needs no library and prints in the browser's own colours.
	 *
	 * @param array<string,array<string,int>> $series
	 */
	private function chart( array $series, int $peak, int $days ): void {
		if ( array() === $series ) {
			return;
		}

		$peak = max( 1, $peak );

		echo '<div class="tisa-panel"><h2 class="tisa-panel__title">' . esc_html__( 'ارسال‌ها و خطاها، روز به روز', 'tisa-otp' ) . '</h2>';

		echo '<div class="tisa-chart" role="img" aria-label="' . esc_attr( sprintf( /* translators: %d: number of days */ __( 'نمودار ارسال و خطا در %d روز گذشته', 'tisa-otp' ), $days ) ) . '">';

		foreach ( $series as $day => $counts ) {
			$sent   = isset( $counts[ Report::SENT ] ) ? (int) $counts[ Report::SENT ] : 0;
			$failed = isset( $counts[ Report::FAILED ] ) ? (int) $counts[ Report::FAILED ] : 0;
			$total  = $sent + $failed;

			printf(
				'<div class="tisa-chart__col"><span class="tisa-chart__count">%1$s</span><div class="tisa-chart__stack"><span class="tisa-chart__bar is-failed" style="height:%2$s%%"></span><span class="tisa-chart__bar is-sent" style="height:%3$s%%"></span></div><span class="tisa-chart__day" dir="ltr">%4$s</span></div>',
				$total > 0 ? esc_html( number_format_i18n( $total ) ) : '',
				esc_attr( (string) round( ( $failed / $peak ) * 100, 2 ) ),
				esc_attr( (string) round( ( $sent / $peak ) * 100, 2 ) ),
				esc_html( gmdate( 'm-d', (int) strtotime( $day ) ) )
			);
		}

		echo '</div>';

		printf(
			'<p class="tisa-legend"><span class="tisa-legend__item"><span class="tisa-dot is-sent"></span>%1$s</span><span class="tisa-legend__item"><span class="tisa-dot is-failed"></span>%2$s</span></p>',
			esc_html__( 'ارسال‌شده', 'tisa-otp' ),
			esc_html__( 'ناموفق', 'tisa-otp' )
		);

		echo '</div>';
	}

	/**
	 * @param array<int,array<string,mixed>> $failures
	 */
	private function failureTable( array $failures ): void {
		echo '<div class="tisa-panel"><h2 class="tisa-panel__title">' . esc_html__( 'بیشترین دلیل‌های شکست', 'tisa-otp' ) . '</h2>';

		if ( array() === $failures ) {
			echo '<p class="tisa-empty">' . esc_html__( 'در این بازه هیچ شکستی ثبت نشده است.', 'tisa-otp' ) . '</p></div>';

			return;
		}

		$top = max( 1, (int) $failures[0]['total'] );

		echo '<table class="widefat tisa-report-table"><thead><tr>';
		echo '<th>' . esc_html__( 'رویداد', 'tisa-otp' ) . '</th><th>' . esc_html__( 'کد خطا', 'tisa-otp' ) . '</th><th>' . esc_html__( 'تعداد', 'tisa-otp' ) . '</th><th>' . esc_html__( 'سهم', 'tisa-otp' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $failures as $row ) {
			printf(
				'<tr><td>%1$s</td><td dir="ltr"><code>%2$s</code></td><td>%3$s</td><td><span class="tisa-meter"><span class="tisa-meter__fill" style="width:%4$s%%"></span></span></td></tr>',
				esc_html( Report::label( (string) $row['event'] ) ),
				esc_html( '' === (string) $row['error_code'] ? '—' : (string) $row['error_code'] ),
				esc_html( number_format_i18n( (int) $row['total'] ) ),
				esc_attr( (string) round( ( (int) $row['total'] / $top ) * 100, 1 ) )
			);
		}

		echo '</tbody></table></div>';
	}

	/**
	 * The same numbers as CSV, for a spreadsheet.
	 */
	public function export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی لازم را ندارید.', 'tisa-otp' ) );
		}

		check_admin_referer( 'tisa_otp_export_report' );

		$days = isset( $_GET['range'] ) ? (int) $_GET['range'] : 14; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$data = $this->data( $days );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=tisa-otp-report-' . $data['days'] . 'd.csv' );

		$out = fopen( 'php://output', 'w' );

		// Excel needs the BOM to read Persian headers as UTF-8.
		fwrite( $out, "\xEF\xBB\xBF" );

		fputcsv( $out, array( __( 'روز', 'tisa-otp' ), __( 'ارسال‌شده', 'tisa-otp' ), __( 'ناموفق', 'tisa-otp' ) ) );

		foreach ( $data['series'] as $day => $counts ) {
			fputcsv(
				$out,
				array(
					$day,
					isset( $counts[ Report::SENT ] ) ? (int) $counts[ Report::SENT ] : 0,
					isset( $counts[ Report::FAILED ] ) ? (int) $counts[ Report::FAILED ] : 0,
				)
			);
		}

		fputcsv( $out, array() );
		fputcsv( $out, array( __( 'دلیل', 'tisa-otp' ), __( 'کد خطا', 'tisa-otp' ), __( 'تعداد', 'tisa-otp' ) ) );

		foreach ( $data['failures'] as $row ) {
			fputcsv( $out, array( Report::label( (string) $row['event'] ), (string) $row['error_code'], (int) $row['total'] ) );
		}

		fclose( $out );

		exit;
	}
}
