<?php
/**
 * Front-end and admin asset loading.
 *
 * Scripts are only queued where a form can actually appear, and every visual
 * option is emitted as a CSS custom property instead of inline style soup.
 *
 * @package Signa
 */

namespace Signa\Front;

use Signa\Bootable;
use Signa\Captcha\Manager;
use Signa\Config\Settings;

defined( 'ABSPATH' ) || exit;

final class Assets implements Bootable {

	const STYLE = 'signa-front';
	const SCRIPT = 'signa-front';

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

		wp_enqueue_style( self::STYLE, SIGNA_URL . 'assets/css/front.css', array(), SIGNA_VERSION );

		$captcha = $this->captcha->clientBundle();

		if ( ! empty( $captcha['enabled'] ) && ! empty( $captcha['script'] ) ) {
			wp_enqueue_script( 'signa-captcha', $captcha['script'], array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		}

		wp_enqueue_script( self::SCRIPT, SIGNA_URL . 'assets/js/front.js', array(), SIGNA_VERSION, true );

		wp_localize_script( self::SCRIPT, 'signaOtp', $this->clientConfig() );

		wp_add_inline_style( self::STYLE, $this->cssVariables() );

		$this->injectCustomCode();

		/**
		 * Fires after front-end assets have been queued.
		 */
		do_action( 'signa_assets_enqueued' );
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

		if ( false === strpos( $screen, 'signa' ) ) {
			return;
		}

		wp_enqueue_style( 'signa-admin', SIGNA_URL . 'assets/css/admin.css', array(), SIGNA_VERSION );
		wp_enqueue_script( 'signa-admin', SIGNA_URL . 'assets/js/admin.js', array(), SIGNA_VERSION, true );

		wp_localize_script( 'signa-admin', 'signaOtpAdmin', $this->adminConfig() );

		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_enqueue_media();

		unset( $hook );
	}

