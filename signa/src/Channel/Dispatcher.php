<?php
/**
 * Chooses a channel, delivers the code and reports what happened.
 *
 * @package Signa
 */

namespace Signa\Channel;

use Signa\Config\Settings;
use Signa\Gateway\DeliveryRequest;
use Signa\Gateway\GatewayResult;
use Signa\Log\Logger;

defined( 'ABSPATH' ) || exit;

final class Dispatcher {

	/** @var array<string,Channel> */
	private $channels;

	/** @var Settings */
	private $settings;

	/** @var Logger */
	private $logger;

	/**
	 * @param array<string,Channel> $channels
	 */
	public function __construct( array $channels, Settings $settings, Logger $logger ) {
		$this->channels = $channels;
		$this->settings = $settings;
		$this->logger   = $logger;

		/**
		 * Register additional delivery channels.
		 *
		 * @param array<string,Channel> $channels id => channel instance.
		 */
		$extra = (array) apply_filters( 'signa_channels', array() );

		foreach ( $extra as $id => $channel ) {
			if ( $channel instanceof Channel ) {
				$this->channels[ (string) $id ] = $channel;
			}
		}
	}

	/**
	 * @return array<string,Channel>
	 */
	public function channels(): array {
		return $this->channels;
	}

	public function channel( string $id ): ?Channel {
		return isset( $this->channels[ $id ] ) ? $this->channels[ $id ] : null;
	}

	/**
	 * Ordered channel ids for the current configuration.
	 *
	 * @return string[]
	 */
	public function order( string $preferred = '' ): array {
		$preferred = '' !== $preferred ? $preferred : $this->settings->str( 'channel', 'sms' );
		$enabled   = $this->settings->arr( 'channels_enabled' );

		if ( array() === $enabled ) {
			$enabled = array( 'sms' );
		}

		$order = array( $preferred );

		if ( $this->settings->bool( 'failover_enabled', true ) ) {
			foreach ( $enabled as $id ) {
				if ( ! in_array( $id, $order, true ) ) {
					$order[] = $id;
				}
			}
		}

		return array_values( array_filter( $order, array( $this, 'exists' ) ) );
	}

	public function exists( string $id ): bool {
		return isset( $this->channels[ $id ] );
	}

	/**
	 * Trace of the last delivery attempt for the SMS channel.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function trace(): array {
		$sms = $this->channel( 'sms' );

		return $sms instanceof SmsChannel ? $sms->trace() : array();
	}

	public function deliver( string $phone, string $code, array $context = array() ): GatewayResult {
		$preferred = isset( $context['channel'] ) ? (string) $context['channel'] : '';
		$order     = $this->order( $preferred );
		$last      = GatewayResult::failed( 'none', 'no_channel', __( 'هیچ کانال ارسال فعالی پیکربندی نشده است.', 'signa' ) );

		if ( array() === $order ) {
			return $last;
		}

		foreach ( $order as $index => $id ) {
			$channel = $this->channels[ $id ];

			if ( ! $channel->available() ) {
				$last = GatewayResult::failed( $id, 'channel_unavailable', $channel->unavailableReason() );
				continue;
			}

			$request = DeliveryRequest::make( $phone, $code, $id, $context );
			$result  = $channel->deliver( $request );

			if ( $result->isSent() ) {
				$this->logger->info(
					'code.sent',
					array(
						'channel' => $id,
						'gateway' => $result->gateway(),
						'phone'   => $phone,
						'user_id' => isset( $context['user_id'] ) ? (int) $context['user_id'] : 0,
					)
				);

				return GatewayResult::sent(
					$result->gateway(),
					$result->reference(),
					$result->httpStatus(),
					array_merge( $result->meta(), array( 'channel' => $id ) )
				);
			}

			$last = GatewayResult::failed(
				$result->gateway(),
				$result->errorCode(),
				$result->message(),
				$result->httpStatus(),
				array_merge( $result->meta(), array( 'channel' => $id ) )
			);

			$meta = $result->meta();

			$this->logger->warning(
				'code.not_sent',
				array(
					'channel'    => $id,
					'gateway'    => $result->gateway(),
					'error_code' => $result->errorCode(),
					'status'     => $result->httpStatus(),
					'phone'      => $phone,
					'reason'     => isset( $meta['reason'] ) ? (string) $meta['reason'] : '',
					'message'    => $result->message(),
				)
			);

			if ( $index + 1 < count( $order ) && ! $this->settings->bool( 'failover_enabled', true ) ) {
				break;
			}
		}

		return $last;
	}
}
