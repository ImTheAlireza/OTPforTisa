<?php
/**
 * The frame every plugin screen is drawn in.
 *
 * A hero with the name, the state and the version, then a two-column body:
 * the side navigation and the screen itself. The settings page, the reports,
 * the event log, the tools and the access screen all open and close through
 * here, so they cannot drift apart.
 *
 * @package Signa
 */

namespace Signa\Admin;

use Signa\Config\Settings;

defined( 'ABSPATH' ) || exit;

final class Layout {

	/**
	 * Open the frame.
	 *
	 * @param string   $current  Section id or tool screen slug, for the nav marker.
	 * @param Settings $settings Read for the on/off pill.
	 * @param string   $title    What this screen is, for the hero's second line.
	 */
	public static function open( string $current, Settings $settings, string $title = '' ): void {
		$enabled = $settings->bool( 'enabled', true );

		echo '<div class="wrap signa-wrap signa-ui" dir="rtl">';

		echo '<header class="signa-hero">';
		echo '<span class="signa-hero__logo" aria-hidden="true">' . Icons::svg( 'phone', 24 ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
		echo '<div class="signa-hero__text"><h1 class="signa-hero__title">' . esc_html__( 'سیگنا', 'signa' ) . '</h1>';
		echo '<p class="signa-hero__sub">' . esc_html( '' !== $title ? $title : __( 'ورود و عضویت با کد یک‌بارمصرف — سریع، امن، بدون گذرواژه', 'signa' ) ) . '</p></div>';

		echo '<div class="signa-hero__actions">';
		printf(
			'<button type="button" class="signa-btn signa-btn--gh signa-btn--sm" data-signa-check="gateways">%1$s<span>%2$s</span></button>',
			Icons::svg( 'bolt', 14 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
			esc_html__( 'تست سریع', 'signa' )
		);
		ScreenNav::appToggle();
		echo '</div>';

		echo '<div class="signa-header__meta">';
		printf(
			'<span class="signa-pill %1$s">%2$s</span>',
			$enabled ? 'is-on' : 'is-off',
			esc_html( $enabled ? __( 'فعال', 'signa' ) : __( 'غیرفعال', 'signa' ) )
		);
		echo '<span class="signa-pill signa-pill--ver">' . esc_html( sprintf( /* translators: %s: plugin version */ __( 'نسخه %s', 'signa' ), SIGNA_VERSION ) ) . '</span>';
		echo '</div>';
		echo '</header>';

		// WordPress moves its admin notices to just after this line.
		echo '<hr class="wp-header-end">';

		echo '<div class="signa-body">';
		echo '<aside class="signa-body__nav">';
		ScreenNav::render( $current );
		echo '</aside>';
		echo '<div class="signa-main">';
	}

	public static function close(): void {
		echo '</div></div></div>';
	}

	/**
	 * A plain card for the tool screens: tile, heading, one line of intro.
	 */
	public static function cardHead( string $title, string $icon = '', string $desc = '', string $side = '' ): void {
		echo '<header class="signa-card__head">';

		if ( '' !== $icon ) {
			echo '<span class="signa-tile">' . Icons::svg( $icon, 18 ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
		}

		echo '<div class="signa-card__heading"><h2 class="signa-card__title">' . esc_html( $title ) . '</h2>';

		if ( '' !== $desc ) {
			echo '<p class="signa-card__desc">' . esc_html( $desc ) . '</p>';
		}

		echo '</div>';

		if ( '' !== $side ) {
			echo '<div class="signa-card__side">' . $side . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- callers pass escaped markup.
		}

		echo '</header>';
	}
}
