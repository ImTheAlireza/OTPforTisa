<?php
/**
 * Tiny lazy service container.
 *
 * Every service is registered as a factory and resolved at most once, which
 * keeps the plugin cheap on requests that never touch the OTP flow.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Support;

defined( 'ABSPATH' ) || exit;

final class Container {

	/** @var array<string,callable> */
	private $factories = array();

	/** @var array<string,mixed> */
	private $resolved = array();

	public function bind( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->resolved[ $id ] );
	}

	public function share( string $id, $instance ): void {
		$this->resolved[ $id ] = $instance;
	}

	public function has( string $id ): bool {
		return isset( $this->resolved[ $id ] ) || isset( $this->factories[ $id ] );
	}

	/**
	 * @return mixed
	 */
	public function make( string $id ) {
		if ( array_key_exists( $id, $this->resolved ) ) {
			return $this->resolved[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new \RuntimeException( sprintf( 'Tisa OTP: service "%s" is not registered.', $id ) );
		}

		$factory              = $this->factories[ $id ];
		$this->resolved[ $id ] = $factory( $this );

		return $this->resolved[ $id ];
	}

	/**
	 * @return string[]
	 */
	public function ids(): array {
		return array_values( array_unique( array_merge( array_keys( $this->factories ), array_keys( $this->resolved ) ) ) );
	}
}
