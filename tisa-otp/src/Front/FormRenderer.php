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
use TisaOtp\Config\Settings;
use TisaOtp\Registration\FieldSchema;
use TisaOtp\Support\View;

defined( 'ABSPATH' ) || exit;

final class FormRenderer {

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
			'captcha'      => $this->captcha->clientBundle(),
			'terms'        => $this->terms(),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'restUrl'      => esc_url_raw( rest_url( 'tisa-otp/v1/' ) ),
			'honeypot'     => \TisaOtp\Guard\BotGuard::HONEYPOT,
			'timestampKey' => \TisaOtp\Guard\BotGuard::TIMESTAMP,
			'renderedAt'   => time(),
		);
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
		if ( $width >= 280 && $width <= 900 ) {
			$parts[] = '--tisa-width:' . $width . 'px';
		}
		if ( $radius >= 0 && $radius <= 40 ) {
			$parts[] = '--tisa-radius:' . $radius . 'px';
		}

		return implode( ';', $parts );
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
