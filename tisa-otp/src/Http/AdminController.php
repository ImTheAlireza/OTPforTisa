<?php
/**
 * Administrator endpoints: test delivery, housekeeping, stats and imports.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Http;

use TisaOtp\Channel\Dispatcher;
use TisaOtp\Config\Settings;
use TisaOtp\Gateway\Registry;
use TisaOtp\Import\Runner;
use TisaOtp\Log\Logger;
use TisaOtp\Log\LogStore;
use TisaOtp\Otp\OtpService;
use TisaOtp\Support\Phone;
use TisaOtp\Support\Rejection;
use TisaOtp\Throttle\Throttle;

defined( 'ABSPATH' ) || exit;

final class AdminController {

	/** @var Settings */
	private $settings;

	/** @var Dispatcher */
	private $dispatcher;

	/** @var OtpService */
	private $otp;

	/** @var Throttle */
	private $throttle;

	/** @var Logger */
	private $logger;

	/** @var LogStore */
	private $logs;

	/** @var Runner */
	private $importer;

	/** @var Registry */
	private $gateways;

	public function __construct(
		Settings $settings,
		Dispatcher $dispatcher,
		OtpService $otp,
		Throttle $throttle,
		Logger $logger,
		LogStore $logs,
		Runner $importer,
		Registry $gateways
	) {
		$this->settings   = $settings;
		$this->dispatcher = $dispatcher;
		$this->otp        = $otp;
		$this->throttle   = $throttle;
		$this->logger     = $logger;
		$this->logs       = $logs;
		$this->importer   = $importer;
		$this->gateways   = $gateways;
	}

	/**
	 * Send a real code to a number the administrator controls.
	 */
	public function sendTest( Request $request ): array {
		$phone = $request->phone();

		if ( ! Phone::isValid( $phone ) ) {
			throw Rejection::make( 'invalid_phone', __( 'شماره موبایل معتبر نیست.', 'tisa-otp' ) );
		}

		$code    = $this->otp->generate();
		$channel = $request->key( 'channel', $this->settings->str( 'channel', 'sms' ) );

		$this->otp->store( $phone, $code, $channel, $request->ip() );

		$result = $this->dispatcher->deliver(
			$phone,
			$code,
			array(
				'test'    => true,
				'channel' => $channel,
				'user_id' => $request->userId(),
				'email'   => $request->userId() ? (string) wp_get_current_user()->user_email : '',
			)
		);

		$this->logger->info(
			'admin.test_send',
			array(
				'phone'      => $phone,
				'gateway'    => $result->gateway(),
				'channel'    => $channel,
				'error_code' => $result->isSent() ? '' : $result->errorCode(),
				'user_id'    => $request->userId(),
			)
		);

		if ( ! $result->isSent() ) {
			$this->otp->revoke( $phone );

			throw Rejection::make( 'delivery_failed', $result->message(), array( 'gateway' => $result->gateway() ) );
		}

		return array(
			'sent'    => true,
			'via'     => $result->gateway(),
			'channel' => $channel,
			'masked'  => Phone::mask( $phone ),
			'message' => __( 'کد آزمایشی ارسال شد. اگر نرسید، لاگ‌ها را ببینید.', 'tisa-otp' ),
		);
	}

	public function resetThrottle( Request $request ): array {
		$cleared = $this->throttle->resetAll();
		$codes   = $this->otp->purge();

		$this->logger->notice( 'admin.throttle_reset', array( 'user_id' => $request->userId(), 'codes_purged' => $codes ) );

		return array(
			'cleared' => $cleared,
			'codes'   => $codes,
			'message' => __( 'شمارنده‌ها و کدهای منقضی پاک شدند.', 'tisa-otp' ),
		);
	}

	/**
	 * Everything the dashboard cards and sparkline need.
	 */
	public function summary( Request $request ): array {
		unset( $request );

		$channels = array();

		foreach ( $this->dispatcher->channels() as $id => $channel ) {
			$channels[ $id ] = array(
				'label'     => $channel->label(),
				'available' => $channel->available(),
				'reason'    => $channel->unavailableReason(),
			);
		}

		return array(
			'logs'     => $this->logs->totals(),
			'series'   => $this->logs->tally( 'code.sent', 14 ),
			'failures' => $this->logs->tally( 'code.not_sent', 14 ),
			'throttle' => $this->throttle->summary(),
			'gateways' => $this->gateways->report(),
			'channels' => $channels,
			'accounts' => $this->accountCount(),
			'code'     => array(
				'length'   => $this->otp->length(),
				'ttl'      => $this->otp->ttl(),
				'store'    => $this->settings->str( 'code_store', 'database' ),
				'channel'  => $this->settings->str( 'channel', 'sms' ),
				'flow'     => $this->settings->str( 'registration_flow', 'fields_then_code' ),
				'captcha'  => $this->settings->str( 'captcha_provider', 'none' ),
			),
		);
	}

	public function importStart( Request $request ): array {
		$source = $request->key( 'source' );

		if ( '' === $source ) {
			throw Rejection::make( 'missing_source', __( 'منبع واردسازی مشخص نشده است.', 'tisa-otp' ) );
		}

		$job = $this->importer->start(
			$source,
			$request->bool( 'dry_run' ),
			$request->key( 'conflict', 'skip' ),
			$request->key( 'custom_key' )
		);

		if ( isset( $job['error'] ) ) {
			throw Rejection::make( $job['error'], __( 'منبع انتخاب‌شده در دسترس نیست.', 'tisa-otp' ) );
		}

		return $this->importState( $job );
	}

	public function importStep( Request $request ): array {
		$job = $this->importer->step( $request->key( 'job_id' ) );

		if ( isset( $job['error'] ) ) {
			throw Rejection::make( $job['error'], __( 'کار واردسازی پیدا نشد.', 'tisa-otp' ) );
		}

		return $this->importState( $job );
	}

	public function importUndo( Request $request ): array {
		$job = $this->importer->undo( $request->key( 'job_id' ) );

		if ( isset( $job['error'] ) ) {
			throw Rejection::make( $job['error'], __( 'کار واردسازی پیدا نشد.', 'tisa-otp' ) );
		}

		return $this->importState( $job );
	}

	private function importState( array $job ): array {
		return array(
			'job_id'    => isset( $job['job_id'] ) ? $job['job_id'] : '',
			'status'    => isset( $job['status'] ) ? $job['status'] : 'unknown',
			'total'     => isset( $job['total'] ) ? (int) $job['total'] : 0,
			'cursor'    => isset( $job['cursor'] ) ? (int) $job['cursor'] : 0,
			'migrated'  => isset( $job['migrated'] ) ? (int) $job['migrated'] : 0,
			'skipped'   => isset( $job['skipped'] ) ? (int) $job['skipped'] : 0,
			'conflicts' => isset( $job['conflicts'] ) ? (int) $job['conflicts'] : 0,
			'errors'    => isset( $job['errors'] ) ? array_slice( (array) $job['errors'], 0, 20 ) : array(),
			'csv'       => isset( $job['job_id'] ) ? $this->importer->csv( (string) $job['job_id'] ) : '',
			'done'      => isset( $job['status'] ) && in_array( $job['status'], array( 'done', 'rolled_back', 'failed' ), true ),
		);
	}

	private function accountCount(): int {
		global $wpdb;

		$count = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT user_id) FROM ' . $wpdb->usermeta . ' WHERE meta_key = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$this->settings->str( 'phone_meta_key', 'tisa_phone' )
			)
		);

		return (int) $count;
	}
}
