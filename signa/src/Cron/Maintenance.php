<?php
/**
 * Housekeeping: expired codes, stale state rows and old log entries.
 *
 * @package Signa
 */

namespace Signa\Cron;

use Signa\Bootable;
use Signa\Config\Settings;
use Signa\Log\LogStore;
use Signa\Otp\CodeStore;
use Signa\State\StateStore;

defined( 'ABSPATH' ) || exit;

final class Maintenance implements Bootable {

	const HOOK = 'signa_maintenance';

	/** @var Settings */
	private $settings;

	/** @var StateStore */
	private $state;

	/** @var LogStore */
	private $logs;

	/** @var CodeStore */
	private $codes;

	public function __construct( Settings $settings, StateStore $state, LogStore $logs, CodeStore $codes ) {
		$this->settings = $settings;
		$this->state    = $state;
		$this->logs     = $logs;
		$this->codes    = $codes;
	}

	public function boot(): void {
		add_action( self::HOOK, array( $this, 'run' ) );

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', self::HOOK );
		}
	}

	/**
	 * @return array<string,int>
	 */
	public function run(): array {
		$result = array(
			'codes' => (int) $this->codes->purge(),
			'state' => (int) $this->state->prune(),
			'logs'  => (int) $this->logs->purge( $this->settings->int( 'logs_keep_days', 7 ) ),
			'capped' => (int) $this->logs->cap( $this->settings->int( 'logs_max_rows', 200000 ) ),
		);

		/**
		 * Fires after scheduled housekeeping has run.
		 *
		 * @param array $result Number of rows removed per area.
		 */
		do_action( 'signa_maintenance_done', $result );

		return $result;
	}
}
