<?php

namespace Signa\Install;

use Signa\Config\Settings;

defined( 'ABSPATH' ) || exit;

final class Upgrades {
	const VERSION_OPTION = 'signa_db_version';
	private $settings;
	private $schema;
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

		do_action( 'signa_upgraded', $current, Schema::DB_VERSION );
	}

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

		update_option( Settings::OPTION, array_merge( Settings::defaults(), $stored ), false );
		$this->settings->forget();
	}
}
