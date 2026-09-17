<?php
/**
 * Persistence and querying for structured log entries.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Log;

use TisaOtp\Config\Settings;

defined( 'ABSPATH' ) || exit;

final class LogStore {

	/** @var Settings */
	private $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'tisa_otp_logs';
	}

	public function write( string $severity, string $event, string $message, array $context ): bool {
		global $wpdb;

		if ( ! $this->settings->bool( 'logs_enabled', true ) ) {
			return false;
		}

		$row = array(
			'created_at'        => current_time( 'mysql', true ),
			'severity'          => $severity,
			'event'             => substr( $event, 0, 80 ),
			'channel'           => isset( $context['channel'] ) ? substr( (string) $context['channel'], 0, 20 ) : null,
			'gateway'           => isset( $context['gateway'] ) ? substr( (string) $context['gateway'], 0, 40 ) : null,
			'error_code'        => isset( $context['error_code'] ) ? substr( (string) $context['error_code'], 0, 80 ) : null,
			'phone_fingerprint' => isset( $context['phone_fingerprint'] ) ? substr( (string) $context['phone_fingerprint'], 0, 64 ) : null,
			'phone_mask'        => isset( $context['phone_mask'] ) ? substr( (string) $context['phone_mask'], 0, 24 ) : null,
			'user_id'           => isset( $context['user_id'] ) ? (int) $context['user_id'] : null,
			'ip_hash'           => isset( $context['ip_hash'] ) ? substr( (string) $context['ip_hash'], 0, 64 ) : null,
			'message'           => substr( $message, 0, 250 ),
			'meta'              => wp_json_encode( $this->metaFrom( $context ) ),
		);

		$inserted = $wpdb->insert( $this->table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return false !== $inserted;
	}

	/**
	 * @return object[]
	 */
	public function query( array $args = array() ): array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'limit'    => 50,
				'offset'   => 0,
				'severity' => '',
				'event'    => '',
				'gateway'  => '',
				'search'   => '',
				'hours'    => 0,
			)
		);

		$sql    = 'SELECT * FROM ' . $this->table();
		$where  = array();
		$params = array();

		if ( '' !== $args['severity'] ) {
			$where[]  = 'severity = %s';
			$params[] = $args['severity'];
		}
		if ( '' !== $args['event'] ) {
			$where[]  = 'event = %s';
			$params[] = $args['event'];
		}
		if ( '' !== $args['gateway'] ) {
			$where[]  = 'gateway = %s';
			$params[] = $args['gateway'];
		}
		if ( (int) $args['hours'] > 0 ) {
			$where[]  = 'created_at >= %s';
			$params[] = gmdate( 'Y-m-d H:i:s', time() - ( (int) $args['hours'] * HOUR_IN_SECONDS ) );
		}
		if ( '' !== trim( (string) $args['search'] ) ) {
			$where[]  = '(message LIKE %s OR phone_mask LIKE %s OR error_code LIKE %s)';
			$like     = '%' . $wpdb->esc_like( trim( (string) $args['search'] ) ) . '%';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( array() !== $where ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}

		$sql .= ' ORDER BY id DESC LIMIT %d OFFSET %d';

		$params[] = max( 1, min( 500, (int) $args['limit'] ) );
		$params[] = max( 0, (int) $args['offset'] );

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	public function count( array $args = array() ): int {
		global $wpdb;

		$rows = $this->query( array_merge( $args, array( 'limit' => 500, 'offset' => 0 ) ) );

		unset( $wpdb );

		return count( $rows );
	}

	/**
	 * Per-day event tallies for the dashboard sparkline.
	 */
	public function tally( string $event, int $days = 14 ): array {
		global $wpdb;

		$since = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT DATE(created_at) AS day, COUNT(*) AS total FROM ' . $this->table() . ' WHERE event = %s AND created_at >= %s GROUP BY day ORDER BY day ASC', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$event,
				$since
			)
		);

		$tally = array();

		foreach ( (array) $rows as $row ) {
			$tally[ (string) $row->day ] = (int) $row->total;
		}

		return $tally;
	}

	public function totals(): array {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT COUNT(*) AS total,
					SUM(CASE WHEN severity = "error" THEN 1 ELSE 0 END) AS errors,
					SUM(CASE WHEN created_at >= "' . esc_sql( gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) . '" THEN 1 ELSE 0 END) AS last_day
			 FROM ' . $this->table()
		);

		return array(
			'total'    => $row ? (int) $row->total : 0,
			'errors'   => $row ? (int) $row->errors : 0,
			'last_day' => $row ? (int) $row->last_day : 0,
		);
	}

	public function truncate(): void {
		global $wpdb;

		$wpdb->query( 'TRUNCATE TABLE ' . $this->table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	}

	public function purge( int $days ): int {
		global $wpdb;

		$days    = max( 1, $days );
		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'DELETE FROM ' . $this->table() . ' WHERE created_at < %s LIMIT 10000', gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		return (int) $deleted;
	}

	/**
	 * @return string[] Distinct event names seen so far.
	 */
	public function events(): array {
		global $wpdb;

		$rows = $wpdb->get_col( 'SELECT DISTINCT event FROM ' . $this->table() . ' ORDER BY event ASC LIMIT 200' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $rows ) ? array_map( 'strval', $rows ) : array();
	}

	private function metaFrom( array $context ): array {
		$reserved = array( 'channel', 'gateway', 'error_code', 'phone_fingerprint', 'phone_mask', 'user_id', 'ip_hash' );

		$meta = array();

		foreach ( $context as $key => $value ) {
			if ( in_array( $key, $reserved, true ) ) {
				continue;
			}
			if ( is_scalar( $value ) || null === $value ) {
				$meta[ $key ] = $value;
			}
		}

		return $meta;
	}
}
