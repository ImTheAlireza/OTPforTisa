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
	 * One value out of a row's `meta` column.
	 *
	 * Everything a record wants to keep that has no column of its own — the
	 * user agent of a rejected request, the reason a fail-open happened — is
	 * stored as JSON here. Reading it with `$row->ua` returns null forever and
	 * looks like "there is no evidence", which is how a diagnosis turns into a
	 * guess without anyone noticing.
	 *
	 * @param object|array<string,mixed> $row   Row from query().
	 * @param string                     $key   Meta key.
	 * @param string                     $default Value when absent.
	 */
	public static function metaOf( $row, string $key, string $default = '' ): string {
		$meta = '';

		if ( is_object( $row ) && isset( $row->meta ) ) {
			$meta = (string) $row->meta;
		} elseif ( is_array( $row ) && isset( $row['meta'] ) ) {
			$meta = (string) $row['meta'];
		}

		if ( '' === $meta ) {
			return $default;
		}

		$decoded = json_decode( $meta, true );

		if ( ! is_array( $decoded ) || ! array_key_exists( $key, $decoded ) ) {
			return $default;
		}

		$value = $decoded[ $key ];

		return is_scalar( $value ) ? (string) $value : $default;
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

	/**
	 * One tally per event, for the report cards.
	 *
	 * @param string[] $events
	 * @return array<string,int> event => total.
	 */
	public function countByEvent( array $events, int $days = 14 ): array {
		global $wpdb;

		$events = array_values( array_filter( array_map( 'strval', $events ) ) );

		if ( array() === $events ) {
			return array();
		}

		$since        = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );
		$placeholders = implode( ', ', array_fill( 0, count( $events ), '%s' ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT event, COUNT(*) AS total FROM ' . $this->table() . ' WHERE event IN (' . $placeholders . ') AND created_at >= %s GROUP BY event', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				array_merge( $events, array( $since ) )
			)
		);

		$counts = array();

		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row->event ] = (int) $row->total;
		}

		return $counts;
	}

	/**
	 * Per-day tallies for several events, so the chart needs three queries
	 * instead of thirty.
	 *
	 * @param string[] $events
	 * @return array<string,array<string,int>> event => (day => total).
	 */
	public function series( array $events, int $days = 14 ): array {
		$out = array();

		foreach ( $events as $event ) {
			$out[ (string) $event ] = $this->tally( (string) $event, $days );
		}

		return $out;
	}

	/**
	 * Why things failed, grouped by event and error code.
	 *
	 * @param string[] $events
	 * @return array<int,array<string,mixed>>
	 */
	public function reasons( array $events, int $days = 14, int $limit = 20 ): array {
		global $wpdb;

		$events = array_values( array_filter( array_map( 'strval', $events ) ) );

		if ( array() === $events ) {
			return array();
		}

		$since        = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );
		$placeholders = implode( ', ', array_fill( 0, count( $events ), '%s' ) );

		$sql = 'SELECT event, error_code, COUNT(*) AS total FROM ' . $this->table()
			. ' WHERE event IN (' . $placeholders . ') AND created_at >= %s'
			. ' GROUP BY event, error_code ORDER BY total DESC LIMIT %d';

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( $sql, array_merge( $events, array( $since, max( 1, $limit ) ) ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'event'      => (string) $row->event,
				'error_code' => (string) $row->error_code,
				'total'      => (int) $row->total,
			);
		}

		return $out;
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
	 * Keep the event table under a hard row ceiling.
	 *
	 * Retention by age is the polite rule, and it is the one that fails exactly
	 * when it matters: a site under a flood logs a hundred thousand rows a day
	 * and seven days of "keep" is seven hundred thousand rows — the table that
	 * was going to tell the owner what happened becomes the thing that fills
	 * the disk. This is the ceiling underneath the retention rule. The oldest
	 * rows go first, in batches, so the delete never holds a long lock.
	 *
	 * @param int $maxRows Ceiling; 0 disables the cap.
	 * @return int Rows removed.
	 */
	public function cap( int $maxRows ): int {
		global $wpdb;

		$maxRows = (int) $maxRows;

		if ( $maxRows <= 0 ) {
			return 0;
		}

		$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

		if ( $total <= $maxRows ) {
			return 0;
		}

		$table = $this->table();
		$cut   = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT id FROM ' . $table . ' ORDER BY id DESC LIMIT 1 OFFSET %d', $maxRows ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		if ( $cut <= 0 ) {
			return 0;
		}

		$removed = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'DELETE FROM ' . $table . ' WHERE id <= %d LIMIT 5000', $cut ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		return max( 0, (int) $removed );
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
