<?php
/**
 * Issue and verify one-time codes.
 *
 * Codes are generated with a CSPRNG, stored only as an HMAC that is bound to
 * the destination phone, and compared in constant time.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Otp;

use TisaOtp\Config\Settings;
use TisaOtp\Support\Crypto;
use TisaOtp\Support\Phone;

defined( 'ABSPATH' ) || exit;

final class OtpService {

	/** @var Settings */
	private $settings;

	/** @var CodeStore */
	private $store;

	public function __construct( Settings $settings, CodeStore $store ) {
		$this->settings = $settings;
		$this->store    = $store;
	}

	public function length(): int {
		/**
		 * Filter the number of digits in a code.
		 *
		 * @param int $length Configured length.
		 */
		$length = (int) apply_filters( 'tisa_otp_code_length', $this->settings->int( 'code_length', 5 ) );

		return max( 4, min( 8, $length ) );
	}

	public function ttl(): int {
		/**
		 * Filter the lifetime of a code in seconds.
		 *
		 * @param int $ttl Configured TTL.
		 */
		$ttl = (int) apply_filters( 'tisa_otp_code_ttl', $this->settings->int( 'code_ttl', 120 ) );

		return max( 30, min( 3600, $ttl ) );
	}

	public function generate(): string {
		return Crypto::digits( $this->length() );
	}

	/**
	 * Store the hash of a code that has just been handed to a channel.
	 */
	public function store( string $phone, string $code, string $channel, string $ip ): CodeRecord {
		$fingerprint = Phone::fingerprint( $phone );

		return $this->store->insert(
			$fingerprint,
			$this->hashCode( $code, $fingerprint ),
			$channel,
			$this->ttl(),
			'' !== $ip ? \TisaOtp\Support\ClientIp::fingerprint( $ip ) : ''
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

		$this->store->consume( $record );

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
