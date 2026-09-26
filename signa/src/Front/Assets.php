<?php

namespace Signa\Front;

use Signa\Bootable;
use Signa\Captcha\Manager;
use Signa\Config\Settings;

defined( 'ABSPATH' ) || exit;

final class Assets implements Bootable {
	const STYLE = 'signa-front';
	const SCRIPT = 'signa-front';
	private $settings;
	private $captcha;
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

	public function enqueue(): void {
		if ( $this->queued ) {
			return;
		}

		$this->queued = true;

		wp_enqueue_style( self::STYLE, SIGNA_URL . 'assets/css/front.css', array(), SIGNA_VERSION );

		$captcha = $this->captcha->clientBundle();

		if ( ! empty( $captcha['enabled'] ) && ! empty( $captcha['script'] ) ) {
			wp_enqueue_script( 'signa-captcha', $captcha['script'], array(), null, true );
		}

		wp_enqueue_script( self::SCRIPT, SIGNA_URL . 'assets/js/front.js', array(), SIGNA_VERSION, true );

		wp_localize_script( self::SCRIPT, 'signaOtp', $this->clientConfig() );

		wp_add_inline_style( self::STYLE, $this->cssVariables() );

		$this->injectCustomCode();

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
		$screen = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

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

	private function ownPhone(): string {
		$key  = $this->settings->str( 'phone_meta_key', 'signa_phone' );
		$user = get_current_user_id();

		if ( ! $user || '' === trim( $key ) ) {
			return '';
		}

		return trim( (string) get_user_meta( $user, $key, true ) );
	}

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

	public function clientConfig(): array {
		return array(
			'restUrl'    => esc_url_raw( rest_url( 'signa/v1/' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'configUrl'  => esc_url_raw( rest_url( 'signa/v1/form-config' ) ),
			'cacheMode'  => $this->settings->str( 'cache_mode', 'auto' ),
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

		return (bool) apply_filters( 'signa_should_load_assets', false );
	}

	private function hasCustomCode(): bool {
		return '' !== trim( $this->settings->str( 'custom_css' ) ) || '' !== trim( $this->settings->str( 'custom_js' ) );
	}

	public function variables(): array {
		$accent  = $this->settings->str( 'accent', '#0f766e' );
		$surface = $this->settings->str( 'surface', '#ffffff' );
		$radius  = max( 0, min( 40, $this->settings->int( 'radius', 14 ) ) );
		$width   = max( 280, min( 900, $this->settings->int( 'width', 420 ) ) );

		return array(
			'signa-accent'        => $accent,
			'signa-accent-strong' => $this->mix( $accent, '#000000', 0.22 ),
			'signa-accent-soft'   => $this->rgba( $accent, 0.14 ),
			'signa-surface'       => '#ffffff' === strtolower( $surface ) ? '' : $surface,
			'signa-radius'        => $radius . 'px',
			'signa-width'         => $width . 'px',
		);
	}

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

	private function rgba( string $hex, float $alpha ): string {
		$rgb = $this->toRgb( $hex );

		if ( null === $rgb ) {
			return $hex;
		}

		return sprintf( 'rgba(%d, %d, %d, %s)', $rgb[0], $rgb[1], $rgb[2], rtrim( rtrim( number_format( $alpha, 3, '.', '' ), '0' ), '.' ) );
	}

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
