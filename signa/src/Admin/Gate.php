<?php

namespace Signa\Admin;

use Signa\Bootable;

defined( 'ABSPATH' ) || exit;

final class Gate implements Bootable {
	const LICENSED = 'src/Admin/Menu.php';
	private $menu;
	private $file;

	public function __construct( callable $menu, string $file = '' ) {
		$this->menu = $menu;
		$this->file = '' === $file ? SIGNA_PATH . self::LICENSED : $file;
	}

	public function boot(): void {
		if ( ! is_admin() ) {
			return;
		}

		$missing = self::missingLoader( $this->file );

		if ( '' !== $missing ) {
			add_action( 'admin_menu', array( $this, 'registerFallback' ) );
			add_action( 'admin_notices', array( $this, 'notice' ) );

			return;
		}

		$menu = ( $this->menu )();
		$menu->boot();
	}

	public static function encoder( string $file ): string {
		if ( ! is_readable( $file ) ) {
			return '';
		}

		$handle = fopen( $file, 'rb' );

		if ( false === $handle ) {
			return '';
		}

		$head = (string) fread( $handle, 4096 );
		fclose( $handle );

		if ( false !== stripos( $head, 'ioncube' ) ) {
			return 'ioncube';
		}

		if ( false !== stripos( $head, 'sg_load' ) || false !== stripos( $head, 'sourceguardian' ) ) {
			return 'sourceguardian';
		}

		return '';
	}

	public static function missingLoader( string $file ): string {
		$encoder = self::encoder( $file );

		if ( 'ioncube' === $encoder && ! extension_loaded( 'ionCube Loader' ) ) {
			return 'ionCube Loader';
		}

		if ( 'sourceguardian' === $encoder && ! extension_loaded( 'SourceGuardian' ) ) {
			return 'SourceGuardian';
		}

		return '';
	}

	public function registerFallback(): void {
		add_menu_page(
			__( 'سیگنا', 'signa' ),
			__( 'سیگنا', 'signa' ),
			Page::CAPABILITY,
			Page::ROOT,
			array( $this, 'render' ),
			'dashicons-shield',
			56
		);
	}

	public function notice(): void {
		if ( ! current_user_can( Page::CAPABILITY ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>'
			. esc_html__( 'سیگنا: پنل تنظیمات باز نمی‌شود.', 'signa' )
			. '</strong> ';

		printf(
			esc_html__( 'افزونهٔ PHP «%s» روی این هاست فعال نیست. فرم ورود سایت با تنظیمات ذخیره‌شده کار می‌کند؛ برای تغییر تنظیمات این افزونه را فعال کنید.', 'signa' ),
			esc_html( self::missingLoader( $this->file ) )
		);

		echo ' <a href="' . esc_url( admin_url( 'admin.php?page=' . Page::ROOT ) ) . '">' . esc_html__( 'راهنما', 'signa' ) . '</a></p></div>';
	}

	public function render(): void {
		$loader = self::missingLoader( $this->file );

		echo '<div class="wrap" dir="rtl"><h1>' . esc_html__( 'سیگنا', 'signa' ) . '</h1>';

		echo '<p>';
		printf(
			esc_html__( 'پنل سیگنا به افزونهٔ PHP «%s» نیاز دارد و این افزونه روی هاست شما فعال نیست. فرم ورود و عضویت سایت همچنان با آخرین تنظیمات ذخیره‌شده کار می‌کند.', 'signa' ),
			esc_html( $loader )
		);
		echo '</p>';

		echo '<h2>' . esc_html__( 'راه رفع', 'signa' ) . '</h2><ol>';
		echo '<li>' . esc_html__( 'از پشتیبانی هاست بخواهید ionCube Loader نسخهٔ ۱۴ یا بالاتر را برای نسخهٔ PHP سایت فعال کند (در سی‌پنل و دایرکت‌ادمین معمولاً در بخش «انتخاب نسخهٔ PHP» یک تیک است).', 'signa' ) . '</li>';
		echo '<li>' . esc_html__( 'نسخهٔ PHP سایت را روی یکی از نسخه‌های پشتیبانی‌شدهٔ راست‌چین بگذارید: ۷.۴، ۸.۱ یا ۸.۲.', 'signa' ) . '</li>';
		echo '<li>' . esc_html__( 'مطمئن شوید افزونهٔ «مدیریت هوشمند راست‌چین» نصب و فعال است و دامنهٔ سایت در پیشخوان راست‌چین ثبت شده است.', 'signa' ) . '</li>';
		echo '</ol>';

		printf(
			'<p><code>PHP %1$s</code> · <code>%2$s</code></p>',
			esc_html( PHP_VERSION ),
			esc_html( self::LICENSED )
		);

		echo '</div>';
	}
}
