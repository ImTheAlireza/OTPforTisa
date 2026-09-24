<?php
/**
 * Default code store backed by the {prefix}signa_codes table.
 *
 * @package Signa
 */

namespace Signa\Otp;

defined( 'ABSPATH' ) || exit;

final class TableCodeStore implements CodeStore {

	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'signa_codes';
	}

	public function insert( string $fingerprint, string $codeHash, string $channel, int $ttl, string $ipFingerprint ): CodeRecord {
		global $wpdb;

		$now = time();

		// Only the newest code per phone stays usable.
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table(),
			array( 'consumed' => 1 ),
			array( 'fingerprint' => $fingerprint, 'consumed' => 0 )
		);

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table(),
			array(
				'fingerprint' => $fingerprint,
				'channel'     => $channel,
				'code_hash'   => $codeHash,
				'attempts'    => 0,
				'ip_hash'     => '' !== $ipFingerprint ? $ipFingerprint : null,
				'issued_at'   => $now,
				'expires_at'  => $now + max( 30, $ttl ),
				'consumed'    => 0,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d' )
		);

		return new CodeRecord(
			(int) $wpdb->insert_id,
			$fingerprint,
			$channel,
			$codeHash,
			0,
			$now,
			$now + max( 30, $ttl ),
			false
		);
	}

	public function active( string $fingerprint ): ?CodeRecord {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT * FROM ' . $this->table() . ' WHERE fingerprint = %s AND consumed = 0 AND expires_at > %d ORDER BY id DESC LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$fingerprint,
				time()
			)
		);

		return $row ? CodeRecord::fromRow( $row ) : null;
	}

	public function registerAttempt( CodeRecord $record ): int {
		global $wpdb;

		if ( $record->id() <= 0 ) {
			return PHP_INT_MAX;
		}

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'UPDATE ' . $this->table() . ' SET attempts = attempts + 1 WHERE id = %d', $record->id() ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT attempts FROM ' . $this->table() . ' WHERE id = %d', $record->id() ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	public function consume( CodeRecord $record ): void {
		global $wpdb;

		if ( $record->id() <= 0 ) {
			return;
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table(),
			array( 'consumed' => 1 ),
			array( 'id' => $record->id() )
		);
	}

	/**
	 * Claim a record with a conditional update, and let the database arbitrate.
	 *
	 * The `consumed = 0` in the WHERE clause is the whole trick: MySQL reports
	 * how many rows it actually changed, so a second request in the same second
	 * sees `0` and loses the race instead of replaying the code.
	 */
	public function claim( CodeRecord $record ): bool {
		global $wpdb;

		if ( $record->id() <= 0 ) {
			return false;
		}

		$changed = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'UPDATE ' . $this->table() . ' SET consumed = 1 WHERE id = %d AND consumed = 0',
				$record->id()
			) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		return 1 === (int) $changed;
	}

	public function revoke( string $fingerprint ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table(),
			array( 'consumed' => 1 ),
			array( 'fingerprint' => $fingerprint, 'consumed' => 0 )
		);
	}

	public function purge(): int {
		global $wpdb;

		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'DELETE FROM ' . $this->table() . ' WHERE expires_at < %d OR consumed = 1 LIMIT 5000', time() - DAY_IN_SECONDS ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		return (int) $deleted;
	}
}
