<?php
/**
 * Alternative code store for sites with a persistent object cache.
 *
 * Nothing is written to the database, which keeps high-traffic logins cheap.
 *
 * @package Signa
 */

namespace Signa\Otp;

defined( 'ABSPATH' ) || exit;

final class CacheCodeStore implements CodeStore {

	const GROUP = 'signa';

	private function key( string $fingerprint ): string {
		return 'code_' . $fingerprint;
	}

	public function insert( string $fingerprint, string $codeHash, string $channel, int $ttl, string $ipFingerprint ): CodeRecord {
		$now    = time();
		$record = new CodeRecord( 0, $fingerprint, $channel, $codeHash, 0, $now, $now + max( 30, $ttl ), false );

		wp_cache_set( $this->key( $fingerprint ), $this->toArray( $record ), self::GROUP, max( 30, $ttl ) );

		return $record;
	}

	public function active( string $fingerprint ): ?CodeRecord {
		$cached = wp_cache_get( $this->key( $fingerprint ), self::GROUP );

		if ( ! is_array( $cached ) ) {
			return null;
		}

		$record = CodeRecord::fromRow( (object) $cached );

		if ( $record->isExpired() || $record->isConsumed() ) {
			return null;
		}

		return $record;
	}

	public function registerAttempt( CodeRecord $record ): int {
		$cached = wp_cache_get( $this->key( $record->fingerprint() ), self::GROUP );

		if ( ! is_array( $cached ) ) {
			return PHP_INT_MAX;
		}

		$cached['attempts'] = (int) $cached['attempts'] + 1;
		$ttl                = max( 1, (int) $cached['expires_at'] - time() );

		wp_cache_set( $this->key( $record->fingerprint() ), $cached, self::GROUP, $ttl );

		return (int) $cached['attempts'];
	}

	public function consume( CodeRecord $record ): void {
		wp_cache_delete( $this->key( $record->fingerprint() ), self::GROUP );
	}

	/**
	 * Claim a record through a second cache key.
	 *
	 * `wp_cache_add()` only succeeds when the key is absent, which makes it the
	 * closest thing a cache has to a compare-and-swap: the winner writes the
	 * marker, everybody else is told the record is already spoken for.
	 */
	public function claim( CodeRecord $record ): bool {
		$ttl = max( 1, $record->expiresAt() - time() );

		$claimed = wp_cache_add( $this->key( $record->fingerprint() ) . '_used', 1, self::GROUP, $ttl );

		if ( $claimed ) {
			wp_cache_delete( $this->key( $record->fingerprint() ), self::GROUP );
		}

		return (bool) $claimed;
	}

	public function revoke( string $fingerprint ): void {
		wp_cache_delete( $this->key( $fingerprint ), self::GROUP );
	}

	public function purge(): int {
		// Cache entries expire on their own.
		return 0;
	}

	private function toArray( CodeRecord $record ): array {
		return array(
			'id'          => $record->id(),
			'fingerprint' => $record->fingerprint(),
			'channel'     => $record->channel(),
			'code_hash'   => $record->codeHash(),
			'attempts'    => $record->attempts(),
			'issued_at'   => $record->issuedAt(),
			'expires_at'  => $record->expiresAt(),
			'consumed'    => $record->isConsumed() ? 1 : 0,
		);
	}
}
