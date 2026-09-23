<?php
/**
 * Builds the template data for the sign-in form.
 *
 * Shortcode attributes win over global settings, but an empty attribute means
 * "inherit", never "override with nothing".
 *
 * @package TisaOtp
 */

namespace TisaOtp\Front;

use TisaOtp\Captcha\Manager;
use TisaOtp\Config\Sanitizer;
use TisaOtp\Config\Settings;
use TisaOtp\Registration\FieldSchema;
use TisaOtp\Support\View;

defined( 'ABSPATH' ) || exit;

final class FormRenderer {

	/**
	 * The font the form was designed in, shipped with the plugin.
	 *
	 * Vazirmatn (SIL OFL, see assets/fonts/OFL.txt) is a Persian-first family,
	 * so a form inside a theme with no Persian glyphs stops falling back to
	 * whatever the operating system has lying around. The names after it are
	 * only there for the case where a site already loads its own copy.
	 */
	const FONT_STACK = "'Vazirmatn','Vazir','IRANSans','Iranian Sans',Tahoma,sans-serif";

	/** @var Settings */
	private $settings;

	/** @var FieldSchema */
	private $schema;

	/** @var Manager */
	private $captcha;

	/** @var View */
	private $view;

	/** @var Assets */
	private $assets;

	/** @var int */
	private static $sequence = 0;

	public function __construct( Settings $settings, FieldSchema $schema, Manager $captcha, View $view, Assets $assets ) {
		$this->settings = $settings;
		$this->schema   = $schema;
		$this->captcha  = $captcha;
		$this->view     = $view;
		$this->assets   = $assets;
	}

	/**
	 * @param array<string,mixed> $args Shortcode / widget attributes.
	 */
	public function render( array $args = array() ): string {
		if ( ! $this->settings->bool( 'enabled', true ) ) {
			return '';
		}

		if ( is_user_logged_in() ) {
			return $this->signedInNotice();
		}

		$this->assets->enqueue();

		self::$sequence++;

		$data = $this->templateData( $args );

		return $this->view->render( 'form.php', $data );
	}

	/**
	 * Payload used by `GET /form-config` for lazily mounted forms.
	 */
	public function clientConfig(): array {
		return array_merge(
			$this->assets->clientConfig(),
			array(
				'fields'   => $this->schema->forClient(),
				'headings' => $this->headings(),
				'flow'     => $this->schema->flow(),
				'register' => $this->schema->enabled(),
			)
		);
	}

