<?php

namespace Signa\Import;

use Signa\Config\Settings;
use Signa\Log\Logger;
use Signa\State\StateStore;
use Signa\Support\Phone;
use Signa\User\PhoneLocator;

defined( 'ABSPATH' ) || exit;

final class Runner {
	const BATCH  = 100;
	const PREFIX = 'import:';
	private $settings;
	private $state;
	private $locator;
	private $logger;

	public function __construct( Settings $settings, StateStore $state, PhoneLocator $locator, Logger $logger ) {
		$this->settings = $settings;
		$this->state    = $state;
		$this->locator  = $locator;
		$this->logger   = $logger;
	}

	public function sources( string $customKey = '' ): array {
		$sources = array(
			'woo_billing' => new WooBillingSource(),
			'digits'      => new DigitsSource(),
		);

		if ( '' !== $customKey ) {
			$custom                = new CustomMetaSource( $customKey );
			$sources[ $custom->id() ] = $custom;
		}

		foreach ( (array) apply_filters( 'signa_import_sources', array() ) as $id => $source ) {
			if ( $source instanceof Source ) {
				$sources[ (string) $id ] = $source;
			}
		}

		return $sources;
	}

	public function detect(): array {
		$found = array();

		foreach ( $this->sources() as $id => $source ) {
			if ( ! $source->available() ) {
				continue;
			}

			$found[] = array(
				'id'    => $id,
				'label' => $source->label(),
				'total' => $source->total(),
			);
		}

		return $found;
	}

	public function start( string $sourceId, bool $dryRun = false, string $conflict = 'skip', string $customKey = '' ): array {
		$sources = $this->sources( $customKey );

		if ( ! isset( $sources[ $sourceId ] ) ) {
			return array( 'error' => 'unknown_source' );
		}

		$source = $sources[ $sourceId ];
		$jobId  = 'j' . substr( md5( uniqid( 'signa', true ) ), 0, 16 );

		$job = array(
			'job_id'     => $jobId,
			'source'     => $sourceId,
			'custom_key' => $customKey,
			'dry_run'    => $dryRun,
			'conflict'   => in_array( $conflict, array( 'skip', 'overwrite' ), true ) ? $conflict : 'skip',
			'status'     => 'running',
			'cursor'     => 0,
			'total'      => $source->total(),
			'processed'  => 0,
			'migrated'   => 0,
			'skipped'    => 0,
			'conflicts'  => 0,
			'errors'     => array(),
			'undo'       => array(),
			'created_at' => time(),
		);

		$this->state->put( self::PREFIX . $jobId, $job, 0 );

		$this->logger->info( 'import.started', array( 'source' => $sourceId, 'total' => $job['total'], 'dry_run' => $dryRun ? 1 : 0 ) );

		return $job;
	}

	public function job( string $jobId ): ?array {
		$job = $this->state->get( self::PREFIX . sanitize_key( $jobId ) );

		return is_array( $job ) ? $job : null;
	}

	public function step( string $jobId ): array {
		$job = $this->job( $jobId );

		if ( null === $job ) {
			return array( 'error' => 'unknown_job' );
		}

		if ( 'done' === $job['status'] ) {
			return $job;
		}

		$sources = $this->sources( isset( $job['custom_key'] ) ? (string) $job['custom_key'] : '' );

		if ( ! isset( $sources[ $job['source'] ] ) ) {
			$job['status'] = 'failed';
			$this->save( $job );
			return $job;
		}

		$source = $sources[ $job['source'] ];
		$ids    = $source->userIds( self::BATCH, (int) $job['cursor'] );

		foreach ( $ids as $userId ) {
			$job = $this->process( $job, $source, (int) $userId );
		}

		$job['cursor']    = (int) $job['cursor'] + count( $ids );
		$job['processed'] = (int) $job['processed'] + count( $ids );

		if ( array() === $ids || $job['cursor'] >= (int) $job['total'] ) {
			$job['status'] = 'done';
			$this->logger->info( 'import.finished', array( 'migrated' => $job['migrated'], 'skipped' => $job['skipped'] ) );
		}

		$this->save( $job );

		return $job;
	}

