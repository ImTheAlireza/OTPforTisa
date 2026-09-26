<?php

namespace Signa\Admin;

defined( 'ABSPATH' ) || exit;

final class ScreenNav {
	public static function sections(): array {
		return array(
			'dash'     => array( __( 'داشبورد', 'signa' ), 'home' ),
			'login'    => array( __( 'ورود و عضویت', 'signa' ), 'login' ),
			'channels' => array( __( 'پیامک و کانال‌ها', 'signa' ), 'send' ),
			'formskin' => array( __( 'ظاهر فرم', 'signa' ), 'palette' ),
			'security' => array( __( 'امنیت و محدودیت', 'signa' ), 'shield' ),
			'integ'    => array( __( 'یکپارچه‌سازی‌ها', 'signa' ), 'link' ),
			'reports'  => array( __( 'گزارش‌ها و رویدادها', 'signa' ), 'chart' ),
			'advanced' => array( __( 'پیشرفته', 'signa' ), 'sliders' ),
		);
	}

	public static function tools(): array {
		return array(
			LogsScreen::SLUG   => array( __( 'همهٔ رویدادها', 'signa' ), 'list' ),
			ToolsScreen::SLUG  => array( __( 'ابزارها و وضعیت', 'signa' ), 'tools' ),
			AccessScreen::SLUG => array( __( 'دسترسی و مسدودی', 'signa' ), 'ban' ),
		);
	}

	public static function formSections(): array {
		return array( 'login', 'channels', 'formskin', 'security', 'integ', 'advanced' );
	}

	public static function url( string $id ): string {
		if ( ReportScreen::SLUG === $id ) {
			return SettingsScreen::tabUrl( 'reports' );
		}

		if ( array_key_exists( $id, self::tools() ) ) {
			return admin_url( 'admin.php?page=' . $id );
		}

		if ( Page::ROOT === $id ) {
			return SettingsScreen::tabUrl( 'dash' );
		}

		return SettingsScreen::tabUrl( $id );
	}

	public static function render( string $current ): void {
		if ( Page::ROOT === $current ) {
			$current = 'dash';
		} elseif ( ReportScreen::SLUG === $current ) {
			$current = 'reports';
		}

		echo '<nav class="signa-screens" aria-label="' . esc_attr__( 'صفحه‌های افزونه', 'signa' ) . '">';
		echo '<ul class="signa-screens__list">';

		foreach ( self::sections() as $id => $item ) {
			self::item( $id, $item[0], $item[1], $current, in_array( $id, self::formSections(), true ) );
		}

		echo '</ul>';

		echo '<p class="signa-screens__cap" id="signa-screens-tools">' . esc_html__( 'ابزارها', 'signa' ) . '</p>';
		echo '<ul class="signa-screens__list" aria-labelledby="signa-screens-tools">';

		foreach ( self::tools() as $slug => $item ) {
			self::item( $slug, $item[0], $item[1], $current, false );
		}

		echo '</ul></nav>';
	}

	private static function item( string $id, string $label, string $icon, string $current, bool $inForm ): void {
		$is_current = $current === $id;

		printf(
			'<li><a href="%1$s" class="signa-screen%2$s"%3$s%4$s>%5$s<span>%6$s</span></a></li>',
			esc_url( self::url( $id ) ),
			$is_current ? ' is-current' : '',
			$is_current ? ' aria-current="page"' : '',
			$inForm ? ' data-signa-section="' . esc_attr( $id ) . '"' : '',
			Icons::svg( $icon ),
			esc_html( $label )
		);
	}

	public static function appToggle(): void {
		$on = AppMode::isOn();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="signa-appmode">';
		echo '<input type="hidden" name="action" value="' . esc_attr( AppMode::ACTION ) . '">';
		wp_nonce_field( AppMode::ACTION );
		printf(
			'<button type="submit" class="signa-btn signa-btn--gh signa-btn--sm" title="%1$s">%2$s<span>%3$s</span></button>',
			esc_attr( $on ? __( 'بازگشت به چیدمان وردپرس.', 'signa' ) : __( 'تمام‌صفحه، بدون منو و نوار مدیریت.', 'signa' ) ),
			Icons::svg( 'expand', 14 ),
			esc_html( $on ? __( 'نمای پیشخوان', 'signa' ) : __( 'حالت اپ', 'signa' ) )
		);
		echo '</form>';
	}
}
