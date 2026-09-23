<?php
/**
 * Event log browser with filters and CSV export.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Admin;

use TisaOtp\Bootable;
use TisaOtp\Config\Settings;
use TisaOtp\Log\LogStore;
use TisaOtp\Log\Report;

defined( 'ABSPATH' ) || exit;

final class LogsScreen implements Bootable {

	const SLUG = 'tisa-otp-logs';

	const PER_PAGE = 40;

	/** @var LogStore */
	private $logs;

	/** @var Settings */
	private $settings;

	public function __construct( LogStore $logs, Settings $settings ) {
		$this->logs     = $logs;
		$this->settings = $settings;
	}

	public function boot(): void {
		add_action( 'admin_post_tisa_otp_export_logs', array( $this, 'export' ) );
		add_action( 'admin_post_tisa_otp_clear_logs', array( $this, 'clear' ) );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$filters = $this->filters();
		$page    = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$offset  = ( $page - 1 ) * self::PER_PAGE;

		$rows   = $this->logs->query( array_merge( $filters, array( 'limit' => self::PER_PAGE + 1, 'offset' => $offset ) ) );
		$hasMore = count( $rows ) > self::PER_PAGE;

		if ( $hasMore ) {
			array_pop( $rows );
		}

		echo '<div class="wrap tisa-wrap" dir="rtl"><div class="tisa-header"><div class="tisa-header__title"><h1>' . esc_html__( 'رویدادها', 'tisa-otp' ) . '</h1></div></div>';

		ScreenNav::render( self::SLUG );

		$this->summaryBar();
		$this->filterBar( $filters );

		echo '<div class="tisa-panel"><table class="widefat tisa-log-table"><thead><tr>';

		foreach ( array( __( 'زمان', 'tisa-otp' ), __( 'سطح', 'tisa-otp' ), __( 'رویداد', 'tisa-otp' ), __( 'کانال/سامانه', 'tisa-otp' ), __( 'شماره', 'tisa-otp' ), __( 'خطا', 'tisa-otp' ), __( 'توضیح', 'tisa-otp' ) ) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		if ( array() === $rows ) {
			echo '<tr><td colspan="7" class="tisa-empty">' . esc_html__( 'رویدادی ثبت نشده است.', 'tisa-otp' ) . '</td></tr>';
		}

		foreach ( $rows as $row ) {
			printf(
				'<tr class="tisa-sev-%1$s"><td dir="ltr">%2$s</td><td>%1$s</td><td><code>%3$s</code></td><td>%4$s</td><td dir="ltr">%5$s</td><td>%6$s</td><td>%7$s</td></tr>',
				esc_html( (string) $row->severity ),
				esc_html( (string) $row->created_at ),
				esc_html( (string) $row->event ),
				esc_html( trim( (string) $row->channel . ( $row->gateway ? ' / ' . (string) $row->gateway : '' ) ) ),
				esc_html( (string) $row->phone_mask ),
				esc_html( (string) $row->error_code ),
				esc_html( (string) $row->message )
			);
		}

		echo '</tbody></table>';

		$this->pagination( $page, $hasMore, $filters );

		echo '</div></div>';
	}

	private function summaryBar(): void {
		$totals = $this->logs->totals();

		echo '<div class="tisa-statbar">';

		foreach (
			array(
				__( 'کل رویدادها', 'tisa-otp' )    => $totals['total'],
				__( '۲۴ ساعت گذشته', 'tisa-otp' )  => $totals['last_day'],
				__( 'خطاها', 'tisa-otp' )          => $totals['errors'],
				__( 'روزهای نگهداری', 'tisa-otp' ) => $this->settings->int( 'logs_keep_days', 7 ),
			) as $label => $value
		) {
			printf( '<div class="tisa-stat"><span class="tisa-stat__value">%1$s</span><span class="tisa-stat__label">%2$s</span></div>', esc_html( (string) $value ), esc_html( (string) $label ) );
		}

		echo '</div>';
	}

	private function filterBar( array $filters ): void {
		$base = admin_url( 'admin.php' );

		echo '<form method="get" action="' . esc_url( $base ) . '" class="tisa-filters">';
		echo '<input type="hidden" name="page" value="tisa-otp-logs">';

		echo '<select name="severity"><option value="">' . esc_html__( 'همه سطح‌ها', 'tisa-otp' ) . '</option>';
		foreach ( array( 'debug', 'info', 'notice', 'warning', 'error' ) as $severity ) {
			printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $severity ), selected( $filters['severity'], $severity, false ) );
		}
		echo '</select>';

		echo '<select name="event"><option value="">' . esc_html__( 'همه رویدادها', 'tisa-otp' ) . '</option>';
		foreach ( $this->logs->events() as $event ) {
			printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $event ), selected( $filters['event'], $event, false ) );
		}
		echo '</select>';

		printf( '<input type="search" name="search" value="%s" placeholder="%s">', esc_attr( $filters['search'] ), esc_attr__( 'جست‌وجو در پیام، شماره یا خطا', 'tisa-otp' ) );

		echo '<select name="hours">';
		foreach ( array( 0 => __( 'همه بازه‌ها', 'tisa-otp' ), 1 => __( '۱ ساعت', 'tisa-otp' ), 6 => __( '۶ ساعت', 'tisa-otp' ), 24 => __( '۲۴ ساعت', 'tisa-otp' ), 72 => __( '۳ روز', 'tisa-otp' ), 168 => __( '۷ روز', 'tisa-otp' ) ) as $value => $label ) {
			printf( '<option value="%1$d"%2$s>%3$s</option>', (int) $value, selected( (int) $filters['hours'], (int) $value, false ), esc_html( $label ) );
		}
		echo '</select>';

		submit_button( __( 'فیلتر', 'tisa-otp' ), 'secondary', 'filter', false );

		echo '</form>';

		echo '<div class="tisa-actions">';
		printf(
			'<a class="button" href="%s">%s</a>',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tisa_otp_export_logs&' . http_build_query( $filters ) ), 'tisa_otp_export' ) ),
			esc_html__( 'خروجی CSV', 'tisa-otp' )
		);
		printf(
			'<a class="button button-link-delete" href="%s" data-tisa-confirm>%s</a>',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tisa_otp_clear_logs' ), 'tisa_otp_clear_logs' ) ),
			esc_html__( 'پاک کردن همه', 'tisa-otp' )
		);
		echo '</div>';
	}

	private function pagination( int $page, bool $hasMore, array $filters ): void {
		if ( $page < 2 && ! $hasMore ) {
			return;
		}

		echo '<div class="tisa-pager">';

		if ( $page > 1 ) {
			printf( '<a class="button" href="%s">%s</a>', esc_url( $this->pageUrl( $page - 1, $filters ) ), esc_html__( 'صفحه قبل', 'tisa-otp' ) );
		}

		echo '<span class="tisa-pager__current">' . esc_html( sprintf( /* translators: %d: page number */ __( 'صفحه %d', 'tisa-otp' ), $page ) ) . '</span>';

		if ( $hasMore ) {
			printf( '<a class="button" href="%s">%s</a>', esc_url( $this->pageUrl( $page + 1, $filters ) ), esc_html__( 'صفحه بعد', 'tisa-otp' ) );
		}

		echo '</div>';
	}

	private function pageUrl( int $page, array $filters ): string {
		return add_query_arg( array_merge( $filters, array( 'page' => 'tisa-otp-logs', 'paged' => $page ) ), admin_url( 'admin.php' ) );
	}

	private function filters(): array {
		return array(
			'severity' => isset( $_GET['severity'] ) ? sanitize_key( wp_unslash( $_GET['severity'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'event'    => isset( $_GET['event'] ) ? sanitize_text_field( wp_unslash( $_GET['event'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'gateway'  => isset( $_GET['gateway'] ) ? sanitize_key( wp_unslash( $_GET['gateway'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'search'   => isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'hours'    => isset( $_GET['hours'] ) ? (int) $_GET['hours'] : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);
	}

	public function export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'tisa-otp' ) );
		}

		check_admin_referer( 'tisa_otp_export' );

		$rows = $this->logs->query(
			array(
				'limit'    => 500,
				'offset'   => 0,
				'severity' => isset( $_GET['severity'] ) ? sanitize_key( wp_unslash( $_GET['severity'] ) ) : '',
				'event'    => isset( $_GET['event'] ) ? sanitize_text_field( wp_unslash( $_GET['event'] ) ) : '',
				'gateway'  => isset( $_GET['gateway'] ) ? sanitize_key( wp_unslash( $_GET['gateway'] ) ) : '',
				'search'   => isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '',
				'hours'    => isset( $_GET['hours'] ) ? (int) $_GET['hours'] : 0,
			)
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=tisa-otp-events-' . gmdate( 'Ymd-His' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );

		fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM so Excel reads Persian correctly.
		fputcsv( $out, Report::row( array( 'created_at', 'severity', 'event', 'channel', 'gateway', 'error_code', 'phone_mask', 'user_id', 'message' ) ) );

		foreach ( $rows as $row ) {
			fputcsv(
				$out,
				Report::row( array(
					$row->created_at,
					$row->severity,
					$row->event,
					$row->channel,
					$row->gateway,
					$row->error_code,
					$row->phone_mask,
					$row->user_id,
					$row->message,
				) )
			);
		}

		fclose( $out );
		exit;
	}

	public function clear(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'tisa-otp' ) );
		}

		check_admin_referer( 'tisa_otp_clear_logs' );

		$this->logs->truncate();

		wp_safe_redirect( admin_url( 'admin.php?page=tisa-otp-logs&cleared=1' ) );
		exit;
	}
}
