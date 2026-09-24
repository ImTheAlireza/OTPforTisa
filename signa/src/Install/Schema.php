<?php
/**
 * Custom tables: issued codes, keyed state (throttle/locks/drafts) and logs.
 *
 * @package Signa
 */

namespace Signa\Install;

defined( 'ABSPATH' ) || exit;

final class Schema {

	const DB_VERSION = '1.0.0';

	public function codes(): string {
		return $this->table( 'signa_codes' );
	}

	public function state(): string {
		return $this->table( 'signa_state' );
	}

	public function logs(): string {
		return $this->table( 'signa_logs' );
	}

	/**
	 * @return string[]
	 */
	public function tables(): array {
		return array( $this->codes(), $this->state(), $this->logs() );
	}

	public function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		dbDelta(
			'CREATE TABLE ' . $this->codes() . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				fingerprint CHAR(64) NOT NULL,
				channel VARCHAR(20) NOT NULL DEFAULT 'sms',
				code_hash CHAR(64) NOT NULL,
				attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				ip_hash CHAR(64) NULL,
				issued_at BIGINT UNSIGNED NOT NULL,
				expires_at BIGINT UNSIGNED NOT NULL,
				consumed TINYINT(1) NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY lookup (fingerprint, consumed, expires_at),
				KEY expires_at (expires_at)
			) {$charset};"
		);

		dbDelta(
			'CREATE TABLE ' . $this->state() . " (
				state_key VARCHAR(191) NOT NULL,
				hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
				payload LONGTEXT NULL,
				expires_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY  (state_key),
				KEY expires_at (expires_at)
			) {$charset};"
		);

		dbDelta(
			'CREATE TABLE ' . $this->logs() . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				created_at DATETIME NOT NULL,
				severity VARCHAR(12) NOT NULL DEFAULT 'info',
				event VARCHAR(80) NOT NULL,
				channel VARCHAR(20) NULL,
				gateway VARCHAR(40) NULL,
				error_code VARCHAR(80) NULL,
				phone_fingerprint CHAR(64) NULL,
				phone_mask VARCHAR(24) NULL,
				user_id BIGINT UNSIGNED NULL,
				ip_hash CHAR(64) NULL,
				message VARCHAR(255) NOT NULL DEFAULT '',
				meta LONGTEXT NULL,
				PRIMARY KEY  (id),
				KEY created_at (created_at),
				KEY severity (severity),
				KEY event (event),
				KEY gateway (gateway),
				KEY phone (phone_fingerprint),
				KEY user (user_id)
			) {$charset};"
		);

		update_option( 'signa_db_version', self::DB_VERSION, false );
	}

	public function drop(): void {
		global $wpdb;

		foreach ( $this->tables() as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		}

		delete_option( 'signa_db_version' );
	}

	public function missingTables(): array {
		global $wpdb;

		$missing = array();

		foreach ( $this->tables() as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $found !== $table ) {
				$missing[] = $table;
			}
		}

		return $missing;
	}

	public function exists(): bool {
		return array() === $this->missingTables();
	}

	private function table( string $suffix ): string {
		global $wpdb;

		return $wpdb->prefix . $suffix;
	}
}
