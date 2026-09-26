<?php

namespace Signa\Admin;

use Signa\Bootable;
use Signa\Config\Settings;
use Signa\Log\LogStore;
use Signa\Log\Report;

defined( 'ABSPATH' ) || exit;

final class ReportScreen implements Bootable {
	const SLUG = 'signa-reports';
	const RANGES = array( 7, 14, 30 );
	private $logs;
	private $settings;

	public function __construct( LogStore $logs, Settings $settings ) {
		$this->logs     = $logs;
		$this->settings = $settings;
	}

	public function boot(): void {
		add_action( 'admin_post_signa_export_report', array( $this, 'export' ) );
	}

	public function range(): int {
		$requested = isset( $_GET['range'] ) ? (int) $_GET['range'] : 14;

		return in_array( $requested, self::RANGES, true ) ? $requested : 14;
	}

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

		Layout::open( 'reports', $this->settings, __( 'گزارش‌ها و رویدادها', 'signa' ) );

		echo '<div class="signa-sechead"><div class="signa-sechead__text"><h2 class="signa-sechead__title">' . esc_html__( 'گزارش‌ها و رویدادها', 'signa' ) . '</h2></div></div>';

		$this->body( $this->range() );

		Layout::close();
	}

	public function body( int $days ): void {
		$data = $this->data( $days );
		$days = (int) $data['days'];

		echo '<div class="signa-report-bar">';
		$this->rangeBar( $days );
		printf(
			'<a class="signa-btn signa-btn--gh signa-btn--sm" href="%1$s">%2$s<span>%3$s</span></a>',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=signa_export_report&range=' . $days ), 'signa_export_report' ) ),
			Icons::svg( 'download', 14 ),
			esc_html__( 'دانلود CSV', 'signa' )
		);
		echo '</div>';

		if ( ! $this->settings->bool( 'logs_enabled', true ) ) {
			echo '<div class="signa-notice signa-notice--warning">' . Icons::svg( 'alert' ) . '<p>' . esc_html__( 'ثبت رویدادها خاموش است؛ عددهای زیر فقط تا لحظهٔ خاموش شدن را نشان می‌دهند.', 'signa' ) . '</p></div>';
		}

		$this->kpis( $data['kpis'] );
		$this->chart( $data['series'], (int) $data['peak'], $days );

		echo '<section class="signa-card" id="signa-card-recent">';
		Layout::cardHead( __( 'آخرین رویدادها', 'signa' ), 'list', __( 'زنده، از همان جدول رویدادها', 'signa' ) );
		echo '<div class="signa-card__body">';
		self::eventsTable( $this->logs->query( array( 'limit' => 8 ) ) );
		printf(
			'<p class="signa-card__more"><a href="%1$s">%2$s</a></p>',
			esc_url( admin_url( 'admin.php?page=' . LogsScreen::SLUG ) ),
			esc_html__( 'همهٔ رویدادها با جست‌وجو و فیلتر ←', 'signa' )
		);
		echo '</div></section>';

		$this->failureTable( $data['failures'] );

		printf(
			'<p class="signa-footnote">%s</p>',
			esc_html(
				sprintf(
					__( '%1$s رویداد ثبت شده است؛ %2$s موردش خطا بوده. رویدادها %3$s روز نگه داشته می‌شوند.', 'signa' ),
					number_format_i18n( (int) $data['totals']['total'] ),
					number_format_i18n( (int) $data['totals']['errors'] ),
					number_format_i18n( $this->settings->int( 'logs_keep_days', 7 ) )
				)
			)
		);
	}

	private function rangeBar( int $days ): void {
		$base = SettingsScreen::tabUrl( 'reports' );

		echo '<nav class="signa-range" aria-label="' . esc_attr__( 'بازهٔ گزارش', 'signa' ) . '">';

		foreach ( self::RANGES as $range ) {
			printf(
				'<a class="signa-range__item signa-chip%1$s" href="%2$s"%3$s>%4$s</a>',
				$range === $days ? ' is-active' : '',
				esc_url( add_query_arg( 'range', $range, $base ) ),
				$range === $days ? ' aria-current="true"' : '',
				esc_html( sprintf(  __( '%s روز', 'signa' ), number_format_i18n( $range ) ) )
			);
		}

		echo '</nav>';
	}

	public static function kpi( string $label, string $value, string $icon, string $tone = '', string $hint = '' ): void {
		printf(
			'<div class="%1$s"><span class="signa-kpi__tile">%2$s</span><span class="signa-kpi__text"><span class="signa-kpi__value">%3$s</span><span class="signa-kpi__label">%4$s</span>%5$s</span></div>',
			esc_attr( trim( 'signa-kpi ' . $tone ) ),
			Icons::svg( $icon, 18 ),
			esc_html( $value ),
			esc_html( $label ),
			'' !== $hint ? '<span class="signa-kpi__hint">' . esc_html( $hint ) . '</span>' : ''
		);
	}

	private function kpis( array $kpis ): void {
		echo '<div class="signa-kpis signa-kpis--6">';

		self::kpi( __( 'درخواست‌ها', 'signa' ), number_format_i18n( (int) $kpis['requests'] ), 'send', '', __( 'هر بار که کاربر کد خواست', 'signa' ) );
		self::kpi( __( 'موفق', 'signa' ), number_format_i18n( (int) $kpis['sent'] ), 'check', 'is-good', __( 'کد ارسال شد', 'signa' ) );
		self::kpi( __( 'ناموفق', 'signa' ), number_format_i18n( (int) $kpis['failed'] ), 'alert', (int) $kpis['failed'] > 0 ? 'is-bad' : 'is-quiet', __( 'ارسال نشد یا رد شد', 'signa' ) );
		self::kpi( __( 'نرخ موفقیت', 'signa' ), number_format_i18n( (float) $kpis['rate'], 1 ) . '٪', 'chart', 'is-rate', __( 'از درخواست‌ها', 'signa' ) );
		self::kpi( __( 'حساب تازه', 'signa' ), number_format_i18n( (int) $kpis['created'] ), 'user-add', '', __( 'عضویت کامل‌شده', 'signa' ) );
		self::kpi( __( 'رد محافظ‌ها', 'signa' ), number_format_i18n( (int) $kpis['rejected'] ), 'shield', '', __( 'محدودیت، ربات، مسدودی', 'signa' ) );

		echo '</div>';
	}

	private function chart( array $series, int $peak, int $days ): void {
		if ( array() === $series ) {
			return;
		}

		$peak = max( 1, $peak );

		echo '<section class="signa-card" id="signa-card-chart">';
		Layout::cardHead(
			__( 'ارسال‌ها و خطاها، روز به روز', 'signa' ),
			'chart',
			sprintf(  __( 'بازه: %s روز گذشته.', 'signa' ), number_format_i18n( $days ) ),
			sprintf(
				'<span class="signa-legend"><span class="signa-legend__item"><span class="signa-dot is-sent"></span>%1$s</span><span class="signa-legend__item"><span class="signa-dot is-failed"></span>%2$s</span></span>',
				esc_html__( 'ارسال‌شده', 'signa' ),
				esc_html__( 'ناموفق', 'signa' )
			)
		);

		echo '<div class="signa-card__body">';
		echo '<div class="signa-chart" role="img" aria-label="' . esc_attr( sprintf(  __( 'نمودار ارسال و خطا در %d روز گذشته', 'signa' ), $days ) ) . '">';

		foreach ( $series as $day => $counts ) {
			$sent   = isset( $counts[ Report::SENT ] ) ? (int) $counts[ Report::SENT ] : 0;
			$failed = isset( $counts[ Report::FAILED ] ) ? (int) $counts[ Report::FAILED ] : 0;
			$total  = $sent + $failed;

			printf(
				'<div class="signa-chart__col" title="%5$s"><span class="signa-chart__count">%1$s</span><div class="signa-chart__stack"><span class="signa-chart__bar is-failed" style="height:%2$s%%"></span><span class="signa-chart__bar is-sent" style="height:%3$s%%"></span></div><span class="signa-chart__day" dir="ltr">%4$s</span></div>',
				$total > 0 ? esc_html( number_format_i18n( $total ) ) : '',
				esc_attr( (string) round( ( $failed / $peak ) * 100, 2 ) ),
				esc_attr( (string) round( ( $sent / $peak ) * 100, 2 ) ),
				esc_html( gmdate( 'm-d', (int) strtotime( $day ) ) ),
				esc_attr( sprintf(  __( '%1$s: %2$s ارسال، %3$s ناموفق', 'signa' ), $day, number_format_i18n( $sent ), number_format_i18n( $failed ) ) )
			);
		}

		echo '</div></div></section>';
	}

	private function failureTable( array $failures ): void {
		echo '<section class="signa-card" id="signa-card-failures">';
		Layout::cardHead( __( 'بیشترین دلیل‌های شکست', 'signa' ), 'alert' );
		echo '<div class="signa-card__body">';

		if ( array() === $failures ) {
			echo '<p class="signa-empty">' . esc_html__( 'در این بازه هیچ شکستی ثبت نشده است.', 'signa' ) . '</p></div></section>';

			return;
		}

		$top = max( 1, (int) $failures[0]['total'] );

		echo '<div class="signa-table-wrap"><table class="signa-table signa-report-table"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'رویداد', 'signa' ) . '</th><th scope="col">' . esc_html__( 'کد خطا', 'signa' ) . '</th><th scope="col">' . esc_html__( 'تعداد', 'signa' ) . '</th><th scope="col">' . esc_html__( 'سهم', 'signa' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $failures as $row ) {
			printf(
				'<tr><td>%1$s</td><td><code class="signa-code" dir="ltr">%2$s</code></td><td class="signa-num">%3$s</td><td><span class="signa-meter"><span class="signa-meter__fill" style="width:%4$s%%"></span></span></td></tr>',
				esc_html( Report::label( (string) $row['event'] ) ),
				esc_html( '' === (string) $row['error_code'] ? '—' : (string) $row['error_code'] ),
				esc_html( number_format_i18n( (int) $row['total'] ) ),
				esc_attr( (string) round( ( (int) $row['total'] / $top ) * 100, 1 ) )
			);
		}

		echo '</tbody></table></div></div></section>';
	}

	public static function eventsTable( array $rows, bool $compact = false ): void {
		if ( array() === $rows ) {
			echo '<p class="signa-empty">' . esc_html__( 'هنوز رویدادی ثبت نشده است.', 'signa' ) . '</p>';

			return;
		}

		$channels = array(
			'sms'   => __( 'پیامک', 'signa' ),
			'email' => __( 'ایمیل', 'signa' ),
		);

		echo '<div class="signa-table-wrap"><table class="signa-table signa-events-table">';

		if ( ! $compact ) {
			echo '<thead><tr><th scope="col">' . esc_html__( 'زمان', 'signa' ) . '</th><th scope="col">' . esc_html__( 'رویداد', 'signa' ) . '</th><th scope="col">' . esc_html__( 'کاربر', 'signa' ) . '</th><th scope="col">' . esc_html__( 'کانال', 'signa' ) . '</th><th scope="col">' . esc_html__( 'نتیجه', 'signa' ) . '</th></tr></thead>';
		}

		echo '<tbody>';

		foreach ( $rows as $row ) {
			$bad     = in_array( (string) $row->event, Report::failureEvents(), true ) || in_array( (string) $row->severity, array( 'error', 'critical', 'warning' ), true );
			$channel = (string) $row->channel;

			printf(
				'<tr><td class="signa-muted-cell">%1$s</td><td>%2$s</td><td dir="ltr" class="signa-mono-cell">%3$s</td><td>%4$s</td><td><span class="signa-chip %5$s">%6$s</span></td></tr>',
				esc_html( self::ago( (string) $row->created_at ) ),
				esc_html( Report::label( (string) $row->event ) ),
				esc_html( '' !== (string) $row->phone_mask ? (string) $row->phone_mask : '—' ),
				'' !== $channel ? '<span class="signa-chip">' . esc_html( isset( $channels[ $channel ] ) ? $channels[ $channel ] : $channel ) . '</span>' : '—',
				$bad ? 'signa-chip--bad' : 'signa-chip--ok',
				esc_html( $bad ? __( 'ناموفق', 'signa' ) : __( 'موفق', 'signa' ) )
			);
		}

		echo '</tbody></table></div>';
	}

	public static function ago( string $gmt ): string {
		$time = strtotime( $gmt . ' UTC' );

		if ( false === $time ) {
			return $gmt;
		}

		$diff = max( 0, time() - $time );

		if ( $diff < MINUTE_IN_SECONDS ) {
			return __( 'همین حالا', 'signa' );
		}

		return sprintf( __( '%s پیش', 'signa' ), human_time_diff( $time, time() ) );
	}

	public function export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی لازم را ندارید.', 'signa' ) );
		}

		check_admin_referer( 'signa_export_report' );

		$days = isset( $_GET['range'] ) ? (int) $_GET['range'] : 14;
		$data = $this->data( $days );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=signa-report-' . $data['days'] . 'd.csv' );

		$out = fopen( 'php://output', 'w' );

		fwrite( $out, "\xEF\xBB\xBF" );

		fputcsv( $out, Report::row( array( __( 'روز', 'signa' ), __( 'ارسال‌شده', 'signa' ), __( 'ناموفق', 'signa' ) ) ) );

		foreach ( $data['series'] as $day => $counts ) {
			fputcsv(
				$out,
				Report::row(
					array(
						$day,
						isset( $counts[ Report::SENT ] ) ? (int) $counts[ Report::SENT ] : 0,
						isset( $counts[ Report::FAILED ] ) ? (int) $counts[ Report::FAILED ] : 0,
					)
				)
			);
		}

		fputcsv( $out, array() );
		fputcsv( $out, Report::row( array( __( 'دلیل', 'signa' ), __( 'کد خطا', 'signa' ), __( 'تعداد', 'signa' ) ) ) );

		foreach ( $data['failures'] as $row ) {
			fputcsv( $out, Report::row( array( Report::label( (string) $row['event'] ), (string) $row['error_code'], (int) $row['total'] ) ) );
		}

		fclose( $out );

		exit;
	}
}
