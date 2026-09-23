<?php
/**
 * Cheap robot heuristics: honeypot field and submission timing.
 *
 * A filled honeypot produces a *silent* rejection so the caller can answer with
 * a convincing fake success instead of teaching the bot what went wrong.
 *
 * Timing used to rely on the raw `time()` value printed into the page, which a
 * full-page cache freezes. On a warm cache every visitor was told "the form
 * expired, please reload" and the send never reached a gateway — the single
 * most likely reason a working gateway looked broken. The browser now sends a
 * signed token minted by `/form-config` (never cached), and the raw timestamp
 * is only a fallback for markup rendered by an older version of the plugin.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Guard;

use TisaOtp\Http\Request;
use TisaOtp\Support\FormToken;
use TisaOtp\Support\Rejection;

defined( 'ABSPATH' ) || exit;

final class BotGuard implements Guard {

	const HONEYPOT  = 'tisa_hp';
	const TIMESTAMP = 'tisa_ts';

	/** Fallback window for the legacy unsigned timestamp. */
	const LEGACY_MAX_AGE = 7200;

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

		$token = $request->str( FormToken::KEY );

		if ( '' !== $token ) {
			$this->inspectToken( $token );

			return;
		}

		$this->inspectTimestamp( $request->int( self::TIMESTAMP ) );
	}

	/**
	 * The signed path: signature first, age second.
	 */
	private function inspectToken( string $token ): void {
		$verdict = FormToken::inspect( $token );

		if ( ! $verdict['valid'] ) {
			// Expired, replayed across a cache boundary or edited by hand. The
			// client answers this by pulling a fresh token and retrying once, so
			// a real visitor never sees it twice.
			throw Rejection::make(
				'stale_form',
				__( 'نشست فرم منقضی شده است. لطفاً دوباره تلاش کنید.', 'tisa-otp' ),
				array( 'recoverable' => true, 'reason' => $verdict['reason'] )
			);
		}

		if ( $verdict['age'] < FormToken::minAge() ) {
			throw Rejection::make(
				'too_fast',
				__( 'فرم سریع‌تر از حد معمول ارسال شد. لطفاً دوباره تلاش کنید.', 'tisa-otp' ),
				array( 'recoverable' => true )
			);
		}
	}

	/**
	 * Legacy path, for cached markup that predates the signed token.
	 *
	 * A form rendered by this plugin always carries one of the two: the signed
	 * token, or the hidden timestamp the previous versions printed. A request
	 * with neither did not come from our form — most often because somebody
	 * posted the endpoint directly — so it is refused, and `stale_form` is the
	 * refusal the client already knows how to recover from: it fetches a fresh
	 * config and posts once more. A real visitor on a cached page never sees it.
	 */
	private function inspectTimestamp( int $rendered ): void {
		if ( $rendered <= 0 ) {
			throw Rejection::make(
				'stale_form',
				__( 'نشست فرم منقضی شده است. لطفاً دوباره تلاش کنید.', 'tisa-otp' ),
				array( 'recoverable' => true, 'reason' => 'no_token' )
			);
		}

		$age = time() - $rendered;

		if ( $age < 0 ) {
			return;
		}

		if ( $age < 2 ) {
			throw Rejection::make(
				'too_fast',
				__( 'فرم سریع‌تر از حد معمول ارسال شد. لطفاً دوباره تلاش کنید.', 'tisa-otp' ),
				array( 'recoverable' => true )
			);
		}

		if ( $age > self::LEGACY_MAX_AGE ) {
			throw Rejection::make(
				'stale_form',
				__( 'نشست فرم منقضی شده است. لطفاً صفحه را تازه کنید.', 'tisa-otp' ),
				array( 'recoverable' => true )
			);
		}
	}
}
