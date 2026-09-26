<?php

namespace Signa\Support;

defined( 'ABSPATH' ) || exit;

final class View {
	private $base;

	public function __construct() {
		$this->base = SIGNA_PATH . 'templates/';
	}

	public function locate( string $template ): string {
		$fromTheme = locate_template( array( 'signa/' . $template ) );

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

		$data = (array) apply_filters( 'signa_template_data', $data, $template );

		$data['view'] = $this;

		ob_start();

		extract( $data, EXTR_SKIP );
		include $file;

		$html = (string) ob_get_clean();

		return (string) apply_filters( 'signa_template_html', $html, $template, $data );
	}

	public function partial( string $name, array $data = array() ): string {
		return $this->render( 'partials/' . $name . '.php', $data );
	}
}
