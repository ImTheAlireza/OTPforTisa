<?php

namespace Signa\Integrations;

use Signa\Bootable;
use Signa\Config\Settings;
use Signa\Front\Assets;
use Signa\Front\FormRenderer;

defined( 'ABSPATH' ) || exit;

final class WoodMart implements Bootable {
	const CALLBACK = 'woodmart_sidebar_login_form';
	const HOOKS = array( 'woodmart_before_wp_footer', 'wp_footer' );
	const FALLBACK = array(
		'woodmart_before_wp_footer' => array( 160, 200 ),
		'wp_footer'                 => array( 160, 200 ),
	);

	const MARKER = 'signa: woodmart login sidebar';
	const ACCOUNT_BLOCK = '<div class="create-account-question"';
	private $settings;
	private $renderer;
	private $assets;
	private $buffers = array();
	private $injected = false;

	public function __construct( Settings $settings, FormRenderer $renderer, Assets $assets ) {
		$this->settings = $settings;
		$this->renderer = $renderer;
		$this->assets   = $assets;
	}

	public static function detected(): bool {
		$theme = function_exists( 'wp_get_theme' ) ? wp_get_theme() : null;
		$name  = is_object( $theme ) && method_exists( $theme, 'get_template' ) ? (string) $theme->get_template() : '';

		if ( '' === $name && function_exists( 'get_template' ) ) {
			$name = (string) get_template();
		}

		$is_woodmart = 'woodmart' === strtolower( $name )
			|| (string) get_option( 'template' ) === 'woodmart'
			|| function_exists( 'woodmart_woocommerce_installed' )
			|| function_exists( 'whb_get_settings' )
			|| defined( 'WOODMART_THEMERROOT' );

		return $is_woodmart && class_exists( 'WooCommerce' );
	}

	public function available(): bool {
		return self::detected();
	}

	public function active(): bool {
		return $this->available() && $this->settings->bool( 'enabled', true ) && $this->settings->bool( 'woodmart_sidebar', true );
	}

	public function mode(): string {
		return 'append' === $this->settings->str( 'woodmart_mode', 'replace' ) ? 'append' : 'replace';
	}

	public static function hidesAccountBlock( Settings $settings ): bool {
		if ( ! $settings->bool( 'woodmart_account_block', true ) ) {
			return false;
		}

		if ( ! $settings->bool( 'registration_enabled', true ) ) {
			return false;
		}

		return 'login_only' !== $settings->str( 'auth_mode', 'smart' );
	}

	public function removesAccountBlock(): bool {
		return self::hidesAccountBlock( $this->settings );
	}

	public function boot(): void {
		add_filter( 'woodmart_my_account_side_login_form_redirect', array( $this, 'redirect' ) );

		if ( ! $this->available() ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 5 );

		add_action( 'template_redirect', array( $this, 'wrap' ) );
	}

	public function enqueue(): void {
		if ( $this->willRender() ) {
			$this->assets->enqueue();
		}
	}

	public function willRender(): bool {
		if ( ! $this->active() || is_admin() || is_user_logged_in() ) {
			return false;
		}

		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return false;
		}

