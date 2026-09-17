<?php
/**
 * ARCaptcha (Iranian captcha service).
 *
 * @package TisaOtp
 */

namespace TisaOtp\Captcha;

use TisaOtp\Config\Settings;
use TisaOtp\Log\Logger;

defined( 'ABSPATH' ) || exit;

final class Arcaptcha implements CaptchaProvider {

	const ENDPOINT = 'https://api.arcaptcha.co/arcaptcha/api/verify';

	/** @var Settings */
	private $settings;

	/** @var Logger */
	private $logger;

	public function __construct( Settings $settings, Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	public function id(): string {
		return 'arcaptcha';
	}

	public function label(): string {
		return __( 'آرکپچا', 'tisa-otp' );
	}

	public function scriptUrl(): string {
		return 'https://widget.arcaptcha.ir/1/api.js';
	}

	public function clientConfig(): array {
		return array(
			'siteKey' => $this->settings->str( 'captcha_site_key' ),
			'kind'    => 'widget',
			'lang'    => 'fa',
		);
	}

	public function verify( string $token, string $ip ): CaptchaResult {
		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'headers'     => array( 'Content-Type' => 'application/json' ),
				'body'        => wp_json_encode(
					array(
						'site_key'     => $this->settings->str( 'captcha_site_key' ),
						'secret_key'   => $this->settings->str( 'captcha_secret_key' ),
						'challenge_id' => $token,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->logger->warning( 'captcha.transport_failed', array( 'gateway' => $this->id(), 'error_code' => $response->get_error_code() ) );

			return CaptchaResult::failed( 'captcha_unreachable', __( 'ارتباط با سرویس کپچا برقرار نشد.', 'tisa-otp' ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 || ! is_array( $body ) ) {
			return CaptchaResult::failed( 'captcha_bad_response', __( 'پاسخ سرویس کپچا معتبر نبود.', 'tisa-otp' ) );
		}

		if ( empty( $body['success'] ) ) {
			return CaptchaResult::failed( 'captcha_rejected', __( 'کپچا تأیید نشد. لطفاً دوباره حل کنید.', 'tisa-otp' ) );
		}

		return CaptchaResult::passed();
	}
}
