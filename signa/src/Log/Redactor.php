<?php
/**
 * Scrubs anything sensitive before a context array is persisted.
 *
 * @package Signa
 */

namespace Signa\Log;

use Signa\Support\ClientIp;
use Signa\Support\Phone;

defined( 'ABSPATH' ) || exit;

final class Redactor {

	/** @var string[] */
	private $forbidden = array(
		'otp', 'code', 'plain_code', 'password', 'secret', 'token', 'api_key', 'apikey',
		'authorization', 'cookie', 'session', 'challenge', 'response_body', 'body',
	);

	/**
	 * @return array<string,mixed>
	 */
	public function clean( array $context ): array {
		$clean = array();

		foreach ( $context as $key => $value ) {
			$name = strtolower( (string) $key );

			if ( in_array( $name, $this->forbidden, true ) ) {
				continue;
			}

			if ( false !== strpos( $name, 'password' ) || false !== strpos( $name, 'secret' ) || false !== strpos( $name, 'api_key' ) ) {
				continue;
			}

			if ( in_array( $name, array( 'phone', 'mobile' ), true ) ) {
				$phone = Phone::normalize( (string) $value );
				if ( '' !== $phone ) {
					$clean['phone_mask']        = Phone::mask( $phone );
					$clean['phone_fingerprint'] = Phone::fingerprint( $phone );
				}
				continue;
			}

			if ( 'ip' === $name ) {
				$clean['ip_hash'] = ClientIp::fingerprint( (string) $value );
				continue;
			}

			if ( 'email' === $name ) {
				$clean['email_mask'] = $this->maskEmail( (string) $value );
				continue;
			}

			if ( is_scalar( $value ) || null === $value ) {
				$clean[ $name ] = $value;
				continue;
			}

			if ( is_array( $value ) ) {
				$clean[ $name ] = wp_json_encode( $this->clean( $value ) );
			}
		}

		return $clean;
	}

	public function maskEmail( string $email ): string {
		$parts = explode( '@', $email );

		if ( count( $parts ) < 2 ) {
			return '***';
		}

		$name   = $parts[0];
		$visible = substr( $name, 0, 2 );

		return $visible . '***@' . $parts[1];
	}
}
