<?php
/**
 * Administrator endpoints: test delivery, housekeeping, stats and imports.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Http;

use TisaOtp\Captcha\Manager as CaptchaManager;
use TisaOtp\Channel\Dispatcher;
use TisaOtp\Config\Settings;
use TisaOtp\Gateway\Health;
use TisaOtp\Gateway\Registry;
use TisaOtp\Import\Runner;
use TisaOtp\Log\Logger;
use TisaOtp\Log\LogStore;
use TisaOtp\Otp\OtpService;
use TisaOtp\Support\Phone;
use TisaOtp\Diagnostics\SelfTest;
use TisaOtp\Support\Rejection;
use TisaOtp\Support\Transport;
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

	/** @var CaptchaManager */
	private $captcha;

	/** @var SelfTest */
	private $selfTest;

	public function __construct(
		Settings $settings,
		Dispatcher $dispatcher,
		OtpService $otp,
		Throttle $throttle,
		Logger $logger,
		LogStore $logs,
		Runner $importer,
		Registry $gateways,
		CaptchaManager $captcha,
		SelfTest $selfTest
	) {
		$this->settings   = $settings;
		$this->dispatcher = $dispatcher;
		$this->otp        = $otp;
		$this->throttle   = $throttle;
		$this->logger     = $logger;
		$this->logs       = $logs;
		$this->importer   = $importer;
		$this->gateways   = $gateways;
		$this->captcha    = $captcha;
		$this->selfTest   = $selfTest;
	}

	/**
	 * Run one of the per-section self-tests and hand the rows back untouched.
	 *
	 * The screen draws whatever this returns, so a check that could not run says
	 * so in its own row instead of disappearing.
	 */
	public function check( Request $request ): array {
		return $this->selfTest->run( $request->key( 'kind' ) );
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

		$meta = $result->meta();

		/*
		 * «ارسال شد» was true and misleading at once: the owner pressed "send a
		 * test SMS", the panel was unreachable, the email channel carried the
		 * code, and the modal showed a green tick. The channel that actually
		 * carried it is named in the meta the dispatcher returns, so the panel
		 * can say "the SMS did not go" instead.
		 */
		$carrier = isset( $meta['channel'] ) ? (string) $meta['channel'] : $channel;
		$direct   = $carrier === $channel;

		$this->logger->info(
			'admin.test_send',
			array(
				'phone'      => $phone,
				'gateway'    => $result->gateway(),
				'channel'    => $channel,
				'error_code' => $result->isSent() ? '' : $result->errorCode(),
				'reason'     => $result->isSent() ? '' : ( isset( $meta['reason'] ) ? (string) $meta['reason'] : '' ),
				'message'    => $result->isSent()
					? sprintf( /* translators: 1: channel, 2: gateway */ __( 'کد آزمایشی از %1$s (%2$s) ارسال شد.', 'tisa-otp' ), $channel, $result->gateway() )
					: $result->message(),
				'user_id'    => $request->userId(),
			)
		);

		if ( ! $result->isSent() ) {
			$this->otp->revoke( $phone );

			throw Rejection::make(
				'delivery_failed',
				$result->message(),
				array(
					'gateway'    => $result->gateway(),
					'error_code' => $result->errorCode(),
					'reason'     => isset( $meta['reason'] ) ? (string) $meta['reason'] : '',
					'status'     => $result->httpStatus(),
					'trace'      => $this->dispatcher->trace(),
					'plan'       => $this->gateways->planFor( $result->gateway() ),
				)
			);
		}

		return array(
			'sent'    => true,
			'via'     => $result->gateway(),
			'channel' => $channel,
			'carrier' => $carrier,
			'direct'  => $direct,
			'masked'  => Phone::mask( $phone ),
			'trace'   => $this->dispatcher->trace(),
			'plan'    => $this->gateways->planFor( $result->gateway() ),
			'message' => $direct
				? __( 'کد آزمایشی ارسال شد. اگر نرسید، رویدادها را ببینید.', 'tisa-otp' )
				: sprintf(
					/* translators: %s: the channel that carried the code instead */
					__( 'پیامک ارسال نشد؛ کد آزمایشی از راه %s رفت. علت شکست پیامک در همین پنجره آمده است.', 'tisa-otp' ),
					$this->channelLabel( $carrier )
				),
		);
	}

	/**
	 * The name of a channel as a person reads it: «ایمیل», not `email`.
	 */
	private function channelLabel( string $id ): string {
		$channel = $this->dispatcher->channel( $id );

		return null !== $channel ? $channel->label() : $id;
	}

	/**
	 * Everything the "system doctor" card shows: what would send, and what stops it.
	 */
	public function doctor( Request $request ): array {

		$channels = array();

		foreach ( $this->dispatcher->channels() as $id => $channel ) {
			$channels[ $id ] = array(
				'label'     => $channel->label(),
				'available' => $channel->available(),
				'reason'    => $channel->unavailableReason(),
			);
		}

		$gateways = array();

		foreach ( $this->gateways->report() as $id => $report ) {
			$plan = array(
				'mode'     => isset( $report['mode'] ) ? (string) $report['mode'] : 'text',
				'sender'   => isset( $report['sender'] ) ? (string) $report['sender'] : '',
				'template' => isset( $report['template'] ) ? (string) $report['template'] : '',
				'endpoint' => '',
				'issues'   => isset( $report['issues'] ) ? (array) $report['issues'] : array(),
				'notes'    => isset( $report['notes'] ) ? (array) $report['notes'] : array(),
			);

			$gateways[ $id ] = array_merge(
				$report,
				array(
					'plan'        => $this->gateways->planFor( $id ),
					'health_text' => isset( $report['health_text'] ) ? (string) $report['health_text'] : '',
					'mode'        => $plan['mode'],
				)
			);
		}

		$this->logger->notice( 'admin.doctor', array( 'user_id' => $request->userId() ) );

		return array(
			'gateways'    => $gateways,
			'channels'    => $channels,
			'captcha'     => $this->captcha->diagnostics(),
			'cache_mode'  => $this->settings->str( 'cache_mode', 'auto' ),
			'webotp'      => $this->settings->bool( 'webotp_enabled', false ),
			'cron'        => (int) wp_next_scheduled( 'tisa_otp_maintenance' ),
			'debug'       => $this->settings->bool( 'debug', false ),
			'form_token'  => \TisaOtp\Support\FormToken::issue(),
		);
	}

	/**
	 * Outbound reachability probe for one service, run on demand.
	 *
	 * The most common cause of "the SMS does not arrive" on Iranian hosting is
	 * WP_HTTP_BLOCK_EXTERNAL, a firewall, or a DNS resolver that cannot resolve
	 * the panel. This answers exactly that question, with the HTTP status.
	 */
	public function probe( Request $request ): array {
		$service = sanitize_key( $request->key( 'service', '' ) );
		$url     = $this->probeUrl( $service );

		if ( '' === $url ) {
			throw Rejection::make( 'unknown_service', __( 'سرویسی با این شناسه برای بررسی وجود ندارد.', 'tisa-otp' ) );
		}

		$started  = microtime( true );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 8,
				'redirection'         => 0,
				'limit_response_size' => 2048,
				'headers'             => array( 'Accept' => '*/*' ),
				'user-agent'          => 'TisaOTP/' . TISA_OTP_VERSION . '; ' . home_url( '/' ),
			)
		);

		$elapsed = (int) round( ( microtime( true ) - $started ) * 1000 );
		$blocked = defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL;

		$this->logger->notice(
			'admin.probe',
			array(
				'service' => $service,
				'user_id' => $request->userId(),
				'ok'      => ! is_wp_error( $response ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$transport = $blocked
				? Transport::from( 'block_external', (string) $response->get_error_code() )
				: Transport::fromError( $response );

			return array(
				'service' => $service,
				'url'     => $url,
				'ok'      => false,
				'ms'      => $elapsed,
				'status'  => 0,
				'error'   => $response->get_error_code(),
				'kind'    => $transport['kind'],
				'reason'  => $transport['reason'],
				'message' => $transport['message'],
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		return array(
			'service' => $service,
			'url'     => $url,
			'ok'      => $status > 0,
			'ms'      => $elapsed,
			'status'  => $status,
			'error'   => '',
			'message' => sprintf(
				/* translators: 1: HTTP status code, 2: milliseconds */
				__( 'پاسخ %1$d در %2$d میلی‌ثانیه — مسیر خروجی باز است.', 'tisa-otp' ),
				$status,
				$elapsed
			),
		);
	}

	/**
	 * Resolve a probe target: a gateway endpoint, or the active captcha script.
	 */
	private function probeUrl( string $service ): string {
		if ( 'captcha' === $service ) {
			$bundle = $this->captcha->diagnostics();
			$scripts = isset( $bundle['scripts'] ) ? (array) $bundle['scripts'] : array();

			return isset( $scripts[0] ) ? (string) $scripts[0] : '';
		}

		if ( 'wordpress' === $service ) {
			return 'https://api.wordpress.org/';
		}

		$plan = $this->gateways->planFor( $service );

		return isset( $plan['endpoint'] ) ? (string) $plan['endpoint'] : '';
	}

	public function resetThrottle( Request $request ): array {
		$cleared = $this->throttle->resetAll();
		$codes   = $this->otp->purge();

		// A gateway the administrator has just fixed should not have to wait out
		// its rest window before the next test proves it works.
		$health = new Health();
		$health->forget();

		$this->logger->notice( 'admin.throttle_reset', array( 'user_id' => $request->userId(), 'codes_purged' => $codes ) );

		return array(
			'cleared' => $cleared,
			'codes'   => $codes,
			'message' => __( 'شمارنده‌ها، کدهای منقضی و سابقهٔ سلامت سامانه‌ها پاک شدند.', 'tisa-otp' ),
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
