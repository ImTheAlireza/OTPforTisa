<?php
/**
 * App mode: the plugin's own screens, without the surrounding wp-admin chrome.
 *
 * A WordPress admin page is a page inside a page. The admin bar, the sideways
 * menu, the footer and the screen-meta row all take room from the panel an
 * administrator opened to do one thing, and on a laptop the panel ends up as a
 * column in the middle of somebody else's furniture.
 *
 * App mode hides that furniture on the plugin's five screens for the person who
 * asked for it. It is a user preference, not a site setting: one administrator
 * can work full-screen while everybody else keeps the ordinary screen. The
 * button that turns it on sits in the same row that turns it off, on every
 * screen, so it is never a mode somebody can get stuck in.
 *
 * @package Signa
 */

namespace Signa\Admin;

use Signa\Bootable;

defined( 'ABSPATH' ) || exit;

final class AppMode implements Bootable {

	const META   = 'signa_app_mode';
	const ACTION = 'signa_app_mode';

	public function boot(): void {
		add_filter( 'admin_body_class', array( $this, 'bodyClass' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Is this user working in app mode?
	 *
	 * @param int $user_id User to ask about; zero means the current user.
	 */
	public static function isOn( int $user_id = 0 ): bool {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();

		return $user_id > 0 && (bool) get_user_meta( $user_id, self::META, true );
	}

	/**
	 * Mark the body before it is painted, so nothing flashes on the way in.
	 *
	 * @param string $classes Space separated body classes.
	 * @return string
	 */
	public function bodyClass( $classes ) {
		if ( self::isOn() ) {
			$classes .= ' signa-app';
		}

		return $classes;
	}

	/**
	 * The toggle itself.
	 *
	 * A form post rather than a script: it works with JavaScript switched off,
	 * it cannot be triggered by following a link, and the page it lands back on
	 * is already drawn in the new state.
	 */
	public function handle(): void {
		if ( ! current_user_can( Page::CAPABILITY ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'signa' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION );

		$user_id = get_current_user_id();
		$on      = ! self::isOn( $user_id );

		update_user_meta( $user_id, self::META, $on ? '1' : '' );

		$back = wp_get_referer();

		wp_safe_redirect( $back ? $back : admin_url( 'admin.php?page=' . Page::ROOT ) );
		exit;
	}
}
