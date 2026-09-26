<?php

namespace Signa\Import;

defined( 'ABSPATH' ) || exit;

abstract class MetaQuerySource implements Source {
	abstract protected function keys(): array;

	public function available(): bool {
		return $this->total() > 0;
	}

	public function total(): int {
		global $wpdb;

		$keys         = $this->keys();
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );

		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT user_id) FROM ' . $wpdb->usermeta . ' WHERE meta_key IN (' . $placeholders . ') AND meta_value <> ""',
				$keys
			)
		);

		return (int) $count;
	}

	public function userIds( int $limit, int $offset ): array {
		global $wpdb;

		$keys         = $this->keys();
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );

		$params = array_merge( $keys, array( max( 1, min( 500, $limit ) ), max( 0, $offset ) ) );

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT user_id FROM ' . $wpdb->usermeta . ' WHERE meta_key IN (' . $placeholders . ') AND meta_value <> "" ORDER BY user_id ASC LIMIT %d OFFSET %d',
				$params
			)
		);

		return array_map( 'intval', (array) $rows );
	}

	public function phoneFor( int $userId ): string {
		foreach ( $this->keys() as $key ) {
			$value = (string) get_user_meta( $userId, $key, true );

			if ( '' !== trim( $value ) ) {
				return $value;
			}
		}

		return '';
	}
}
