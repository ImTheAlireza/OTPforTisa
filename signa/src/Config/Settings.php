<?php
/**
 * Typed access to the single plugin option row.
 *
 * @package Signa
 */

namespace Signa\Config;

defined( 'ABSPATH' ) || exit;

final class Settings {

	const OPTION = 'signa_settings';

	/** @var array<string,mixed>|null */
	private $values;

	public function all(): array {
		if ( null === $this->values ) {
			$stored       = get_option( self::OPTION, array() );
			$this->values = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}

		return $this->values;
	}

	/**
	 * @return mixed
	 */
	/**
	 * Put unsaved values in front of the stored ones, for this request only.
	 *
	 * The form preview draws the real template with what the owner has typed
	 * but not saved; nothing here touches the database.
	 *
	 * @param array<string,mixed> $values Already sanitised values.
	 */
	public function preview( array $values ): void {
		$this->values = array_merge( $this->all(), $values );
	}

	public function get( string $key, $default = null ) {
		$all = $this->all();

		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}

		return $default;
	}

	public function str( string $key, string $default = '' ): string {
		$value = $this->get( $key, $default );

		return is_scalar( $value ) ? (string) $value : $default;
	}

	public function bool( string $key, bool $default = false ): bool {
		$value = $this->get( $key, $default );

		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( (string) $value, array( '1', 'yes', 'on', 'true' ), true );
	}

	public function int( string $key, int $default = 0 ): int {
		$value = $this->get( $key, $default );

		return is_numeric( $value ) ? (int) $value : $default;
	}

	public function arr( string $key ): array {
		$value = $this->get( $key, array() );

		return is_array( $value ) ? $value : array();
	}

	/**
	 * Comma separated option → trimmed list.
	 *
	 * @return string[]
	 */
	public function items( string $key ): array {
		$raw = $this->str( $key, '' );

		if ( '' === trim( $raw ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
	}

	/**
	 * Sanitise and persist a full or partial settings payload.
	 */
	public function replace( array $input ): bool {
		$merged       = Sanitizer::sanitize( $input, $this->all() );
		$this->values = $merged;

		$saved = update_option( self::OPTION, $merged, false );

		/**
		 * Fires after settings have been written.
		 *
		 * @param array $merged  New values.
		 * @param array $input   Raw submitted values.
		 */
		do_action( 'signa_settings_saved', $merged, $input );

		return $saved;
	}

	public function forget(): void {
		$this->values = null;
	}

	public static function defaults(): array {
		return array(
			// Switch & behaviour.
			'enabled'                => '1',
			'auth_mode'              => 'smart',
			'auto_register'          => '1',
			'default_role'           => 'subscriber',
			'login_redirect'         => '',
			'register_redirect'      => '',
			'replace_wp_login'       => '0',
			'cache_mode'             => 'auto',
			'prevent_enumeration'    => '1',
			'guard_roles'            => '1',
			'guarded_roles'          => 'administrator,editor,shop_manager',

			// One-time code.
			'code_length'            => '5',
			'code_ttl'               => '120',
			'code_store'             => 'database',
			// A 14-day cookie behind a one-time code: the site chooses, and the
			// default keeps behaving the way every earlier version did.
			'remember_login'         => '1',
			'password_login_off'     => '0',

			'verify_attempts'        => '5',
			'resend_delay'           => '60',
			'auto_verify'            => '1',
			'webotp_enabled'         => '0',
			'request_timeout'        => '15',

			// Channels.
			'channel'                => 'sms',
			'channels_enabled'       => array( 'sms' ),
			'failover_enabled'       => '1',
			'sms_template'           => 'کد ورود {site}: {code}',
			'email_subject'          => 'کد ورود یکبارمصرف',
			'email_body'             => "کد ورود شما: {code}\nاین کد تا {minutes} دقیقه اعتبار دارد.",
			'email_from'             => '',

			// Gateways.
			'sms_gateway'            => 'smsir',
			// Off by default: it bypasses the site's own outbound block, so it
			// is the owner's decision to make, not ours.
			'direct_send'            => '0',
			'sms_backup_gateway'     => '',
			'smsir_api_key'          => '',
			'smsir_template_id'      => '',
			'smsir_sender'           => '',
			'kavenegar_api_key'      => '',
			'kavenegar_template'     => '',
			'kavenegar_sender'       => '',
			'meli_username'          => '',
			'meli_password'          => '',
			'meli_from'              => '',
			'ippanel_api_key'        => '',
			'ippanel_pattern'        => '',
			'ippanel_sender'         => '',
			'faraz_username'         => '',
			'faraz_password'         => '',
			'faraz_from'             => '',
			'faraz_pattern'          => '',

			// Throttling.
			'throttle_enabled'       => '1',
			'window_minutes'         => '60',
			'limit_per_phone'        => '5',
			'limit_per_ip'           => '12',
			'limit_per_ip_daily'     => '60',
			// The site's own ceiling, so a distributed flood cannot spend the
			// whole credit line before anyone notices. 0 turns it off.
			'limit_per_site_daily'   => '300',
			'limit_verify_per_ip'    => '25',
			'proxy_mode'             => 'none',
			'trusted_proxies'        => '',
			// Numbers that should not be stopped by the traffic guards. Off by
			// default: an allowlist that nobody turned on is a hole, not a feature.
			'trusted_enabled'        => '0',
			'trusted_numbers'        => '',
			'trusted_skip'           => 'captcha,throttle',

			// Captcha.
			'captcha_provider'       => 'none',
			'captcha_site_key'       => '',
			'captcha_secret_key'     => '',
			'captcha_score'          => '0.5',
			'captcha_trigger'        => 'always',
			'captcha_fail_open'      => '1',
			'captcha_timeout'        => '8000',
			'captcha_script_override'=> '',
			'captcha_arcaptcha_v3'   => '0',

			// Registration.
			'registration_enabled'   => '1',
			'registration_flow'      => 'fields_then_code',
			'field_preset'           => 'minimal',
			'fields'                 => array(),
			'username_from'          => 'phone',
			'display_name_from'      => 'full_name',
			'email_mode'             => 'optional',
			// WoodMart: the header's sign-in panel. On by default because the panel
			// only exists on a WoodMart site, and because it is the one place a
			// WoodMart visitor tries to sign in.
			'woodmart_sidebar'       => '1',
			'woodmart_mode'          => 'replace',
			'woodmart_account_block' => '1',

			'woo_account_form'       => '1',
			'woo_checkout_gate'      => '0',
			'woo_checkout_notice'    => 'برای ادامه خرید و مشاهده صفحه تسویه حساب، ابتدا وارد حساب کاربری شوید.',
			'woo_checkout_page'      => '',
			'sync_billing_phone'     => '1',
			'link_guest_orders'      => '1',
			'send_welcome_email'     => '0',
			'form_heading'           => 'ورود یا عضویت',
			'form_subheading'        => 'شماره موبایل خود را وارد کنید تا کد تأیید برایتان ارسال شود.',
			'register_heading'       => 'تکمیل اطلاعات',
			'register_subheading'    => 'برای ساخت حساب کاربری، اطلاعات زیر را کامل کنید.',

			// Appearance.
			'skin'                   => 'line',
			// The form ships with its own Persian font (assets/fonts, OFL). A theme
			// whose font has no Persian glyphs otherwise decides how the form looks,
			// which is how "your plugin looks different from your preview" happens.
			'form_font'              => 'vazirmatn',
			'form_font_custom'       => '',
			// The form is rendered inside a shadow root so the theme's CSS cannot
			// restyle it. Off means "let the theme in", which is a choice, not a bug.
			'style_isolation'        => '1',
			'accent'                 => '#0f766e',
			'surface'                => '#ffffff',
			'radius'                 => '14',
			'width'                  => '420',
			'align'                  => 'center',
			'show_brand'             => '1',
			'brand_logo'             => '',
			'brand_width'            => '110',
			'code_input'             => 'boxes',
			'label_send'             => 'دریافت کد تأیید',
			'label_verify'           => 'ورود به حساب',
			'label_resend'           => 'ارسال دوباره کد',
			'label_edit_phone'       => 'ویرایش شماره',
			'terms_enabled'          => '0',
			'terms_text'             => 'ورود به معنای پذیرش قوانین سایت است.',
			'terms_url'              => '',
			'custom_css'             => '',
			'custom_js'              => '',
			'custom_code_scope'      => 'form_pages',

			// Data, logs & housekeeping.
			'logs_enabled'           => '1',
			'logs_keep_days'         => '7',
			'logs_max_rows'          => '200000',
			'debug'                  => '0',
			'phone_meta_key'         => 'signa_phone',
			'lookup_meta_keys'       => 'billing_phone,digits_phone,digits_phone_no',
			'wipe_on_uninstall'      => '0',
		);
	}
}
