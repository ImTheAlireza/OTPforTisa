<?php

namespace Signa\Cron;

use Signa\Bootable;
use Signa\Config\Settings;
use Signa\Log\LogStore;
use Signa\Otp\CodeStore;
use Signa\State\StateStore;

defined( 'ABSPATH' ) || exit;

final class Maintenance implements Bootable {
	const HOOK = 'signa_maintenance';
	private $settings;
	private $state;
	private $logs;
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

	public function run(): array {
		$result = array(
			'codes' => (int) $this->codes->purge(),
			'state' => (int) $this->state->prune(),
			'logs'  => (int) $this->logs->purge( $this->settings->int( 'logs_keep_days', 7 ) ),
			'capped' => (int) $this->logs->cap( $this->settings->int( 'logs_max_rows', 200000 ) ),
		);

		do_action( 'signa_maintenance_done', $result );

		return $result;
	}
}
