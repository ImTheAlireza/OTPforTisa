<?php

namespace Signa\Otp;

use Signa\Config\Settings;
use Signa\Support\Crypto;
use Signa\Support\Phone;

defined( 'ABSPATH' ) || exit;

final class OtpService {
	private $settings;
	private $store;

	public function __construct( Settings $settings, CodeStore $store ) {
		$this->settings = $settings;
		$this->store    = $store;
	}

	public function length(): int {
		$length = (int) apply_filters( 'signa_code_length', $this->settings->int( 'code_length', 5 ) );

		return max( 4, min( 8, $length ) );
	}

	public function ttl(): int {
		$ttl = (int) apply_filters( 'signa_code_ttl', $this->settings->int( 'code_ttl', 120 ) );

		return max( 30, min( 3600, $ttl ) );
	}

	public function generate(): string {
		return Crypto::digits( $this->length() );
	}

	public function store( string $phone, string $code, string $channel, string $ip ): CodeRecord {
		$fingerprint = Phone::fingerprint( $phone );

		return $this->store->insert(
			$fingerprint,
			$this->hashCode( $code, $fingerprint ),
			$channel,
			$this->ttl(),
			'' !== $ip ? \Signa\Support\ClientIp::fingerprint( $ip ) : ''
		);
	}

	public function verify( string $phone, string $input ): VerificationResult {
		$normalized = preg_replace( '/\D/', '', Phone::latinDigits( $input ) );
		$maxTries   = max( 2, min( 15, $this->settings->int( 'verify_attempts', 5 ) ) );

		if ( '' === $normalized || strlen( $normalized ) !== $this->length() ) {
			return VerificationResult::rejected( VerificationResult::MALFORMED );
		}

		$fingerprint = Phone::fingerprint( $phone );
		$record      = $this->store->active( $fingerprint );

		if ( null === $record ) {
			return VerificationResult::rejected( VerificationResult::MISSING );
		}

		if ( $record->isExpired() ) {
			$this->store->consume( $record );
			return VerificationResult::rejected( VerificationResult::EXPIRED );
		}

		$attempts = $this->store->registerAttempt( $record );

		if ( $attempts > $maxTries ) {
			$this->store->consume( $record );
			return VerificationResult::rejected( VerificationResult::EXHAUSTED );
		}

		$expected = $this->hashCode( $normalized, $fingerprint );

		if ( ! Crypto::match( $record->codeHash(), $expected ) ) {
			return VerificationResult::rejected( VerificationResult::MISMATCH, max( 0, $maxTries - $attempts ) );
		}

		if ( ! $this->store->claim( $record ) ) {
			return VerificationResult::rejected( VerificationResult::MISSING );
		}

		return VerificationResult::accepted();
	}

	public function revoke( string $phone ): void {
		$this->store->revoke( Phone::fingerprint( $phone ) );
	}

	public function isPending( string $phone ): bool {
		return null !== $this->store->active( Phone::fingerprint( $phone ) );
	}

	public function secondsLeft( string $phone ): int {
		$record = $this->store->active( Phone::fingerprint( $phone ) );

		return $record ? $record->secondsLeft() : 0;
	}

	public function purge(): int {
		return $this->store->purge();
	}

	private function hashCode( string $code, string $fingerprint ): string {
		return Crypto::sign( $code, 'code:' . $fingerprint );
	}
}
