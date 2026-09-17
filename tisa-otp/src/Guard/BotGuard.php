<?php
/**
 * Cheap robot heuristics: honeypot field and submission timing.
 *
 * A filled honeypot produces a *silent* rejection so the caller can answer with
 * a convincing fake success instead of teaching the bot what went wrong.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Guard;

use TisaOtp\Http\Request;
use TisaOtp\Support\Rejection;

defined( 'ABSPATH' ) || exit;

final class BotGuard implements Guard {

	const HONEYPOT  = 'tisa_hp';
	const TIMESTAMP = 'tisa_ts';

	public function name(): string {
		return 'bot';
	}

	public function stages(): array {
		return array( 'send', 'register' );
	}

	public function inspect( Request $request, string $stage ): void {
		if ( '' !== $request->str( self::HONEYPOT ) ) {
			throw Rejection::make(
				'robot',
				__( 'درخواست شما به‌عنوان ربات شناسایی شد.', 'tisa-otp' ),
				array( 'silent' => true )
			);
		}

		$rendered = $request->int( self::TIMESTAMP );

		if ( $rendered <= 0 ) {
			return;
		}

		$age = time() - $rendered;

		if ( $age < 2 ) {
			throw Rejection::make( 'too_fast', __( 'فرم سریع‌تر از حد معمول ارسال شد. لطفاً دوباره تلاش کنید.', 'tisa-otp' ) );
		}

		if ( $age > 2 * HOUR_IN_SECONDS ) {
			throw Rejection::make( 'stale_form', __( 'نشست فرم منقضی شده است. لطفاً صفحه را تازه کنید.', 'tisa-otp' ) );
		}
	}
}
