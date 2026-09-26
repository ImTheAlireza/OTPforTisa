<?php

namespace Signa\Install;

use Signa\Config\Settings;
use Signa\Cron\Maintenance;
use Signa\Support\Crypto;

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

		do_action( 'signa_activated' );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( Maintenance::HOOK );
		flush_rewrite_rules();

		do_action( 'signa_deactivated' );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( Maintenance::HOOK ) ) {
			wp_schedule_event( time(), 'twicedaily', Maintenance::HOOK );
		}
	}

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
