<?php
/**
 * Google reCAPTCHA v3 (score based, invisible).
 *
 * @package TisaOtp
 */

namespace TisaOtp\Captcha;

use TisaOtp\Config\Settings;
use TisaOtp\Log\Logger;

defined( 'ABSPATH' ) || exit;

final class RecaptchaV3 implements CaptchaProvider {

	const ENDPOINT = 'https://www.google.com/recaptcha/api/siteverify';

	/** @var Settings */
	private $settings;

	/** @var Logger */
	private $logger;

	public function __construct( Settings $settings, Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	public function id(): string {
		return 'recaptcha_v3';
	}

	public function label(): string {
		return __( 'reCAPTCHA v3', 'tisa-otp' );
	}

	public function scriptUrl(): string {
		$key = $this->settings->str( 'captcha_site_key' );

		return '' === $key ? '' : 'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( $key );
	}

	public function clientConfig(): array {
		return array(
			'siteKey' => $this->settings->str( 'captcha_site_key' ),
			'action'  => 'tisa_otp_send',
			'kind'    => 'score',
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
			return CaptchaResult::failed( 'captcha_rejected', __( 'کپچا تأیید نشد. لطفاً دوباره تلاش کنید.', 'tisa-otp' ) );
		}

		$score     = isset( $body['score'] ) ? (float) $body['score'] : 1.0;
		$threshold = (float) $this->settings->str( 'captcha_score', '0.5' );

		if ( $score < $threshold ) {
			$this->logger->notice( 'captcha.low_score', array( 'gateway' => $this->id(), 'score' => $score, 'threshold' => $threshold ) );

			return CaptchaResult::failed( 'captcha_low_score', __( 'امتیاز امنیتی این درخواست پایین است.', 'tisa-otp' ), $score );
		}

		return CaptchaResult::passed( $score );
	}
}