	public function undo( string $jobId ): array {
		$job = $this->job( $jobId );

		if ( null === $job ) {
			return array( 'error' => 'unknown_job' );
		}

		$metaKey = $this->locator->metaKey();
		$restored = 0;

		foreach ( array_reverse( (array) $job['undo'] ) as $entry ) {
			if ( ! isset( $entry['user_id'] ) ) {
				continue;
			}

			$userId = (int) $entry['user_id'];

			if ( empty( $entry['previous'] ) ) {
				delete_user_meta( $userId, $metaKey );
			} else {
				update_user_meta( $userId, $metaKey, (string) $entry['previous'] );
			}

			$restored++;
		}

		$job['undo']     = array();
		$job['restored'] = $restored;
		$job['status']   = 'rolled_back';

		$this->save( $job );

		$this->logger->notice( 'import.rolled_back', array( 'restored' => $restored ) );

		return $job;
	}

	public function csv( string $jobId ): string {
		$job = $this->job( $jobId );

		if ( null === $job ) {
			return '';
		}

		$lines = array( 'user_id,action,detail,phone_mask' );

		foreach ( (array) $job['undo'] as $entry ) {
			$lines[] = implode(
				',',
				array(
					(int) $entry['user_id'],
					'migrated',
					'',
					isset( $entry['mask'] ) ? (string) $entry['mask'] : '',
				)
			);
		}

		foreach ( (array) $job['errors'] as $error ) {
			$lines[] = implode(
				',',
				array(
					(int) $error['user_id'],
					'skipped',
					str_replace( ',', ' ', (string) $error['reason'] ),
					isset( $error['mask'] ) ? (string) $error['mask'] : '',
				)
			);
		}

		return implode( "\n", $lines );
	}

	public function prune(): int {
		global $wpdb;

		$state = new StateStore();

		unset( $wpdb, $state );

		return $this->state->forgetPrefix( self::PREFIX );
	}

	private function process( array $job, Source $source, int $userId ): array {
		$metaKey = $this->locator->metaKey();
		$raw     = $source->phoneFor( $userId );
		$phone   = Phone::normalize( $raw );

		$job['processed'] = isset( $job['processed'] ) ? (int) $job['processed'] : 0;

		if ( ! Phone::isValid( $phone ) ) {
			return $this->recordError( $job, $userId, 'invalid_number', $raw );
		}

		$current = Phone::normalize( (string) get_user_meta( $userId, $metaKey, true ) );

		if ( $current === $phone ) {
			$job['skipped'] = (int) $job['skipped'] + 1;
			return $job;
		}

		if ( '' !== $current && 'overwrite' !== $job['conflict'] ) {
			$job['conflicts'] = (int) $job['conflicts'] + 1;
			return $this->recordError( $job, $userId, 'existing_value', $raw );
		}

		$owner = $this->locator->find( $phone );

		if ( $owner instanceof \WP_User && (int) $owner->ID !== $userId ) {
			$job['conflicts'] = (int) $job['conflicts'] + 1;
			return $this->recordError( $job, $userId, 'owned_by_another_user', $raw );
		}

		if ( ! empty( $job['dry_run'] ) ) {
			$job['migrated'] = (int) $job['migrated'] + 1;
			return $job;
		}

		update_user_meta( $userId, $metaKey, $phone );
		update_user_meta( $userId, 'signa_phone_imported_from', $source->id() );

		$job['migrated'] = (int) $job['migrated'] + 1;
		$job['undo'][]   = array(
			'user_id'  => $userId,
			'previous' => $current,
			'mask'     => Phone::mask( $phone ),
		);

		if ( count( $job['undo'] ) > 20000 ) {
			array_shift( $job['undo'] );
		}

		return $job;
	}

	private function recordError( array $job, int $userId, string $reason, string $raw ): array {
		$job['skipped']  = (int) $job['skipped'] + 1;
		$job['errors'][] = array(
			'user_id' => $userId,
			'reason'  => $reason,
			'mask'    => Phone::mask( $raw ),
		);

		if ( count( $job['errors'] ) > 500 ) {
			array_shift( $job['errors'] );
		}

		return $job;
	}

	private function save( array $job ): void {
		$this->state->put( self::PREFIX . $job['job_id'], $job, 0 );
	}
}
