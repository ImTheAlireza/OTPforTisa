<?php

namespace Signa\Support;

defined( 'ABSPATH' ) || exit;

final class FormToken {
	const KEY = 'signa_ft';
	const MIN_AGE = 1;
	const MAX_AGE = 21600;

	public static function issue(): string {
		$payload = time() . '.' . substr( Crypto::token( 8 ), 0, 16 );

		return $payload . '.' . self::signature( $payload );
	}

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

		$max = (int) apply_filters( 'signa_form_token_ttl', self::MAX_AGE );

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

	public static function minAge(): int {
		return max( 0, (int) apply_filters( 'signa_min_form_age', self::MIN_AGE ) );
	}

	private static function signature( string $payload ): string {
		return substr( hash_hmac( 'sha256', 'form-token|' . $payload, wp_salt( 'auth' ) ), 0, 32 );
	}
}