	/**
	 * What admin.js is handed: the REST root, the nonce and every sentence it
	 * prints. Public so the static admin demo shows the same words.
	 *
	 * @return array<string,mixed>
	 */
	public function adminConfig(): array {
		return array(
			'restUrl' => esc_url_raw( rest_url( 'signa/v1/' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'captcha' => $this->captchaTest(),
			'i18n'    => array(
				'working'    => __( 'در حال انجام…', 'signa' ),
				'done'       => __( 'انجام شد', 'signa' ),
				'failed'     => __( 'ناموفق', 'signa' ),
				'confirm'    => __( 'این عملیات قابل بازگشت نیست. ادامه می‌دهید؟', 'signa' ),
				'captcha'    => __( 'کپچا', 'signa' ),
				'traceTitle' => __( 'مسیر تلاش برای ارسال:', 'signa' ),
				'traceSent'  => __( 'ارسال شد', 'signa' ),
				'ok'         => __( 'فعال', 'signa' ),
				'testing'    => __( 'در حال آزمایش…', 'signa' ),
				'captchaOk'  => __( 'کپچا درست بارگذاری شد.', 'signa' ),
				'captchaNoScript' => __( 'نشانی اسکریپت خالی است. کلید سایت را در همین کارت وارد کنید.', 'signa' ),
				'captchaBlocked'  => __( 'اسکریپت کپچا در مرورگر بارگذاری نشد. افزونهٔ مسدودکننده، DNS یا فیلترینگ را بررسی کنید؛ می‌توانید «نشانی جایگزین اسکریپت» را هم پر کنید.', 'signa' ),
				'close'         => __( 'بستن', 'signa' ),
				'rerun'         => __( 'اجرای دوباره', 'signa' ),
				'statusOk'      => __( 'سالم', 'signa' ),
				'statusWarn'    => __( 'هشدار', 'signa' ),
				'statusFail'    => __( 'نیاز به رسیدگی', 'signa' ),
				'statusInfo'    => __( 'اطلاع', 'signa' ),
				'captchaRow'    => __( 'بارگذاری در مرورگر', 'signa' ),
				'captchaTrying' => __( 'اسکریپت‌هایی که امتحان می‌شوند', 'signa' ),
				'smsTitle'      => __( 'ارسال پیامک آزمایشی', 'signa' ),
				'smsIntro'      => __( 'یک کد واقعی از مسیر واقعی ارسال می‌شود. شماره‌ای را وارد کنید که در دسترس خودتان است؛ هر سامانه‌ای که امتحان شود با پاسخش نشان داده می‌شود.', 'signa' ),
				'smsPhone'      => __( 'شماره', 'signa' ),
				'smsNeedPhone'  => __( 'بدون شماره، آزمایشی ارسال نمی‌شود.', 'signa' ),
				'smsSend'       => __( 'ارسال', 'signa' ),
				'smsSent'       => __( 'ارسال شد', 'signa' ),
				'smsNotSent'    => __( 'پیامک ارسال نشد', 'signa' ),
				'smsVia'        => __( 'از طریق', 'signa' ),
				'smsChannel'    => __( 'پیامک', 'signa' ),
				'emailChannel'  => __( 'ایمیل', 'signa' ),
				'fix'           => __( 'راه‌حل', 'signa' ),
				'planIssues'    => __( 'ایرادهای پیکربندی این سامانه', 'signa' ),
				'stateClean'    => __( 'همه‌چیز ذخیره شده است', 'signa' ),
				'stateDirty'    => __( 'تغییرات ذخیره نشده دارید', 'signa' ),
				'stateSaving'   => __( 'در حال ذخیره…', 'signa' ),
				'stateSaved'    => __( 'ذخیره شد', 'signa' ),
				'stateError'    => __( 'ذخیره نشد؛ دوباره امتحان کنید.', 'signa' ),
				'stateFallback' => __( 'ذخیرهٔ سریع انجام نشد؛ فرم به روش عادی ارسال می‌شود…', 'signa' ),
				'leave'         => __( 'تغییرات ذخیره نشده از بین می‌رود.', 'signa' ),
				'copied'        => __( 'رونوشت شد', 'signa' ),
			),
			'myPhone' => $this->ownPhone(),
		);
	}

	/**
	 * The administrator's own number, used to pre-fill the test-send modal.
	 *
	 * It is read from the profile key the plugin itself writes, so the field is
	 * empty rather than wrong when nothing is stored.
	 */
	private function ownPhone(): string {
		$key  = $this->settings->str( 'phone_meta_key', 'signa_phone' );
		$user = get_current_user_id();

		if ( ! $user || '' === trim( $key ) ) {
			return '';
		}

		return trim( (string) get_user_meta( $user, $key, true ) );
	}

	/**
	 * What the admin screen needs to test the captcha the way a visitor meets it.
	 *
	 * The point is to answer "why is there no captcha on my login page?" from
	 * inside the dashboard: the same script URLs the front end tries, in the
	 * same order, in a real browser.
	 *
	 * @return array<string,mixed>
	 */
	private function captchaTest(): array {
		$provider = $this->captcha->active();

		if ( null === $provider ) {
			return array( 'on' => false );
		}

		$globals = array(
			'arcaptcha'    => 'arcaptcha',
			'hcaptcha'     => 'hcaptcha',
			'recaptcha_v3' => 'grecaptcha',
		);

		$config = (array) $provider->clientConfig();

		return array(
			'on'        => true,
			'id'        => $provider->id(),
			'label'     => $provider->label(),
			'script'    => $provider->scriptUrl(),
			'fallbacks' => method_exists( $provider, 'fallbackScriptUrls' ) ? array_values( (array) $provider->fallbackScriptUrls() ) : array(),
			'global'    => isset( $globals[ $provider->id() ] ) ? $globals[ $provider->id() ] : '',
			'siteKey'   => isset( $config['siteKey'] ) ? (string) $config['siteKey'] : '',
			'kind'      => isset( $config['kind'] ) ? (string) $config['kind'] : 'widget',
		);
	}

	/**
	 * Everything the browser needs; also served over `GET /form-config`.
	 */
	public function clientConfig(): array {
		return array(
			'restUrl'    => esc_url_raw( rest_url( 'signa/v1/' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'configUrl'  => esc_url_raw( rest_url( 'signa/v1/form-config' ) ),
			'cacheMode'  => $this->settings->str( 'cache_mode', 'auto' ),
			// Minted here and refreshed by `/form-config`, never cached: this is
			// what keeps the bot-timing check honest behind a page cache.
			'formToken'  => \Signa\Support\FormToken::issue(),
			'renderedAt' => time(),
			'autoVerify' => $this->settings->bool( 'auto_verify', true ),
			'webOtp'     => $this->settings->bool( 'webotp_enabled', false ),
			'timeoutMs'  => max( 5, min( 60, $this->settings->int( 'request_timeout', 15 ) ) ) * 1000,
			'enabled'    => $this->settings->bool( 'enabled', true ),
			'codeLength' => max( 4, min( 8, $this->settings->int( 'code_length', 5 ) ) ),
			'codeInput'  => $this->settings->str( 'code_input', 'boxes' ),
			'rescueAfter'=> 30,
			'cooldown'   => $this->settings->int( 'resend_delay', 60 ),
			'ttl'        => $this->settings->int( 'code_ttl', 120 ),
			'channel'    => $this->settings->str( 'channel', 'sms' ),
			'skin'       => $this->settings->str( 'skin', 'line' ),
			'captcha'    => $this->captcha->clientBundle(),
			/*
			 * Style isolation. The form is moved into a shadow root and this
			 * stylesheet is injected into it, so the theme's CSS cannot reach
			 * the markup — see the note in assets/js/front.js. `css` is the
			 * same URL the <link> already loaded (so the fetch is a cache
			 * hit), and `assets` is the base the font URLs are rewritten
			 * against, because a <style> element resolves `url()` next to the
			 * page rather than next to the file the text came from.
			 */
			'isolate'    => $this->settings->bool( 'style_isolation', true ),
			'css'        => esc_url_raw( add_query_arg( 'ver', SIGNA_VERSION, SIGNA_URL . 'assets/css/front.css' ) ),
			'assets'     => esc_url_raw( SIGNA_URL . 'assets/' ),
			'vars'       => $this->variables(),
			'labels'     => array(
				'send'     => $this->settings->str( 'label_send', __( 'دریافت کد ورود', 'signa' ) ),
				'verify'   => $this->settings->str( 'label_verify', __( 'ورود به حساب', 'signa' ) ),
				'resend'   => $this->settings->str( 'label_resend', __( 'ارسال دوبارهٔ کد', 'signa' ) ),
				'editPhone'=> $this->settings->str( 'label_edit_phone', __( 'ویرایش شماره', 'signa' ) ),
			),
			/*
			 * Wording rules (docs/UI-PLAN.fa.md §9): short sentences, no blame,
			 * one suggested action per message, Persian digits in prose and Latin
			 * digits inside inputs.
			 */
			'i18n'       => array(
				'phonePlaceholder' => __( '۰۹۱۲۳۴۵۶۷۸۹', 'signa' ),
				'codePlaceholder'  => __( 'کد ۵ رقمی', 'signa' ),
				'sending'          => __( 'در حال ارسال کد…', 'signa' ),
				'checking'         => __( 'در حال بررسی کد…', 'signa' ),
				'creating'         => __( 'در حال ساخت حساب…', 'signa' ),
				'resendIn'         => __( 'تا {s} ثانیهٔ دیگر می‌توانید کد تازه بگیرید', 'signa' ),
				'network'          => __( 'ارتباط با سرور برقرار نشد.', 'signa' ),
				'invalidPhone'     => __( 'شمارهٔ موبایل معتبر نیست. با ۰۹ شروع شود و ۱۱ رقم باشد.', 'signa' ),
				'fillFields'       => __( 'فیلدهای ستاره‌دار را کامل کنید.', 'signa' ),
				'requiredField'    => __( 'این فیلد الزامی است.', 'signa' ),
				'invalidEmail'     => __( 'قالب ایمیل معتبر نیست.', 'signa' ),
				'incompleteCode'   => __( 'کد را کامل وارد کنید.', 'signa' ),
				'redirecting'      => __( 'خوش آمدید. در حال انتقال…', 'signa' ),
				'timeout'          => __( 'ارتباط با سرور برقرار نشد.', 'signa' ),
				'offline'          => __( 'به اینترنت وصل نیستید. اتصال را بررسی کنید.', 'signa' ),
				'otpFilled'        => __( 'کد از پیامک خوانده شد.', 'signa' ),
				'resendReady'      => __( 'اکنون می‌توانید کد را دوباره ارسال کنید.', 'signa' ),
				'expired'          => __( 'این کد منقضی شده است.', 'signa' ),
				'attemptsLeft'     => __( '{n} تلاش دیگر باقی مانده.', 'signa' ),
				'stepOf'           => __( 'گام {n} از {total}: {name}', 'signa' ),
				'digitLabel'       => __( 'رقم {n}', 'signa' ),
				// Captcha loading is a real failure mode, so it has real sentences.
				'captchaLoad'      => __( 'تأیید امنیتی بارگذاری نشد. اگر افزونهٔ مسدودکننده دارید، آن را برای این سایت غیرفعال کنید.', 'signa' ),
				'captchaRetry'     => __( 'تلاش دوباره برای بارگذاری', 'signa' ),
				'captchaContinue'  => __( 'می‌توانید بدون تأیید امنیتی ادامه دهید؛ سایت از روش‌های دیگر محافظت می‌کند.', 'signa' ),
				'captchaBlocked'   => __( 'تأیید امنیتی در مرورگر شما بارگذاری نشد. صفحه را دوباره باز کنید یا افزونهٔ مسدودکننده را غیرفعال کنید.', 'signa' ),
				'pasteLabel'       => __( 'چسباندن کد از پیامک', 'signa' ),
				'pasteManual'      => __( 'کد پیامک را دستی در خانه‌ها وارد کنید.', 'signa' ),
				'pasteEmpty'       => __( 'کدی در حافظه پیدا نشد. پیامک را باز کنید و کد را کپی کنید.', 'signa' ),
				'pasteDone'        => __( 'کد از حافظه چسبانده شد.', 'signa' ),
				'pasteDenied'      => __( 'مرورگر اجازهٔ خواندن حافظه را نداد. کد را در کادر اول بچسبانید (Ctrl+V).', 'signa' ),
				'problemTitle'     => __( 'یک مشکل پیش آمد', 'signa' ),
				'doneTitle'        => __( 'انجام شد', 'signa' ),
				'noteTitle'        => __( 'توجه', 'signa' ),
				'trustSecure'      => __( 'بدون رمز عبور', 'signa' ),
				'trustInstant'     => __( 'ورود در چند ثانیه', 'signa' ),
				'trustPrivate'     => __( 'شماره شما محفوظ می‌ماند', 'signa' ),
			),
			/* Buttons offered inside an error message, keyed by the server's code. */
			'actions'    => array(
				'retry'     => __( 'تلاش دوباره', 'signa' ),
				'newCode'   => __( 'دریافت کد تازه', 'signa' ),
				'editPhone' => __( 'ویرایش شماره', 'signa' ),
				'solve'     => __( 'تأیید امنیتی را کامل کنید', 'signa' ),
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
			foreach ( array( 'signa_form', 'signa' ) as $tag ) {
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
		return (bool) apply_filters( 'signa_should_load_assets', false );
	}

	private function hasCustomCode(): bool {
		return '' !== trim( $this->settings->str( 'custom_css' ) ) || '' !== trim( $this->settings->str( 'custom_js' ) );
	}

	/**
	 * Every design value the settings imply, as CSS custom properties.
	 *
	 * It is a map rather than a stylesheet because it has two consumers: the
	 * `:root` block for the light DOM, and the form itself inside its shadow
	 * root — where a `:root` rule cannot reach and the values have to be set on
	 * the element.
	 *
	 * Every colour the accent implies is derived here, next to the accent
	 * itself. `--signa-accent-strong` is what the button hover, its shadow and
	 * the cooldown bar darken to; when it was a fixed teal, a crimson form
	 * hovered green.
	 *
	 * @return array<string,string>
	 */
	public function variables(): array {
		$accent  = $this->settings->str( 'accent', '#0f766e' );
		$surface = $this->settings->str( 'surface', '#ffffff' );
		$radius  = max( 0, min( 40, $this->settings->int( 'radius', 14 ) ) );
		$width   = max( 280, min( 900, $this->settings->int( 'width', 420 ) ) );

		return array(
			'signa-accent'        => $accent,
			'signa-accent-strong' => $this->mix( $accent, '#000000', 0.22 ),
			'signa-accent-soft'   => $this->rgba( $accent, 0.14 ),
			// Empty (so not printed) at the default: the skins bring their own
			// surface, and an inline white beat the dark skin's (see FormRenderer).
			'signa-surface'       => '#ffffff' === strtolower( $surface ) ? '' : $surface,
			'signa-radius'        => $radius . 'px',
			'signa-width'         => $width . 'px',
		);
	}

	/**
	 * The form's stylesheet and its inline variables, for a page that prints
	 * its own <head> (the admin form preview).
	 */
	public function previewStyles(): string {
		$css = $this->cssVariables();
		$own = trim( $this->settings->str( 'custom_css' ) );

		if ( '' !== $own ) {
			$css .= "\n" . wp_strip_all_tags( $own );
		}

		return '<link rel="stylesheet" href="' . esc_url( SIGNA_URL . 'assets/css/front.css?ver=' . SIGNA_VERSION ) . '">'
			. '<style>' . $css . '</style>';
	}

	private function cssVariables(): string {
		$parts = array();

		foreach ( $this->variables() as $name => $value ) {
			if ( '' !== $value ) {
				$parts[] = '--' . $name . ':' . $value;
			}
		}

		return ':root{' . implode( ';', $parts ) . ';}';
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
	 * The accent as a translucent wash.
	 *
	 * Alpha, not a lightened solid: the same ring has to sit on a white card
	 * and on the dark skin without turning into an opaque band.
	 */
	private function rgba( string $hex, float $alpha ): string {
		$rgb = $this->toRgb( $hex );

		if ( null === $rgb ) {
			return $hex;
		}

		return sprintf( 'rgba(%d, %d, %d, %s)', $rgb[0], $rgb[1], $rgb[2], rtrim( rtrim( number_format( $alpha, 3, '.', '' ), '0' ), '.' ) );
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
