<?php
/**
 * Storage contract for issued codes.
 *
 * Two implementations ship with the plugin (database table or object cache);
 * sites can register their own through the `tisa_otp_code_store` filter.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Otp;

defined( 'ABSPATH' ) || exit;

interface CodeStore {

	/**
	 * Persist a freshly issued code hash.
	 */
	public function insert( string $fingerprint, string $codeHash, string $channel, int $ttl, string $ipFingerprint ): CodeRecord;

	/**
	 * Newest still-usable record for a phone fingerprint.
	 */
	public function active( string $fingerprint ): ?CodeRecord;

	/**
	 * Atomically increase the attempt counter and return the new value.
	 */
	public function registerAttempt( CodeRecord $record ): int;

	/**
	 * Mark a record as used so it can never be replayed.
	 */
	public function consume( CodeRecord $record ): void;

	/**
	 * Drop every outstanding code for a phone fingerprint.
	 */
	public function revoke( string $fingerprint ): void;

	/**
	 * Remove expired rows; returns how many were deleted.
	 */
	public function purge(): int;
}
