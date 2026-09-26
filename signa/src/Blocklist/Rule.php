<?php

namespace Signa\Blocklist;

use Signa\Support\Phone;

defined( 'ABSPATH' ) || exit;

final class Rule {
	const KIND_EXACT  = 'exact';
	const KIND_PREFIX = 'prefix';
	const KIND_WILD   = 'wildcard';
	private $kind;
	private $pattern;
	private $note;
	private $until;
	private $addedAt;

	public function __construct( string $kind, string $pattern, string $note = '', int $until = 0, string $addedAt = '' ) {
		$this->kind    = $kind;
		$this->pattern = $pattern;
		$this->note    = $note;
		$this->until   = $until;
		$this->addedAt = $addedAt;
	}

	public static function parse( string $raw, string $note = '', int $until = 0 ): ?self {
		$raw = trim( Phone::latinDigits( $raw ) );

		if ( '' === $raw ) {
			return null;
		}

		$hasWildcard = false !== strpos( $raw, '*' );

		$clean = preg_replace( '/[^0-9*]/', '', $raw );

		if ( ! is_string( $clean ) || '' === $clean ) {
			return null;
		}

		$clean = preg_replace( '/\*{2,}/', '*', $clean );

		if ( ! is_string( $clean ) || '' === trim( $clean, '*' ) ) {
			return null;
		}

		$addedAt = current_time( 'mysql', true );

		if ( $hasWildcard ) {
			if ( substr_count( $clean, '*' ) === 1 && '*' === substr( $clean, -1 ) ) {
				return new self( self::KIND_PREFIX, self::canonicalPrefix( rtrim( $clean, '*' ) ), $note, $until, $addedAt );
			}

			return new self( self::KIND_WILD, $clean, $note, $until, $addedAt );
		}

		$canonical = Phone::normalize( $clean );

		if ( '' !== $canonical && Phone::isValid( $canonical ) ) {
			return new self( self::KIND_EXACT, $canonical, $note, $until, $addedAt );
		}

		return new self( self::KIND_PREFIX, self::canonicalPrefix( $clean ), $note, $until, $addedAt );
	}

	public static function fromArray( array $row ): ?self {
		$kind    = isset( $row['kind'] ) ? (string) $row['kind'] : '';
		$pattern = isset( $row['pattern'] ) ? (string) $row['pattern'] : '';

		if ( '' === $pattern || ! in_array( $kind, array( self::KIND_EXACT, self::KIND_PREFIX, self::KIND_WILD ), true ) ) {
			return null;
		}

		return new self(
			$kind,
			$pattern,
			isset( $row['note'] ) ? (string) $row['note'] : '',
			isset( $row['until'] ) ? (int) $row['until'] : 0,
			isset( $row['added_at'] ) ? (string) $row['added_at'] : ''
		);
	}

	public function toArray(): array {
		return array(
			'kind'     => $this->kind,
			'pattern'  => $this->pattern,
			'note'     => $this->note,
			'until'    => $this->until,
			'added_at' => $this->addedAt,
		);
	}

	public function kind(): string {
		return $this->kind;
	}

	public function pattern(): string {
		return $this->pattern;
	}

	public function note(): string {
		return $this->note;
	}

	public function until(): int {
		return $this->until;
	}

	public function addedAt(): string {
		return $this->addedAt;
	}

	public function isExpired(): bool {
		return $this->until > 0 && $this->until < time();
	}

	public function id(): string {
		return substr( md5( $this->kind . '|' . $this->pattern ), 0, 12 );
	}

	public function matches( string $canonicalPhone ): bool {
		if ( '' === $canonicalPhone || $this->isExpired() ) {
			return false;
		}

		switch ( $this->kind ) {
			case self::KIND_EXACT:
				return $this->pattern === $canonicalPhone;

			case self::KIND_PREFIX:
				return 0 === strpos( $canonicalPhone, $this->pattern );

			case self::KIND_WILD:
				return 1 === preg_match( $this->regex(), $canonicalPhone );
		}

		return false;
	}

	public function label(): string {
		switch ( $this->kind ) {
			case self::KIND_EXACT:
				return __( 'شماره دقیق', 'signa' );

			case self::KIND_PREFIX:
				return __( 'پیش‌شماره', 'signa' );
		}

		return __( 'الگو', 'signa' );
	}

	private static function canonicalPrefix( string $digits ): string {
		$digits = preg_replace( '/\D/', '', $digits );

		if ( ! is_string( $digits ) || '' === $digits ) {
			return '';
		}

		foreach ( array( '0098', '98' ) as $intl ) {
			if ( 0 === strpos( $digits, $intl ) && strlen( $digits ) > strlen( $intl ) ) {
				$digits = substr( $digits, strlen( $intl ) );
				break;
			}
		}

		if ( '0' !== substr( $digits, 0, 1 ) ) {
			$digits = '0' . $digits;
		}

		return $digits;
	}

	private function regex(): string {
		$parts = array_map(
			static function ( string $chunk ): string {
				return preg_quote( $chunk, '/' );
			},
			explode( '*', $this->pattern )
		);

		return '/^' . implode( '\d*', $parts ) . '$/';
	}
}
