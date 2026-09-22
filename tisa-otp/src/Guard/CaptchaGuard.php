<?php
/**
 * Captcha challenge, either always or once a visitor has burned some quota.
 *
 * Failure policy matters more than the challenge itself. Three very different
 * things used to produce the same dead end:
 *
 *   - the visitor did not solve anything yet  → ask them to solve it;
 *   - the token was already used or expired   → silently ask again;
 *   - the captcha *service* is unreachable    → NOT the visitor's fault. Blocking
 *     here means a site in Iran with reCAPTCHA configured can never sign anyone
 *     in. That case is let through (honeypot and quotas still apply) and logged,
 *     unless the administrator explicitly turned `captcha_fail_open` off.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Guard;

use TisaOtp\Captcha\Manager;
use TisaOtp\Http\Request;
use TisaOtp\Log\Logger;
use TisaOtp\Support\Rejection;
use TisaOtp\Throttle\Throttle;

defined( 'ABSPATH' ) || exit;

final class CaptchaGuard implements Guard {

	const TOKEN_KEYS = array( 'captcha_token', 'captcha-token', 'g-recaptcha-response', 'h-captcha-response', 'arcaptcha_token' );

	/** Failures that say something about the service, not about the visitor. */
	const TRANSPORT_ERRORS = array( 'captcha_unreachable', 'captcha_bad_response' );

	/** Failures where the visitor should simply solve a fresh challenge. */
	const RETRY_ERRORS = array( 'captcha_expired', 'captcha_rejected', 'captcha_missing', 'captcha_low_score' );

	/** @var Manager */
	private $captcha;

	/** @var Throttle */
	private $throttle;

	/** @var Logger */
	private $logger;

	public function __construct( Manager $captcha, Throttle $throttle, Logger $logger ) {
		$this->captcha  = $captcha;
		$this->throttle = $throttle;
		$this->logger   = $logger;
	}

	public function name(): string {
		return 'captcha';
	}

	public function stages(): array {
		return array( 'send', 'register' );
	}

	public function inspect( Request $request, string $stage ): void {
		if ( ! $this->captcha->isOn() ) {
			return;
		}

		if ( ! $this->needed( $request ) ) {
			return;
		}

		$this->throttle->chargeChallenge( $request->ip() );

		$result = $this->captcha->verify( $this->token( $request ), $request->ip() );

		if ( $result->isPassed() ) {
			return;
		}

		if ( in_array( $result->errorCode(), self::TRANSPORT_ERRORS, true ) && $this->captcha->failOpen() ) {
			$this->logger->warning(
				'captcha.fail_open',
				array(
					'error_code' => $result->errorCode(),
					'phone'      => $request->phone(),
					'ip'         => $request->ip(),
				)
			);

			/**
			 * Fires when a captcha outage lets a request through.
			 *
			 * @param string  $reason  Why the service could not be reached.
			 * @param Request $request Incoming request.
			 */
			do_action( 'tisa_otp_captcha_fail_open', $result->errorCode(), $request );

			return;
		}

		throw Rejection::make(
			$result->errorCode(),
			$result->message(),
			array(
				'captcha_required' => true,
				'captcha_reset'    => in_array( $result->errorCode(), self::RETRY_ERRORS, true ),
				'reason'           => $result->errorCode(),
				'score'            => $result->score(),
			)
		);
	}

	/**
	 * `after_limit` keeps the form frictionless for the first few attempts.
	 */
	private function needed( Request $request ): bool {
		if ( 'always' === $this->captcha->trigger() ) {
			return true;
		}

		$usage = $this->throttle->usage( $request->phone(), $request->ip() );

		return $usage['phone'] >= 2 || $usage['ip'] >= max( 3, (int) floor( $usage['ip_limit'] / 2 ) );
	}

	private function token( Request $request ): string {
		foreach ( self::TOKEN_KEYS as $key ) {
			$value = $request->str( $key );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}
}
