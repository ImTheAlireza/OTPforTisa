<?php

namespace Signa\Guard;

use Signa\Captcha\Manager;
use Signa\Http\Request;
use Signa\Log\Logger;
use Signa\Support\Rejection;
use Signa\Throttle\Throttle;

defined( 'ABSPATH' ) || exit;

final class CaptchaGuard implements Guard {
	const TOKEN_KEYS = array( 'captcha_token', 'captcha-token', 'g-recaptcha-response', 'h-captcha-response', 'arcaptcha_token' );
	const STATE_UNAVAILABLE = 'unavailable';
	const TRANSPORT_ERRORS = array( 'captcha_unreachable', 'captcha_bad_response' );
	const RETRY_ERRORS = array( 'captcha_expired', 'captcha_rejected', 'captcha_missing', 'captcha_low_score' );
	private $captcha;
	private $throttle;
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

			do_action( 'signa_captcha_fail_open', 'browser_unavailable', $request );

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

			do_action( 'signa_captcha_fail_open', $result->errorCode(), $request );

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

	private function browserOutage( Request $request ): bool {
		if ( ! $this->captcha->failOpen() ) {
			return false;
		}

		return self::STATE_UNAVAILABLE === strtolower( trim( $request->str( 'captcha_state' ) ) );
	}

	private function shorten( string $ua ): string {
		return substr( trim( $ua ), 0, 200 );
	}

	private function needed( Request $request ): bool {
		if ( 'always' === $this->captcha->trigger() ) {
			return true;
		}

		$usage = $this->throttle->usage( $request->phone(), $request->ip() );

		return ( $usage['phone'] + 1 ) >= 2 || ( $usage['ip'] + 1 ) >= max( 3, (int) floor( $usage['ip_limit'] / 2 ) );
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
