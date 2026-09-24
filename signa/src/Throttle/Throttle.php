<?php
/**
 * Fixed-window quotas, resend cooldown and verification throttling.
 *
 * @package Signa
 */

namespace Signa\Throttle;

use Signa\Config\Settings;
use Signa\State\StateStore;
use Signa\Support\ClientIp;
use Signa\Support\Phone;
use Signa\Support\Rejection;

defined( 'ABSPATH' ) || exit;

final class Throttle {

	const PREFIX_SEND_PHONE = 'quota:send:phone:';
	const PREFIX_SEND_IP    = 'quota:send:ip:';
	const PREFIX_SEND_DAY   = 'quota:send:day:';
	const PREFIX_SEND_SITE  = 'quota:send:site:';
	const PREFIX_VERIFY_IP  = 'quota:verify:ip:';
	const PREFIX_CHALLENGE  = 'quota:challenge:ip:';
	const PREFIX_COOLDOWN   = 'cooldown:';
	const PREFIX_LOCK       = 'cooldownlock:';

	/** @var StateStore */
	private $state;

	/** @var Settings */
	private $settings;

	public function __construct( StateStore $state, Settings $settings ) {
		$this->state    = $state;
		$this->settings = $settings;
	}

	public function isEnabled(): bool {
		return $this->settings->bool( 'throttle_enabled', true );
	}

	public function windowSeconds(): int {
		return max( MINUTE_IN_SECONDS, $this->settings->int( 'window_minutes', 60 ) * MINUTE_IN_SECONDS );
	}

	/**
	 * Charge one send attempt against every quota. Throws when a limit is hit.
	 */
	public function chargeSend( string $phone, string $ip ): void {
		if ( ! $this->isEnabled() ) {
			return;
		}

		$window = $this->windowSeconds();

		$perPhone = $this->state->bump( self::PREFIX_SEND_PHONE . Phone::fingerprint( $phone ), $window );
		$perIp    = $this->state->bump( self::PREFIX_SEND_IP . ClientIp::fingerprint( $ip ), $window );
		$perDay   = $this->state->bump( self::PREFIX_SEND_DAY . ClientIp::fingerprint( $ip ), DAY_IN_SECONDS );
		$perSite  = $this->state->bump( self::PREFIX_SEND_SITE . gmdate( 'Y-m-d' ), DAY_IN_SECONDS );

		$phoneLimit = max( 1, $this->settings->int( 'limit_per_phone', 5 ) );
		$ipLimit    = max( 1, $this->settings->int( 'limit_per_ip', 12 ) );
		$dayLimit   = max( $ipLimit, $this->settings->int( 'limit_per_ip_daily', 60 ) );
		$siteLimit  = $this->settings->int( 'limit_per_site_daily', 300 );

		/*
		 * The per-address quotas above are defeated by a botnet: a thousand
		 * addresses each sending twelve codes is a thousand addresses inside
		 * every limit. This one counter is not about abuse on a single address,
		 * it is the site's own ceiling — the day the number of codes the whole
		 * site sent crosses it, sending stops and says so. It protects the
		 * owner's credit line, which is the only thing a distributed flood
		 * actually spends.
		 */
		if ( $siteLimit > 0 && $perSite > $siteLimit ) {
			throw Rejection::make(
				'site_daily_limit',
				__( 'سقف ارسال روزانهٔ این سایت تکمیل شده است. فردا دوباره تلاش کنید یا با مدیریت سایت تماس بگیرید.', 'signa' ),
				array( 'retry_after' => DAY_IN_SECONDS )
			);
		}

		if ( $perPhone > $phoneLimit ) {
			throw Rejection::make(
				'quota_phone',
				__( 'سقف ارسال کد برای این شماره در بازه جاری پر شده است. کمی بعد دوباره تلاش کنید.', 'signa' ),
				array( 'retry_after' => $window )
			);
		}

		if ( $perIp > $ipLimit ) {
			throw Rejection::make(
				'quota_ip',
				__( 'تعداد درخواست‌ها از آدرس شما بیش از حد مجاز است.', 'signa' ),
				array( 'retry_after' => $window )
			);
		}

		if ( $perDay > $dayLimit ) {
			throw Rejection::make(
				'quota_ip_daily',
				__( 'سقف روزانه ارسال کد از این آدرس تکمیل شده است.', 'signa' ),
				array( 'retry_after' => DAY_IN_SECONDS )
			);
		}
	}

	/**
	 * Charge a verification attempt (IP scoped, so an attacker cannot lock out
	 * a victim's phone number).
	 */
	public function chargeVerify( string $ip ): void {
		if ( ! $this->isEnabled() ) {
			return;
		}

		$window = $this->windowSeconds();
		$hits   = $this->state->bump( self::PREFIX_VERIFY_IP . ClientIp::fingerprint( $ip ), $window );
		$limit  = max( 5, $this->settings->int( 'limit_verify_per_ip', 25 ) );

		if ( $hits > $limit ) {
			throw Rejection::make( 'quota_verify', __( 'تلاش‌های تأیید کد از این آدرس بیش از حد مجاز است.', 'signa' ), array( 'retry_after' => $window ) );
		}
	}

