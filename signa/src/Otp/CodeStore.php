<?php
/**
 * Storage contract for issued codes.
 *
 * Two implementations ship with the plugin (database table or object cache);
 * sites can register their own through the `signa_code_store` filter.
 *
 * @package Signa
 */

namespace Signa\Otp;

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
	 * Claim a record for this request, atomically.
	 *
	 * `consume()` is unconditional, which is right for a record whose code was
	 * wrong, expired or out of attempts. A *correct* code is different: two
	 * requests can arrive in the same second with the same code — a double-click,
	 * a retry, or two tabs — and if both read the record before either marks it
	 * used, both are accepted and the code is spent twice. This asks the storage
	 * layer to flip the flag and report whether *this* request is the one that
	 * flipped it. Exactly one caller can win.
	 *
	 * @return bool True when this request claimed a still-unused record.
	 */
	public function claim( CodeRecord $record ): bool;

	/**
	 * Drop every outstanding code for a phone fingerprint.
	 */
	public function revoke( string $fingerprint ): void;

	/**
	 * Remove expired rows; returns how many were deleted.
	 */
	public function purge(): int;
}
