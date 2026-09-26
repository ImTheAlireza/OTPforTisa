<?php

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

		$wpdb->update(
			$this->table(),
			array( 'consumed' => 1 ),
			array( 'fingerprint' => $fingerprint, 'consumed' => 0 )
		);

		$wpdb->insert(
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

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->table() . ' WHERE fingerprint = %s AND consumed = 0 AND expires_at > %d ORDER BY id DESC LIMIT 1',
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

		$wpdb->query(
			$wpdb->prepare( 'UPDATE ' . $this->table() . ' SET attempts = attempts + 1 WHERE id = %d', $record->id() )
		);

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT attempts FROM ' . $this->table() . ' WHERE id = %d', $record->id() )
		);
	}

	public function consume( CodeRecord $record ): void {
		global $wpdb;

		if ( $record->id() <= 0 ) {
			return;
		}

		$wpdb->update(
			$this->table(),
			array( 'consumed' => 1 ),
			array( 'id' => $record->id() )
		);
	}

	public function claim( CodeRecord $record ): bool {
		global $wpdb;

		if ( $record->id() <= 0 ) {
			return false;
		}

		$changed = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . $this->table() . ' SET consumed = 1 WHERE id = %d AND consumed = 0',
				$record->id()
			)
		);

		return 1 === (int) $changed;
	}

	public function revoke( string $fingerprint ): void {
		global $wpdb;

		$wpdb->update(
			$this->table(),
			array( 'consumed' => 1 ),
			array( 'fingerprint' => $fingerprint, 'consumed' => 0 )
		);
	}

	public function purge(): int {
		global $wpdb;

		$deleted = $wpdb->query(
			$wpdb->prepare( 'DELETE FROM ' . $this->table() . ' WHERE expires_at < %d OR consumed = 1 LIMIT 5000', time() - DAY_IN_SECONDS )
		);

		return (int) $deleted;
	}
}
