<?php
/**
 * The plugin's screen switcher.
 *
 * The plugin registers five screens: settings, reports, events, tools and
 * access. Only the settings screen has its own in-page tab navigation, so
 * until now four of the five were reachable *only* from the WordPress sidebar —
 * which is exactly where nobody looks when they are already inside the plugin.
 *
 * This row prints the five screens in menu order on every one of them, so
 * "where is the reports page?" has an answer on the page you are standing on.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Admin;

defined( 'ABSPATH' ) || exit;

final class ScreenNav {

	/**
	 * Every screen the plugin registers, in the order WordPress lists them.
	 *
	 * @return array<string,string> Screen slug => menu label.
	 */
	public static function screens(): array {
		return array(
			Menu::ROOT         => __( 'تنظیمات', 'tisa-otp' ),
			ReportScreen::SLUG => __( 'گزارش‌ها', 'tisa-otp' ),
			LogsScreen::SLUG   => __( 'رویدادها', 'tisa-otp' ),
			ToolsScreen::SLUG  => __( 'ابزارها', 'tisa-otp' ),
			AccessScreen::SLUG => __( 'دسترسی و مسدودی', 'tisa-otp' ),
		);
	}

	/**
	 * Print the row, marking the screen the visitor is already on.
	 *
	 * @param string $current Slug of the screen being rendered.
	 */
	public static function render( string $current ): void {
		echo '<nav class="tisa-screens" aria-label="' . esc_attr__( 'صفحه‌های افزونه', 'tisa-otp' ) . '"><ul>';

		foreach ( self::screens() as $slug => $label ) {
			$is_current = $current === $slug;

			printf(
				'<li><a href="%1$s" class="tisa-screen%2$s"%3$s>%4$s</a></li>',
				esc_url( admin_url( 'admin.php?page=' . $slug ) ),
				$is_current ? ' is-current' : '',
				$is_current ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}

		echo '</ul></nav>';
	}
}
