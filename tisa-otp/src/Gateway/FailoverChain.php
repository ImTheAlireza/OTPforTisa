<?php
/**
 * Walks the configured gateway order until one of them accepts the delivery.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Gateway;

use TisaOtp\Config\Settings;
use TisaOtp\Log\Logger;

defined( 'ABSPATH' ) || exit;

final class FailoverChain {

	/** @var Registry */
	private $registry;

	/** @var Settings */
	private $settings;

	/** @var Logger */
	private $logger;

	/** @var Health */
	private $health;

	/** @var array<int,array<string,mixed>> */
	private $trace = array();

	public function __construct( Registry $registry, Settings $settings, Logger $logger, ?Health $health = null ) {
		$this->registry = $registry;
		$this->settings = $settings;
		$this->logger   = $logger;
		$this->health   = null === $health ? new Health() : $health;
	}

	/**
	 * Delivery plan for one gateway, straight from its driver.
	 *
	 * @return array{mode:string,sender:string,template:string,endpoint:string,issues:string[],notes:string[]}
	 */
	public function planFor( string $gateway ): array {
		return $this->registry->planFor( $gateway );
	}

	/**
	 * Gateway => outcome for the attempt that just ran.
	 *
	 * This is what the tools screen shows when an administrator asks "why did my
	 * test message not arrive": every gateway tried, in order, with the exact
	 * upstream error code and HTTP status.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function trace(): array {
		return $this->trace;
	}

	public function health(): Health {
		return $this->health;
	}

	public function deliver( DeliveryRequest $request ): GatewayResult {
		$order   = $this->registry->deliveryOrder();
		$attempt = 0;
		$last    = GatewayResult::failed( 'none', 'no_gateway', __( 'هیچ سامانه پیامکی پیکربندی نشده است.', 'tisa-otp' ) );

		$this->trace = array();

		foreach ( $order as $index => $id ) {
			$driver = $this->registry->find( $id );

			if ( null === $driver ) {
				$last = GatewayResult::failed( $id, 'unknown_gateway', __( 'سامانه پیامکی انتخاب‌شده شناخته نشده است.', 'tisa-otp' ) );
				continue;
			}

			$attempt++;
			$result = $driver->deliver( $request );

			if ( $result->isSent() ) {
				if ( $index > 0 ) {
					$this->logger->notice(
						'gateway.failover_used',
						array(
							'gateway' => $result->gateway(),
							'phone'   => $request->phone(),
							'attempt' => $attempt,
						)
					);
				}

				$this->record( $result, $attempt, $id );
				$this->health->success( $result->gateway(), $result->reference(), $result->httpStatus() );

				return $result;
			}

			$last = $result;

			$this->record( $result, $attempt, $id );
			$this->health->failure( $result->gateway(), $result->errorCode(), $result->message(), $result->httpStatus() );

			$this->logger->warning(
				'gateway.failed',
				array(
					'gateway'    => $result->gateway(),
					'error_code' => $result->errorCode(),
					'status'     => $result->httpStatus(),
					'phone'      => $request->phone(),
					'attempt'    => $attempt,
				)
			);

			if ( ! $this->shouldContinue( $result, $index, count( $order ) ) ) {
				break;
			}
		}

		return $last;
	}

	/**
	 * One line of the delivery trace shown on the tools screen.
	 */
	private function record( GatewayResult $result, int $attempt, string $id ): void {
		$this->trace[] = array(
			'gateway'    => $result->gateway(),
			'configured' => $id,
			'attempt'    => $attempt,
			'sent'       => $result->isSent(),
			'error_code' => $result->errorCode(),
			'message'    => $result->message(),
			'status'     => $result->httpStatus(),
			'reference'  => $result->reference(),
		);
	}

	/**
	 * Failover only makes sense for transient problems and only while another
	 * gateway is left in the chain.
	 */
	private function shouldContinue( GatewayResult $result, int $index, int $total ): bool {
		if ( $index + 1 >= $total ) {
			return false;
		}

		if ( ! $this->settings->bool( 'failover_enabled', true ) ) {
			return false;
		}

		if ( $result->isConfigurationProblem() ) {
			return false;
		}

		return $result->isTransient();
	}
}
