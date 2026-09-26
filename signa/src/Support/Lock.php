<?php

namespace Signa\Support;

use Signa\State\StateStore;

defined( 'ABSPATH' ) || exit;

final class Lock {
	private $state;
	private $held = array();

	public function __construct( StateStore $state ) {
		$this->state = $state;
	}

	public function acquire( string $name, int $ttl = 30 ): bool {
		$key   = $this->key( $name );
		$token = Crypto::token( 16 );

		if ( ! $this->state->claim( $key, $token, $ttl ) ) {
			return false;
		}

		$this->held[ $key ] = $token;

		return true;
	}

	public function release( string $name ): void {
		$key = $this->key( $name );

		if ( ! isset( $this->held[ $key ] ) ) {
			return;
		}

		$this->state->release( $key, $this->held[ $key ] );
		unset( $this->held[ $key ] );
	}

	public function withLock( string $name, callable $callback, int $ttl = 30 ) {
		if ( ! $this->acquire( $name, $ttl ) ) {
			return null;
		}

		try {
			return $callback();
		} finally {
			$this->release( $name );
		}
	}

	private function key( string $name ): string {
		return 'lock:' . preg_replace( '/[^a-z0-9_:\-.]/i', '_', $name );
	}
}
