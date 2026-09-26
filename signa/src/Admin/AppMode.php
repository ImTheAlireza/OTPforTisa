<?php

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

	public static function isOn( int $user_id = 0 ): bool {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();

		return $user_id > 0 && (bool) get_user_meta( $user_id, self::META, true );
	}

	public function bodyClass( $classes ) {
		if ( self::isOn() ) {
			$classes .= ' signa-app';
		}

		return $classes;
	}

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
