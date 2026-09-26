<?php

namespace Signa\Gateway;

use Signa\Config\Settings;
use Signa\Support\Transport;
use Signa\Log\Logger;

defined( 'ABSPATH' ) || exit;

final class FailoverChain {
	private $registry;
	private $settings;
	private $logger;
	private $health;
	private $trace = array();

	public function __construct( Registry $registry, Settings $settings, Logger $logger, ?Health $health = null ) {
		$this->registry = $registry;
		$this->settings = $settings;
		$this->logger   = $logger;
		$this->health   = null === $health ? new Health() : $health;
	}

	public function order(): array {
		return array_values( array_map( 'strval', $this->registry->deliveryOrder() ) );
	}

	public function planFor( string $gateway ): array {
		return $this->registry->planFor( $gateway );
	}

	public function trace(): array {
		return $this->trace;
	}

	public function health(): Health {
		return $this->health;
	}

	public function deliver( DeliveryRequest $request ): GatewayResult {
		$order   = $this->choose( $this->registry->deliveryOrder() );
		$attempt = 0;
		$last    = GatewayResult::failed( 'none', 'no_gateway', __( 'هیچ سامانه پیامکی پیکربندی نشده است.', 'signa' ) );

		$this->trace = array();

		foreach ( $order as $index => $id ) {
			$driver = $this->registry->find( $id );

			if ( null === $driver ) {
				$last = GatewayResult::failed( $id, 'unknown_gateway', __( 'سامانه پیامکی انتخاب‌شده شناخته نشده است.', 'signa' ) );
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

			$detail = trim(
				( isset( $meta['reason'] ) ? (string) $meta['reason'] : '' ) . ' '
				. (string) $result->message()
			);

			$this->record( $result, $attempt, $id );

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

	public static function usable( array $order, array $blocked ): array {
		$usable = array();

		foreach ( $order as $id ) {
			if ( empty( $blocked[ (string) $id ] ) ) {
				$usable[] = (string) $id;
			}
		}

		return array() === $usable ? array_values( array_map( 'strval', $order ) ) : $usable;
	}

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

	private function shouldContinue( GatewayResult $result, int $index, int $total ): bool {
		return self::continues( $result, $index, $total, $this->settings->bool( 'failover_enabled', true ) );
	}

	public static function continues( GatewayResult $result, int $index, int $total, bool $enabled ): bool {
		if ( $index + 1 >= $total || ! $enabled ) {
			return false;
		}

		return $result->worthFailover();
	}
}
