<?php
/**
 * Captcha verification outcome.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Captcha;

defined( 'ABSPATH' ) || exit;

final class CaptchaResult {

	/** @var bool */
	private $passed;

	/** @var string */
	private $errorCode;

	/** @var string */
	private $message;

	/** @var float */
	private $score;

	private function __construct( bool $passed, string $errorCode = '', string $message = '', float $score = 0.0 ) {
		$this->passed    = $passed;
		$this->errorCode = $errorCode;
		$this->message   = $message;
		$this->score     = $score;
	}

	public static function passed( float $score = 1.0 ): self {
		return new self( true, '', '', $score );
	}

	public static function failed( string $errorCode, string $message = '', float $score = 0.0 ): self {
		return new self( false, $errorCode, $message, $score );
	}

	/**
	 * Named `isPassed()` rather than `passed()` because the static factory
	 * above already owns that name — same split as GatewayResult::sent() and
	 * GatewayResult::isSent().
	 */
	public function isPassed(): bool {
		return $this->passed;
	}

	public function errorCode(): string {
		return $this->errorCode;
	}

	public function score(): float {
		return $this->score;
	}

	public function message(): string {
		if ( $this->passed ) {
			return '';
		}

		return '' !== $this->message ? $this->message : __( 'اعتبارسنجی کپچا ناموفق بود. لطفاً دوباره تلاش کنید.', 'tisa-otp' );
	}
}
