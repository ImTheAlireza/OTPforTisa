<?php
/**
 * Template renderer with theme-level overrides.
 *
 * Lookup order: {stylesheet}/tisa-otp/{file} → {template}/tisa-otp/{file} → plugin /templates/{file}.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Support;

defined( 'ABSPATH' ) || exit;

final class View {

	/** @var string */
	private $base;

	public function __construct() {
		$this->base = TISA_OTP_PATH . 'templates/';
	}

	public function locate( string $template ): string {
		$fromTheme = locate_template( array( 'tisa-otp/' . $template ) );

		if ( is_string( $fromTheme ) && '' !== $fromTheme && is_readable( $fromTheme ) ) {
			return $fromTheme;
		}

		$local = $this->base . $template;

		return is_readable( $local ) ? $local : '';
	}

	public function render( string $template, array $data = array() ): string {
		$file = $this->locate( $template );

		if ( '' === $file ) {
			return '';
		}

		/**
		 * Filter the data handed to a template before it is rendered.
		 *
		 * @param array  $data     Template variables.
		 * @param string $template Relative template name.
		 */
		$data = (array) apply_filters( 'tisa_otp_template_data', $data, $template );

		// Templates render nested partials through this handle.
		$data['view'] = $this;

		ob_start();

		// Templates escape their own output; variables are intentionally in scope.
		extract( $data, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		include $file;

		$html = (string) ob_get_clean();

		/**
		 * Filter rendered template markup.
		 *
		 * @param string $html     Markup.
		 * @param string $template Relative template name.
		 * @param array  $data     Template variables.
		 */
		return (string) apply_filters( 'tisa_otp_template_html', $html, $template, $data );
	}

	public function partial( string $name, array $data = array() ): string {
		return $this->render( 'partials/' . $name . '.php', $data );
	}
}
