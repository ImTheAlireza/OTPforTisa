<?php

namespace Signa\Access;

use Signa\Support\Crypto;
use Signa\Support\Phone;

defined( 'ABSPATH' ) || exit;

final class EmergencyToken {
	const OPTION       = 'signa_emergency';
	const MIN_LENGTH   = 6;
	const MAX_LENGTH   = 12;
	const MAX_ATTEMPTS = 3;
	const HOLD_SECONDS = 120;
	private $state;

	public static function blank(): array {
		return array(
			'hash'       => '',
			'created_at' => 0,
			'expires_at' => 0,
			'uses_left'  => 0,
			'use_limit'  => 0,
			'ip_hash'    => '',
			'ip_lock'    => true,
			'phones'     => array(),
			'fail_count' => 0,
			'used_at'    => 0,
		);
	}

	public function state(): array {
		if ( null === $this->state ) {
			$stored      = get_option( self::OPTION, array() );
			$this->state = array_merge( self::blank(), is_array( $stored ) ? $stored : array() );
		}

		return $this->state;
	}

	public function isArmed(): bool {
		$state = $this->state();

		return '' !== (string) $state['hash']
			&& (int) $state['uses_left'] > 0
			&& (int) $state['expires_at'] > time();
	}

	public function expiryLeft(): int {
		$expires = (int) $this->state()['expires_at'];

		if ( $expires <= 0 ) {
			return 0;
		}

		return max( 0, $expires - time() );
	}

	public function isExpired(): bool {
		$state = $this->state();

		return '' !== (string) $state['hash'] && (int) $state['expires_at'] <= time();
	}

	public function issue( string $code, int $minutes, int $uses, bool $ipLock, array $phones, string $ip ): array {
		$code = preg_replace( '/\D/', '', Phone::latinDigits( $code ) );

		if ( ! is_string( $code ) || ! $this->lengthIsAllowed( strlen( $code ) ) ) {
			return array();
		}

		$phones = array_values( array_unique( array_filter( array_map( array( Phone::class, 'normalize' ), $phones ) ) ) );

		$minutes = max( 5, min( 1440, $minutes ) );
		$uses    = max( 1, min( 50, $uses ) );

		$state = array(
			'hash'       => $this->hash( $code ),
			'created_at' => time(),
			'expires_at' => time() + ( $minutes * MINUTE_IN_SECONDS ),
			'uses_left'  => $uses,
			'use_limit'  => $uses,
			'ip_hash'    => $ipLock && '' !== $ip ? Crypto::sign( $ip, 'emergency-ip' ) : '',
			'ip_lock'    => $ipLock && '' !== $ip,
			'phones'     => $phones,
			'fail_count' => 0,
			'used_at'    => 0,
		);

		$this->write( $state );

		do_action( 'signa_emergency_issued', (int) $state['expires_at'], $uses, count( $phones ) );

		return array(
			'code'       => $code,
			'expires_at' => (string) $state['expires_at'],
			'uses'       => (string) $uses,
		);
	}

	public function revoke(): void {
		if ( '' === (string) $this->state()['hash'] ) {
			return;
		}

		$this->write( self::blank() );

		do_action( 'signa_emergency_revoked' );
	}

	public function looksLikeAttempt( string $input ): bool {
		$digits = preg_replace( '/\D/', '', Phone::latinDigits( $input ) );

		if ( ! is_string( $digits ) || '' === (string) $this->state()['hash'] ) {
			return false;
		}

		return strlen( $digits ) >= self::MIN_LENGTH;
	}

	public function matches( string $input ): bool {
		$digits = preg_replace( '/\D/', '', Phone::latinDigits( $input ) );

		if ( ! is_string( $digits ) || strlen( $digits ) < self::MIN_LENGTH ) {
			return false;
		}

		return Crypto::match( (string) $this->state()['hash'], $this->hash( $digits ) );
	}

