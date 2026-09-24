<?php
/**
 * Numbers the traffic guards should leave alone.
 *
 * A site owner testing their own login form is the most common victim of their
 * own protection: three test sends in a row and the number is throttled, the
 * captcha appears, and "the plugin is broken" is the obvious conclusion. Same
 * for staff accounts and for the number a gateway is verified with.
 *
 * This list is deliberately *not* a permission system. A trusted number still
 * has to prove it owns the phone with a code; it only skips the guards that
 * exist to slow down strangers. The blocklist is never skipped — a banned
 * number stays banned, no matter who typed it into which list.
 *
 * @package Signa
 */

namespace Signa\Blocklist;

use Signa\Config\Settings;
use Signa\Support\Phone;

defined( 'ABSPATH' ) || exit;

final class Trusted {

	/** Guards a trusted number may skip when the administrator names none. */
	const DEFAULT_SKIP = 'captcha,throttle';

	/** Guards a trusted number may never skip. */
	const NEVER_SKIP = array( 'blocklist' );

	/** @var Settings */
	private $settings;

	/** @var Rule[]|null */
	private $rules;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function enabled(): bool {
		return $this->settings->bool( 'trusted_enabled', false );
	}

	/**
	 * One rule per line, comma or semicolon allowed as well.
	 *
	 * @return Rule[]
	 */
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

	/**
	 * Is this number trusted? Disabled means nobody is.
	 */
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

	/**
	 * Guard names to skip for a trusted number.
	 *
	 * @return string[]
	 */
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
