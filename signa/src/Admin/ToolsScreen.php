<?php

namespace Signa\Admin;

use Signa\Bootable;
use Signa\Config\Settings;
use Signa\Cron\Maintenance;
use Signa\Import\Runner;
use Signa\Install\Activator;
use Signa\Install\Schema;
use Signa\Log\LogStore;
use Signa\Throttle\Throttle;

defined( 'ABSPATH' ) || exit;

final class ToolsScreen implements Bootable {
	const SLUG = 'signa-tools';
	private $settings;
	private $importer;
	private $throttle;
	private $logs;
	private $schema;

	public function __construct( Settings $settings, Runner $importer, Throttle $throttle, LogStore $logs, Schema $schema ) {
		$this->settings = $settings;
		$this->importer = $importer;
		$this->throttle = $throttle;
		$this->logs     = $logs;
		$this->schema   = $schema;
	}

	public function boot(): void {
		add_action( 'admin_post_signa_maintenance', array( $this, 'runMaintenance' ) );
		add_action( 'admin_post_signa_repair', array( $this, 'repair' ) );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		Layout::open( self::SLUG, $this->settings, __( 'ابزارها و وضعیت', 'signa' ) );

		echo '<div class="signa-sechead"><div class="signa-sechead__text"><h2 class="signa-sechead__title">' . esc_html__( 'ابزارها و وضعیت', 'signa' ) . '</h2>';
		echo '<p class="signa-sechead__desc">' . esc_html__( 'سلامت سرور و سامانه، ارسال آزمایشی، واردسازی شماره‌های قدیمی و نگهداری.', 'signa' ) . '</p></div></div>';

		$this->statusCards();
		$this->doctorCard();
		$this->testSendCard();
		$this->importCard();
		$this->housekeepingCard();

		Layout::close();
	}

