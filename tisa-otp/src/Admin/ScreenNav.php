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
 * The row also carries the app-mode switch, because it is the one element that
 * exists on all five screens: the control that leaves the mode has to be where
 * the control that entered it was.
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
	 * Where one screen lives.
	 *
	 * Reports has two doors (its own page and the settings tab), and the pill
	 * points at the tab: that is where an administrator already is, and it keeps
	 * the plugin's own navigation inside the plugin.
	 */
	public static function url( string $slug ): string {
		if ( ReportScreen::SLUG === $slug ) {
			return SettingsScreen::tabUrl( 'reports' );
		}

		return admin_url( 'admin.php?page=' . $slug );
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
				esc_url( self::url( $slug ) ),
				$is_current ? ' is-current' : '',
				$is_current ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}

		echo '</ul>';

		self::appToggle();

		echo '</nav>';
	}

	/**
	 * The full-screen switch, drawn with the state it would go to.
	 *
	 * Nothing here needs JavaScript: a form post flips the administrator's own
	 * preference and sends them straight back to this page.
	 */
	private static function appToggle(): void {
		$on = AppMode::isOn();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="tisa-appmode" aria-label="' . esc_attr__( 'حالت نمایش', 'tisa-otp' ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( AppMode::ACTION ) . '">';
		wp_nonce_field( AppMode::ACTION );
		echo '<button type="submit" class="button tisa-appmode__button">'
			. esc_html( $on ? __( 'نمای پیشخوان', 'tisa-otp' ) : __( 'حالت اپ', 'tisa-otp' ) )
			. '</button>';
		echo '<span class="tisa-appmode__hint">'
			. esc_html( $on ? __( 'بازگشت به چیدمان وردپرس.', 'tisa-otp' ) : __( 'تمام‌صفحه، بدون منو و نوار مدیریت.', 'tisa-otp' ) )
			. '</span>';
		echo '</form>';
	}
}