	/**
	 * Charge a captcha submission before it reaches the external service.
	 */
	public function chargeChallenge( string $ip ): void {
		if ( ! $this->isEnabled() ) {
			return;
		}

		$window = $this->windowSeconds();
		$hits   = $this->state->bump( self::PREFIX_CHALLENGE . ClientIp::fingerprint( $ip ), $window );
		$limit  = max( 20, $this->settings->int( 'limit_per_ip', 12 ) * 6 );

		if ( $hits > $limit ) {
			throw Rejection::make( 'quota_challenge', __( 'تعداد درخواست‌های امنیتی از این آدرس بیش از حد مجاز است.', 'signa' ) );
		}
	}

	public function cooldownSeconds(): int {
		return max( 10, $this->settings->int( 'resend_delay', 60 ) );
	}

	/**
	 * Remaining cooldown in seconds (0 when the user may request again).
	 */
	public function cooldownRemaining( string $phone ): int {
		$value = $this->state->get( self::PREFIX_COOLDOWN . Phone::fingerprint( $phone ) );

		if ( ! is_array( $value ) || empty( $value['until'] ) ) {
			return 0;
		}

		return max( 0, (int) $value['until'] - time() );
	}

	public function startCooldown( string $phone ): void {
		if ( ! $this->isEnabled() ) {
			return;
		}

		$seconds = $this->cooldownSeconds();

		$this->state->put(
			self::PREFIX_COOLDOWN . Phone::fingerprint( $phone ),
			array(
				'until' => time() + $seconds,
				'since' => time(),
			),
			$seconds + 5
		);
	}

	/**
	 * Claim the right to send before contacting the gateway. Prevents two
	 * parallel requests from both slipping through the cooldown check.
	 */
	public function reserveCooldown( string $phone ): bool {
		if ( ! $this->isEnabled() ) {
			return true;
		}

		if ( $this->cooldownRemaining( $phone ) > 0 ) {
			return false;
		}

		return $this->state->claim( self::PREFIX_LOCK . Phone::fingerprint( $phone ), '1', 20 );
	}

	public function releaseReservation( string $phone ): void {
		$this->state->forget( self::PREFIX_LOCK . Phone::fingerprint( $phone ) );
	}

	/**
	 * How much of the current window a phone/IP pair has already consumed.
	 */
	public function usage( string $phone, string $ip ): array {
		return array(
			'phone'       => $this->state->hits( self::PREFIX_SEND_PHONE . Phone::fingerprint( $phone ) ),
			'ip'          => $this->state->hits( self::PREFIX_SEND_IP . ClientIp::fingerprint( $ip ) ),
			'ip_daily'    => $this->state->hits( self::PREFIX_SEND_DAY . ClientIp::fingerprint( $ip ) ),
			'phone_limit' => max( 1, $this->settings->int( 'limit_per_phone', 5 ) ),
			'ip_limit'    => max( 1, $this->settings->int( 'limit_per_ip', 12 ) ),
		);
	}

	public function forgetPhone( string $phone ): void {
		$fingerprint = Phone::fingerprint( $phone );

		$this->state->forget( self::PREFIX_COOLDOWN . $fingerprint );
		$this->state->forget( self::PREFIX_LOCK . $fingerprint );
		$this->state->forget( self::PREFIX_SEND_PHONE . $fingerprint );
	}

	public function resetAll(): array {
		return array(
			'cooldowns' => $this->state->forgetPrefix( self::PREFIX_COOLDOWN ),
			'locks'     => $this->state->forgetPrefix( self::PREFIX_LOCK ),
			'send'      => $this->state->forgetPrefix( 'quota:send:' ),
			'verify'    => $this->state->forgetPrefix( self::PREFIX_VERIFY_IP ),
			'challenge' => $this->state->forgetPrefix( self::PREFIX_CHALLENGE ),
		);
	}

	/**
	 * Snapshot used by the admin tools screen.
	 */
	public function summary(): array {
		return array(
			'cooldown_rows' => $this->state->countPrefix( self::PREFIX_COOLDOWN ),
			'quota_rows'    => $this->state->countPrefix( 'quota:' ),
			'window'        => $this->windowSeconds(),
			'per_phone'     => $this->settings->int( 'limit_per_phone', 5 ),
			'per_ip'        => $this->settings->int( 'limit_per_ip', 12 ),
			'per_ip_daily'  => $this->settings->int( 'limit_per_ip_daily', 60 ),
		);
	}
}
