<?php

namespace Signa\Http;

use Signa\Config\Settings;
use Signa\Support\ClientIp;
use Signa\Support\Phone;

defined( 'ABSPATH' ) || exit;

final class Request {
	private $phone;
	private $ip;
	private $data;
	private $user;
	private $userAgent;

	private function __construct( string $phone, string $ip, array $data, ?\WP_User $user, string $userAgent ) {
		$this->phone     = $phone;
		$this->ip        = $ip;
		$this->data      = $data;
		$this->user      = $user;
		$this->userAgent = $userAgent;
	}

	public static function fromRest( \WP_REST_Request $rest, Settings $settings ): self {
		$params = array();

		foreach ( $rest->get_params() as $key => $value ) {
			$params[ (string) $key ] = $value;
		}

		$raw   = isset( $params['phone'] ) ? (string) $params['phone'] : '';
		$phone = Phone::normalize( wp_unslash( $raw ) );
		$ip    = ClientIp::current( $settings->str( 'proxy_mode', 'none' ), $settings->str( 'trusted_proxies' ) );
		$user  = is_user_logged_in() ? wp_get_current_user() : null;
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( (string) $_SERVER['HTTP_USER_AGENT'], 0, 200 ) : '';

		return new self( $phone, $ip, $params, $user instanceof \WP_User && $user->exists() ? $user : null, $agent );
	}

	public static function make( string $phone, string $ip, array $data = array(), ?\WP_User $user = null, string $userAgent = '' ): self {
		return new self( Phone::normalize( $phone ), $ip, $data, $user, substr( $userAgent, 0, 200 ) );
	}

	public function phone(): string {
		return $this->phone;
	}

	public function ip(): string {
		return $this->ip;
	}

	public function ipFingerprint(): string {
		return ClientIp::fingerprint( $this->ip );
	}

	public function phoneFingerprint(): string {
		return Phone::fingerprint( $this->phone );
	}

	public function user(): ?\WP_User {
		return $this->user;
	}

	public function userId(): int {
		return $this->user ? (int) $this->user->ID : 0;
	}

	public function userAgent(): string {
		return $this->userAgent;
	}

	public function has( string $key ): bool {
		return array_key_exists( $key, $this->data );
	}

	public function raw( string $key, $default = null ) {
		return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : $default;
	}

	public function str( string $key, string $default = '' ): string {
		$value = $this->raw( $key, $default );

		if ( is_array( $value ) ) {
			return $default;
		}

		return sanitize_text_field( (string) $value );
	}

	public function key( string $key, string $default = '' ): string {
		$value = $this->str( $key, $default );

		return '' !== $value ? sanitize_key( $value ) : $default;
	}

	public function int( string $key, int $default = 0 ): int {
		$value = $this->raw( $key, $default );

		return is_numeric( $value ) ? (int) $value : $default;
	}

	public function bool( string $key ): bool {
		return in_array( (string) $this->raw( $key, '' ), array( '1', 'true', 'yes', 'on' ), true );
	}

	public function fields(): array {
		$fields = $this->raw( 'fields', array() );

		if ( ! is_array( $fields ) ) {
			return array();
		}

		$clean = array();

		foreach ( $fields as $key => $value ) {
			$clean[ sanitize_key( (string) $key ) ] = is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : $value;
		}

		return $clean;
	}

	public function all(): array {
		return $this->data;
	}
}
