<?php
/**
 * WoodMart: the OTP form inside the theme's login sidebar.
 *
 * WoodMart prints the header's sign-in panel on its own footer hook —
 * `woodmart_before_wp_footer` at priority 200 in 7.x/8.x, `wp_footer` at 160 in
 * 6.x — and the panel contains a plain WooCommerce username/password form
 * (the theme's `woodmart_login_form` helper), all printed straight to the
 * page. The theme gives
 * no filter for that markup, and its templates live in the theme, where an
 * update would erase anything we changed.
 *
 * So this integration does not touch a single theme file. It wraps the theme's
 * own callback with output buffering — opened one priority before it runs and
 * closed one after — and, if the captured markup really is the login sidebar,
 * swaps the WooCommerce form inside it for the plugin's OTP form. Everything
 * else in that panel (heading, close button, notices, the theme's markup) is
 * echoed back byte for byte.
 *
 * The priority is *discovered* from `$wp_filter` rather than hard-coded, so a
 * WoodMart release that moves the callback moves this integration with it. If
 * the markup is never found — a changed theme, the sidebar disabled, a child
 * theme that prints its own — nothing is captured longer than one call, nothing
 * is injected, and the site behaves exactly as it did before.
 *
 * The form itself is the same one the shortcode renders: same renderer, same
 * REST routes, same OTP flow, same captcha, same stylesheet. Nothing about it is
 * re-implemented here.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Integrations;

use TisaOtp\Bootable;
use TisaOtp\Config\Settings;
use TisaOtp\Front\Assets;
use TisaOtp\Front\FormRenderer;

defined( 'ABSPATH' ) || exit;

final class WoodMart implements Bootable {

	/** The theme's callback, as WoodMart registers it. */
	const CALLBACK = 'woodmart_sidebar_login_form';

	/** Where WoodMart has printed that callback across its versions. */
	const HOOKS = array( 'woodmart_before_wp_footer', 'wp_footer' );

	/** The fallback window per hook, used when discovery finds nothing. */
	const FALLBACK = array(
		'woodmart_before_wp_footer' => 200,
		'wp_footer'                 => 160,
	);

	/** A comment in the output, so anyone reading the page can see where it came from. */
	const MARKER = 'tisa-otp: woodmart login sidebar';

	/** @var Settings */
	private $settings;

	/** @var FormRenderer */
	private $renderer;

	/** @var Assets */
	private $assets;

	/** @var bool Output buffering is open right now. */
	private $buffering = false;

	/** @var bool The sidebar has already been served this request. */
	private $injected = false;

	public function __construct( Settings $settings, FormRenderer $renderer, Assets $assets ) {
		$this->settings = $settings;
		$this->renderer = $renderer;
		$this->assets   = $assets;
	}

	/**
	 * The theme this was written for — checked by name and by function, because
	 * a child theme changes `get_template()` not at all and its functions are
	 * the ones we actually call.
	 *
	 * Static so the settings screen can ask the question without building the
	 * renderer: the answer is about the site, not about this object.
	 */
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

	/**
	 * Is the integration switched on? Off is a supported state, not a failure.
	 */
	public function active(): bool {
		return $this->available() && $this->settings->bool( 'enabled', true ) && $this->settings->bool( 'woodmart_sidebar', true );
	}

	/**
	 * `replace` removes the theme's username/password form from the panel;
	 * `append` adds the OTP form below it and leaves password login available.
	 */
	public function mode(): string {
		return 'append' === $this->settings->str( 'woodmart_mode', 'replace' ) ? 'append' : 'replace';
	}

	public function boot(): void {
		/*
		 * The theme name is read on every request regardless; a site without
		 * WoodMart gets a filter that never fires and nothing else.
		 */
		add_filter( 'woodmart_my_account_side_login_form_redirect', array( $this, 'redirect' ) );

		if ( ! $this->available() ) {
			return;
		}

		// The panel is printed in the footer, which is far too late to enqueue a
		// stylesheet — so assets are queued in the head when the panel will be
		// rendered. The sidebar is global: it exists on every page of the site.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 5 );

		// Discover the theme's priority, then wrap it.
		add_action( 'template_redirect', array( $this, 'wrap' ) );
	}

	public function enqueue(): void {
		if ( $this->willRender() ) {
			$this->assets->enqueue();
		}
	}

	/**
	 * A visitor who is not signed in, on a page that has the header at all.
	 */
	public function willRender(): bool {
		if ( ! $this->active() || is_admin() || is_user_logged_in() ) {
			return false;
		}

		return $this->sidebarConfigured();
	}

	/**
	 * Did the header builder actually choose the side login form?
	 *
	 * Mirrors the theme's own condition (`login_dropdown` and `form_display`
	 * `side`). When the header builder is absent the answer is "assume yes":
	 * the cost of being wrong is one stylesheet, and the benefit is a form that
	 * is styled when the panel does appear.
	 */
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

	/**
	 * Point the theme's own redirect at the plugin's, when one is configured.
	 *
	 * @param string $url Default redirect the theme computed.
	 * @return string
	 */
	public function redirect( $url ) {
		if ( ! $this->active() ) {
			return $url;
		}

		$configured = trim( $this->settings->str( 'login_redirect' ) );

		return '' === $configured ? $url : $configured;
	}

	/**
	 * Register the buffer around whichever hook the theme is really using.
	 */
	public function wrap(): void {
		if ( ! $this->willRender() ) {
			return;
		}

		$targets = $this->locate();

		if ( empty( $targets ) ) {
			$targets = self::FALLBACK;
		}

		foreach ( $targets as $hook => $priority ) {
			$priority = max( 0, (int) $priority );

			add_action( $hook, array( $this, 'open' ), max( 0, $priority - 1 ), 0 );
			add_action( $hook, array( $this, 'close' ), $priority + 1, 0 );
		}
	}

	/**
	 * At which priority, on which hook, does WoodMart print the sidebar?
	 *
	 * Read from the hook registry, so a theme update that moves the callback is
	 * followed automatically instead of breaking the integration.
	 *
	 * @return array<string,int>
	 */
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
						$found[ $hook ] = (int) $priority;
						break;
					}
				}
			}
		}

		return $found;
	}

	/**
	 * Start buffering. `ob_start()` can fail when output buffering is disabled
	 * on the host, so the level is verified rather than assumed.
	 */
	public function open(): void {
		if ( $this->buffering || $this->injected || ! $this->willRender() ) {
			return;
		}

		$level = ob_get_level();
		ob_start();

		$this->buffering = ob_get_level() > $level;
	}

	/**
	 * Stop buffering and hand the panel back, with our form in it.
	 */
	public function close(): void {
		if ( ! $this->buffering ) {
			return;
		}

		$this->buffering = false;
		$html            = ob_get_clean();

		if ( false === $html ) {
			return;
		}

		echo $this->swap( $html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plugin markup, escaped where it is built.
	}

	/**
	 * Swap the theme's login form for ours — inside the sidebar, and only there.
	 *
	 * Anything that is not the login sidebar is returned untouched, byte for
	 * byte: the buffer may well contain another plugin's output.
	 *
	 * @param string $html Captured output.
	 * @return string
	 */
	public function swap( string $html ): string {
		if ( $this->injected || '' === trim( $html ) || false === strpos( $html, 'login-form-side' ) ) {
			return $html;
		}

		$block = $this->block();

		if ( '' === $block ) {
			return $html;
		}

		if ( 'replace' === $this->mode() ) {
			$replaced = $this->replaceForm( $html, $block );

			if ( $replaced !== $html ) {
				$this->injected = true;

				return $replaced;
			}
		}

		/*
		 * Either the theme's form was not there (a version that renders the
		 * panel differently) or the administrator asked to keep password login.
		 * The panel's own "create an account" block is the anchor: the OTP form
		 * belongs directly above it, where the form was.
		 */
		$inserted = $this->insertBefore( $html, '<div class="create-account-question"', $block );

		if ( $inserted !== $html ) {
			$this->injected = true;

			return $inserted;
		}

		return $html;
	}

	/**
	 * The plugin's real form, with the marker that says where it came from.
	 */
	private function block(): string {
		$form = $this->renderer->render( array( 'custom_class' => 'tisa-otp--woodmart' ) );

		if ( '' === trim( $form ) ) {
			return '';
		}

		return "\n<!-- " . self::MARKER . " -->\n" . $form . "\n";
	}

	/**
	 * Replace the theme's `woocommerce-form-login` with the OTP form.
	 *
	 * Written as a scanner rather than a regex because the panel markup is not
	 * something to guess at: the opening tag is inspected, the matching closing
	 * tag is found by counting, and a panel with no such form is returned as it
	 * came in.
	 *
	 * @param string $html  Captured output.
	 * @param string $block OTP form markup.
	 * @return string
	 */
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

			$end = $this->matchingClose( $html, $tagEnd + 1 );

			if ( $end < 0 ) {
				return $html;
			}

			return substr( $html, 0, $start ) . $block . substr( $html, $end + 7 );
		}

		return $html;
	}

	/**
	 * The offset of the `</form>` that closes the form opened before `$from`.
	 *
	 * @param string $html Captured output.
	 * @param int    $from Offset just past the opening tag.
	 * @return int Negative when there is no closing tag.
	 */
	private function matchingClose( string $html, int $from ): int {
		$depth = 1;
		$at    = $from;

		while ( $depth > 0 ) {
			$open  = strpos( $html, '<form', $at );
			$close = strpos( $html, '</form>', $at );

			if ( false === $close ) {
				return -1;
			}

			if ( false !== $open && $open < $close ) {
				$depth++;
				$at = $open + 5;
				continue;
			}

			$depth--;
			$at = $close + 7;

			if ( 0 === $depth ) {
				return $close;
			}
		}

		return -1;
	}

	/**
	 * @param string $html   Captured output.
	 * @param string $needle Anchor to insert before.
	 * @param string $block  OTP form markup.
	 * @return string
	 */
	private function insertBefore( string $html, string $needle, string $block ): string {
		$at = strpos( $html, $needle );

		if ( false === $at ) {
			return $html;
		}

		return substr( $html, 0, $at ) . $block . substr( $html, $at );
	}
}