		return $this->sidebarConfigured();
	}

	private function sidebarConfigured(): bool {
		if ( ! function_exists( 'whb_get_settings' ) ) {
			return true;
		}

		$settings = whb_get_settings();

		if ( ! is_array( $settings ) || empty( $settings['account'] ) || ! is_array( $settings['account'] ) ) {
			return true;
		}

		$account = $settings['account'];

		if ( empty( $account['login_dropdown'] ) || empty( $account['form_display'] ) ) {
			return true;
		}

		return 'side' === $account['form_display'];
	}

	public function redirect( $url ) {
		if ( ! $this->active() ) {
			return $url;
		}

		$configured = trim( $this->settings->str( 'login_redirect' ) );

		return '' === $configured ? $url : $configured;
	}

	public function wrap(): void {
		if ( ! $this->willRender() ) {
			return;
		}

		$targets = $this->locate();

		if ( empty( $targets ) ) {
			$targets = self::FALLBACK;
		}

		foreach ( $targets as $hook => $priorities ) {
			foreach ( (array) $priorities as $priority ) {
				$priority = max( 0, (int) $priority );

				add_action( $hook, array( $this, 'open' ), max( 0, $priority - 1 ), 0 );
				add_action( $hook, array( $this, 'close' ), $priority + 1, 0 );
			}
		}
	}

	private function locate(): array {
		$found = array();

		foreach ( self::HOOKS as $hook ) {
			if ( empty( $GLOBALS['wp_filter'][ $hook ] ) ) {
				continue;
			}

			$registry = $GLOBALS['wp_filter'][ $hook ];

			if ( ! is_object( $registry ) || empty( $registry->callbacks ) || ! is_array( $registry->callbacks ) ) {
				continue;
			}

			foreach ( $registry->callbacks as $priority => $callbacks ) {
				foreach ( (array) $callbacks as $callback ) {
					if ( isset( $callback['function'] ) && self::CALLBACK === $callback['function'] ) {
						$found[ $hook ][] = (int) $priority;
						break;
					}
				}
			}
		}

		return $found;
	}

	public function open(): void {
		if ( $this->injected || ! $this->willRender() ) {
			return;
		}

		if ( ! empty( $this->buffers ) ) {
			return;
		}

		$level = ob_get_level();
		ob_start();

		if ( ob_get_level() > $level ) {
			$this->buffers[] = $level;
		}
	}

	public function close(): void {
		if ( empty( $this->buffers ) ) {
			return;
		}

		$level = (int) array_pop( $this->buffers );

		if ( ob_get_level() !== $level + 1 ) {
			return;
		}

		$html = ob_get_clean();

		if ( false === $html ) {
			return;
		}

		echo $this->swap( $html );
	}

	public function swap( string $html ): string {
		if ( $this->injected || '' === trim( $html ) || false === strpos( $html, 'login-form-side' ) ) {
			return $html;
		}

		$block = $this->block();

		if ( '' === $block ) {
			return $html;
		}

		$placed = $this->withForm( $html, $block );

		if ( $placed === $html ) {
			return $html;
		}

		$this->injected = true;

		return $this->removeAccountBlock( $placed );
	}

	private function withForm( string $html, string $block ): string {
		if ( 'replace' === $this->mode() ) {
			$replaced = $this->replaceForm( $html, $block );

			if ( $replaced !== $html ) {
				return $replaced;
			}
		}

		return $this->insertBefore( $html, '<div class="create-account-question"', $block );
	}

	private function removeAccountBlock( string $html ): string {
		if ( ! $this->removesAccountBlock() ) {
			return $html;
		}

		$at = strpos( $html, self::ACCOUNT_BLOCK );

		if ( false === $at ) {
			return $html;
		}

		$tagEnd = strpos( $html, '>', $at );

		if ( false === $tagEnd ) {
			return $html;
		}

		$close = $this->matchingClose( $html, $tagEnd + 1, 'div' );

		if ( $close < 0 ) {
			return $html;
		}

		$start = $at;

		while ( $start > 0 && false !== strpos( "\n\r\t ", $html[ $start - 1 ] ) ) {
			$start--;
		}

		return substr( $html, 0, $start ) . substr( $html, $close + 6 );
	}

	private function block(): string {
		$form = $this->renderer->render( array( 'custom_class' => 'signa--woodmart' ) );

		if ( '' === trim( $form ) ) {
			return '';
		}

		return "\n<!-- " . self::MARKER . " -->\n" . $form . "\n";
	}

	private function replaceForm( string $html, string $block ): string {
		$offset = 0;

		while ( false !== ( $start = strpos( $html, '<form', $offset ) ) ) {
			$tagEnd = strpos( $html, '>', $start );

			if ( false === $tagEnd ) {
				return $html;
			}

			$opening = substr( $html, $start, $tagEnd - $start + 1 );
			$offset  = $start + 5;

			if ( false === strpos( $opening, 'woocommerce-form-login' ) ) {
				continue;
			}

			$end = $this->matchingClose( $html, $tagEnd + 1, 'form' );

			if ( $end < 0 ) {
				return $html;
			}

			return substr( $html, 0, $start ) . $block . substr( $html, $end + 7 );
		}

		return $html;
	}

	private function matchingClose( string $html, int $from, string $tag ): int {
		$depth = 1;
		$at    = $from;
		$open  = '<' . $tag;
		$shut  = '</' . $tag . '>';

		while ( $depth > 0 ) {
			$next  = strpos( $html, $open, $at );
			$close = strpos( $html, $shut, $at );

			if ( false === $close ) {
				return -1;
			}

			if ( false !== $next && $next < $close ) {
				$depth++;
				$at = $next + strlen( $open );
				continue;
			}

			$depth--;
			$at = $close + strlen( $shut );

			if ( 0 === $depth ) {
				return $close;
			}
		}

		return -1;
	}

	private function insertBefore( string $html, string $needle, string $block ): string {
		$at = strpos( $html, $needle );

		if ( false === $at ) {
			return $html;
		}

		return substr( $html, 0, $at ) . $block . substr( $html, $at );
	}
}
