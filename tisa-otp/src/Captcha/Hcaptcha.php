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

final class Hcaptcha implements CaptchaProvider {

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
		return 'https://js.hcaptcha.com/1/api.js?render=explicit';
	}

	public function clientConfig(): array {
		return array(
			'siteKey' => $this->settings->str( 'captcha_site_key' ),
			'kind'    => 'widget',
			'lang'    => get_locale(),
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

		if ( ! is_array( $body ) || empty( $body['success'] ) ) {
			return CaptchaResult::failed( 'captcha_rejected', __( 'کپچا تأیید نشد. لطفاً دوباره تلاش کنید.', 'tisa-otp' ) );
		}

		return CaptchaResult::passed();
	}
}
