<?php

namespace Signa\Blocklist;

use Signa\Config\Settings;
use Signa\Support\Phone;

defined( 'ABSPATH' ) || exit;

final class Trusted {
	const DEFAULT_SKIP = 'captcha,throttle';
	const NEVER_SKIP = array( 'blocklist' );
	private $settings;
	private $rules;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function enabled(): bool {
		return $this->settings->bool( 'trusted_enabled', false );
	}

	public function rules(): array {
		if ( null !== $this->rules ) {
			return $this->rules;
		}

		$this->rules = array();

		$lines = preg_split( '/[\r\n,;]+/', $this->settings->str( 'trusted_numbers', '' ) );

		foreach ( (array) $lines as $line ) {
			$rule = Rule::parse( (string) $line );

			if ( $rule instanceof Rule ) {
				$this->rules[] = $rule;
			}
		}

		return $this->rules;
	}

	public function matches( string $phone ): bool {
		if ( ! $this->enabled() ) {
			return false;
		}

		$canonical = Phone::normalize( $phone );

		if ( '' === $canonical ) {
			return false;
		}

		foreach ( $this->rules() as $rule ) {
			if ( ! $rule->isExpired() && $rule->matches( $canonical ) ) {
				return true;
			}
		}

		return false;
	}

	public function skips(): array {
		$declared = $this->settings->str( 'trusted_skip', self::DEFAULT_SKIP );
		$names    = array();

		foreach ( explode( ',', $declared ) as $name ) {
			$name = sanitize_key( trim( $name ) );

			if ( '' !== $name && ! in_array( $name, self::NEVER_SKIP, true ) ) {
				$names[] = $name;
			}
		}

		return array_values( array_unique( $names ) );
	}

	public function skipsGuard( string $guard ): bool {
		return in_array( $guard, $this->skips(), true );
	}
}
