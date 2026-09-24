<?php
/**
 * Structured logger. Events are namespaced strings such as `otp.sent`.
 *
 * @package Signa
 */

namespace Signa\Log;

use Signa\Config\Settings;

defined( 'ABSPATH' ) || exit;

final class Logger {

	/** @var Settings */
	private $settings;

	/** @var Redactor */
	private $redactor;

	/** @var LogStore */
	private $store;

	public function __construct( Settings $settings, Redactor $redactor, LogStore $store ) {
		$this->settings = $settings;
		$this->redactor = $redactor;
		$this->store    = $store;
	}

	public function debug( string $event, array $context = array() ): void {
		$this->record( 'debug', $event, $context );
	}

	public function info( string $event, array $context = array() ): void {
		$this->record( 'info', $event, $context );
	}

	public function notice( string $event, array $context = array() ): void {
		$this->record( 'notice', $event, $context );
	}

	public function warning( string $event, array $context = array() ): void {
		$this->record( 'warning', $event, $context );
	}

	public function error( string $event, array $context = array() ): void {
		$this->record( 'error', $event, $context );
	}

	public function store(): LogStore {
		return $this->store;
	}

	private function record( string $severity, string $event, array $context ): void {
		/**
		 * Allow third parties to tap every log record.
		 *
		 * @param string $severity Severity level.
		 * @param string $event    Event name.
		 * @param array  $context  Raw context.
		 */
		do_action( 'signa_log', $severity, $event, $context );

		if ( ! $this->settings->bool( 'logs_enabled', true ) ) {
			return;
		}

		$safe    = $this->redactor->clean( $context );
		$message = isset( $safe['message'] ) ? (string) $safe['message'] : $event;

		unset( $safe['message'] );

		$this->store->write( $severity, $event, $message, $safe );

		if ( $this->settings->bool( 'debug', false ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( '[signa][%s] %s %s', $severity, $event, wp_json_encode( $safe ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
