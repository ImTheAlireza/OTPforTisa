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

	/**
	 * The browser's own report that the challenge never became usable.
	 *
	 * Only the browser can observe this: the script its own page loads, or the
	 * network call `grecaptcha.execute()` makes, can fail while the server's own
	 * verification call would still succeed — so no server-side check can find
	 * it. A visitor in that state used to be rejected as if they were a robot,
	 * and their rejection was counted against the site's captcha health.
	 *
	 * The claim is honoured **only** when the administrator turned «باز ماندن
	 * ورود» on, which is their own statement that an outage must not lock people
	 * out. With that setting off it is worth nothing, so forging it buys exactly
	 * what the setting already grants, and every use is logged and counted.
	 */
	const STATE_UNAVAILABLE = 'unavailable';

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

		/*
		 * The browser could not obtain a challenge at all. With fail-open on this
		 * is the same emergency as an unreachable service, and it is recorded the
		 * same way — with the reason that says which side of the wire was down.
		 */
		if ( 'captcha_missing' === $result->errorCode() && $this->browserOutage( $request ) ) {
			$this->logger->warning(
				'captcha.fail_open',
				array(
					'error_code' => 'captcha_missing',
					'reason'     => 'browser_unavailable',
					'phone'      => $request->phone(),
					'ip'         => $request->ip(),
					'ua'         => $this->shorten( $request->userAgent() ),
				)
			);

			/**
			 * Fires when a captcha the browser could not load lets a request through.
			 *
			 * @param string  $reason  Why the challenge never appeared.
			 * @param Request $request Incoming request.
			 */
			do_action( 'tisa_otp_captcha_fail_open', 'browser_unavailable', $request );

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
	 * Did the browser say the challenge could not be obtained there?
	 */
	private function browserOutage( Request $request ): bool {
		if ( ! $this->captcha->failOpen() ) {
			return false;
		}

		return self::STATE_UNAVAILABLE === strtolower( trim( $request->str( 'captcha_state' ) ) );
	}

	/**
	 * Two hundred characters of user agent, for the diagnosis only.
	 */
	private function shorten( string $ua ): string {
		return substr( trim( $ua ), 0, 200 );
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
