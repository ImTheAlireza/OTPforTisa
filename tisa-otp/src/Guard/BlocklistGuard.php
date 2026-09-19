<?php
/**
 * Refuses numbers an administrator has banned.
 *
 * This runs before the throttle guard so a banned number never reserves a
 * cooldown slot or burns quota, and it covers `verify` as well as `send`:
 * banning a number while a code is already in flight must stop the sign-in,
 * not just the next send.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Guard;

use TisaOtp\Blocklist\Blocklist;
use TisaOtp\Http\Request;
use TisaOtp\Support\Rejection;

defined( 'ABSPATH' ) || exit;

final class BlocklistGuard implements Guard {

	/** @var Blocklist */
	private $blocklist;

	public function __construct( Blocklist $blocklist ) {
		$this->blocklist = $blocklist;
	}

	public function name(): string {
		return 'blocklist';
	}

	public function stages(): array {
		return array( 'send', 'verify', 'register' );
	}

	public function inspect( Request $request, string $stage ): void {
		$rule = $this->blocklist->match( $request->phone() );

		if ( null === $rule ) {
			return;
		}

		/**
		 * Filter the message shown to a banned number.
		 *
		 * Deliberately vague by default: telling a spammer which prefix is
		 * banned tells them which prefix to switch to.
		 *
		 * @param string $message Refusal text.
		 * @param string $kind    Rule kind that matched.
		 */
		$message = (string) apply_filters(
			'tisa_otp_blocked_message',
			__( 'امکان ورود با این شماره وجود ندارد. در صورت نیاز با پشتیبانی سایت تماس بگیرید.', 'tisa-otp' ),
			$rule->kind()
		);

		throw Rejection::make( 'blocked', $message, array( 'rule' => $rule->kind() ) );
	}
}
