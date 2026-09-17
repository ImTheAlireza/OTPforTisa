<?php
/**
 * Keyed state with expiry: throttle counters, mutex locks and registration drafts.
 *
 * Writes are atomic thanks to the PRIMARY KEY on state_key, which makes the
 * counters safe across concurrent PHP workers without an object cache.
 *
 * @package TisaOtp
 */

namespace TisaOtp\State;

defined( 'ABSPATH' ) || exit;

final class StateStore {

	public function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'tisa_otp_state';
	}

	/**
	 * @return mixed|null
	 */
	public function get( string $key ) {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT payload, hits, expires_at FROM ' . $this->table() . ' WHERE state_key = %s LIMIT 1', $key ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		if ( ! $row ) {
			return null;
		}

		if ( (int) $row->expires_at > 0 && (int) $row->expires_at < time() ) {
			$this->forget( $key );
			return null;
		}

		return null === $row->payload ? (int) $row->hits : $this->decode( (string) $row->payload );
	}

	public function hits( string $key ): int {
		global $wpdb;

		$value = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT hits FROM ' . $this->table() . ' WHERE state_key = %s AND (expires_at = 0 OR expires_at > %d) LIMIT 1', $key, time() ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		return null === $value ? 0 : (int) $value;
	}

	/**
	 * @param mixed $value
	 */
	public function put( string $key, $value, int $ttl = 0 ): void {
		global $wpdb;

		$expires = $ttl > 0 ? time() + $ttl : 0;
		$payload = wp_json_encode( array( 'v' => $value ) );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'INSERT INTO ' . $this->table() . ' (state_key, hits, payload, expires_at) VALUES (%s, 0, %s, %d)
				 ON DUPLICATE KEY UPDATE payload = VALUES(payload), expires_at = VALUES(expires_at)',
				$key,
				$payload,
				$expires
			)
		);
	}

	/**
	 * Increment a counter, resetting it when the window has rolled over.
	 *
	 * @return int New counter value.
	 */
	public function bump( string $key, int $windowSeconds ): int {
		global $wpdb;

		$windowEnd = ( intdiv( time(), max( 1, $windowSeconds ) ) + 1 ) * max( 1, $windowSeconds );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'INSERT INTO ' . $this->table() . " (state_key, hits, payload, expires_at) VALUES (%s, 1, NULL, %d)
				 ON DUPLICATE KEY UPDATE
					hits = IF(expires_at <= %d, 1, hits + 1),
					expires_at = IF(expires_at <= %d, %d, expires_at)",
				$key,
				$windowEnd,
				time(),
				time(),
				$windowEnd
			)
		);

		$hits = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT hits FROM ' . $this->table() . ' WHERE state_key = %s LIMIT 1', $key ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		if ( random_int( 1, 120 ) === 1 ) {
			$this->prune();
		}

		return null === $hits ? PHP_INT_MAX : (int) $hits;
	}

	public function forget( string $key ): void {
		global $wpdb;

		$wpdb->delete( $this->table(), array( 'state_key' => $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Atomic "insert if absent" used for mutex locks.
	 */
	public function claim( string $key, string $token, int $ttl ): bool {
		global $wpdb;

		// Clear a stale lock first, then race on the unique key.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( 'DELETE FROM ' . $this->table() . ' WHERE state_key = %s AND expires_at > 0 AND expires_at < %d', $key, time() )
		);

		$inserted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . $this->table() . ' (state_key, hits, payload, expires_at) VALUES (%s, 0, %s, %d)',
				$key,
				wp_json_encode( array( 'v' => $token ) ),
				time() + max( 1, $ttl )
			)
		);

		return 1 === (int) $inserted;
	}

	public function release( string $key, string $token ): bool {
		global $wpdb;

		$affected = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( 'DELETE FROM ' . $this->table() . ' WHERE state_key = %s AND payload = %s', $key, wp_json_encode( array( 'v' => $token ) ) )
		);

		return (int) $affected > 0;
	}

	public function prune(): int {
		global $wpdb;

		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( 'DELETE FROM ' . $this->table() . ' WHERE expires_at > 0 AND expires_at < %d LIMIT 5000', time() )
		);

		return (int) $deleted;
	}

	public function forgetPrefix( string $prefix ): int {
		global $wpdb;

		$like    = $wpdb->esc_like( $prefix ) . '%';
		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( 'DELETE FROM ' . $this->table() . ' WHERE state_key LIKE %s', $like )
		);

		return (int) $deleted;
	}

	public function countPrefix( string $prefix ): int {
		global $wpdb;

		$like  = $wpdb->esc_like( $prefix ) . '%';
		$count = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE state_key LIKE %s', $like )
		);

		return (int) $count;
	}

	/**
	 * @return mixed
	 */
	private function decode( string $payload ) {
		$data = json_decode( $payload, true );

		if ( ! is_array( $data ) || ! array_key_exists( 'v', $data ) ) {
			return null;
		}

		return $data['v'];
	}
}
