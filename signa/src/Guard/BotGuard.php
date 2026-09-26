<?php

namespace Signa\Guard;

use Signa\Http\Request;
use Signa\Support\FormToken;
use Signa\Support\Rejection;

defined( 'ABSPATH' ) || exit;

final class BotGuard implements Guard {
	const HONEYPOT  = 'signa_hp';
	const TIMESTAMP = 'signa_ts';
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
				__( 'درخواست شما به‌عنوان ربات شناسایی شد.', 'signa' ),
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

	private function inspectToken( string $token ): void {
		$verdict = FormToken::inspect( $token );

		if ( ! $verdict['valid'] ) {
			throw Rejection::make(
				'stale_form',
				__( 'نشست فرم منقضی شده است. لطفاً دوباره تلاش کنید.', 'signa' ),
				array( 'recoverable' => true, 'reason' => $verdict['reason'] )
			);
		}

		if ( $verdict['age'] < FormToken::minAge() ) {
			throw Rejection::make(
				'too_fast',
				__( 'فرم سریع‌تر از حد معمول ارسال شد. لطفاً دوباره تلاش کنید.', 'signa' ),
				array( 'recoverable' => true )
			);
		}
	}

	private function inspectTimestamp( int $rendered ): void {
		if ( $rendered <= 0 ) {
			throw Rejection::make(
				'stale_form',
				__( 'نشست فرم منقضی شده است. لطفاً دوباره تلاش کنید.', 'signa' ),
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
				__( 'فرم سریع‌تر از حد معمول ارسال شد. لطفاً دوباره تلاش کنید.', 'signa' ),
				array( 'recoverable' => true )
			);
		}

		if ( $age > self::LEGACY_MAX_AGE ) {
			throw Rejection::make(
				'stale_form',
				__( 'نشست فرم منقضی شده است. لطفاً صفحه را تازه کنید.', 'signa' ),
				array( 'recoverable' => true )
			);
		}
	}
}
