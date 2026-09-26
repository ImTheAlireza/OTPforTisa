<?php

namespace Signa\Admin;

use Signa\Bootable;
use Signa\Config\Sanitizer;
use Signa\Config\Settings;
use Signa\Front\Assets;
use Signa\Front\FormRenderer;

defined( 'ABSPATH' ) || exit;

final class FormPreview implements Bootable {
	const ACTION = 'signa_form_preview';
	const STEPS = array( 'phone', 'code', 'fields' );
	private $settings;
	private $renderer;
	private $assets;

	public function __construct( Settings $settings, FormRenderer $renderer, Assets $assets ) {
		$this->settings = $settings;
		$this->renderer = $renderer;
		$this->assets   = $assets;
	}

	public function boot(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'signa' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION );

		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=utf-8' );
			header( 'X-Robots-Tag: noindex' );
			header( 'Cache-Control: no-store' );
		}

		echo $this->page( $this->draft(), $this->step() );
		exit;
	}

	private function draft(): array {
		if ( ! isset( $_POST[ Settings::OPTION ] ) || ! is_array( $_POST[ Settings::OPTION ] ) ) {
			return array();
		}

		return Sanitizer::sanitize( (array) wp_unslash( $_POST[ Settings::OPTION ] ), $this->settings->all() );
	}

	private function step(): string {
		$step = isset( $_REQUEST['step'] ) ? sanitize_key( wp_unslash( $_REQUEST['step'] ) ) : 'phone';

		return in_array( $step, self::STEPS, true ) ? $step : 'phone';
	}

	public function page( array $draft, string $step ): string {
		if ( array() !== $draft ) {
			$this->settings->preview( $draft );
		}

		$form = $this->renderer->preview();
		$dir  = is_rtl() ? 'rtl' : 'ltr';

		return '<!doctype html><html lang="fa" dir="' . esc_attr( $dir ) . '"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<meta name="robots" content="noindex">'
			. '<title>' . esc_html__( 'پیش‌نمایش فرم', 'signa' ) . '</title>'
			. $this->assets->previewStyles()
			. '<style>' . self::frameCss() . '</style>'
			. '</head><body class="signa-preview-body" data-step="' . esc_attr( $step ) . '">'
			. '<main class="signa-preview-stage">' . $form . '</main>'
			. '<script>' . self::stepScript() . '</script>'
			. '</body></html>';
	}

	private static function frameCss(): string {
		return 'html,body{margin:0;background:#f4f6f8;min-height:100%}'
			. '.signa-preview-stage{display:flex;align-items:flex-start;justify-content:center;padding:20px 14px;box-sizing:border-box}'
			. '.signa-preview-stage>.signa{width:100%;max-width:400px}'
			. 'a,button,input,select,textarea{pointer-events:none}'
			. '*{animation:none!important;transition:none!important}';
	}

	private static function stepScript(): string {
		return '(function(){var s=document.body.getAttribute("data-step"),order=["phone","code","fields"],i=order.indexOf(s);'
			. 'document.querySelectorAll("[data-signa-step]").forEach(function(el){el.classList.toggle("is-current",el.getAttribute("data-signa-step")===s);});'
			. 'document.querySelectorAll("[data-signa-step-marker]").forEach(function(el){var p=order.indexOf(el.getAttribute("data-signa-step-marker"));el.classList.toggle("is-current",p===i);el.classList.toggle("is-done",p>-1&&p<i);});'
			. 'var chip=document.querySelector("[data-signa-phone-chip]");if(chip&&s!=="phone"){chip.hidden=false;var t=chip.querySelector("[data-signa-phone-chip-value]");if(t){t.textContent="0912•••4567";}}'
			. 'document.addEventListener("submit",function(e){e.preventDefault();},true);'
			. 'document.addEventListener("click",function(e){e.preventDefault();},true);'
			. '})();';
	}
}
