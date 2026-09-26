<?php

namespace Signa\Otp;

defined( 'ABSPATH' ) || exit;

final class VerificationResult {
	const ACCEPTED  = 'accepted';
	const MISSING   = 'missing';
	const EXPIRED   = 'expired';
	const MISMATCH  = 'mismatch';
	const EXHAUSTED = 'exhausted';
	const MALFORMED = 'malformed';
	private $status;
	private $attemptsLeft;

	public function __construct( string $status, int $attemptsLeft = 0 ) {
		$this->status       = $status;
		$this->attemptsLeft = max( 0, $attemptsLeft );
	}

	public static function accepted(): self {
		return new self( self::ACCEPTED );
	}

	public static function rejected( string $status, int $attemptsLeft = 0 ): self {
		return new self( $status, $attemptsLeft );
	}

	public function isAccepted(): bool {
		return self::ACCEPTED === $this->status;
	}

	public function status(): string {
		return $this->status;
	}

	public function attemptsLeft(): int {
		return $this->attemptsLeft;
	}

	public function message(): string {
		switch ( $this->status ) {
			case self::ACCEPTED:
				return __( 'کد تأیید پذیرفته شد.', 'signa' );

			case self::EXPIRED:
			case self::MISSING:
				return __( 'کد تأیید منقضی شده است. لطفاً کد تازه درخواست کنید.', 'signa' );

			case self::EXHAUSTED:
				return __( 'تلاش‌های مجاز برای این کد تمام شد. لطفاً کد تازه بگیرید.', 'signa' );

			case self::MALFORMED:
				return __( 'قالب کد واردشده درست نیست.', 'signa' );

			case self::MISMATCH:
			default:
				return sprintf(
					__( 'کد واردشده درست نیست. %d تلاش باقی مانده است.', 'signa' ),
					$this->attemptsLeft
				);
		}
	}
}
