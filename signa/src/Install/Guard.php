<?php

namespace Signa\Install;

use Signa\Bootable;

defined( 'ABSPATH' ) || exit;

final class Guard implements Bootable {
	private static $failures = array();

	public function boot(): void {
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'network_admin_notices', array( $this, 'notices' ) );
	}

	public static function record( string $id, $error ): void {
		self::$failures[] = array(
			'id'      => $id,
			'message' => $error->getMessage(),
		);
	}

	public static function failures(): array {
		return self::$failures;
	}

	public static function failed( string $id ): bool {
		foreach ( self::$failures as $failure ) {
			if ( $failure['id'] === $id ) {
				return true;
			}
		}

		return false;
	}

	public function notices(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$state = Package::cached();

		if ( null === $state ) {
			$state = Package::verify();
		}

		if ( ! empty( self::$failures ) ) {
			$this->printFailures();
		}

		if ( empty( $state['ok'] ) ) {
			$this->printMismatch( $state );
		}
	}

	private function printFailures(): void {
		$ids = array();

		foreach ( self::$failures as $failure ) {
			$ids[] = $failure['id'];
		}

		echo '<div class="notice notice-error"><p><strong>'
			. esc_html__( 'سیگنا نتوانست کامل بالا بیاید.', 'signa' )
			. '</strong> ';

		echo esc_html__( 'این بخش‌ها شروع نشدند، پس تا وقتی این پیام هست بخشی از افزونه کار نمی‌کند:', 'signa' );
		echo ' <code>' . esc_html( implode( '</code> <code>', array_unique( $ids ) ) ) . '</code></p>';

		echo '<p>' . esc_html__( 'نشانهٔ شناخته‌شدهٔ این خطا: فایل‌های افزونه از دو نسخهٔ مختلف‌اند (بستهٔ نصب‌شده کامل جایگزین نشده). بستهٔ کامل همان نسخه را از نو نصب کنید: افزونه‌ها ← افزودن ← بارگذاری افزونه ← «جایگزینی با نسخهٔ بارگذاری‌شده».', 'signa' ) . '</p>';

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			foreach ( array_slice( self::$failures, 0, 3 ) as $failure ) {
				echo '<p><code>' . esc_html( $failure['id'] . ': ' . $failure['message'] ) . '</code></p>';
			}
		}

		echo '</div>';
	}

	private function printMismatch( array $state ): void {
		$offenders = Package::offenders( $state );

		if ( empty( $offenders ) && 'manifest_missing' !== $state['note'] ) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>'
			. esc_html__( 'سیگنا: فایل‌های افزونه با هم نمی‌خوانند.', 'signa' )
			. '</strong> ';

		if ( 'manifest_missing' === $state['note'] ) {
			echo esc_html__( 'فهرست بستهٔ افزونه (build.json) پیدا نشد، پس معلوم نیست کدام نسخه کامل نصب شده است.', 'signa' );
		} else {
			printf(
				esc_html__( '%1$d فایل با نسخهٔ %2$s نمی‌خواند. اگر همین حالا بسته را به‌روز کرده‌اید، یعنی همهٔ فایل‌ها جایگزین نشده‌اند.', 'signa' ),
				count( $offenders ),
				esc_html( $state['version'] )
			);
		}

		echo '</p>';

		if ( ! empty( $offenders ) ) {
			$shown = array_slice( $offenders, 0, 8 );

			echo '<p><code>' . esc_html( implode( '</code><br><code>', $shown ) ) . '</code>';

			if ( count( $offenders ) > count( $shown ) ) {
				printf(
					esc_html__( ' و %d فایل دیگر', 'signa' ),
					count( $offenders ) - count( $shown )
				);
			}

			echo '</p>';
		}

		echo '<p>' . esc_html__( 'راه‌حل: بستهٔ کامل نسخهٔ همین افزونه را از نو نصب کنید — افزونه‌ها ← افزودن ← بارگذاری افزونه ← «جایگزینی با نسخهٔ بارگذاری‌شده». با جایگزینی فایل‌ها هیچ تنظیمات یا داده‌ای پاک نمی‌شود؛ هرگز افزونه را «حذف» نکنید.', 'signa' ) . '</p>';
		echo '</div>';
	}
}