	private function statusCards(): void {
		$checks = $this->checks();

		echo '<div class="signa-statbar">';

		foreach ( $checks as $check ) {
			printf(
				'<div class="signa-stat %1$s"><span class="signa-stat__value">%2$s</span><span class="signa-stat__label">%3$s</span></div>',
				$check['ok'] ? 'is-good' : 'is-bad',
				esc_html( (string) $check['value'] ),
				esc_html( (string) $check['label'] )
			);
		}

		echo '</div>';

		echo '<div class="signa-panel"><table class="widefat striped signa-diag"><tbody>';

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
				'label' => __( 'جدول‌های افزونه', 'signa' ),
				'value' => array() === $missing ? __( 'سالم', 'signa' ) : __( 'نیاز به بازسازی', 'signa' ),
				'ok'    => array() === $missing,
				'note'  => implode( ', ', $missing ),
			),
			array(
				'label' => __( 'نسخه PHP', 'signa' ),
				'value' => PHP_VERSION,
				'ok'    => version_compare( PHP_VERSION, '7.4', '>=' ),
				'note'  => '',
			),
			array(
				'label' => __( 'نسخه وردپرس', 'signa' ),
				'value' => get_bloginfo( 'version' ),
				'ok'    => version_compare( (string) get_bloginfo( 'version' ), '6.1', '>=' ),
				'note'  => '',
			),
			array(
				'label' => __( 'کد یکبارمصرف', 'signa' ),
				'value' => 'database' === $this->settings->str( 'code_store', 'database' ) ? __( 'جدول', 'signa' ) : __( 'کش شیء', 'signa' ),
				'ok'    => true,
				'note'  => sprintf(  __( '%1$d رقم / %2$d ثانیه', 'signa' ), $this->settings->int( 'code_length', 5 ), $this->settings->int( 'code_ttl', 120 ) ),
			),
			array(
				'label' => __( 'کش شیء پایدار', 'signa' ),
				'value' => wp_using_ext_object_cache() ? __( 'فعال', 'signa' ) : __( 'غیرفعال', 'signa' ),
				'ok'    => true,
				'note'  => wp_using_ext_object_cache() ? '' : __( 'برای ذخیره کد در کش، Redis یا Memcached لازم است.', 'signa' ),
			),
			array(
				'label' => __( 'زمان‌بند cron', 'signa' ),
				'value' => wp_next_scheduled( Maintenance::HOOK ) ? __( 'ثبت شده', 'signa' ) : __( 'ثبت نشده', 'signa' ),
				'ok'    => (bool) wp_next_scheduled( Maintenance::HOOK ),
				'note'  => '',
			),
			array(
				'label' => __( 'محدودیت‌های فعال', 'signa' ),
				'value' => (string) ( (int) $throttle['cooldown_rows'] + (int) $throttle['quota_rows'] ),
				'ok'    => true,
				'note'  => sprintf(  __( 'سقف هر شماره در بازه: %d', 'signa' ), (int) $throttle['per_phone'] ),
			),
			array(
				'label' => __( 'رویدادهای ثبت‌شده', 'signa' ),
				'value' => (string) $totals['total'],
				'ok'    => $this->settings->bool( 'logs_enabled', true ),
				'note'  => sprintf(  __( '%d خطا', 'signa' ), (int) $totals['errors'] ),
			),
			array(
				'label' => __( 'حساب‌های دارای شماره', 'signa' ),
				'value' => (string) $this->accountCount(),
				'ok'    => true,
				'note'  => $this->settings->str( 'phone_meta_key', 'signa_phone' ),
			),
			array(
				'label' => __( 'ارتباط خروجی', 'signa' ),
				'value' => $blocked ? __( 'محدود شده', 'signa' ) : __( 'باز', 'signa' ),
				'ok'    => ! $blocked,
				'note'  => $blocked ? __( 'WP_HTTP_BLOCK_EXTERNAL فعال است؛ دامنه سامانه پیامکی را در WP_ACCESSIBLE_HOSTS اضافه کنید.', 'signa' ) : '',
			),
			array(
				'label' => __( 'پیشوند جدول‌ها', 'signa' ),
				'value' => (string) $wpdb->prefix,
				'ok'    => true,
				'note'  => '',
			),
		);
	}

	private function doctorCard(): void {
		echo '<section class="signa-panel signa-card" data-signa-doctor>';
		echo '<h2>' . esc_html__( 'سلامت ارسال و کپچا', 'signa' ) . '</h2>';
		echo '<p class="signa-card__intro">' . esc_html__( 'مسیر ارسال، شماره خط، آخرین خطا و دسترسی خروجی سرور.', 'signa' ) . '</p>';
		echo '<p class="signa-inline"><button type="button" class="button button-primary" data-signa-doctor-refresh>' . esc_html__( 'بررسی سلامت', 'signa' ) . '</button>';
		echo '<span class="signa-note">' . esc_html__( 'هیچ پیامکی در این بخش ارسال نمی‌شود.', 'signa' ) . '</span></p>';
		echo '<div class="signa-doctor" data-signa-doctor-report hidden></div>';
		echo '<p class="signa-result" data-signa-doctor-result hidden></p>';
		echo '</section>';
	}

	private function testSendCard(): void {
		echo '<section class="signa-panel signa-card"><h2>' . esc_html__( 'ارسال آزمایشی', 'signa' ) . '</h2>';
		echo '<p class="signa-card__intro">' . esc_html__( 'یک کد واقعی به این شماره فرستاده می‌شود.', 'signa' ) . '</p>';

		echo '<div class="signa-inline">';
		echo '<input type="tel" class="regular-text" dir="ltr" data-signa-test-phone placeholder="09xxxxxxxxx">';
		echo '<select data-signa-test-channel><option value="sms">' . esc_html__( 'پیامک', 'signa' ) . '</option><option value="email">' . esc_html__( 'ایمیل', 'signa' ) . '</option></select>';
		echo '<button type="button" class="button button-primary" data-signa-test-send>' . esc_html__( 'ارسال کد آزمایشی', 'signa' ) . '</button>';
		echo '</div>';

		echo '<p class="signa-result" data-signa-test-result hidden></p>';
		echo '</section>';
	}

	private function importCard(): void {
		$found = $this->importer->detect();

		echo '<section class="signa-panel signa-card" data-signa-import><h2>' . esc_html__( 'واردسازی شماره‌های قدیمی', 'signa' ) . '</h2>';
		echo '<p class="signa-card__intro">' . esc_html__( 'شماره‌های ووکامرس را به کلید اصلی سیگنا منتقل می‌کند؛ دسته‌ای و قابل بازگشت.', 'signa' ) . '</p>';

		echo '<div class="signa-inline">';
		echo '<select data-signa-import-source>';

		if ( array() === $found ) {
			echo '<option value="">' . esc_html__( 'منبعی با داده پیدا نشد', 'signa' ) . '</option>';
		}

		foreach ( $found as $source ) {
			printf(
				'<option value="%1$s">%2$s (%3$s)</option>',
				esc_attr( (string) $source['id'] ),
				esc_html( (string) $source['label'] ),
				esc_html( sprintf(  __( '%d کاربر', 'signa' ), (int) $source['total'] ) )
			);
		}

		echo '</select>';

		echo '<input type="text" dir="ltr" data-signa-import-custom placeholder="' . esc_attr__( 'کلید متای دلخواه', 'signa' ) . '">';

		echo '<select data-signa-import-conflict>';
		echo '<option value="skip">' . esc_html__( 'در صورت تضاد: رد کردن', 'signa' ) . '</option>';
		echo '<option value="overwrite">' . esc_html__( 'در صورت تضاد: بازنویسی', 'signa' ) . '</option>';
		echo '</select>';

		echo '<label class="signa-check"><input type="checkbox" data-signa-import-dry><span>' . esc_html__( 'اجرای آزمایشی (بدون تغییر داده)', 'signa' ) . '</span></label>';

		echo '<button type="button" class="button button-primary" data-signa-import-start>' . esc_html__( 'شروع', 'signa' ) . '</button>';
		echo '<button type="button" class="button" data-signa-import-undo hidden>' . esc_html__( 'بازگشت آخرین کار', 'signa' ) . '</button>';
		echo '</div>';

		echo '<div class="signa-progress" hidden><span data-signa-progress-bar></span></div>';
		echo '<p class="signa-result" data-signa-import-result hidden></p>';
		echo '<pre class="signa-report" data-signa-import-report hidden></pre>';
		echo '</section>';
	}

	private function housekeepingCard(): void {
		echo '<section class="signa-panel signa-card"><h2>' . esc_html__( 'نگهداری', 'signa' ) . '</h2>';
		echo '<div class="signa-inline">';

		printf(
			'<a class="button" href="%s">%s</a>',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=signa_maintenance' ), 'signa_maintenance' ) ),
			esc_html__( 'پاک‌سازی کدها و رویدادهای قدیمی', 'signa' )
		);

		printf(
			'<a class="button" href="%s">%s</a>',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=signa_repair' ), 'signa_repair' ) ),
			esc_html__( 'بازسازی جدول‌ها', 'signa' )
		);

		echo '<button type="button" class="button" data-signa-reset-throttle>' . esc_html__( 'صفر کردن محدودیت‌ها', 'signa' ) . '</button>';

		echo '</div><p class="signa-result" data-signa-house-result hidden></p></section>';
	}

	public function runMaintenance(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'signa' ) );
		}

		check_admin_referer( 'signa_maintenance' );

		$plugin = \Signa\Plugin::i();
		$result = $plugin->get( Maintenance::class )->run();

		set_transient( 'signa_maintenance_result', $result, 60 );

		wp_safe_redirect( admin_url( 'admin.php?page=signa-tools&maintained=1' ) );
		exit;
	}

	public function repair(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'signa' ) );
		}

		check_admin_referer( 'signa_repair' );

		Activator::repair();

		wp_safe_redirect( admin_url( 'admin.php?page=signa-tools&repaired=1' ) );
		exit;
	}

	private function accountCount(): int {
		global $wpdb;

		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT user_id) FROM ' . $wpdb->usermeta . ' WHERE meta_key = %s',
				$this->settings->str( 'phone_meta_key', 'signa_phone' )
			)
		);

		return (int) $count;
	}
}
