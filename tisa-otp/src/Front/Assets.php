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
				'captcha' => $this->captchaTest(),
				'i18n'    => array(
					'working'    => __( 'در حال انجام…', 'tisa-otp' ),
					'done'       => __( 'انجام شد', 'tisa-otp' ),
					'failed'     => __( 'ناموفق', 'tisa-otp' ),
					'confirm'    => __( 'این عملیات قابل بازگشت نیست. ادامه می‌دهید؟', 'tisa-otp' ),
					'captcha'    => __( 'کپچا', 'tisa-otp' ),
					'traceTitle' => __( 'مسیر تلاش برای ارسال:', 'tisa-otp' ),
					'traceSent'  => __( 'ارسال شد', 'tisa-otp' ),
					'ok'         => __( 'فعال', 'tisa-otp' ),
					'testing'    => __( 'در حال آزمایش…', 'tisa-otp' ),
					'captchaOk'  => __( 'کپچا درست بارگذاری شد.', 'tisa-otp' ),
					'captchaNoScript' => __( 'نشانی اسکریپت خالی است. کلید سایت را در همین کارت وارد کنید.', 'tisa-otp' ),
					'captchaBlocked'  => __( 'اسکریپت کپچا در مرورگر بارگذاری نشد. افزونهٔ مسدودکننده، DNS یا فیلترینگ را بررسی کنید؛ می‌توانید «نشانی جایگزین اسکریپت» را هم پر کنید.', 'tisa-otp' ),
					'close'         => __( 'بستن', 'tisa-otp' ),
					'rerun'         => __( 'اجرای دوباره', 'tisa-otp' ),
					'statusOk'      => __( 'سالم', 'tisa-otp' ),
					'statusWarn'    => __( 'هشدار', 'tisa-otp' ),
					'statusFail'    => __( 'نیاز به رسیدگی', 'tisa-otp' ),
					'statusInfo'    => __( 'اطلاع', 'tisa-otp' ),
					'captchaRow'    => __( 'بارگذاری در مرورگر', 'tisa-otp' ),
					'captchaTrying' => __( 'اسکریپت‌هایی که امتحان می‌شوند', 'tisa-otp' ),
					'smsTitle'      => __( 'ارسال پیامک آزمایشی', 'tisa-otp' ),
					'smsIntro'      => __( 'یک کد واقعی از مسیر واقعی ارسال می‌شود. شماره‌ای را وارد کنید که در دسترس خودتان است؛ هر سامانه‌ای که امتحان شود با پاسخش نشان داده می‌شود.', 'tisa-otp' ),
					'smsPhone'      => __( 'شماره', 'tisa-otp' ),
					'smsNeedPhone'  => __( 'بدون شماره، آزمایشی ارسال نمی‌شود.', 'tisa-otp' ),
					'smsSend'       => __( 'ارسال', 'tisa-otp' ),
					'smsSent'       => __( 'ارسال شد', 'tisa-otp' ),
					'smsVia'        => __( 'از طریق', 'tisa-otp' ),
					'smsChannel'    => __( 'پیامک', 'tisa-otp' ),
					'emailChannel'  => __( 'ایمیل', 'tisa-otp' ),
					'smsHint'       => __( 'اگر ارسال ناموفق بود، ردیف‌های پایین نشان می‌دهند کدام سامانه چه پاسخی داد.', 'tisa-otp' ),
					'planIssues'    => __( 'ایرادهای پیکربندی این سامانه', 'tisa-otp' ),
				),
				'myPhone' => $this->ownPhone(),
			)
		);

		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_enqueue_media();

		unset( $hook );
	}

	/**
	 * The administrator's own number, used to pre-fill the test-send modal.
	 *
	 * It is read from the profile key the plugin itself writes, so the field is
	 * empty rather than wrong when nothing is stored.
	 */
	private function ownPhone(): string {
		$key  = $this->settings->str( 'phone_meta_key', 'tisa_phone' );
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
			'restUrl'    => esc_url_raw( rest_url( 'tisa-otp/v1/' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'configUrl'  => esc_url_raw( rest_url( 'tisa-otp/v1/form-config' ) ),
			'cacheMode'  => $this->settings->str( 'cache_mode', 'auto' ),
			// Minted here and refreshed by `/form-config`, never cached: this is
			// what keeps the bot-timing check honest behind a page cache.
			'formToken'  => \TisaOtp\Support\FormToken::issue(),
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
			'labels'     => array(
				'send'     => $this->settings->str( 'label_send', __( 'دریافت کد ورود', 'tisa-otp' ) ),
				'verify'   => $this->settings->str( 'label_verify', __( 'ورود به حساب', 'tisa-otp' ) ),
				'resend'   => $this->settings->str( 'label_resend', __( 'ارسال دوبارهٔ کد', 'tisa-otp' ) ),
				'editPhone'=> $this->settings->str( 'label_edit_phone', __( 'ویرایش شماره', 'tisa-otp' ) ),
			),
			/*
			 * Wording rules (docs/UI-PLAN.fa.md §9): short sentences, no blame,
			 * one suggested action per message, Persian digits in prose and Latin
			 * digits inside inputs.
			 */
			'i18n'       => array(
				'phonePlaceholder' => __( '۰۹۱۲۳۴۵۶۷۸۹', 'tisa-otp' ),
				'codePlaceholder'  => __( 'کد ۵ رقمی', 'tisa-otp' ),
				'sending'          => __( 'در حال ارسال کد…', 'tisa-otp' ),
				'checking'         => __( 'در حال بررسی کد…', 'tisa-otp' ),
				'creating'         => __( 'در حال ساخت حساب…', 'tisa-otp' ),
				'resendIn'         => __( 'تا {s} ثانیهٔ دیگر می‌توانید کد تازه بگیرید', 'tisa-otp' ),
				'network'          => __( 'ارتباط با سرور برقرار نشد.', 'tisa-otp' ),
				'invalidPhone'     => __( 'شمارهٔ موبایل معتبر نیست. با ۰۹ شروع شود و ۱۱ رقم باشد.', 'tisa-otp' ),
				'fillFields'       => __( 'فیلدهای ستاره‌دار را کامل کنید.', 'tisa-otp' ),
				'requiredField'    => __( 'این فیلد الزامی است.', 'tisa-otp' ),
				'invalidEmail'     => __( 'قالب ایمیل معتبر نیست.', 'tisa-otp' ),
				'incompleteCode'   => __( 'کد را کامل وارد کنید.', 'tisa-otp' ),
				'redirecting'      => __( 'خوش آمدید. در حال انتقال…', 'tisa-otp' ),
				'timeout'          => __( 'ارتباط با سرور برقرار نشد.', 'tisa-otp' ),
				'offline'          => __( 'به اینترنت وصل نیستید. اتصال را بررسی کنید.', 'tisa-otp' ),
				'otpFilled'        => __( 'کد از پیامک خوانده شد.', 'tisa-otp' ),
				'resendReady'      => __( 'اکنون می‌توانید کد را دوباره ارسال کنید.', 'tisa-otp' ),
				'expired'          => __( 'این کد منقضی شده است.', 'tisa-otp' ),
				'attemptsLeft'     => __( '{n} تلاش دیگر باقی مانده.', 'tisa-otp' ),
				'stepOf'           => __( 'گام {n} از {total}: {name}', 'tisa-otp' ),
				'digitLabel'       => __( 'رقم {n}', 'tisa-otp' ),
				// Captcha loading is a real failure mode, so it has real sentences.
				'captchaLoad'      => __( 'تأیید امنیتی بارگذاری نشد. اگر افزونهٔ مسدودکننده دارید، آن را برای این سایت غیرفعال کنید.', 'tisa-otp' ),
				'captchaRetry'     => __( 'تلاش دوباره برای بارگذاری', 'tisa-otp' ),
				'captchaContinue'  => __( 'می‌توانید بدون تأیید امنیتی ادامه دهید؛ سایت از روش‌های دیگر محافظت می‌کند.', 'tisa-otp' ),
				'captchaBlocked'   => __( 'تأیید امنیتی در مرورگر شما بارگذاری نشد. صفحه را دوباره باز کنید یا افزونهٔ مسدودکننده را غیرفعال کنید.', 'tisa-otp' ),
				'pasteLabel'       => __( 'چسباندن کد از پیامک', 'tisa-otp' ),
				'pasteManual'      => __( 'کد پیامک را دستی در خانه‌ها وارد کنید.', 'tisa-otp' ),
				'pasteEmpty'       => __( 'کدی در حافظه پیدا نشد. پیامک را باز کنید و کد را کپی کنید.', 'tisa-otp' ),
				'pasteDone'        => __( 'کد از حافظه چسبانده شد.', 'tisa-otp' ),
				'pasteDenied'      => __( 'مرورگر اجازهٔ خواندن حافظه را نداد. کد را در کادر اول بچسبانید (Ctrl+V).', 'tisa-otp' ),
				'problemTitle'     => __( 'یک مشکل پیش آمد', 'tisa-otp' ),
				'doneTitle'        => __( 'انجام شد', 'tisa-otp' ),
				'noteTitle'        => __( 'توجه', 'tisa-otp' ),
				'trustSecure'      => __( 'بدون رمز عبور', 'tisa-otp' ),
				'trustInstant'     => __( 'ورود در چند ثانیه', 'tisa-otp' ),
				'trustPrivate'     => __( 'شماره شما محفوظ می‌ماند', 'tisa-otp' ),
			),
			/* Buttons offered inside an error message, keyed by the server's code. */
			'actions'    => array(
				'retry'     => __( 'تلاش دوباره', 'tisa-otp' ),
				'newCode'   => __( 'دریافت کد تازه', 'tisa-otp' ),
				'editPhone' => __( 'ویرایش شماره', 'tisa-otp' ),
				'solve'     => __( 'تأیید امنیتی را کامل کنید', 'tisa-otp' ),
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

		/*
		 * Every colour the accent implies is derived here, next to the accent
		 * itself. `--tisa-accent-strong` is what the button hover, its shadow
		 * and the cooldown bar darken to; when it was a fixed teal, a crimson
		 * form hovered green.
		 */
		return sprintf(
			':root{--tisa-accent:%1$s;--tisa-accent-strong:%2$s;--tisa-accent-soft:%3$s;--tisa-surface:%4$s;--tisa-radius:%5$dpx;--tisa-width:%6$dpx;}',
			esc_attr( $accent ),
			esc_attr( $this->mix( $accent, '#000000', 0.22 ) ),
			esc_attr( $this->rgba( $accent, 0.14 ) ),
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
