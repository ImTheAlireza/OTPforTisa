<?php

namespace Signa\Install;

defined( 'ABSPATH' ) || exit;

final class Package {
	const MANIFEST = 'build.json';
	const CACHE    = 'signa_package_state';
	private static $state = null;

	public static function manifest( string $root = '' ): ?array {
		$path = ( '' === $root ? SIGNA_PATH : trailingslashit( $root ) ) . self::MANIFEST;

		if ( ! is_readable( $path ) ) {
			return null;
		}

		$raw = file_get_contents( $path );

		if ( false === $raw ) {
			return null;
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) || ! isset( $data['files'] ) || ! is_array( $data['files'] ) ) {
			return null;
		}

		return $data;
	}

	public static function verify( string $root = '', bool $fresh = false ): array {
		$root   = '' === $root ? SIGNA_PATH : trailingslashit( $root );
		$cached = ( '' === $root || $root === SIGNA_PATH ) ? self::$state : null;

		if ( ! $fresh && is_array( $cached ) ) {
			return $cached;
		}

		$manifest = self::manifest( $root );

		$state = array(
			'ok'      => false,
			'version' => defined( 'SIGNA_VERSION' ) ? SIGNA_VERSION : '',
			'checked' => 0,
			'stale'   => array(),
			'missing' => array(),
			'note'    => '',
		);

		if ( null === $manifest ) {
			$state['note'] = 'manifest_missing';

			return self::remember( $root, $state );
		}

		$state['version'] = isset( $manifest['version'] ) ? (string) $manifest['version'] : $state['version'];

		$encoded = isset( $manifest['encoded'] ) && is_array( $manifest['encoded'] ) ? array_map( 'strval', $manifest['encoded'] ) : array();

		foreach ( $manifest['files'] as $name => $sha ) {
			$path = $root . $name;

			if ( ! is_readable( $path ) ) {
				$state['missing'][] = (string) $name;
				continue;
			}

			$state['checked']++;

			if ( in_array( (string) $name, $encoded, true ) ) {
				continue;
			}

			$actual = hash_file( 'sha256', $path );

			if ( ! is_string( $actual ) || ! hash_equals( (string) $sha, $actual ) ) {
				$state['stale'][] = (string) $name;
			}
		}

		$state['ok'] = empty( $state['stale'] ) && empty( $state['missing'] );

		if ( ! $state['ok'] ) {
			$state['note'] = 'files_differ';
		}

		return self::remember( $root, $state );
	}

	public static function offenders( array $state ): array {
		return array_merge( $state['missing'], $state['stale'] );
	}

	public static function version(): string {
		$manifest = self::manifest();

		return is_array( $manifest ) && isset( $manifest['version'] ) ? (string) $manifest['version'] : '';
	}

	private static function remember( string $root, array $state ): array {
		self::$state = $state;

		$manifest = $root . self::MANIFEST;

		if ( ! function_exists( 'set_transient' ) || ! function_exists( 'get_transient' ) ) {
			return $state;
		}

		if ( is_readable( $manifest ) ) {
			$fresh = array(
				'key'   => (string) filesize( $manifest ) . '-' . (string) filemtime( $manifest ),
				'state' => $state,
			);

			set_transient( self::CACHE, $fresh, HOUR_IN_SECONDS );

			return $state;
		}

		return $state;
	}

	public static function cached(): ?array {
		if ( ! function_exists( 'get_transient' ) ) {
			return null;
		}

		$held = get_transient( self::CACHE );

		if ( ! is_array( $held ) || ! isset( $held['key'], $held['state'] ) ) {
			return null;
		}

		$manifest = SIGNA_PATH . self::MANIFEST;

		if ( ! is_readable( $manifest ) ) {
			return null;
		}

		if ( (string) filesize( $manifest ) . '-' . (string) filemtime( $manifest ) !== (string) $held['key'] ) {
			return null;
		}

		self::$state = $held['state'];

		return self::$state;
	}
}
