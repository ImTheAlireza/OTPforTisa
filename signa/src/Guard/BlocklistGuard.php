<?php

namespace Signa\Guard;

use Signa\Blocklist\Blocklist;
use Signa\Http\Request;
use Signa\Support\Rejection;

defined( 'ABSPATH' ) || exit;

final class BlocklistGuard implements Guard {
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

		$message = (string) apply_filters(
			'signa_blocked_message',
			__( 'امکان ورود با این شماره وجود ندارد. در صورت نیاز با پشتیبانی سایت تماس بگیرید.', 'signa' ),
			$rule->kind()
		);

		throw Rejection::make( 'blocked', $message, array( 'rule' => $rule->kind() ) );
	}
}
