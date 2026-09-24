<?php
/**
 * Versioned upgrade routines.
 *
 * Each entry maps a version to a callable, so future releases can migrate data
 * without rewriting a monolithic "maybe_upgrade" method.
 *
 * @package Signa
 */

namespace Signa\Install;

use Signa\Config\Settings;

defined( 'ABSPATH' ) || exit;

final class Upgrades {

	const VERSION_OPTION = 'signa_db_version';

	/** @var Settings */
	private $settings;

	/** @var Schema */
	private $schema;

	/** @var bool */
	private $checked = false;

	public function __construct( Settings $settings, Schema $schema ) {
		$this->settings = $settings;
		$this->schema   = $schema;
	}

	public function run(): void {
		if ( $this->checked ) {
			return;
		}
		$this->checked = true;

		$current = (string) get_option( self::VERSION_OPTION, '0.0.0' );

		if ( version_compare( $current, Schema::DB_VERSION, '>=' ) && $this->schema->exists() ) {
			return;
		}

		foreach ( $this->steps() as $version => $step ) {
			if ( version_compare( $current, $version, '<' ) ) {
				$step();
			}
		}

		$this->schema->install();

		/**
		 * Fires after the database has been upgraded.
		 *
		 * @param string $from Previous stored version.
		 * @param string $to   New version.
		 */
		do_action( 'signa_upgraded', $current, Schema::DB_VERSION );
	}

	/**
	 * @return array<string,callable>
	 */
	private function steps(): array {
		return array(
			'1.0.0' => array( $this, 'seedDefaults' ),
		);
	}

	public function seedDefaults(): void {
		$stored = get_option( Settings::OPTION, array() );

		if ( ! is_array( $stored ) || array() === $stored ) {
			return;
		}

		// Keep existing values but make sure every new key exists.
		update_option( Settings::OPTION, array_merge( Settings::defaults(), $stored ), false );
		$this->settings->forget();
	}
}
