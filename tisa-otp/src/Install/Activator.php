<?php
/**
 * Activation / deactivation routines.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Install;

use TisaOtp\Config\Settings;
use TisaOtp\Cron\Maintenance;
use TisaOtp\Support\Crypto;

defined( 'ABSPATH' ) || exit;

final class Activator {

	public static function activate(): void {
		$schema = new Schema();
		$schema->install();

		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', false );
		}

		Crypto::ensurePepper();

		self::schedule();

		if ( ! wp_next_scheduled( Maintenance::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', Maintenance::HOOK );
		}

		flush_rewrite_rules();

		/**
		 * Fires once, right after the plugin has been activated.
		 */
		do_action( 'tisa_otp_activated' );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( Maintenance::HOOK );
		flush_rewrite_rules();

		/**
		 * Fires when the plugin is deactivated. Data is never removed here.
		 */
		do_action( 'tisa_otp_deactivated' );
	}

	/**
	 * Repair missing pieces (tables or cron) on a normal request.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( Maintenance::HOOK ) ) {
			wp_schedule_event( time(), 'twicedaily', Maintenance::HOOK );
		}
	}

	/**
	 * Cheap self-heal used by the diagnostics screen.
	 */
	public static function repair(): array {
		$schema  = new Schema();
		$missing = $schema->missingTables();

		if ( array() !== $missing ) {
			$schema->install();
		}

		self::schedule();
		Crypto::ensurePepper();

		return array(
			'recreated' => $missing,
			'ok'        => ( new Schema() )->exists(),
		);
	}
}
