<?php
/**
 * ARCaptcha (Iranian captcha service).
 *
 * Two client integrations exist and they are not interchangeable:
 *
 *   v2 (widget)  https://widget.arcaptcha.ir/1/api.js
 *                arcaptcha.render(el, {site_key}) → arcaptcha.getArcToken(id)
 *   v3 (score)   https://widget.arcaptcha.ir/3/api.js?render=<sitekey>
 *                arcaptcha.ready(fn) → arcaptcha.execute(sitekey, {action})
 *
 * The old client code called `arcaptcha.widget.render()`, an object this
 * library has never exposed, so the widget silently never appeared. Both shapes
 * are implemented in `assets/js/front.js` and picked by the `kind` handed to
 * the browser below.
 *
 * Verification is unchanged and matches the vendor docs: JSON POST with
 * `challenge_id`, `site_key` and `secret_key`.
 *
 * @package Signa
 */

namespace Signa\Captcha;

use Signa\Config\Settings;
use Signa\Log\Logger;

defined( 'ABSPATH' ) || exit;

final class Arcaptcha implements CaptchaProvider, ScriptFallbacks {

	const ENDPOINT  = 'https://api.arcaptcha.co/arcaptcha/api/verify';
	const WIDGET_JS = 'https://widget.arcaptcha.ir/1/api.js';
	const SCORE_JS  = 'https://widget.arcaptcha.ir/3/api.js';

	/**
	 * The host the vendor's current installation docs load the widget from.
	 *
	 * `widget.arcaptcha.ir` is what their own React and Vue packages still ship
	 * as the default and what this plugin used first; `nwidget.arcaptcha.ir` is
	 * what docs.arcaptcha.co hands out today. Both answer, but which one a given
	 * visitor can reach depends on their network, so both are offered — the
	 * browser walks the list until the library appears.
	 */
	const WIDGET_JS_NEW = 'https://nwidget.arcaptcha.ir/1/api.js';
	const SCORE_JS_NEW  = 'https://nwidget.arcaptcha.ir/3/api.js';

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
		return __( 'آرکپچا', 'signa' );
	}

	public function scriptUrl(): string {
		$key = $this->siteKey();

		if ( '' === $key ) {
			return '';
		}

		return $this->isScore() ? self::SCORE_JS . '?render=' . rawurlencode( $key ) : self::WIDGET_JS;
	}

	/**
	 * Every other host that serves the same bundle.
	 *
	 * `.ir` is the vendor's own host, `.co` answers from outside Iran and has
	 * saved more than one migration. Each kind gets its own version path: a v3
	 * site key cannot render a v2 widget, so crossing them would be worse than
	 * failing.
	 *
	 * @return string[]
	 */
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
