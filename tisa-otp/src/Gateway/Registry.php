<?php
/**
 * Gateway catalogue and delivery order.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Gateway;

use TisaOtp\Config\Settings;
use TisaOtp\Support\Transport;

defined( 'ABSPATH' ) || exit;

final class Registry {

	/** @var Settings */
	private $settings;

	/** @var array<string,SmsGateway>|null */
	private $instances;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * @return array<string,string> id => class name
	 */
	public function catalogue(): array {
		$drivers = array(
			'smsir'     => Drivers\SmsIr::class,
			'kavenegar' => Drivers\Kavenegar::class,
			'meli'      => Drivers\MeliPayamak::class,
			'ippanel'   => Drivers\Ippanel::class,
			'faraz'     => Drivers\FarazSms::class,
		);

		/**
		 * Register extra SMS gateway drivers.
		 *
		 * @param array<string,string> $drivers id => fully qualified class name.
		 */
		return (array) apply_filters( 'tisa_otp_sms_gateways', $drivers );
	}

	/**
	 * @return array<string,SmsGateway>
	 */
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

	/**
	 * @return array<string,string>
	 */
	public function labels(): array {
		$labels = array();

		foreach ( $this->all() as $id => $driver ) {
			$labels[ $id ] = $driver->label();
		}

		return $labels;
	}

	/**
	 * Ordered list of gateways to try for one delivery.
	 *
	 * @return string[]
	 */
	public function deliveryOrder(): array {
		$order = array( $this->settings->str( 'sms_gateway', 'smsir' ) );

		if ( $this->settings->bool( 'failover_enabled', true ) ) {
			$backup = $this->settings->str( 'sms_backup_gateway', '' );
			if ( '' !== $backup && ! in_array( $backup, $order, true ) ) {
				$order[] = $backup;
			}
		}

		/**
		 * Filter the gateway order for the current request.
		 *
		 * @param string[] $order Gateway ids.
		 */
		return array_values( array_filter( (array) apply_filters( 'tisa_otp_delivery_order', $order ) ) );
	}

	/**
	 * How one gateway would send right now, without sending anything.
	 *
	 * @return array{mode:string,sender:string,template:string,endpoint:string,issues:string[],notes:string[]}
	 */
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
				/* translators: %s: gateway id read from the settings */
				__( 'سامانهٔ «%s» در فهرست سامانه‌ها نیست؛ از تنظیمات › سامانه‌های پیامکی یکی از سامانه‌های موجود را انتخاب کنید.', 'tisa-otp' ),
				$id
			);

			return $empty;
		}

		$plan = $driver->plan();

		/*
		 * The site's own outbound block is a problem of this installation, not
		 * of the gateway's credentials — and it belongs in this card, which in
		 * the screenshot that started round 10 said «سامانه پیامکی انتخاب‌شده
		 * شناخته نشده است» under a failed SMS test. The card answers «راه حلش
		 * چیه»: turn the plugin's own switch on, or open wp-config.php.
		 */
		if ( ! empty( $plan['endpoint'] ) && ! $this->settings->bool( 'direct_send', false ) ) {
			$host = (string) wp_parse_url( (string) $plan['endpoint'], PHP_URL_HOST );

			if ( '' !== $host && Transport::egressBlocked( $host ) ) {
				$plan['issues'][] = sprintf(
					/* translators: %s: gateway host */
					__( 'وردپرس درخواست‌های خروجی به %s را بسته است؛ در تنظیمات › سامانه‌های پیامکی «ارسال مستقیم» را روشن کنید یا دامنه را در WP_ACCESSIBLE_HOSTS بگذارید.', 'tisa-otp' ),
					$host
				);
			}
		}

		return $plan;
	}

	/**
	 * Readiness report used by the admin screens.
	 */
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
