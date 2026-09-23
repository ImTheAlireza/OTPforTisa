<?php
/**
 * Walks the configured gateway order until one of them accepts the delivery.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Gateway;

use TisaOtp\Config\Settings;
use TisaOtp\Support\Transport;
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
		$order   = $this->choose( $this->registry->deliveryOrder() );
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
			$meta = $result->meta();

			/*
			 * The health card is where "why did it fail last time?" is answered
			 * without reading logs, so the technical reason travels with the
			 * sentence: "DNS: Could not resolve host: api.sms.ir — نام دامنه…".
			 */
			$detail = trim(
				( isset( $meta['reason'] ) ? (string) $meta['reason'] : '' ) . ' '
				. (string) $result->message()
			);

			$this->record( $result, $attempt, $id );

			/*
			 * A request the site itself refused is recorded, but not counted:
			 * the gateway was never asked, so it is neither unhealthy nor
			 * benched. The event keeps its own name too, so the events screen
			 * reads «خروجی سایت بسته است» instead of a gateway failure.
			 */
			$outbound = Transport::isBlocked( isset( $meta['reason'] ) ? (string) $meta['reason'] : (string) $result->message() );

			if ( $outbound ) {
				$this->health->blocked( $result->gateway(), $detail, $result->httpStatus() );
			} else {
				$this->health->failure( $result->gateway(), $result->errorCode(), $detail, $result->httpStatus() );
			}

			$this->logger->warning(
				$outbound ? 'gateway.blocked' : 'gateway.failed',
				array(
					'gateway'    => $result->gateway(),
					'error_code' => $result->errorCode(),
					'status'     => $result->httpStatus(),
					'phone'      => $request->phone(),
					'attempt'    => $attempt,
					// What actually failed, in the row the administrator reads.
					'reason'     => isset( $meta['reason'] ) ? (string) $meta['reason'] : '',
					'message'    => $result->message(),
				)
			);

			if ( ! $this->shouldContinue( $result, $index, count( $order ) ) ) {
				break;
			}
		}

		return $last;
	}

	/**
	 * Keep the gateways that are not resting, and log the ones that were held
	 * back. The order of the rest is untouched, because it is the admin's.
	 *
	 * @param string[] $order Configured order.
	 * @return string[]
	 */
	private function choose( array $order ): array {
		$blocked = array();

		foreach ( $order as $id ) {
			$blocked[ (string) $id ] = $this->health->resting( (string) $id );
		}

		$usable = self::usable( $order, $blocked );

		if ( count( $usable ) !== count( $order ) ) {
			$this->logger->notice(
				'gateway.breaker_skipped',
				array(
					'skipped' => array_values( array_diff( array_map( 'strval', $order ), $usable ) ),
					'trying'  => $usable,
				)
			);
		}

		return $usable;
	}

	/**
	 * A breaker must never be the reason nobody can log in: when every gateway
	 * is resting, the chain runs in full — which is also the half-open probe.
	 *
	 * Pure function, so the rule is testable without WordPress or a network.
	 *
	 * @param string[]            $order   Configured order.
	 * @param array<string,bool>  $blocked Gateway => resting.
	 * @return string[]
	 */
	public static function usable( array $order, array $blocked ): array {
		$usable = array();

		foreach ( $order as $id ) {
			if ( empty( $blocked[ (string) $id ] ) ) {
				$usable[] = (string) $id;
			}
		}

		return array() === $usable ? array_values( array_map( 'strval', $order ) ) : $usable;
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
			'reason'     => isset( $result->meta()['reason'] ) ? (string) $result->meta()['reason'] : '',
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