	/**
	 * @param array<string,mixed> $args
	 */
	private function templateData( array $args ): array {
		$args = $this->normalizeArgs( $args );

		$skin = $this->pick( $args, 'skin', $this->settings->str( 'skin', 'line' ), array( 'line', 'card', 'glass', 'slate', 'pill' ) );

		$classes = array( 'tisa-otp', 'tisa-skin-' . $skin );
		$classes[] = 'tisa-align-' . $this->pick( $args, 'align', $this->settings->str( 'align', 'center' ), array( 'center', 'start', 'end' ) );
		$classes[] = 'tisa-code-' . $this->pick( $args, 'code_input', $this->settings->str( 'code_input', 'boxes' ), array( 'boxes', 'single' ) );

		if ( ! empty( $args['custom_class'] ) ) {
			$classes[] = sanitize_html_class( (string) $args['custom_class'] );
		}

		$showBrand = $this->flag( $args, 'show_brand', $this->settings->bool( 'show_brand', true ) );
		$logo      = (string) $this->pick( $args, 'logo', $this->settings->str( 'brand_logo' ), array() );

		return array(
			'instance'     => 'tisa-form-' . self::$sequence . '-' . wp_unique_id(),
			'classes'      => implode( ' ', array_map( 'sanitize_html_class', $classes ) ),
			'skin'         => $skin,
			'style'        => $this->inlineStyle( $args ),
			'heading'      => (string) $this->pick( $args, 'title', $this->settings->str( 'form_heading' ), array() ),
			'hint'         => (string) $this->pick( $args, 'description', $this->settings->str( 'form_subheading' ), array() ),
			'regHeading'   => $this->settings->str( 'register_heading' ),
			'regHint'      => $this->settings->str( 'register_subheading' ),
			'redirect'     => $this->resolveRedirect( $args ),
			'phoneLabel'   => __( 'شماره موبایل', 'tisa-otp' ),
			// The chip already shows 09; the placeholder shows what is left to type.
			'phonePlaceholder' => __( '09121234567', 'tisa-otp' ),
			'trust'        => $this->trust(),
			'codeLabel'    => __( 'کد تأیید', 'tisa-otp' ),
			'sendLabel'    => $this->settings->str( 'label_send', __( 'دریافت کد تأیید', 'tisa-otp' ) ),
			'verifyLabel'  => $this->settings->str( 'label_verify', __( 'ورود به حساب', 'tisa-otp' ) ),
			'resendLabel'  => $this->settings->str( 'label_resend', __( 'ارسال دوباره کد', 'tisa-otp' ) ),
			'editLabel'    => $this->settings->str( 'label_edit_phone', __( 'ویرایش شماره', 'tisa-otp' ) ),
			'continueLabel'=> __( 'ادامه', 'tisa-otp' ),
			'codeLength'   => max( 4, min( 8, $this->settings->int( 'code_length', 5 ) ) ),
			'cooldown'     => $this->settings->int( 'resend_delay', 60 ),
			'showBrand'    => $showBrand,
			'logo'         => '' !== $logo ? esc_url( $logo ) : '',
			'logoWidth'    => max( 32, min( 320, $this->settings->int( 'brand_width', 110 ) ) ),
			'fields'       => $this->schema->forClient(),
			'flow'         => $this->schema->flow(),
			'registration' => $this->schema->enabled(),
			'steps'        => $this->steps(),
			'captcha'      => $this->captcha->clientBundle(),
			'terms'        => $this->terms(),
			'dir'          => is_rtl() ? 'rtl' : 'ltr',
			'configUrl'    => esc_url_raw( rest_url( 'tisa-otp/v1/form-config' ) ),
			'cacheMode'    => $this->settings->str( 'cache_mode', 'auto' ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'restUrl'      => esc_url_raw( rest_url( 'tisa-otp/v1/' ) ),
			'honeypot'     => \TisaOtp\Guard\BotGuard::HONEYPOT,
			'timestampKey' => \TisaOtp\Guard\BotGuard::TIMESTAMP,
			'formToken'    => \TisaOtp\Support\FormToken::issue(),
			'renderedAt'   => time(),
		);
	}

	/**
	 * Steps shown in the progress bar, in the order the visitor meets them.
	 *
	 * Registration off means two steps, which is not worth a progress bar, so
	 * the template hides it; the order follows the configured flow.
	 *
	 * @return array<int,array{id:string,label:string}>
	 */
	/**
	 * The three quiet claims under the send button.
	 *
	 * Icons are inline SVG on purpose: this row has to work on a page with no
	 * icon font, no external request and no theme stylesheet.
	 *
	 * @return array<int,array{icon:string,label:string}>
	 */
	private function trust(): array {
		$items = array(
			array(
				'icon'  => '<path d="M10 2.5 4 5v5c0 3.2 2.5 6.1 6 7.5 3.5-1.4 6-4.3 6-7.5V5l-6-2.5Z" stroke-linejoin="round"></path><path d="m7.5 9.8 1.8 1.8 3.4-3.6" stroke-linecap="round" stroke-linejoin="round"></path>',
				'label' => __( 'بدون رمز عبور', 'tisa-otp' ),
			),
			array(
				'icon'  => '<circle cx="10" cy="10" r="7.5"></circle><path d="M10 5.8V10l2.8 1.7" stroke-linecap="round" stroke-linejoin="round"></path>',
				'label' => __( 'ورود در چند ثانیه', 'tisa-otp' ),
			),
			array(
				'icon'  => '<rect x="4.5" y="4.5" width="11" height="11" rx="2.5"></rect><path d="M8.5 10h3" stroke-linecap="round"></path>',
				'label' => __( 'شماره شما محفوظ می‌ماند', 'tisa-otp' ),
			),
		);

		/**
		 * Filter the reassurance row under the send button. Return an empty array
		 * to hide it.
		 *
		 * @param array<int,array{icon:string,label:string}> $items Icon path plus label.
		 */
		return (array) apply_filters( 'tisa_otp_form_trust', $items );
	}

	private function steps(): array {
		$phone = array(
			'id'    => 'phone',
			'label' => __( 'شماره', 'tisa-otp' ),
		);
		$code  = array(
			'id'    => 'code',
			'label' => __( 'کد', 'tisa-otp' ),
		);

		if ( ! $this->schema->enabled() ) {
			return array( $phone, $code );
		}

		$fields = array(
			'id'    => 'fields',
			'label' => __( 'اطلاعات', 'tisa-otp' ),
		);

		$steps = 'code_then_fields' === $this->schema->flow()
			? array( $phone, $code, $fields )
			: array( $phone, $fields, $code );

		/**
		 * Filter the steps shown in the progress bar.
		 *
		 * @param array $steps Ordered list of `id`/`label` pairs.
		 */
		return (array) apply_filters( 'tisa_otp_form_steps', $steps );
	}

	/**
	 * @param array<string,mixed> $args
	 */
	private function normalizeArgs( array $args ): array {
		$clean = array();

		foreach ( $args as $key => $value ) {
			if ( null === $value || ( is_string( $value ) && '' === trim( $value ) ) ) {
				continue;
			}

			$clean[ str_replace( '-', '_', (string) $key ) ] = $value;
		}

		return $clean;
	}

	/**
	 * @param array<string,mixed> $args
	 * @param string[] $allowed Empty array disables the whitelist.
	 * @return mixed
	 */
	private function pick( array $args, string $key, $fallback, array $allowed ) {
		$value = array_key_exists( $key, $args ) ? $args[ $key ] : $fallback;

		if ( array() !== $allowed && ! in_array( $value, $allowed, true ) ) {
			return $fallback;
		}

		return $value;
	}

	/**
	 * @param array<string,mixed> $args
	 */
	private function flag( array $args, string $key, bool $fallback ): bool {
		if ( ! array_key_exists( $key, $args ) ) {
			return $fallback;
		}

		return in_array( (string) $args[ $key ], array( '1', 'true', 'yes' ), true );
	}

	/**
	 * @param array<string,mixed> $args
	 */
	private function inlineStyle( array $args ): string {
		$accent = sanitize_hex_color( (string) $this->pick( $args, 'accent', $this->settings->str( 'accent', '#0f766e' ), array() ) );
		$width  = (int) $this->pick( $args, 'width', (string) $this->settings->int( 'width', 420 ), array() );
		$radius = (int) $this->pick( $args, 'radius', (string) $this->settings->int( 'radius', 14 ), array() );

		$parts = array();

		if ( $accent ) {
			$parts[] = '--tisa-accent:' . $accent;
		}

		/*
		 * The surface and the font are set here rather than on `:root`, because a
		 * variable declared on `.tisa-otp` in the stylesheet beats one inherited
		 * from `:root` — the settings used to be emitted per request and silently
		 * lose to the stylesheet's own defaults.
		 */
		$surface = sanitize_hex_color( (string) $this->pick( $args, 'surface', $this->settings->str( 'surface', '#ffffff' ), array() ) );

		if ( $surface ) {
			$parts[] = '--tisa-surface:' . $surface;
		}

		$font = $this->fontFamily();

		if ( '' !== $font ) {
			$parts[] = '--tisa-font:' . $font;
		}
		if ( $width >= 280 && $width <= 900 ) {
			$parts[] = '--tisa-width:' . $width . 'px';
		}
		if ( $radius >= 0 && $radius <= 40 ) {
			$parts[] = '--tisa-radius:' . $radius . 'px';
		}

		return implode( ';', $parts );
	}

	/**
	 * The font stack the form prints with.
	 *
	 * `theme` keeps the old behaviour (`inherit`), which is also the honest name
	 * for it: the form then looks like whatever the site's theme uses, Persian
	 * glyphs or not. The default is the font shipped in `assets/fonts`, which is
	 * the same one the packaged preview renders with.
	 */
	private function fontFamily(): string {
		$choice = $this->settings->str( 'form_font', 'vazirmatn' );

		if ( 'theme' === $choice ) {
			return 'inherit';
		}

		if ( 'custom' === $choice ) {
			$custom = Sanitizer::fontFamily( $this->settings->str( 'form_font_custom' ) );

			if ( '' !== $custom ) {
				return $custom;
			}
		}

		return self::FONT_STACK;
	}

	/**
	 * @param array<string,mixed> $args
	 */
	private function resolveRedirect( array $args ): string {
		$candidate = isset( $args['redirect'] ) ? (string) $args['redirect'] : $this->settings->str( 'login_redirect' );

		if ( isset( $_GET['redirect_to'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$requested = sanitize_text_field( wp_unslash( $_GET['redirect_to'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if ( '' !== $requested ) {
				$candidate = $requested;
			}
		}

		$safe = wp_validate_redirect( esc_url_raw( rawurldecode( $candidate ) ), '' );

		return is_string( $safe ) && '' !== $safe ? $safe : '';
	}

	private function terms(): array {
		if ( ! $this->settings->bool( 'terms_enabled', false ) ) {
			return array( 'show' => false );
		}

		return array(
			'show' => true,
			'text' => $this->settings->str( 'terms_text' ),
			'url'  => esc_url( (string) $this->settings->str( 'terms_url' ) ),
		);
	}

	private function headings(): array {
		return array(
			'form'     => $this->settings->str( 'form_heading' ),
			'formHint' => $this->settings->str( 'form_subheading' ),
			'register' => $this->settings->str( 'register_heading' ),
			'regHint'  => $this->settings->str( 'register_subheading' ),
		);
	}

	private function signedInNotice(): string {
		$user = wp_get_current_user();

		return sprintf(
			'<div class="tisa-otp tisa-otp--signed-in"><p>%s</p></div>',
			sprintf(
				/* translators: %s: display name */
				esc_html__( 'وارد شده‌اید: %s', 'tisa-otp' ),
				esc_html( $user->display_name )
			)
		);
	}
}
