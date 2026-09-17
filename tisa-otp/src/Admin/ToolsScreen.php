<?php
/**
 * Tools: environment status, test delivery, throttling reset and the importer.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Admin;

use TisaOtp\Bootable;
use TisaOtp\Config\Settings;
use TisaOtp\Cron\Maintenance;
use TisaOtp\Import\Runner;
use TisaOtp\Install\Activator;
use TisaOtp\Install\Schema;
use TisaOtp\Log\LogStore;
use TisaOtp\Throttle\Throttle;

defined( 'ABSPATH' ) || exit;

final class ToolsScreen implements Bootable {

	/** @var Settings */
	private $settings;

	/** @var Runner */
	private $importer;

	/** @var Throttle */
	private $throttle;

	/** @var LogStore */
	private $logs;

	/** @var Schema */
	private $schema;

	public function __construct( Settings $settings, Runner $importer, Throttle $throttle, LogStore $logs, Schema $schema ) {
		$this->settings = $settings;
		$this->importer = $importer;
		$this->throttle = $throttle;
		$this->logs     = $logs;
		$this->schema   = $schema;
	}

	public function boot(): void {
		add_action( 'admin_post_tisa_otp_maintenance', array( $this, 'runMaintenance' ) );
		add_action( 'admin_post_tisa_otp_repair', array( $this, 'repair' ) );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap tisa-wrap" dir="rtl"><div class="tisa-header"><div class="tisa-header__title"><h1>' . esc_html__( 'ابزارها و وضعیت', 'tisa-otp' ) . '</h1></div></div>';

		$this->statusCards();
		$this->testSendCard();
		$this->importCard();
		$this->housekeepingCard();

		echo '</div>';
	}

	private function statusCards(): void {
		$checks = $this->checks();

		echo '<div class="tisa-statbar">';

		foreach ( $checks as $check ) {
			printf(
				'<div class="tisa-stat %1$s"><span class="tisa-stat__value">%2$s</span><span class="tisa-stat__label">%3$s</span></div>',
				$check['ok'] ? 'is-good' : 'is-bad',
				esc_html( (string) $check['value'] ),
				esc_html( (string) $check['label'] )
			);
		}

		echo '</div>';

		echo '<div class="tisa-panel"><table class="widefat striped tisa-diag"><tbody>';

		foreach ( $checks as $check ) {
			printf(
				'<tr><th>%1$s</th><td>%2$s%3$s</td></tr>',
				esc_html( (string) $check['label'] ),
				esc_html( (string) $check['value'] ),
				'' !== $check['note'] ? ' <em>' . esc_html( (string) $check['note'] ) . '</em>' : ''
			);
		}

		echo '</tbody></table></div>';
	}

	private function checks(): array {
		global $wpdb;

		$missing  = $this->schema->missingTables();
		$throttle = $this->throttle->summary();
		$totals   = $this->logs->totals();
		$blocked  = defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL;

		return array(
			array(
				'label' => __( 'جدول‌های افزونه', 'tisa-otp' ),
				'value' => array() === $missing ? __( 'سالم', 'tisa-otp' ) : __( 'نیاز به بازسازی', 'tisa-otp' ),
				'ok'    => array() === $missing,
				'note'  => implode( ', ', $missing ),
			),
			array(
				'label' => __( 'نسخه PHP', 'tisa-otp' ),
				'value' => PHP_VERSION,
				'ok'    => version_compare( PHP_VERSION, '7.4', '>=' ),
				'note'  => '',
			),
			array(
				'label' => __( 'نسخه وردپرس', 'tisa-otp' ),
				'value' => get_bloginfo( 'version' ),
				'ok'    => version_compare( (string) get_bloginfo( 'version' ), '6.1', '>=' ),
				'note'  => '',
			),
			array(
				'label' => __( 'کد یکبارمصرف', 'tisa-otp' ),
				'value' => 'database' === $this->settings->str( 'code_store', 'database' ) ? __( 'جدول', 'tisa-otp' ) : __( 'کش شیء', 'tisa-otp' ),
				'ok'    => true,
				'note'  => sprintf( /* translators: 1: length, 2: ttl */ __( '%1$d رقم / %2$d ثانیه', 'tisa-otp' ), $this->settings->int( 'code_length', 5 ), $this->settings->int( 'code_ttl', 120 ) ),
			),
			array(
				'label' => __( 'کش شیء پایدار', 'tisa-otp' ),
				'value' => wp_using_ext_object_cache() ? __( 'فعال', 'tisa-otp' ) : __( 'غیرفعال', 'tisa-otp' ),
				'ok'    => true,
				'note'  => wp_using_ext_object_cache() ? '' : __( 'برای ذخیره کد در کش، Redis یا Memcached لازم است.', 'tisa-otp' ),
			),
			array(
				'label' => __( 'زمان‌بند cron', 'tisa-otp' ),
				'value' => wp_next_scheduled( Maintenance::HOOK ) ? __( 'ثبت شده', 'tisa-otp' ) : __( 'ثبت نشده', 'tisa-otp' ),
				'ok'    => (bool) wp_next_scheduled( Maintenance::HOOK ),
				'note'  => '',
			),
			array(
				'label' => __( 'محدودیت‌های فعال', 'tisa-otp' ),
				'value' => (string) ( (int) $throttle['cooldown_rows'] + (int) $throttle['quota_rows'] ),
				'ok'    => true,
				'note'  => sprintf( /* translators: %d: limit per phone */ __( 'سقف هر شماره در بازه: %d', 'tisa-otp' ), (int) $throttle['per_phone'] ),
			),
			array(
				'label' => __( 'رویدادهای ثبت‌شده', 'tisa-otp' ),
				'value' => (string) $totals['total'],
				'ok'    => $this->settings->bool( 'logs_enabled', true ),
				'note'  => sprintf( /* translators: %d: errors */ __( '%d خطا', 'tisa-otp' ), (int) $totals['errors'] ),
			),
			array(
				'label' => __( 'حساب‌های دارای شماره', 'tisa-otp' ),
				'value' => (string) $this->accountCount(),
				'ok'    => true,
				'note'  => $this->settings->str( 'phone_meta_key', 'tisa_phone' ),
			),
			array(
				'label' => __( 'ارتباط خروجی', 'tisa-otp' ),
				'value' => $blocked ? __( 'محدود شده', 'tisa-otp' ) : __( 'باز', 'tisa-otp' ),
				'ok'    => ! $blocked,
				'note'  => $blocked ? __( 'WP_HTTP_BLOCK_EXTERNAL فعال است؛ دامنه سامانه پیامکی را در WP_ACCESSIBLE_HOSTS اضافه کنید.', 'tisa-otp' ) : '',
			),
			array(
				'label' => __( 'پیشوند جدول‌ها', 'tisa-otp' ),
				'value' => (string) $wpdb->prefix,
				'ok'    => true,
				'note'  => '',
			),
		);
	}

	private function testSendCard(): void {
		echo '<section class="tisa-panel tisa-card"><h2>' . esc_html__( 'ارسال آزمایشی', 'tisa-otp' ) . '</h2>';
		echo '<p class="tisa-card__intro">' . esc_html__( 'یک کد واقعی به شماره زیر ارسال می‌شود تا تنظیمات سامانه را بسنجید.', 'tisa-otp' ) . '</p>';

		echo '<div class="tisa-inline">';
		echo '<input type="tel" class="regular-text" dir="ltr" data-tisa-test-phone placeholder="09xxxxxxxxx">';
		echo '<select data-tisa-test-channel><option value="sms">' . esc_html__( 'پیامک', 'tisa-otp' ) . '</option><option value="email">' . esc_html__( 'ایمیل', 'tisa-otp' ) . '</option></select>';
		echo '<button type="button" class="button button-primary" data-tisa-test-send>' . esc_html__( 'ارسال کد آزمایشی', 'tisa-otp' ) . '</button>';
		echo '</div>';

		echo '<p class="tisa-result" data-tisa-test-result hidden></p>';
		echo '</section>';
	}

	private function importCard(): void {
		$found = $this->importer->detect();

		echo '<section class="tisa-panel tisa-card" data-tisa-import><h2>' . esc_html__( 'واردسازی شماره‌های قدیمی', 'tisa-otp' ) . '</h2>';
		echo '<p class="tisa-card__intro">' . esc_html__( 'شماره‌های ذخیره‌شده توسط ووکامرس یا افزونه‌های مشابه را به کلید اصلی تیسا منتقل کنید. کار به‌صورت دسته‌ای اجرا و قابل بازگشت است.', 'tisa-otp' ) . '</p>';

		echo '<div class="tisa-inline">';
		echo '<select data-tisa-import-source>';

		if ( array() === $found ) {
			echo '<option value="">' . esc_html__( 'منبعی با داده پیدا نشد', 'tisa-otp' ) . '</option>';
		}

		foreach ( $found as $source ) {
			printf(
				'<option value="%1$s">%2$s (%3$s)</option>',
				esc_attr( (string) $source['id'] ),
				esc_html( (string) $source['label'] ),
				esc_html( sprintf( /* translators: %d: user count */ __( '%d کاربر', 'tisa-otp' ), (int) $source['total'] ) )
			);
		}

		echo '</select>';

		echo '<input type="text" dir="ltr" data-tisa-import-custom placeholder="' . esc_attr__( 'کلید متای دلخواه', 'tisa-otp' ) . '">';

		echo '<select data-tisa-import-conflict>';
		echo '<option value="skip">' . esc_html__( 'در صورت تضاد: رد کردن', 'tisa-otp' ) . '</option>';
		echo '<option value="overwrite">' . esc_html__( 'در صورت تضاد: بازنویسی', 'tisa-otp' ) . '</option>';
		echo '</select>';

		echo '<label class="tisa-check"><input type="checkbox" data-tisa-import-dry><span>' . esc_html__( 'اجرای آزمایشی (بدون تغییر داده)', 'tisa-otp' ) . '</span></label>';

		echo '<button type="button" class="button button-primary" data-tisa-import-start>' . esc_html__( 'شروع', 'tisa-otp' ) . '</button>';
		echo '<button type="button" class="button" data-tisa-import-undo hidden>' . esc_html__( 'بازگشت آخرین کار', 'tisa-otp' ) . '</button>';
		echo '</div>';

		echo '<div class="tisa-progress" hidden><span data-tisa-progress-bar></span></div>';
		echo '<p class="tisa-result" data-tisa-import-result hidden></p>';
		echo '<pre class="tisa-report" data-tisa-import-report hidden></pre>';
		echo '</section>';
	}

	private function housekeepingCard(): void {
		echo '<section class="tisa-panel tisa-card"><h2>' . esc_html__( 'نگهداری', 'tisa-otp' ) . '</h2>';
		echo '<div class="tisa-inline">';

		printf(
			'<a class="button" href="%s">%s</a>',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tisa_otp_maintenance' ), 'tisa_otp_maintenance' ) ),
			esc_html__( 'پاک‌سازی کدها و رویدادهای قدیمی', 'tisa-otp' )
		);

		printf(
			'<a class="button" href="%s">%s</a>',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tisa_otp_repair' ), 'tisa_otp_repair' ) ),
			esc_html__( 'بازسازی جدول‌ها', 'tisa-otp' )
		);

		echo '<button type="button" class="button" data-tisa-reset-throttle>' . esc_html__( 'صفر کردن محدودیت‌ها', 'tisa-otp' ) . '</button>';

		echo '</div><p class="tisa-result" data-tisa-house-result hidden></p></section>';
	}

	public function runMaintenance(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'tisa-otp' ) );
		}

		check_admin_referer( 'tisa_otp_maintenance' );

		$plugin = \TisaOtp\Plugin::i();
		$result = $plugin->get( Maintenance::class )->run();

		set_transient( 'tisa_otp_maintenance_result', $result, 60 );

		wp_safe_redirect( admin_url( 'admin.php?page=tisa-otp-tools&maintained=1' ) );
		exit;
	}

	public function repair(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'tisa-otp' ) );
		}

		check_admin_referer( 'tisa_otp_repair' );

		Activator::repair();

		wp_safe_redirect( admin_url( 'admin.php?page=tisa-otp-tools&repaired=1' ) );
		exit;
	}

	private function accountCount(): int {
		global $wpdb;

		$count = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT user_id) FROM ' . $wpdb->usermeta . ' WHERE meta_key = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$this->settings->str( 'phone_meta_key', 'tisa_phone' )
			)
		);

		return (int) $count;
	}
}
