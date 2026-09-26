<?php

namespace Signa\Front;

use Signa\Bootable;
use Signa\Config\Settings;

defined( 'ABSPATH' ) || exit;

final class Shortcodes implements Bootable {
	private $renderer;
	private $settings;

	public function __construct( FormRenderer $renderer, Settings $settings ) {
		$this->renderer = $renderer;
		$this->settings = $settings;
	}

	public function boot(): void {
		add_shortcode( 'signa_form', array( $this, 'form' ) );
		add_shortcode( 'signa', array( $this, 'form' ) );
		add_shortcode( 'signa_hint', array( $this, 'hint' ) );
	}

	public function form( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'title'        => '',
				'description'  => '',
				'redirect'     => '',
				'skin'         => '',
				'accent'       => '',
				'width'        => '',
				'radius'       => '',
				'align'        => '',
				'code_input'   => '',
				'show_brand'   => '',
				'logo'         => '',
				'custom_class' => '',
			),
			is_array( $atts ) ? $atts : array(),
			'signa_form'
		);

		return $this->renderer->render( $atts );
	}

	public function hint(): string {
		if ( ! $this->settings->bool( 'enabled', true ) ) {
			return '';
		}

		return sprintf(
			'<p class="signa-hint">%s</p>',
			esc_html( $this->settings->str( 'form_subheading' ) )
		);
	}
}
