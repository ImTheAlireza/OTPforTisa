<?php

namespace Signa\Guard;

use Signa\Http\Request;
use Signa\Support\Rejection;
use Signa\Throttle\Throttle;

defined( 'ABSPATH' ) || exit;

final class ThrottleGuard implements Guard {
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
	}

	public function settle( Request $request ): void {
		if ( ! $this->throttle->isEnabled() ) {
			return;
		}

		$this->throttle->chargeSend( $request->phone(), $request->ip() );
	}

	public function release( Request $request ): void {
		$this->throttle->releaseReservation( $request->phone() );
	}
}
