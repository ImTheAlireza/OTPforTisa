<?php
/**
 * Shortcodes exposing the form anywhere in content.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Front;

use TisaOtp\Bootable;
use TisaOtp\Config\Settings;

defined( 'ABSPATH' ) || exit;

final class Shortcodes implements Bootable {

	/** @var FormRenderer */
	private $renderer;

	/** @var Settings */
	private $settings;

	public function __construct( FormRenderer $renderer, Settings $settings ) {
		$this->renderer = $renderer;
		$this->settings = $settings;
	}

	public function boot(): void {
		add_shortcode( 'tisa_otp_form', array( $this, 'form' ) );
		add_shortcode( 'tisa_otp', array( $this, 'form' ) );
		add_shortcode( 'tisa_otp_hint', array( $this, 'hint' ) );
	}

	/**
	 * @param array<string,mixed> $atts
	 */
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
			'tisa_otp_form'
		);

		return $this->renderer->render( $atts );
	}

	/**
	 * Small helper shortcode for pages that already show their own heading.
	 */
	public function hint(): string {
		if ( ! $this->settings->bool( 'enabled', true ) ) {
			return '';
		}

		return sprintf(
			'<p class="tisa-otp-hint">%s</p>',
			esc_html( $this->settings->str( 'form_subheading' ) )
		);
	}
}
