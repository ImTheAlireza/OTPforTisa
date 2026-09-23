<?php
/**
 * Signed, time-bound token behind the bot-timing guard.
 *
 * The old guard trusted a raw `time()` printed into the page and rejected the
 * request when it looked older than two hours. On a site with a full-page cache
 * that timestamp is frozen at cache-fill time, so every visitor of a warm page
 * was rejected with "the form expired" — the send never reached a gateway.
 *
 * A token is minted by `GET /form-config` (which is always `no-store`), so the
 * age is measured from the moment the visitor actually loaded the form and the
 * check survives any cache, however long its TTL is.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Support;

defined( 'ABSPATH' ) || exit;

final class FormToken {

	/** Field name carried in the JSON payload. */
	const KEY = 'tisa_ft';

	/** Nobody solves a phone form in under a second; bots happily do. */
	const MIN_AGE = 1;

	/** Long enough for a slow check-out, short enough to expire stale copies. */
	const MAX_AGE = 21600;

	/**
	 * Mint a token: `<issued>.<nonce>.<signature>`.
	 */
	public static function issue(): string {
		$payload = time() . '.' . substr( Crypto::token( 8 ), 0, 16 );

		return $payload . '.' . self::signature( $payload );
	}

	/**
	 * Inspect a token without trusting a single byte of it.
	 *
	 * @return array{valid:bool,age:int,reason:string}
	 */
	public static function inspect( string $token ): array {
		$invalid = array(
			'valid'  => false,
			'age'    => 0,
			'reason' => 'malformed',
		);

		$parts = explode( '.', trim( $token ) );

		if ( 3 !== count( $parts ) ) {
			return $invalid;
		}

		list( $issued, $nonce, $signature ) = $parts;

		if ( ! ctype_digit( $issued ) || ! ctype_xdigit( $nonce ) ) {
			return $invalid;
		}

		$expected = self::signature( $issued . '.' . $nonce );

		if ( ! Crypto::match( $expected, $signature ) ) {
			$invalid['reason'] = 'signature';

			return $invalid;
		}

		$age = time() - (int) $issued;

		if ( $age < 0 ) {
			$invalid['reason'] = 'future';

			return $invalid;
		}

		$max = (int) apply_filters( 'tisa_otp_form_token_ttl', self::MAX_AGE );

		if ( $age > $max ) {
			return array(
				'valid'  => false,
				'age'    => $age,
				'reason' => 'expired',
			);
		}

		return array(
			'valid'  => true,
			'age'    => $age,
			'reason' => '',
		);
	}

	/**
	 * Seconds a visitor must have had the form before submitting.
	 */
	public static function minAge(): int {
		return max( 0, (int) apply_filters( 'tisa_otp_min_form_age', self::MIN_AGE ) );
	}

	private static function signature( string $payload ): string {
		return substr( hash_hmac( 'sha256', 'form-token|' . $payload, wp_salt( 'auth' ) ), 0, 32 );
	}
}
