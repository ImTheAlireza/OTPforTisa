<?php
/**
 * Finds the account that owns a phone number.
 *
 * Legacy plugins store numbers under different meta keys and in different
 * formats; this class searches all of them and promotes the canonical value.
 *
 * @package TisaOtp
 */

namespace TisaOtp\User;

use TisaOtp\Config\Settings;
use TisaOtp\Log\Logger;
use TisaOtp\Support\Phone;

defined( 'ABSPATH' ) || exit;

final class PhoneLocator {

	/** @var Settings */
	private $settings;

	/** @var Logger */
	private $logger;

	/** @var array<string,bool> */
	private $ambiguous = array();

	public function __construct( Settings $settings, Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	public function metaKey(): string {
		$key = $this->settings->str( 'phone_meta_key', 'tisa_phone' );

		return '' !== $key ? $key : 'tisa_phone';
	}

	/**
	 * Meta keys searched when locating an account.
	 *
	 * @return string[]
	 */
	public function lookupKeys(): array {
		$keys   = array( $this->metaKey() );
		$extras = $this->settings->items( 'lookup_meta_keys' );

		if ( ! class_exists( 'WooCommerce' ) || ! $this->settings->bool( 'sync_billing_phone', true ) ) {
			$extras = array_diff( $extras, array( 'billing_phone' ) );
		}

		$keys = array_merge( $keys, $extras );

		/**
		 * Filter the meta keys used to look up an account by phone.
		 *
		 * @param string[] $keys Meta keys.
		 */
		return array_values( array_unique( (array) apply_filters( 'tisa_otp_lookup_meta_keys', $keys ) ) );
	}

	/**
	 * @return \WP_User|null Null when nobody matches *or* when several do.
	 */
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

			/**
			 * Fires when one phone number maps to several accounts.
			 *
			 * @param string $phone   Canonical phone number.
			 * @param array  $matches user_id => matched meta keys.
			 */
			do_action( 'tisa_otp_ambiguous_phone', $phone, $matches );

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

	/**
	 * @return array<int,string[]> user_id => meta keys that matched.
	 */
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

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
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

	/**
	 * Write the canonical value so the next lookup is cheap and unambiguous.
	 *
	 * @param string[] $sources Meta keys that produced the match.
	 */
	private function promote( int $userId, string $phone, array $sources ): void {
		$current = Phone::normalize( (string) get_user_meta( $userId, $this->metaKey(), true ) );

		if ( $current === $phone && in_array( $this->metaKey(), $sources, true ) ) {
			return;
		}

		update_user_meta( $userId, $this->metaKey(), $phone );

		if ( '' !== $current && $current !== $phone ) {
			/**
			 * Fires when the canonical phone of an account changes.
			 *
			 * @param int    $userId   User id.
			 * @param string $previous Old canonical number.
			 * @param string $phone    New canonical number.
			 */
			do_action( 'tisa_otp_phone_changed', $userId, $current, $phone );
		}

		if ( ! in_array( $this->metaKey(), $sources, true ) ) {
			update_user_meta( $userId, 'tisa_phone_imported_from', implode( ',', $sources ) );
		}
	}

	/**
	 * Persist a verified number plus bookkeeping meta.
	 */
	public function persist( int $userId, string $phone ): void {
		$previous = Phone::normalize( (string) get_user_meta( $userId, $this->metaKey(), true ) );

		update_user_meta( $userId, $this->metaKey(), $phone );
		update_user_meta( $userId, 'tisa_last_signin', current_time( 'mysql' ) );

		$logins = (int) get_user_meta( $userId, 'tisa_signin_count', true );
		update_user_meta( $userId, 'tisa_signin_count', $logins + 1 );

		if ( $previous !== $phone && '' !== $previous ) {
			do_action( 'tisa_otp_phone_changed', $userId, $previous, $phone );
		}

		if ( class_exists( 'WooCommerce' ) && $this->settings->bool( 'sync_billing_phone', true ) ) {
			$billing = (string) get_user_meta( $userId, 'billing_phone', true );

			if ( '' === trim( $billing ) ) {
				update_user_meta( $userId, 'billing_phone', $phone );
			}
		}
	}

	/**
	 * Number currently stored for a user — primary key first, then lookups.
	 */
	public function phoneFor( int $userId ): string {
		foreach ( $this->lookupKeys() as $key ) {
			$candidate = Phone::normalize( (string) get_user_meta( $userId, $key, true ) );

			if ( Phone::isValid( $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Which meta key holds the number right now (used for profile hints).
	 */
	public function sourceKeyFor( int $userId ): string {
		foreach ( $this->lookupKeys() as $key ) {
			$candidate = Phone::normalize( (string) get_user_meta( $userId, $key, true ) );

			if ( Phone::isValid( $candidate ) ) {
				return (string) $key;
			}
		}

		return '';
	}

	/**
	 * Drop the stored number. Lookup keys owned by other plugins are left alone.
	 */
	public function clear( int $userId ): void {
		delete_user_meta( $userId, $this->metaKey() );

		/**
		 * Fires after a user's stored number has been removed.
		 *
		 * @param int $userId User id.
		 */
		do_action( 'tisa_otp_phone_removed', $userId );
	}

	public function emailFor( int $userId ): string {
		$user = get_user_by( 'id', $userId );

		return $user instanceof \WP_User ? (string) $user->user_email : '';
	}
}
