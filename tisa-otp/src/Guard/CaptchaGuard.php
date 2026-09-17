<?php
/**
 * Captcha challenge, either always or once a visitor has burned some quota.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Guard;

use TisaOtp\Captcha\Manager;
use TisaOtp\Http\Request;
use TisaOtp\Support\Rejection;
use TisaOtp\Throttle\Throttle;

defined( 'ABSPATH' ) || exit;

final class CaptchaGuard implements Guard {

	const TOKEN_KEYS = array( 'captcha_token', 'captcha-token', 'g-recaptcha-response', 'h-captcha-response', 'arcaptcha_token' );

	/** @var Manager */
	private $captcha;

	/** @var Throttle */
	private $throttle;

	public function __construct( Manager $captcha, Throttle $throttle ) {
		$this->captcha  = $captcha;
		$this->throttle = $throttle;
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

		if ( $result->passed() ) {
			return;
		}

		throw Rejection::make(
			$result->errorCode(),
			$result->message(),
			array(
				'captcha_required' => true,
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
