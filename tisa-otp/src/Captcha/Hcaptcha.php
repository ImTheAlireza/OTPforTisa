<?php
/**
 * hCaptcha (checkbox or invisible, privacy friendly).
 *
 * @package TisaOtp
 */

namespace TisaOtp\Captcha;

use TisaOtp\Config\Settings;
use TisaOtp\Log\Logger;

defined( 'ABSPATH' ) || exit;

final class Hcaptcha implements CaptchaProvider, ScriptFallbacks {

	const ENDPOINT = 'https://api.hcaptcha.com/siteverify';

	/** @var Settings */
	private $settings;

	/** @var Logger */
	private $logger;

	public function __construct( Settings $settings, Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	public function id(): string {
		return 'hcaptcha';
	}

	public function label(): string {
		return __( 'hCaptcha', 'tisa-otp' );
	}

	public function scriptUrl(): string {
		return 'https://js.hcaptcha.com/1/api.js?render=explicit&recaptchacompat=off';
	}

	/**
	 * The compatibility bundle auto-renders every `.h-captcha` element; it is the
	 * second chance when the explicit bundle is blocked or cached badly.
	 *
	 * @return string[]
	 */
	public function fallbackScriptUrls(): array {
		return array( 'https://js.hcaptcha.com/1/api.js' );
	}

	public function clientConfig(): array {
		return array(
			'siteKey' => trim( $this->settings->str( 'captcha_site_key' ) ),
			'kind'    => 'widget',
			'lang'    => str_replace( '_', '-', get_locale() ),
		);
	}

	public function verify( string $token, string $ip ): CaptchaResult {
		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => $this->settings->str( 'captcha_secret_key' ),
					'response' => $token,
					'sitekey'  => trim( $this->settings->str( 'captcha_site_key' ) ),
					'remoteip' => $ip,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->logger->warning( 'captcha.transport_failed', array( 'gateway' => $this->id(), 'error_code' => $response->get_error_code() ) );

			return CaptchaResult::failed( 'captcha_unreachable', __( 'ارتباط با سرویس کپچا برقرار نشد.', 'tisa-otp' ) );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return CaptchaResult::failed( 'captcha_bad_response', __( 'پاسخ سرویس کپچا قابل خواندن نیست.', 'tisa-otp' ) );
		}

		if ( empty( $body['success'] ) ) {
			$codes = isset( $body['error-codes'] ) ? array_map( 'strval', (array) $body['error-codes'] ) : array();

			$this->logger->notice( 'captcha.rejected', array( 'gateway' => $this->id(), 'reason' => implode( ',', array_slice( $codes, 0, 3 ) ) ) );

			if ( in_array( 'invalid-input-secret', $codes, true ) || in_array( 'missing-input-secret', $codes, true ) ) {
				return CaptchaResult::failed( 'captcha_misconfigured', __( 'کلیدهای hCaptcha درست نیستند. با مدیر سایت تماس بگیرید.', 'tisa-otp' ) );
			}

			if ( in_array( 'expired-input-response', $codes, true ) || in_array( 'already-seen-response', $codes, true ) ) {
				return CaptchaResult::failed( 'captcha_expired', __( 'اعتبار کپچا منقضی شده است. لطفاً دوباره تأیید کنید.', 'tisa-otp' ) );
			}

			return CaptchaResult::failed( 'captcha_rejected', __( 'کپچا تأیید نشد. لطفاً دوباره تلاش کنید.', 'tisa-otp' ) );
		}

		return CaptchaResult::passed();
	}
}
