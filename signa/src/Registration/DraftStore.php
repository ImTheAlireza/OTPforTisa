<?php
/**
 * Short-lived storage for a half-finished registration.
 *
 * A draft either carries the submitted field values (fields-first flow) or only
 * proves that a phone number has been verified (code-first flow).
 *
 * @package Signa
 */

namespace Signa\Registration;

use Signa\State\StateStore;
use Signa\Support\Crypto;
use Signa\Support\Phone;

defined( 'ABSPATH' ) || exit;

final class DraftStore {

	const PREFIX = 'draft:';
	const TTL    = 1800;

	/** @var StateStore */
	private $state;

	public function __construct( StateStore $state ) {
		$this->state = $state;
	}

	public function create( string $phone, array $values, bool $verifiedOnly = false ): string {
		$token = Crypto::token( 16 );

		$this->state->put(
			self::PREFIX . $token,
			array(
				'phone'         => Phone::normalize( $phone ),
				'values'        => $values,
				'verified_only' => $verifiedOnly,
				'created'       => time(),
			),
			self::TTL
		);

		return $token;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function find( string $token ): ?array {
		if ( '' === trim( $token ) ) {
			return null;
		}

		$draft = $this->state->get( self::PREFIX . sanitize_key( $token ) );

		if ( ! is_array( $draft ) || empty( $draft['phone'] ) ) {
			return null;
		}

		return $draft;
	}

	/**
	 * @param array<string,mixed> $draft
	 */
	public function matches( array $draft, string $phone ): bool {
		return isset( $draft['phone'] ) && Phone::normalize( (string) $draft['phone'] ) === Phone::normalize( $phone );
	}

	/**
	 * @param array<string,mixed> $draft
	 */
	public function isVerifiedOnly( array $draft ): bool {
		return ! empty( $draft['verified_only'] );
	}

	/**
	 * @param array<string,mixed> $draft
	 */
	public function values( array $draft ): array {
		return isset( $draft['values'] ) && is_array( $draft['values'] ) ? $draft['values'] : array();
	}

	public function destroy( string $token ): void {
		$this->state->forget( self::PREFIX . sanitize_key( $token ) );
	}

	public function purge(): int {
		return $this->state->forgetPrefix( self::PREFIX );
	}
}
