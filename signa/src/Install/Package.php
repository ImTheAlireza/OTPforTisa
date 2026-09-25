<?php
/**
 * Is this the package we think it is?
 *
 * The package ships `build.json`: the release version and a sha256 for every
 * file in it (written by `tools/build_package.py`). Reading that manifest back
 * at runtime answers a question this plugin has now been asked three times —
 * "why has nothing changed?" — from inside the plugin, without trusting a
 * version number that two mixed halves of an install would both claim.
 *
 * When files do not match, the answer is specific: which ones, and what to do.
 *
 * @package Signa
 */

namespace Signa\Install;

defined( 'ABSPATH' ) || exit;

final class Package {

	const MANIFEST = 'build.json';
	const CACHE    = 'signa_package_state';

	/** @var array<string,mixed>|null */
	private static $state = null;

	/**
	 * The manifest as shipped, or null when it is not readable.
	 *
	 * @param string $root Plugin directory; empty means the installed one.
	 * @return array<string,mixed>|null
	 */
	public static function manifest( string $root = '' ): ?array {
		$path = ( '' === $root ? SIGNA_PATH : trailingslashit( $root ) ) . self::MANIFEST;

		if ( ! is_readable( $path ) ) {
			return null;
		}

		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $raw ) {
			return null;
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) || ! isset( $data['files'] ) || ! is_array( $data['files'] ) ) {
			return null;
		}

		return $data;
	}

	/**
	 * Compare every shipped file with the manifest.
	 *
	 * The state is cached, because the answer only changes when the installed
	 * files change: the cache key is the manifest's own size and timestamp, so
	 * a fresh upload is measured immediately.
	 *
	 * @param string $root Plugin directory; empty means the installed one.
	 * @param bool   $fresh Skip the cache.
	 * @return array{ok:bool,version:string,checked:int,stale:string[],missing:string[],note:string}
	 */
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

		/*
		 * Files the marketplace encodes for licensing are rewritten after the
		 * build, so their hash can never match. They still have to be there.
		 */
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

	/**
	 * Everything that is not as shipped, in one list, worst first.
	 *
	 * @param array{stale:string[],missing:string[]} $state Result of verify().
	 * @return string[]
	 */
	public static function offenders( array $state ): array {
		return array_merge( $state['missing'], $state['stale'] );
	}

	/**
	 * The version the manifest claims, for messages.
	 *
	 * @return string
	 */
	public static function version(): string {
		$manifest = self::manifest();

		return is_array( $manifest ) && isset( $manifest['version'] ) ? (string) $manifest['version'] : '';
	}

	/**
	 * Store the state for the rest of the request and, outside tests, a while
	 * longer.
	 *
	 * @param string               $root  Plugin directory.
	 * @param array<string,mixed>  $state Result.
	 * @return array<string,mixed>
	 */
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

	/**
	 * The cached answer, when the installed files have not changed.
	 *
	 * @return array<string,mixed>|null
	 */
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
