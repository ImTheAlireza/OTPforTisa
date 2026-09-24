<?php
/**
 * Event log browser with filters and CSV export.
 *
 * @package Signa
 */

namespace Signa\Admin;

use Signa\Bootable;
use Signa\Config\Settings;
use Signa\Log\LogStore;
use Signa\Log\Report;

defined( 'ABSPATH' ) || exit;

final class LogsScreen implements Bootable {

	const SLUG = 'signa-logs';

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
		add_action( 'admin_post_signa_export_logs', array( $this, 'export' ) );
		add_action( 'admin_post_signa_clear_logs', array( $this, 'clear' ) );
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

		Layout::open( self::SLUG, $this->settings, __( 'همهٔ رویدادها', 'signa' ) );

		echo '<div class="signa-sechead"><div class="signa-sechead__text"><h2 class="signa-sechead__title">' . esc_html__( 'همهٔ رویدادها', 'signa' ) . '</h2>';
		echo '<p class="signa-sechead__desc">' . esc_html__( 'تک‌تک رویدادها با جست‌وجو، فیلتر و خروجی CSV. شماره‌ها فقط ماسک‌شده ذخیره می‌شوند.', 'signa' ) . '</p></div></div>';

		$this->summaryBar();
		$this->filterBar( $filters );

		echo '<div class="signa-panel"><div class="signa-table-wrap"><table class="widefat signa-table signa-log-table"><thead><tr>';

		foreach ( array( __( 'زمان', 'signa' ), __( 'سطح', 'signa' ), __( 'رویداد', 'signa' ), __( 'کانال/سامانه', 'signa' ), __( 'شماره', 'signa' ), __( 'خطا', 'signa' ), __( 'توضیح', 'signa' ) ) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		if ( array() === $rows ) {
			echo '<tr><td colspan="7" class="signa-empty">' . esc_html__( 'رویدادی ثبت نشده است.', 'signa' ) . '</td></tr>';
		}

		foreach ( $rows as $row ) {
			printf(
				'<tr class="signa-sev-%1$s"><td dir="ltr">%2$s</td><td>%1$s</td><td><code>%3$s</code></td><td>%4$s</td><td dir="ltr">%5$s</td><td>%6$s</td><td>%7$s</td></tr>',
				esc_html( (string) $row->severity ),
				esc_html( (string) $row->created_at ),
				esc_html( (string) $row->event ),
				esc_html( trim( (string) $row->channel . ( $row->gateway ? ' / ' . (string) $row->gateway : '' ) ) ),
				esc_html( (string) $row->phone_mask ),
				esc_html( (string) $row->error_code ),
				esc_html( (string) $row->message )
			);
		}

		echo '</tbody></table></div>';

		$this->pagination( $page, $hasMore, $filters );

		echo '</div>';

		Layout::close();
	}

	private function summaryBar(): void {
		$totals = $this->logs->totals();

		echo '<div class="signa-statbar">';

		foreach (
			array(
				__( 'کل رویدادها', 'signa' )    => $totals['total'],
				__( '۲۴ ساعت گذشته', 'signa' )  => $totals['last_day'],
				__( 'خطاها', 'signa' )          => $totals['errors'],
				__( 'روزهای نگهداری', 'signa' ) => $this->settings->int( 'logs_keep_days', 7 ),
			) as $label => $value
		) {
			printf( '<div class="signa-stat"><span class="signa-stat__value">%1$s</span><span class="signa-stat__label">%2$s</span></div>', esc_html( (string) $value ), esc_html( (string) $label ) );
		}

		echo '</div>';
	}

	private function filterBar( array $filters ): void {
		$base = admin_url( 'admin.php' );

		echo '<form method="get" action="' . esc_url( $base ) . '" class="signa-filters">';
		echo '<input type="hidden" name="page" value="signa-logs">';

		echo '<select name="severity"><option value="">' . esc_html__( 'همه سطح‌ها', 'signa' ) . '</option>';
		foreach ( array( 'debug', 'info', 'notice', 'warning', 'error' ) as $severity ) {
			printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $severity ), selected( $filters['severity'], $severity, false ) );
		}
		echo '</select>';

		echo '<select name="event"><option value="">' . esc_html__( 'همه رویدادها', 'signa' ) . '</option>';
		foreach ( $this->logs->events() as $event ) {
			printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $event ), selected( $filters['event'], $event, false ) );
		}
		echo '</select>';

		printf( '<input type="search" name="search" value="%s" placeholder="%s">', esc_attr( $filters['search'] ), esc_attr__( 'جست‌وجو در پیام، شماره یا خطا', 'signa' ) );

		echo '<select name="hours">';
		foreach ( array( 0 => __( 'همه بازه‌ها', 'signa' ), 1 => __( '۱ ساعت', 'signa' ), 6 => __( '۶ ساعت', 'signa' ), 24 => __( '۲۴ ساعت', 'signa' ), 72 => __( '۳ روز', 'signa' ), 168 => __( '۷ روز', 'signa' ) ) as $value => $label ) {
			printf( '<option value="%1$d"%2$s>%3$s</option>', (int) $value, selected( (int) $filters['hours'], (int) $value, false ), esc_html( $label ) );
		}
		echo '</select>';

		submit_button( __( 'فیلتر', 'signa' ), 'secondary', 'filter', false );

		echo '</form>';

		echo '<div class="signa-actions">';
		printf(
			'<a class="button" href="%s">%s</a>',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=signa_export_logs&' . http_build_query( $filters ) ), 'signa_export' ) ),
			esc_html__( 'خروجی CSV', 'signa' )
		);
		printf(
			'<a class="button button-link-delete" href="%s" data-signa-confirm>%s</a>',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=signa_clear_logs' ), 'signa_clear_logs' ) ),
			esc_html__( 'پاک کردن همه', 'signa' )
		);
		echo '</div>';
	}

	private function pagination( int $page, bool $hasMore, array $filters ): void {
		if ( $page < 2 && ! $hasMore ) {
			return;
		}

		echo '<div class="signa-pager">';

		if ( $page > 1 ) {
			printf( '<a class="button" href="%s">%s</a>', esc_url( $this->pageUrl( $page - 1, $filters ) ), esc_html__( 'صفحه قبل', 'signa' ) );
		}

		echo '<span class="signa-pager__current">' . esc_html( sprintf( /* translators: %d: page number */ __( 'صفحه %d', 'signa' ), $page ) ) . '</span>';

		if ( $hasMore ) {
			printf( '<a class="button" href="%s">%s</a>', esc_url( $this->pageUrl( $page + 1, $filters ) ), esc_html__( 'صفحه بعد', 'signa' ) );
		}

		echo '</div>';
	}

	private function pageUrl( int $page, array $filters ): string {
		return add_query_arg( array_merge( $filters, array( 'page' => 'signa-logs', 'paged' => $page ) ), admin_url( 'admin.php' ) );
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
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'signa' ) );
		}

		check_admin_referer( 'signa_export' );

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
		header( 'Content-Disposition: attachment; filename=signa-events-' . gmdate( 'Ymd-His' ) . '.csv' );

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
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'signa' ) );
		}

		check_admin_referer( 'signa_clear_logs' );

		$this->logs->truncate();

		wp_safe_redirect( admin_url( 'admin.php?page=signa-logs&cleared=1' ) );
		exit;
	}
}
