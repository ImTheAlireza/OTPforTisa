<?php
/**
 * Cooldown reservation and quota charging.
 *
 * The cooldown is *reserved* before the gateway is contacted, so two parallel
 * requests cannot both slip through; the caller releases the reservation when
 * delivery fails.
 *
 * @package Signa
 */

namespace Signa\Guard;

use Signa\Http\Request;
use Signa\Support\Rejection;
use Signa\Throttle\Throttle;

defined( 'ABSPATH' ) || exit;

final class ThrottleGuard implements Guard {

	/** @var Throttle */
	private $throttle;

	public function __construct( Throttle $throttle ) {
		$this->throttle = $throttle;
	}

	public function name(): string {
		return 'throttle';
	}

	public function stages(): array {
		return array( 'send', 'verify' );
	}

	public function inspect( Request $request, string $stage ): void {
		if ( ! $this->throttle->isEnabled() ) {
			return;
		}

		if ( 'verify' === $stage ) {
			$this->throttle->chargeVerify( $request->ip() );
			return;
		}

		$remaining = $this->throttle->cooldownRemaining( $request->phone() );

		if ( $remaining > 0 ) {
			throw Rejection::make(
				'cooldown',
				sprintf(
					/* translators: %d: seconds left */
					__( 'برای دریافت کد جدید %d ثانیه صبر کنید.', 'signa' ),
					$remaining
				),
				array( 'retry_after' => $remaining )
			);
		}

		if ( ! $this->throttle->reserveCooldown( $request->phone() ) ) {
			throw Rejection::make(
				'cooldown',
				__( 'درخواست موازی دیگری برای این شماره در جریان است.', 'signa' ),
				array( 'retry_after' => 5 )
			);
		}

		/*
		 * The quota is not charged here any more: see settle(). A request the
		 * captcha refuses next must not spend the phone's quota, or a bot
		 * that never solves a challenge could lock a stranger's number (and,
		 * spread over many addresses, the whole site's daily ceiling) out.
		 */
	}

	/**
	 * Charge the send quotas once every guard has let the request through.
	 * Called by the pipeline; throws the quota rejections as before.
	 */
	public function settle( Request $request ): void {
		if ( ! $this->throttle->isEnabled() ) {
			return;
		}

		$this->throttle->chargeSend( $request->phone(), $request->ip() );
	}

	/**
	 * Give back the in-flight lock taken by inspect() when a later guard
	 * refuses — otherwise the visitor who mistyped a captcha reads «درخواست
	 * موازی دیگری در جریان است» for twenty seconds.
	 */
	public function release( Request $request ): void {
		$this->throttle->releaseReservation( $request->phone() );
	}
}
