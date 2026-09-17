<?php
/**
 * Cooldown reservation and quota charging.
 *
 * The cooldown is *reserved* before the gateway is contacted, so two parallel
 * requests cannot both slip through; the caller releases the reservation when
 * delivery fails.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Guard;

use TisaOtp\Http\Request;
use TisaOtp\Support\Rejection;
use TisaOtp\Throttle\Throttle;

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
					__( 'برای دریافت کد جدید %d ثانیه صبر کنید.', 'tisa-otp' ),
					$remaining
				),
				array( 'retry_after' => $remaining )
			);
		}

		if ( ! $this->throttle->reserveCooldown( $request->phone() ) ) {
			throw Rejection::make(
				'cooldown',
				__( 'درخواست موازی دیگری برای این شماره در جریان است.', 'tisa-otp' ),
				array( 'retry_after' => 5 )
			);
		}

		$this->throttle->chargeSend( $request->phone(), $request->ip() );
	}
}
