<?php

namespace Signa\Gateway;

use Signa\Config\Settings;
use Signa\Support\Transport;

defined( 'ABSPATH' ) || exit;

final class Registry {
	private $settings;
	private $instances;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function catalogue(): array {
		$drivers = array(
			'smsir'     => Drivers\SmsIr::class,
			'kavenegar' => Drivers\Kavenegar::class,
			'meli'      => Drivers\MeliPayamak::class,
			'ippanel'   => Drivers\Ippanel::class,
			'faraz'     => Drivers\FarazSms::class,
		);

		return (array) apply_filters( 'signa_sms_gateways', $drivers );
	}

	public function all(): array {
		if ( null !== $this->instances ) {
			return $this->instances;
		}

		$this->instances = array();

		foreach ( $this->catalogue() as $id => $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}

			$driver = new $class( $this->settings );

			if ( $driver instanceof SmsGateway ) {
				$this->instances[ $id ] = $driver;
			}
		}

		return $this->instances;
	}

	public function find( string $id ): ?SmsGateway {
		$all = $this->all();

		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	public function labels(): array {
		$labels = array();

		foreach ( $this->all() as $id => $driver ) {
			$labels[ $id ] = $driver->label();
		}

		return $labels;
	}

	public function deliveryOrder(): array {
		$order = array( $this->settings->str( 'sms_gateway', 'smsir' ) );

		if ( $this->settings->bool( 'failover_enabled', true ) ) {
			$backup = $this->settings->str( 'sms_backup_gateway', '' );
			if ( '' !== $backup && ! in_array( $backup, $order, true ) ) {
				$order[] = $backup;
			}
		}

		return array_values( array_filter( (array) apply_filters( 'signa_delivery_order', $order ) ) );
	}

	public function planFor( string $id ): array {
		$driver = $this->find( $id );
		$empty  = array(
			'mode'     => 'text',
			'sender'   => '',
			'template' => '',
			'endpoint' => '',
			'issues'   => array(),
			'notes'    => array(),
		);

		if ( null === $driver ) {
			$empty['issues'][] = sprintf(
				__( 'سامانهٔ «%s» در فهرست سامانه‌ها نیست؛ از تنظیمات › سامانه‌های پیامکی یکی از سامانه‌های موجود را انتخاب کنید.', 'signa' ),
				$id
			);

			return $empty;
		}

		$plan = $driver->plan();

		if ( ! empty( $plan['endpoint'] ) && ! $this->settings->bool( 'direct_send', false ) ) {
			$host = (string) wp_parse_url( (string) $plan['endpoint'], PHP_URL_HOST );

			if ( '' !== $host && Transport::egressBlocked( $host ) ) {
				$plan['issues'][] = sprintf(
					__( 'وردپرس درخواست‌های خروجی به %s را بسته است؛ در پیشرفته › کلیدهای داده و ارسال «ارسال مستقیم» را روشن کنید یا دامنه را در WP_ACCESSIBLE_HOSTS بگذارید.', 'signa' ),
					$host
				);
			}
		}

		return $plan;
	}

	public function report(): array {
		$report  = array();
		$health  = new Health();

		foreach ( $this->all() as $id => $driver ) {
			$plan = $driver->plan();

			$report[ $id ] = array(
				'label'    => $driver->label(),
				'ready'    => $driver->ready() && array() === $plan['issues'],
				'missing'  => $driver->missing(),
				'docs'     => $driver->docsUrl(),
				'active'   => $this->settings->str( 'sms_gateway' ) === $id,
				'backup'   => $this->settings->str( 'sms_backup_gateway' ) === $id,
				'mode'     => $plan['mode'],
				'sender'   => $plan['sender'],
				'template' => $plan['template'],
				'issues'   => $plan['issues'],
				'notes'    => $plan['notes'],
				'health'   => $health->get( $id ),
				'health_text' => $health->describe( $id ),
				'resting'  => $health->resting( $id ),
				'blocked_until' => $health->blockedUntil( $id ),
			);
		}

		return $report;
	}
}
