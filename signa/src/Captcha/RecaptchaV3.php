<?php
/**
 * Google reCAPTCHA v3 (score based, invisible).
 *
 * `www.google.com` is unreachable from a good part of the world (Iran, China,
 * some corporate networks). When the browser cannot load the script the visitor
 * used to hit a dead end: the form refused the send with "prove you are not a
 * robot" while showing no challenge at all. Two things changed:
 *
 *   1. `recaptcha.net` — Google's own mirror — is offered as a fallback URL and
 *      the browser walks the list until one loads (see ScriptFallbacks).
 *   2. A failure to reach the service no longer blocks sign-in: the guard falls
 *      back to the honeypot and the throttle and logs `captcha.fail_open`.
 *
 * @package Signa
 */

namespace Signa\Captcha;

use Signa\Config\Settings;
use Signa\Log\Logger;

defined( 'ABSPATH' ) || exit;

final class RecaptchaV3 implements CaptchaProvider, ScriptFallbacks {

	const ENDPOINT = 'https://www.google.com/recaptcha/api/siteverify';
	const MIRROR   = 'https://recaptcha.net/recaptcha/api/siteverify';

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
		return __( 'reCAPTCHA v3', 'signa' );
	}

	public function scriptUrl(): string {
		$key = trim( $this->settings->str( 'captcha_site_key' ) );

		return '' === $key ? '' : 'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( $key );
	}

	/**
	 * @return string[]
	 */
	public function fallbackScriptUrls(): array {
		$key = trim( $this->settings->str( 'captcha_site_key' ) );

		return '' === $key ? array() : array( 'https://recaptcha.net/recaptcha/api.js?render=' . rawurlencode( $key ) );
	}

	public function clientConfig(): array {
		return array(
			'siteKey' => trim( $this->settings->str( 'captcha_site_key' ) ),
			'action'  => 'signa_send',
			'kind'    => 'score',
		);
	}

	public function verify( string $token, string $ip ): CaptchaResult {
		$response = $this->request( self::ENDPOINT, $token, $ip );

		if ( is_wp_error( $response ) ) {
			// Google proper is filtered here more often than it is down.
			$response = $this->request( self::MIRROR, $token, $ip );
		}

		if ( is_wp_error( $response ) ) {
			$this->logger->warning( 'captcha.transport_failed', array( 'gateway' => $this->id(), 'error_code' => $response->get_error_code() ) );

			return CaptchaResult::failed( 'captcha_unreachable', __( 'ارتباط با سرویس کپچا برقرار نشد.', 'signa' ) );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return CaptchaResult::failed( 'captcha_bad_response', __( 'پاسخ سرویس کپچا قابل خواندن نیست.', 'signa' ) );
		}

		if ( empty( $body['success'] ) ) {
			$codes = isset( $body['error-codes'] ) ? array_map( 'strval', (array) $body['error-codes'] ) : array();

			$this->logger->notice( 'captcha.rejected', array( 'gateway' => $this->id(), 'reason' => implode( ',', array_slice( $codes, 0, 3 ) ) ) );

			if ( in_array( 'invalid-input-secret', $codes, true ) || in_array( 'missing-input-secret', $codes, true ) ) {
				return CaptchaResult::failed( 'captcha_misconfigured', __( 'کلیدهای reCAPTCHA درست نیستند. با مدیر سایت تماس بگیرید.', 'signa' ) );
			}

			if ( in_array( 'timeout-or-duplicate', $codes, true ) ) {
				return CaptchaResult::failed( 'captcha_expired', __( 'اعتبار کپچا منقضی شده است. لطفاً دوباره تلاش کنید.', 'signa' ) );
			}

			return CaptchaResult::failed( 'captcha_rejected', __( 'کپچا تأیید نشد. لطفاً دوباره تلاش کنید.', 'signa' ) );
		}

		/*
		 * Google's advice for v3: check that the token was made for this
		 * action. A token minted by another form on the same site key
		 * (a comment form, a newsletter box) must not unlock an SMS.
		 */
		if ( isset( $body['action'] ) && is_string( $body['action'] ) && '' !== $body['action'] && 'signa_send' !== $body['action'] ) {
			$this->logger->notice( 'captcha.wrong_action', array( 'gateway' => $this->id(), 'action' => substr( sanitize_key( $body['action'] ), 0, 40 ) ) );

			return CaptchaResult::failed( 'captcha_rejected', __( 'کپچا تأیید نشد. لطفاً دوباره تلاش کنید.', 'signa' ) );
		}

		/*
		 * A v3 answer always carries a score. None means the keys belong to a
		 * reCAPTCHA v2 site, which cannot be judged here: say so instead of
		 * silently scoring every request 1.0.
		 */
		if ( ! isset( $body['score'] ) ) {
			$this->logger->warning( 'captcha.no_score', array( 'gateway' => $this->id() ) );

			return CaptchaResult::failed( 'captcha_misconfigured', __( 'کلیدهای reCAPTCHA مربوط به نسخهٔ ۳ نیستند. با مدیر سایت تماس بگیرید.', 'signa' ) );
		}

		$score     = (float) $body['score'];
		$threshold = (float) $this->settings->str( 'captcha_score', '0.5' );

		if ( $score < $threshold ) {
			$this->logger->notice( 'captcha.low_score', array( 'gateway' => $this->id(), 'score' => $score, 'threshold' => $threshold ) );

			return CaptchaResult::failed( 'captcha_low_score', __( 'امتیاز امنیتی این درخواست پایین است.', 'signa' ), $score );
		}

		return CaptchaResult::passed( $score );
	}

	/**
	 * @return array|\WP_Error
	 */
	private function request( string $url, string $token, string $ip ) {
		return wp_remote_post(
			$url,
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => $this->settings->str( 'captcha_secret_key' ),
					'response' => $token,
					'remoteip' => $ip,
				),
			)
		);
	}
}
