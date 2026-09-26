<?php

namespace Signa\Otp;

defined( 'ABSPATH' ) || exit;

interface CodeStore {
	public function insert( string $fingerprint, string $codeHash, string $channel, int $ttl, string $ipFingerprint ): CodeRecord;

	public function active( string $fingerprint ): ?CodeRecord;

	public function registerAttempt( CodeRecord $record ): int;

	public function consume( CodeRecord $record ): void;

	public function claim( CodeRecord $record ): bool;

	public function revoke( string $fingerprint ): void;

	public function purge(): int;
}
