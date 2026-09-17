<?php
/**
 * Elementor registration: one category, one widget.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Elementor;

use TisaOtp\Bootable;
use TisaOtp\Config\Settings;
use TisaOtp\Front\FormRenderer;

defined( 'ABSPATH' ) || exit;

final class Module implements Bootable {

	const CATEGORY = 'tisa-otp';

	/** @var FormRenderer */
	private $renderer;

	/** @var Settings */
	private $settings;

	public function __construct( FormRenderer $renderer, Settings $settings ) {
		$this->renderer = $renderer;
		$this->settings = $settings;
	}

	public function boot(): void {
		add_action( 'elementor/elements/categories_registered', array( $this, 'category' ) );
		add_action( 'elementor/widgets/register', array( $this, 'widgets' ) );

		// Elementor 3.4 and older.
		add_action( 'elementor/widgets/widgets_registered', array( $this, 'legacyWidgets' ) );
	}

	/**
	 * @param \Elementor\Elements_Manager $manager
	 */
	public function category( $manager ): void {
		if ( ! is_object( $manager ) || ! method_exists( $manager, 'add_category' ) ) {
			return;
		}

		$manager->add_category(
			self::CATEGORY,
			array(
				'title' => __( 'تیسا OTP', 'tisa-otp' ),
				'icon'  => 'eicon-lock-user',
			)
		);
	}

	/**
	 * @param \Elementor\Widgets_Manager $manager
	 */
	public function widgets( $manager ): void {
		if ( ! $this->usable() || ! is_object( $manager ) || ! method_exists( $manager, 'register' ) ) {
			return;
		}

		$manager->register( new FormWidget( $this->renderer, $this->settings ) );
	}

	/**
	 * @param \Elementor\Widgets_Manager $manager
	 */
	public function legacyWidgets( $manager ): void {
		if ( ! $this->usable() || ! is_object( $manager ) || ! method_exists( $manager, 'register_widget_type' ) ) {
			return;
		}

		$manager->register_widget_type( new FormWidget( $this->renderer, $this->settings ) );
	}

	private function usable(): bool {
		return class_exists( '\Elementor\Widget_Base' ) && $this->settings->bool( 'enabled', true );
	}
}
