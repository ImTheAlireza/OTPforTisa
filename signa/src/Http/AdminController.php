<?php

namespace Signa\Http;

use Signa\Captcha\Manager as CaptchaManager;
use Signa\Channel\Dispatcher;
use Signa\Config\Settings;
use Signa\Gateway\Health;
use Signa\Gateway\Registry;
use Signa\Import\Runner;
use Signa\Log\Logger;
use Signa\Log\LogStore;
use Signa\Otp\OtpService;
use Signa\Support\Phone;
use Signa\Diagnostics\SelfTest;
use Signa\Support\Rejection;
use Signa\Support\Transport;
use Signa\Throttle\Throttle;

defined( 'ABSPATH' ) || exit;

final class AdminController {
	private $settings;
	private $dispatcher;
	private $otp;
	private $throttle;
	private $logger;
	private $logs;
	private $importer;
	private $gateways;
	private $captcha;
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

	public function check( Request $request ): array {
		return $this->selfTest->run( $request->key( 'kind' ) );
	}

	public function sendTest( Request $request ): array {
		$phone = $request->phone();

		if ( ! Phone::isValid( $phone ) ) {
			throw Rejection::make( 'invalid_phone', __( 'شماره موبایل معتبر نیست.', 'signa' ) );
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
					? sprintf(  __( 'کد آزمایشی از %1$s (%2$s) ارسال شد.', 'signa' ), $channel, $result->gateway() )
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
					'plan'       => $this->smsPlan(),
					'fix'        => $this->fixFor( (string) ( $meta['reason'] ?? '' ) ),
				)
			);
		}

		return array(
			'sent'    => true,
			'via'     => $result->gateway(),
			'channel' => $channel,
			'carrier' => $carrier,
			'carrier_label' => $this->channelLabel( $carrier ),
			'direct'  => $direct,
			'masked'  => Phone::mask( $phone ),
			'trace'   => $this->dispatcher->trace(),
			'plan'    => $this->smsPlan(),
			'fix'     => $this->fixFor( $this->blockedReason() ),
			'message' => $direct
				? __( 'کد آزمایشی ارسال شد. اگر نرسید، رویدادها را ببینید.', 'signa' )
				: sprintf(
					__( 'کد آزمایشی از راه %s رفت؛ علت شکست پیامک در همین پنجره آمده است.', 'signa' ),
					$this->channelLabel( $carrier )
				),
		);
	}

	private function smsPlan(): array {
		$order = $this->gateways->deliveryOrder();
		$id    = isset( $order[0] ) ? (string) $order[0] : (string) $this->settings->str( 'sms_gateway', 'smsir' );

		return $this->gateways->planFor( $id );
	}

	private function blockedReason(): string {
		foreach ( $this->dispatcher->trace() as $step ) {
			$reason = isset( $step['reason'] ) ? (string) $step['reason'] : '';

			if ( '' !== $reason && false === (bool) ( $step['sent'] ?? false ) ) {
				return $reason;
			}
		}

		return '';
	}

	private function fixFor( string $reason ): string {
		if ( '' === $reason || Transport::BLOCKED !== Transport::classify( '', $reason ) ) {
			return '';
		}

		if ( $this->settings->bool( 'direct_send', false ) ) {
			return '';
		}

		return __( 'در پیشرفته › کلیدهای داده و ارسال «ارسال مستقیم» را روشن کنید؛ افزونه خودش درخواست را می‌فرستد و لازم نیست wp-config.php را عوض کنید.', 'signa' );
	}

	private function channelLabel( string $id ): string {
		$channel = $this->dispatcher->channel( $id );

		return null !== $channel ? $channel->label() : $id;
	}

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
			'cron'        => (int) wp_next_scheduled( 'signa_maintenance' ),
			'debug'       => $this->settings->bool( 'debug', false ),
			'form_token'  => \Signa\Support\FormToken::issue(),
		);
	}

	public function probe( Request $request ): array {
		$service = sanitize_key( $request->key( 'service', '' ) );
		$url     = $this->probeUrl( $service );

		if ( '' === $url ) {
			throw Rejection::make( 'unknown_service', __( 'سرویسی با این شناسه برای بررسی وجود ندارد.', 'signa' ) );
		}

		$started  = microtime( true );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 8,
				'redirection'         => 0,
				'limit_response_size' => 2048,
				'headers'             => array( 'Accept' => '*/*' ),
				'user-agent'          => 'SignaOTP/' . SIGNA_VERSION . '; ' . home_url( '/' ),
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
				__( 'پاسخ %1$d در %2$d میلی‌ثانیه. مسیر خروجی باز است.', 'signa' ),
				$status,
				$elapsed
			),
		);
	}

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

		$health = new Health();
		$health->forget();

		$this->logger->notice( 'admin.throttle_reset', array( 'user_id' => $request->userId(), 'codes_purged' => $codes ) );

		return array(
			'cleared' => $cleared,
			'codes'   => $codes,
			'message' => __( 'شمارنده‌ها، کدهای منقضی و سابقهٔ سلامت سامانه‌ها پاک شدند.', 'signa' ),
		);
	}

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
			throw Rejection::make( 'missing_source', __( 'منبع واردسازی مشخص نشده است.', 'signa' ) );
		}

		$job = $this->importer->start(
			$source,
			$request->bool( 'dry_run' ),
			$request->key( 'conflict', 'skip' ),
			$request->key( 'custom_key' )
		);

		if ( isset( $job['error'] ) ) {
			throw Rejection::make( $job['error'], __( 'منبع انتخاب‌شده در دسترس نیست.', 'signa' ) );
		}

		return $this->importState( $job );
	}

	public function importStep( Request $request ): array {
		$job = $this->importer->step( $request->key( 'job_id' ) );

		if ( isset( $job['error'] ) ) {
			throw Rejection::make( $job['error'], __( 'کار واردسازی پیدا نشد.', 'signa' ) );
		}

		return $this->importState( $job );
	}

	public function importUndo( Request $request ): array {
		$job = $this->importer->undo( $request->key( 'job_id' ) );

		if ( isset( $job['error'] ) ) {
			throw Rejection::make( $job['error'], __( 'کار واردسازی پیدا نشد.', 'signa' ) );
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

		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT user_id) FROM ' . $wpdb->usermeta . ' WHERE meta_key = %s',
				$this->settings->str( 'phone_meta_key', 'signa_phone' )
			)
		);

		return (int) $count;
	}
}
