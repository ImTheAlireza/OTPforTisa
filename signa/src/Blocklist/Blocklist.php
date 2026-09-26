<?php

namespace Signa\Blocklist;

use Signa\Support\Phone;

defined( 'ABSPATH' ) || exit;

final class Blocklist {
	const OPTION = 'signa_blocklist';
	const LIMIT  = 500;
	private $rules;

	public function all(): array {
		if ( null !== $this->rules ) {
			return $this->rules;
		}

		$stored      = get_option( self::OPTION, array() );
		$this->rules = array();

		if ( is_array( $stored ) ) {
			foreach ( $stored as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$rule = Rule::fromArray( $row );

				if ( $rule instanceof Rule ) {
					$this->rules[] = $rule;
				}
			}
		}

		return $this->rules;
	}

	public function active(): array {
		return array_values(
			array_filter(
				$this->all(),
				static function ( Rule $rule ): bool {
					return ! $rule->isExpired();
				}
			)
		);
	}

	public function match( string $phone ): ?Rule {
		$canonical = Phone::normalize( $phone );

		if ( '' === $canonical ) {
			return null;
		}

		foreach ( $this->all() as $rule ) {
			if ( $rule->matches( $canonical ) ) {
				return $rule;
			}
		}

		return null;
	}

	public function blocks( string $phone ): bool {
		return null !== $this->match( $phone );
	}

	public function add( string $raw, string $note = '', int $until = 0 ): bool {
		$rule = Rule::parse( $raw, $note, $until );

		if ( ! $rule instanceof Rule ) {
			return false;
		}

		$rules = $this->all();

		foreach ( $rules as $existing ) {
			if ( $existing->id() === $rule->id() ) {
				return false;
			}
		}

		if ( count( $rules ) >= self::LIMIT ) {
			return false;
		}

		array_unshift( $rules, $rule );

		return $this->persist( $rules );
	}

	public function addMany( string $blob, string $note = '', int $until = 0 ): array {
		$added   = 0;
		$skipped = 0;

		foreach ( preg_split( '/[\r\n,]+/', $blob ) ?: array() as $line ) {
			$line = trim( (string) $line );

			if ( '' === $line ) {
				continue;
			}

			if ( $this->add( $line, $note, $until ) ) {
				++$added;
			} else {
				++$skipped;
			}
		}

		return array( $added, $skipped );
	}

	public function remove( string $id ): bool {
		$kept    = array();
		$removed = false;

		foreach ( $this->all() as $rule ) {
			if ( $rule->id() === $id ) {
				$removed = true;
				continue;
			}

			$kept[] = $rule;
		}

		return $removed && $this->persist( $kept );
	}

	public function clear(): bool {
		return $this->persist( array() );
	}

	public function purgeExpired(): int {
		$kept = $this->active();
		$gone = count( $this->all() ) - count( $kept );

		if ( $gone > 0 ) {
			$this->persist( $kept );
		}

		return $gone;
	}

	public function count(): int {
		return count( $this->all() );
	}

	private function persist( array $rules ): bool {
		$rows = array();

		foreach ( array_slice( $rules, 0, self::LIMIT ) as $rule ) {
			$rows[] = $rule->toArray();
		}

		$this->rules = null;

		return update_option( self::OPTION, $rows, false );
	}
}
