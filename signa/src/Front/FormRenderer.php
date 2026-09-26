<?php

namespace Signa\Front;

use Signa\Captcha\Manager;
use Signa\Config\Sanitizer;
use Signa\Config\Settings;
use Signa\Registration\FieldSchema;
use Signa\Support\View;

defined( 'ABSPATH' ) || exit;

final class FormRenderer {
	const FONT_STACK = "'Vazirmatn','Vazir','IRANSans','Iranian Sans',Tahoma,sans-serif";
	private $settings;
	private $schema;
	private $captcha;
	private $view;
	private $assets;
	private static $sequence = 0;

	public function __construct( Settings $settings, FieldSchema $schema, Manager $captcha, View $view, Assets $assets ) {
		$this->settings = $settings;
		$this->schema   = $schema;
		$this->captcha  = $captcha;
		$this->view     = $view;
		$this->assets   = $assets;
	}

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

	public function preview(): string {
		self::$sequence++;

		return $this->view->render( 'form.php', $this->templateData( array() ) );
	}

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

	private function templateData( array $args ): array {
		$args = $this->normalizeArgs( $args );

		$skin = $this->pick( $args, 'skin', $this->settings->str( 'skin', 'line' ), array( 'line', 'card', 'glass', 'slate', 'pill' ) );

		$classes = array( 'signa', 'signa-skin-' . $skin );
		$classes[] = 'signa-align-' . $this->pick( $args, 'align', $this->settings->str( 'align', 'center' ), array( 'center', 'start', 'end' ) );
		$classes[] = 'signa-code-' . $this->pick( $args, 'code_input', $this->settings->str( 'code_input', 'boxes' ), array( 'boxes', 'single' ) );

		if ( ! empty( $args['custom_class'] ) ) {
			$classes[] = sanitize_html_class( (string) $args['custom_class'] );
		}

		$showBrand = $this->flag( $args, 'show_brand', $this->settings->bool( 'show_brand', true ) );
		$logo      = (string) $this->pick( $args, 'logo', $this->settings->str( 'brand_logo' ), array() );

		return array(
			'instance'     => 'signa-form-' . self::$sequence . '-' . wp_unique_id(),
			'classes'      => implode( ' ', array_map( 'sanitize_html_class', $classes ) ),
			'skin'         => $skin,
			'style'        => $this->inlineStyle( $args ),
			'heading'      => (string) $this->pick( $args, 'title', $this->settings->str( 'form_heading' ), array() ),
			'hint'         => (string) $this->pick( $args, 'description', $this->settings->str( 'form_subheading' ), array() ),
			'regHeading'   => $this->settings->str( 'register_heading' ),
			'regHint'      => $this->settings->str( 'register_subheading' ),
			'redirect'     => $this->resolveRedirect( $args ),
			'phoneLabel'   => __( 'شماره موبایل', 'signa' ),
			'phonePlaceholder' => __( '09121234567', 'signa' ),
			'trust'        => $this->trust(),
			'codeLabel'    => __( 'کد تأیید', 'signa' ),
			'sendLabel'    => $this->settings->str( 'label_send', __( 'دریافت کد تأیید', 'signa' ) ),
			'verifyLabel'  => $this->settings->str( 'label_verify', __( 'ورود به حساب', 'signa' ) ),
			'resendLabel'  => $this->settings->str( 'label_resend', __( 'ارسال دوباره کد', 'signa' ) ),
			'editLabel'    => $this->settings->str( 'label_edit_phone', __( 'ویرایش شماره', 'signa' ) ),
			'continueLabel'=> __( 'ادامه', 'signa' ),
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
			'configUrl'    => esc_url_raw( rest_url( 'signa/v1/form-config' ) ),
			'cacheMode'    => $this->settings->str( 'cache_mode', 'auto' ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'restUrl'      => esc_url_raw( rest_url( 'signa/v1/' ) ),
			'honeypot'     => \Signa\Guard\BotGuard::HONEYPOT,
			'timestampKey' => \Signa\Guard\BotGuard::TIMESTAMP,
			'formToken'    => \Signa\Support\FormToken::issue(),
			'renderedAt'   => time(),
		);
	}

	private function trust(): array {
		$items = array(
			array(
				'icon'  => '<path d="M10 2.5 4 5v5c0 3.2 2.5 6.1 6 7.5 3.5-1.4 6-4.3 6-7.5V5l-6-2.5Z" stroke-linejoin="round"></path><path d="m7.5 9.8 1.8 1.8 3.4-3.6" stroke-linecap="round" stroke-linejoin="round"></path>',
				'label' => __( 'بدون رمز عبور', 'signa' ),
			),
			array(
				'icon'  => '<circle cx="10" cy="10" r="7.5"></circle><path d="M10 5.8V10l2.8 1.7" stroke-linecap="round" stroke-linejoin="round"></path>',
				'label' => __( 'ورود در چند ثانیه', 'signa' ),
			),
			array(
				'icon'  => '<rect x="4.5" y="4.5" width="11" height="11" rx="2.5"></rect><path d="M8.5 10h3" stroke-linecap="round"></path>',
				'label' => __( 'شماره شما محفوظ می‌ماند', 'signa' ),
			),
		);

		return (array) apply_filters( 'signa_form_trust', $items );
	}

	private function steps(): array {
		$phone = array(
			'id'    => 'phone',
			'label' => __( 'شماره', 'signa' ),
		);
		$code  = array(
			'id'    => 'code',
			'label' => __( 'کد', 'signa' ),
		);

		if ( ! $this->schema->enabled() ) {
			return array( $phone, $code );
		}

		$fields = array(
			'id'    => 'fields',
			'label' => __( 'اطلاعات', 'signa' ),
		);

		$steps = 'code_then_fields' === $this->schema->flow()
			? array( $phone, $code, $fields )
			: array( $phone, $fields, $code );

		return (array) apply_filters( 'signa_form_steps', $steps );
	}

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

	private function pick( array $args, string $key, $fallback, array $allowed ) {
		$value = array_key_exists( $key, $args ) ? $args[ $key ] : $fallback;

		if ( array() !== $allowed && ! in_array( $value, $allowed, true ) ) {
			return $fallback;
		}

		return $value;
	}

	private function flag( array $args, string $key, bool $fallback ): bool {
		if ( ! array_key_exists( $key, $args ) ) {
			return $fallback;
		}

		return in_array( (string) $args[ $key ], array( '1', 'true', 'yes' ), true );
	}

	private function inlineStyle( array $args ): string {
		$accent = sanitize_hex_color( (string) $this->pick( $args, 'accent', $this->settings->str( 'accent', '#0f766e' ), array() ) );
		$width  = (int) $this->pick( $args, 'width', (string) $this->settings->int( 'width', 420 ), array() );
		$radius = (int) $this->pick( $args, 'radius', (string) $this->settings->int( 'radius', 14 ), array() );

		$parts = array();

		if ( $accent ) {
			$parts[] = '--signa-accent:' . $accent;
		}

		$surface = sanitize_hex_color( (string) $this->pick( $args, 'surface', $this->settings->str( 'surface', '#ffffff' ), array() ) );

		if ( $surface && '#ffffff' !== strtolower( $surface ) ) {
			$parts[] = '--signa-surface:' . $surface;
		}

		$font = $this->fontFamily();

		if ( '' !== $font ) {
			$parts[] = '--signa-font:' . $font;
		}
		if ( $width >= 280 && $width <= 900 ) {
			$parts[] = '--signa-width:' . $width . 'px';
		}
		if ( $radius >= 0 && $radius <= 40 ) {
			$parts[] = '--signa-radius:' . $radius . 'px';
		}

		return implode( ';', $parts );
	}

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

	private function resolveRedirect( array $args ): string {
		$candidate = isset( $args['redirect'] ) ? (string) $args['redirect'] : $this->settings->str( 'login_redirect' );

		if ( isset( $_GET['redirect_to'] ) ) {
			$requested = sanitize_text_field( wp_unslash( $_GET['redirect_to'] ) );

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
			'<div class="signa signa--signed-in"><p>%s</p></div>',
			sprintf(
				esc_html__( 'وارد شده‌اید: %s', 'signa' ),
				esc_html( $user->display_name )
			)
		);
	}
}
