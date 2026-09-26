<?php

namespace Signa\Captcha;

use Signa\Config\Settings;
use Signa\Log\Logger;

defined( 'ABSPATH' ) || exit;

final class Arcaptcha implements CaptchaProvider, ScriptFallbacks {
	const ENDPOINT  = 'https://api.arcaptcha.co/arcaptcha/api/verify';
	const WIDGET_JS = 'https://widget.arcaptcha.ir/1/api.js';
	const SCORE_JS  = 'https://widget.arcaptcha.ir/3/api.js';
	const WIDGET_JS_NEW = 'https://nwidget.arcaptcha.ir/1/api.js';
	const SCORE_JS_NEW  = 'https://nwidget.arcaptcha.ir/3/api.js';
	private $settings;
	private $logger;

	public function __construct( Settings $settings, Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	public function id(): string {
		return 'arcaptcha';
	}

	public function label(): string {
		return __( 'آرکپچا', 'signa' );
	}

	public function scriptUrl(): string {
		$key = $this->siteKey();

		if ( '' === $key ) {
			return '';
		}

		return $this->isScore() ? self::SCORE_JS . '?render=' . rawurlencode( $key ) : self::WIDGET_JS;
	}

	public function fallbackScriptUrls(): array {
		$key = $this->siteKey();

		if ( '' === $key ) {
			return array();
		}

		$key = rawurlencode( $key );

		return $this->isScore()
			? array(
				self::SCORE_JS_NEW . '?render=' . $key,
				'https://widget.arcaptcha.co/3/api.js?render=' . $key,
			)
			: array(
				self::WIDGET_JS_NEW,
				'https://widget.arcaptcha.co/1/api.js',
			);
	}

	public function clientConfig(): array {
		return array(
			'siteKey' => $this->siteKey(),
			'kind'    => $this->isScore() ? 'score' : 'widget',
			'lang'    => 'fa',
			'dir'     => 'rtl',
			'theme'   => 'light',
			'action'  => 'signa_send',
		);
	}

	public function verify( string $token, string $ip ): CaptchaResult {
		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'        => wp_json_encode(
					array(
						'site_key'     => $this->siteKey(),
						'secret_key'   => $this->settings->str( 'captcha_secret_key' ),
						'challenge_id' => $token,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->logger->warning( 'captcha.transport_failed', array( 'gateway' => $this->id(), 'error_code' => $response->get_error_code() ) );

			return CaptchaResult::failed( 'captcha_unreachable', __( 'ارتباط با سرویس کپچا برقرار نشد.', 'signa' ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 || ! is_array( $body ) ) {
			$this->logger->warning( 'captcha.bad_response', array( 'gateway' => $this->id(), 'status' => $status ) );

			return CaptchaResult::failed( 'captcha_bad_response', __( 'پاسخ سرویس کپچا معتبر نبود.', 'signa' ) );
		}

		if ( empty( $body['success'] ) ) {
			$codes = isset( $body['error-codes'] ) ? (array) $body['error-codes'] : array();
			$codes = array_map( 'strval', array_slice( $codes, 0, 3 ) );

			$this->logger->notice( 'captcha.rejected', array( 'gateway' => $this->id(), 'reason' => implode( ',', $codes ) ) );

			if ( array() !== array_intersect( array( 'invalid-input-secret', 'missing-input-secret', 'invalid-input-sitekey', 'missing-input-sitekey' ), $codes ) ) {
				return CaptchaResult::failed( 'captcha_misconfigured', __( 'کلیدهای آرکپچا درست نیستند. با مدیر سایت تماس بگیرید.', 'signa' ) );
			}

			if ( in_array( 'timeout-or-duplicate', $codes, true ) ) {
				return CaptchaResult::failed( 'captcha_expired', __( 'اعتبار کپچا منقضی شده است. لطفاً دوباره تأیید کنید.', 'signa' ) );
			}

			return CaptchaResult::failed( 'captcha_rejected', __( 'کپچا تأیید نشد. لطفاً دوباره حل کنید.', 'signa' ) );
		}

		return CaptchaResult::passed();
	}

	private function siteKey(): string {
		return trim( $this->settings->str( 'captcha_site_key' ) );
	}

	private function isScore(): bool {
		return $this->settings->bool( 'captcha_arcaptcha_v3', false );
	}
}
