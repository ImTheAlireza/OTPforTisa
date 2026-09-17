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

	public function __construct( Registry $registry, Settings $settings, Logger $logger ) {
		$this->registry = $registry;
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	public function deliver( DeliveryRequest $request ): GatewayResult {
		$order   = $this->registry->deliveryOrder();
		$attempt = 0;
		$last    = GatewayResult::failed( 'none', 'no_gateway', __( 'هیچ سامانه پیامکی پیکربندی نشده است.', 'tisa-otp' ) );

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

				return $result;
			}

			$last = $result;

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
