<?php

namespace Signa\Elementor;

use Signa\Bootable;
use Signa\Config\Settings;
use Signa\Front\FormRenderer;

defined( 'ABSPATH' ) || exit;

final class Module implements Bootable {
	const CATEGORY = 'signa';
	private $renderer;
	private $settings;

	public function __construct( FormRenderer $renderer, Settings $settings ) {
		$this->renderer = $renderer;
		$this->settings = $settings;
	}

	public function boot(): void {
		add_action( 'elementor/elements/categories_registered', array( $this, 'category' ) );
		add_action( 'elementor/widgets/register', array( $this, 'widgets' ) );

		add_action( 'elementor/widgets/widgets_registered', array( $this, 'legacyWidgets' ) );
	}

	public function category( $manager ): void {
		if ( ! is_object( $manager ) || ! method_exists( $manager, 'add_category' ) ) {
			return;
		}

		$manager->add_category(
			self::CATEGORY,
			array(
				'title' => __( 'سیگنا', 'signa' ),
				'icon'  => 'eicon-lock-user',
			)
		);
	}

	public function widgets( $manager ): void {
		if ( ! $this->usable() || ! is_object( $manager ) || ! method_exists( $manager, 'register' ) ) {
			return;
		}

		$manager->register( new FormWidget( $this->renderer, $this->settings ) );
	}

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
