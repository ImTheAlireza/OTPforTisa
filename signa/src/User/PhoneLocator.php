<?php

namespace Signa\User;

use Signa\Config\Settings;
use Signa\Log\Logger;
use Signa\Support\Phone;

defined( 'ABSPATH' ) || exit;

final class PhoneLocator {
	private $settings;
	private $logger;
	private $ambiguous = array();

	public function __construct( Settings $settings, Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	public function metaKey(): string {
		$key = $this->settings->str( 'phone_meta_key', 'signa_phone' );

		return '' !== $key ? $key : 'signa_phone';
	}

	public function lookupKeys(): array {
		$keys   = array( $this->metaKey() );
		$extras = $this->settings->items( 'lookup_meta_keys' );

		if ( ! class_exists( 'WooCommerce' ) || ! $this->settings->bool( 'sync_billing_phone', true ) ) {
			$extras = array_diff( $extras, array( 'billing_phone' ) );
		}

		$keys = array_merge( $keys, $extras );

		return array_values( array_unique( (array) apply_filters( 'signa_lookup_meta_keys', $keys ) ) );
	}

	public function find( string $phone ): ?\WP_User {
		$phone = Phone::normalize( $phone );

		unset( $this->ambiguous[ $phone ] );

		if ( ! Phone::isValid( $phone ) ) {
			return null;
		}

		$matches = $this->matches( $phone );

		if ( array() === $matches ) {
			return null;
		}

		if ( count( $matches ) > 1 ) {
			$this->ambiguous[ $phone ] = true;

			$this->logger->warning(
				'lookup.ambiguous',
				array(
					'phone'    => $phone,
					'matches'  => count( $matches ),
					'user_ids' => array_keys( $matches ),
				)
			);

			do_action( 'signa_ambiguous_phone', $phone, $matches );

			return null;
		}

		$userId = (int) array_key_first( $matches );
		$user   = get_user_by( 'id', $userId );

		if ( ! $user instanceof \WP_User ) {
			return null;
		}

		$this->promote( $user->ID, $phone, (array) $matches[ $userId ] );

		return $user;
	}

	public function matches( string $phone ): array {
		global $wpdb;

		$phone    = Phone::normalize( $phone );
		$variants = Phone::variants( $phone );
		$keys     = $this->lookupKeys();

		if ( array() === $variants || array() === $keys ) {
			return array();
		}

		$keyPlaceholders  = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$valuePlaceholders = implode( ',', array_fill( 0, count( $variants ), '%s' ) );

		$sql = 'SELECT user_id, meta_key, meta_value FROM ' . $wpdb->usermeta
			. ' WHERE meta_key IN (' . $keyPlaceholders . ')'
			. ' AND meta_value IN (' . $valuePlaceholders . ')'
			. ' ORDER BY user_id ASC LIMIT 50';

		$rows = $wpdb->get_results(
			$wpdb->prepare( $sql, array_merge( $keys, $variants ) )
		);

		$matches = array();

		foreach ( (array) $rows as $row ) {
			if ( Phone::normalize( (string) $row->meta_value ) !== $phone ) {
				continue;
			}

			$userId = (int) $row->user_id;

			if ( ! isset( $matches[ $userId ] ) ) {
				$matches[ $userId ] = array();
			}

			$matches[ $userId ][] = (string) $row->meta_key;
		}

		return $matches;
	}

	public function isAmbiguous( string $phone ): bool {
		return ! empty( $this->ambiguous[ Phone::normalize( $phone ) ] );
	}

	private function promote( int $userId, string $phone, array $sources ): void {
		$current = Phone::normalize( (string) get_user_meta( $userId, $this->metaKey(), true ) );

		if ( $current === $phone && in_array( $this->metaKey(), $sources, true ) ) {
			return;
		}

		update_user_meta( $userId, $this->metaKey(), $phone );

		if ( '' !== $current && $current !== $phone ) {
			do_action( 'signa_phone_changed', $userId, $current, $phone );
		}

		if ( ! in_array( $this->metaKey(), $sources, true ) ) {
			update_user_meta( $userId, 'signa_phone_imported_from', implode( ',', $sources ) );
		}
	}

	public function persist( int $userId, string $phone ): void {
		$previous = Phone::normalize( (string) get_user_meta( $userId, $this->metaKey(), true ) );

		update_user_meta( $userId, $this->metaKey(), $phone );
		update_user_meta( $userId, 'signa_last_signin', current_time( 'mysql' ) );

		$logins = (int) get_user_meta( $userId, 'signa_signin_count', true );
		update_user_meta( $userId, 'signa_signin_count', $logins + 1 );

		if ( $previous !== $phone && '' !== $previous ) {
			do_action( 'signa_phone_changed', $userId, $previous, $phone );
		}

		if ( class_exists( 'WooCommerce' ) && $this->settings->bool( 'sync_billing_phone', true ) ) {
			$billing = (string) get_user_meta( $userId, 'billing_phone', true );

			if ( '' === trim( $billing ) ) {
				update_user_meta( $userId, 'billing_phone', $phone );
			}
		}
	}

	public function phoneFor( int $userId ): string {
		foreach ( $this->lookupKeys() as $key ) {
			$candidate = Phone::normalize( (string) get_user_meta( $userId, $key, true ) );

			if ( Phone::isValid( $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}

	public function sourceKeyFor( int $userId ): string {
		foreach ( $this->lookupKeys() as $key ) {
			$candidate = Phone::normalize( (string) get_user_meta( $userId, $key, true ) );

			if ( Phone::isValid( $candidate ) ) {
				return (string) $key;
			}
		}

		return '';
	}

	public function clear( int $userId ): void {
		delete_user_meta( $userId, $this->metaKey() );

		do_action( 'signa_phone_removed', $userId );
	}

	public function emailFor( int $userId ): string {
		$user = get_user_by( 'id', $userId );

		return $user instanceof \WP_User ? (string) $user->user_email : '';
	}
}