	public function registerFailure(): int {
		$state = $this->state();
		$fails = (int) $state['fail_count'] + 1;

		if ( $fails >= self::MAX_ATTEMPTS ) {
			$this->revoke();

			return 0;
		}

		$state['fail_count'] = $fails;
		$this->write( $state );

		return self::MAX_ATTEMPTS - $fails;
	}

	public function consume(): bool {
		$state = $this->state();
		$left  = (int) $state['uses_left'];

		if ( $left < 1 ) {
			return false;
		}

		$state['uses_left'] = $left - 1;
		$state['used_at']   = time();

		if ( 0 === $state['uses_left'] ) {
			$state['hash'] = '';
		}

		$this->write( $state );

		return true;
	}

	public function summary(): array {
		$state = $this->state();

		return array(
			'armed'      => $this->isArmed(),
			'expired'    => $this->isExpired(),
			'created_at' => (int) $state['created_at'],
			'expires_at' => (int) $state['expires_at'],
			'left'       => $this->expiryLeft(),
			'uses_left'  => (int) $state['uses_left'],
			'use_limit'  => (int) $state['use_limit'],
			'ip_lock'    => (bool) $state['ip_lock'],
			'phones'     => (array) $state['phones'],
			'fails'      => (int) $state['fail_count'],
			'used_at'    => (int) $state['used_at'],
		);
	}

	public function inspect( string $code, string $phone, string $ip ): array {
		$state = $this->state();

		if ( '' === (string) $state['hash'] || ! $this->looksLikeAttempt( $code ) ) {
			return array( 'status' => 'none', 'attempts_left' => self::MAX_ATTEMPTS );
		}

		if ( $this->isExpired() ) {
			$this->revoke();

			return array( 'status' => 'none', 'attempts_left' => self::MAX_ATTEMPTS );
		}

		if ( ! $this->matches( $code ) ) {
			return array( 'status' => 'mismatch', 'attempts_left' => $this->registerFailure() );
		}

		if ( $this->ipAcceptable( $ip ) ) {
			return array( 'status' => 'match', 'attempts_left' => self::MAX_ATTEMPTS );
		}

		return array( 'status' => 'wrong_ip', 'attempts_left' => $this->registerFailure() );
	}

	public function phoneAllowed( string $phone ): bool {
		$allowed = (array) $this->state()['phones'];

		if ( array() === $allowed ) {
			return true;
		}

		return in_array( Phone::normalize( $phone ), $allowed, true );
	}

	public function registerAbuse(): int {
		return $this->registerFailure();
	}

	public function allowedPhones(): array {
		return (array) $this->state()['phones'];
	}

	private function ipAcceptable( string $ip ): bool {
		$state = $this->state();

		if ( empty( $state['ip_lock'] ) || '' === (string) $state['ip_hash'] ) {
			return true;
		}

		return '' !== $ip && Crypto::match( (string) $state['ip_hash'], Crypto::sign( $ip, 'emergency-ip' ) );
	}

	public static function suggest( int $length = 8 ): string {
		return Crypto::digits( self::clampLength( $length ) );
	}

	public static function clampLength( int $length ): int {
		return max( self::MIN_LENGTH, min( self::MAX_LENGTH, $length ) );
	}

	public function hold( string $code, int $userId ): void {
		if ( $userId > 0 ) {
			set_transient( 'signa_emergency_reveal_' . $userId, $code, self::HOLD_SECONDS );
		}
	}

	public function pull( int $userId ): string {
		$key  = 'signa_emergency_reveal_' . $userId;
		$code = get_transient( $key );

		if ( ! is_string( $code ) || '' === $code ) {
			return '';
		}

		delete_transient( $key );

		return $code;
	}

	private function lengthIsAllowed( int $length ): bool {
		return $length >= self::MIN_LENGTH && $length <= self::MAX_LENGTH;
	}

	private function hash( string $code ): string {
		return Crypto::sign( $code, 'emergency' );
	}

	private function write( array $state ): void {
		$this->state = array_merge( self::blank(), $state );
		update_option( self::OPTION, $this->state, false );
	}
}
