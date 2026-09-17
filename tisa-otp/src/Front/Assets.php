<?php
/**
 * Front-end and admin asset loading.
 *
 * Scripts are only queued where a form can actually appear, and every visual
 * option is emitted as a CSS custom property instead of inline style soup.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Front;

use TisaOtp\Bootable;
use TisaOtp\Captcha\Manager;
use TisaOtp\Config\Settings;

defined( 'ABSPATH' ) || exit;

final class Assets implements Bootable {

	const STYLE = 'tisa-otp-front';
	const SCRIPT = 'tisa-otp-front';

	/** @var Settings */
	private $settings;

	/** @var Manager */
	private $captcha;

	/** @var bool */
	private $queued = false;

	public function __construct( Settings $settings, Manager $captcha ) {
		$this->settings = $settings;
		$this->captcha  = $captcha;
	}

	public function boot(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'maybeEnqueue' ) );
		add_action( 'login_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAdmin' ) );
	}

	/**
	 * Idempotent front-end enqueue — safe to call from a renderer.
	 */
	public function enqueue(): void {
		if ( $this->queued ) {
			return;
		}

		$this->queued = true;

		wp_enqueue_style( self::STYLE, TISA_OTP_URL . 'assets/css/front.css', array(), TISA_OTP_VERSION );

		$captcha = $this->captcha->clientBundle();

		if ( ! empty( $captcha['enabled'] ) && ! empty( $captcha['script'] ) ) {
			wp_enqueue_script( 'tisa-otp-captcha', $captcha['script'], array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		}

		wp_enqueue_script( self::SCRIPT, TISA_OTP_URL . 'assets/js/front.js', array(), TISA_OTP_VERSION, true );

		wp_localize_script( self::SCRIPT, 'tisaOtp', $this->clientConfig() );

		wp_add_inline_style( self::STYLE, $this->cssVariables() );

		$this->injectCustomCode();

		/**
		 * Fires after front-end assets have been queued.
		 */
		do_action( 'tisa_otp_assets_enqueued' );
	}

	public function maybeEnqueue(): void {
		if ( ! $this->settings->bool( 'enabled', true ) ) {
			return;
		}

		if ( 'everywhere' === $this->settings->str( 'custom_code_scope', 'form_pages' ) && $this->hasCustomCode() ) {
			$this->enqueue();
			return;
		}

		if ( $this->isFormPage() ) {
			$this->enqueue();
		}
	}

	public function enqueueAdmin( string $hook ): void {
		$screen = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( false === strpos( $screen, 'tisa-otp' ) ) {
			return;
		}

		wp_enqueue_style( 'tisa-otp-admin', TISA_OTP_URL . 'assets/css/admin.css', array(), TISA_OTP_VERSION );
		wp_enqueue_script( 'tisa-otp-admin', TISA_OTP_URL . 'assets/js/admin.js', array(), TISA_OTP_VERSION, true );

		wp_localize_script(
			'tisa-otp-admin',
			'tisaOtpAdmin',
			array(
				'restUrl' => esc_url_raw( rest_url( 'tisa-otp/v1/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'working' => __( 'در حال انجام…', 'tisa-otp' ),
					'done'    => __( 'انجام شد', 'tisa-otp' ),
					'failed'  => __( 'ناموفق', 'tisa-otp' ),
					'confirm' => __( 'این عملیات قابل بازگشت نیست. ادامه می‌دهید؟', 'tisa-otp' ),
				),
			)
		);

		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_enqueue_media();

		unset( $hook );
	}

	/**
	 * Everything the browser needs; also served over `GET /form-config`.
	 */
	public function clientConfig(): array {
		return array(
			'restUrl'    => esc_url_raw( rest_url( 'tisa-otp/v1/' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'enabled'    => $this->settings->bool( 'enabled', true ),
			'codeLength' => max( 4, min( 8, $this->settings->int( 'code_length', 5 ) ) ),
			'codeInput'  => $this->settings->str( 'code_input', 'boxes' ),
			'cooldown'   => $this->settings->int( 'resend_delay', 60 ),
			'ttl'        => $this->settings->int( 'code_ttl', 120 ),
			'channel'    => $this->settings->str( 'channel', 'sms' ),
			'skin'       => $this->settings->str( 'skin', 'line' ),
			'captcha'    => $this->captcha->clientBundle(),
			'labels'     => array(
				'send'     => $this->settings->str( 'label_send', __( 'دریافت کد تأیید', 'tisa-otp' ) ),
				'verify'   => $this->settings->str( 'label_verify', __( 'ورود به حساب', 'tisa-otp' ) ),
				'resend'   => $this->settings->str( 'label_resend', __( 'ارسال دوباره کد', 'tisa-otp' ) ),
				'editPhone'=> $this->settings->str( 'label_edit_phone', __( 'ویرایش شماره', 'tisa-otp' ) ),
			),
			'i18n'       => array(
				'phonePlaceholder' => __( '۰۹۱۲۳۴۵۶۷۸۹', 'tisa-otp' ),
				'codePlaceholder'  => __( 'کد ۵ رقمی', 'tisa-otp' ),
				'sending'          => __( 'در حال ارسال کد…', 'tisa-otp' ),
				'checking'         => __( 'در حال بررسی کد…', 'tisa-otp' ),
				'creating'         => __( 'در حال ساخت حساب…', 'tisa-otp' ),
				'resendIn'         => __( 'ارسال دوباره تا {s} ثانیه', 'tisa-otp' ),
				'network'          => __( 'خطای شبکه. لطفاً دوباره تلاش کنید.', 'tisa-otp' ),
				'invalidPhone'     => __( 'شماره موبایل معتبر نیست.', 'tisa-otp' ),
				'fillFields'       => __( 'لطفاً فیلدهای ستاره‌دار را کامل کنید.', 'tisa-otp' ),
				'incompleteCode'   => __( 'کد را کامل وارد کنید.', 'tisa-otp' ),
				'redirecting'      => __( 'در حال انتقال…', 'tisa-otp' ),
			),
		);
	}

	public function isFormPage(): bool {
		if ( is_admin() ) {
			return false;
		}

		if ( function_exists( 'is_account_page' ) && is_account_page() && ! is_user_logged_in() ) {
			return true;
		}

		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return true;
		}

		$queried = get_queried_object();

		if ( $queried instanceof \WP_Post ) {
			foreach ( array( 'tisa_otp_form', 'tisa_otp' ) as $tag ) {
				if ( has_shortcode( (string) $queried->post_content, $tag ) ) {
					return true;
				}
			}
		}

		/**
		 * Decide whether assets should load on the current request.
		 *
		 * @param bool $load Current decision.
		 */
		return (bool) apply_filters( 'tisa_otp_should_load_assets', false );
	}

	private function hasCustomCode(): bool {
		return '' !== trim( $this->settings->str( 'custom_css' ) ) || '' !== trim( $this->settings->str( 'custom_js' ) );
	}

	private function cssVariables(): string {
		$accent  = $this->settings->str( 'accent', '#0f766e' );
		$surface = $this->settings->str( 'surface', '#ffffff' );
		$radius  = max( 0, min( 40, $this->settings->int( 'radius', 14 ) ) );
		$width   = max( 280, min( 900, $this->settings->int( 'width', 420 ) ) );

		return sprintf(
			':root{--tisa-accent:%1$s;--tisa-accent-soft:%2$s;--tisa-surface:%3$s;--tisa-radius:%4$dpx;--tisa-width:%5$dpx;}',
			esc_attr( $accent ),
			esc_attr( $this->mix( $accent, '#ffffff', 0.12 ) ),
			esc_attr( $surface ),
			$radius,
			$width
		);
	}

	private function injectCustomCode(): void {
		$css = $this->settings->str( 'custom_css' );
		$js  = $this->settings->str( 'custom_js' );

		if ( '' !== trim( $css ) ) {
			wp_add_inline_style( self::STYLE, wp_strip_all_tags( $css ) );
		}

		if ( '' !== trim( $js ) ) {
			wp_add_inline_script( self::SCRIPT, $js, 'after' );
		}
	}

	/**
	 * Cheap colour mixer used for hover/soft accents.
	 */
	private function mix( string $hex, string $target, float $ratio ): string {
		$from = $this->toRgb( $hex );
		$to   = $this->toRgb( $target );

		if ( null === $from || null === $to ) {
			return $hex;
		}

		$mixed = array();

		for ( $i = 0; $i < 3; $i++ ) {
			$mixed[] = str_pad( dechex( (int) round( $from[ $i ] * ( 1 - $ratio ) + $to[ $i ] * $ratio ) ), 2, '0', STR_PAD_LEFT );
		}

		return '#' . implode( '', $mixed );
	}

	/**
	 * @return int[]|null
	 */
	private function toRgb( string $hex ): ?array {
		$hex = ltrim( trim( $hex ), '#' );

		if ( ! preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ) {
			return null;
		}

		return array(
			hexdec( substr( $hex, 0, 2 ) ),
			hexdec( substr( $hex, 2, 2 ) ),
			hexdec( substr( $hex, 4, 2 ) ),
		);
	}
}
