<?php

namespace Signa\Captcha;

defined( 'ABSPATH' ) || exit;

final class CaptchaResult {
	private $passed;
	private $errorCode;
	private $message;
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

		return '' !== $this->message ? $this->message : __( 'اعتبارسنجی کپچا ناموفق بود. لطفاً دوباره تلاش کنید.', 'signa' );
	}
}
