<?php
/**
 * What happened the last time each gateway was asked to send.
 *
 * "The SMS never arrived" is the single most common support question, and the
 * answer is almost always one of: wrong credentials, no credit, a sender line
 * the panel does not recognise, or an outbound connection the host blocks. None
 * of those were visible anywhere before — only the visitor saw an error.
 *
 * One small option row keeps the last outcome per gateway so the tools screen
 * can show the exact upstream error code and when it happened. It is written on
 * every failure and only on a *recovery*, so a healthy site barely touches it.
 *
 * @package Signa
 */

namespace Signa\Gateway;

defined( 'ABSPATH' ) || exit;

final class Health {

	const OPTION = 'signa_gateway_health';

	/** How many entries are kept in the shared detail map. */
	const HISTORY = 5;

	/**
	 * Consecutive failures that make a gateway rest.
	 *
	 * Three is the smallest number that means "something is really wrong": one
	 * failure is a blip, two can be a bad phone number, the third says the
	 * credentials, the credit or the route is broken.
	 */
	const BREAKER = 3;

	/** How long a rested gateway is skipped before it gets another try. */
	const REST = 600;

	/**
	 * Record a successful delivery.
	 */
	public function success( string $gateway, string $reference = '', int $status = 0 ): void {
		$state  = $this->all();
		$record = isset( $state[ $gateway ] ) ? $state[ $gateway ] : array();

		// A chatty site does not need a write on every single send.
		if ( isset( $record['ok'] ) && $record['ok'] && time() - (int) $record['at'] < 300 ) {
			return;
		}

		$state[ $gateway ] = array(
			'ok'        => true,
			'at'        => time(),
			'error'     => '',
			'status'    => $status,
			'reference' => substr( $reference, 0, 64 ),
			'since'     => isset( $record['ok'] ) && ! $record['ok'] && isset( $record['since'] ) ? (int) $record['since'] : time(),
		);

		$this->save( $state );
	}

	/**
	 * Record a failure that was not the gateway's fault.
	 *
	 * When wp-config.php blocks outbound HTTP, WordPress refuses the request
	 * before cURL is reached: no gateway was asked anything, and nothing about
	 * the panel's credentials, credit or line has changed. Counting that as a
	 * gateway failure did two visible wrongs — the health card said «ناموفق»
	 * with a three-strike count, and after three attempts the circuit breaker
	 * benched a gateway that was innocent for ten minutes, so a site that had
	 * just fixed wp-config.php still waited.
	 *
	 * The record is kept (the owner needs to see it) but with no count, which
	 * is what the breaker reads.
	 */
	public function blocked( string $gateway, string $detail = '', int $status = 0 ): void {
		$state  = $this->all();
		$record = isset( $state[ $gateway ] ) ? $state[ $gateway ] : array();

		$state[ $gateway ] = array(
			'ok'        => false,
			'at'        => time(),
			'error'     => 'blocked',
			'detail'    => substr( $detail, 0, 160 ),
			'status'    => $status,
			'reference' => '',
			'blocked'   => true,
			'since'     => isset( $record['ok'] ) && $record['ok'] ? time() : ( isset( $record['since'] ) ? (int) $record['since'] : time() ),
			'count'     => 0,
		);

		$this->save( $state );
	}

	/**
	 * Record a failure — always, because this is the interesting case.
	 */
	public function failure( string $gateway, string $error, string $detail = '', int $status = 0 ): void {
		$state  = $this->all();
		$record = isset( $state[ $gateway ] ) ? $state[ $gateway ] : array();

		$state[ $gateway ] = array(
			'ok'        => false,
			'at'        => time(),
			'error'     => substr( $error, 0, 64 ),
			'detail'    => substr( $detail, 0, 160 ),
			'status'    => $status,
			'reference' => '',
			'since'     => isset( $record['ok'] ) && $record['ok'] ? time() : ( isset( $record['since'] ) ? (int) $record['since'] : time() ),
			'count'     => isset( $record['count'] ) && ! $record['ok'] ? (int) $record['count'] + 1 : 1,
		);

		$this->save( $state );
	}

	/**
	 * Everything known about one gateway.
	 *
	 * @return array<string,mixed>
	 */
	public function get( string $gateway ): array {
		$state = $this->all();

		return isset( $state[ $gateway ] ) && is_array( $state[ $gateway ] ) ? $state[ $gateway ] : array();
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public function all(): array {
		$state = get_option( self::OPTION, array() );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * Until when a gateway is skipped, as a timestamp (0 when it is usable).
	 *
	 * A rested gateway is not blacklisted forever: once the window passes it is
	 * tried again, and that single attempt decides whether the breaker reopens.
	 * This is the half-open state, and it is why a wrong credential cannot lock
	 * a site's SMS out permanently.
	 */
	public function blockedUntil( string $gateway ): int {
		$record = $this->get( $gateway );

		if ( array() === $record || ! empty( $record['ok'] ) ) {
			return 0;
		}

		if ( (int) ( isset( $record['count'] ) ? $record['count'] : 0 ) < self::BREAKER ) {
			return 0;
		}

		$until = (int) $record['at'] + self::REST;

		return $until > time() ? $until : 0;
	}

	/**
	 * Is this gateway resting right now?
	 */
	public function resting( string $gateway ): bool {
		return 0 < $this->blockedUntil( $gateway );
	}

	/**
	 * Forget one gateway's history — used when an administrator has just fixed
	 * its configuration and does not want to wait out the window.
	 */
	public function reset( string $gateway ): void {
		$state = $this->all();

		unset( $state[ $gateway ] );

		if ( array() === $state ) {
			delete_option( self::OPTION );

			return;
		}

		$this->save( $state );
	}

	public function forget(): void {
		delete_option( self::OPTION );
	}

	/**
	 * A short, human sentence for the admin screen.
	 */
	public function describe( string $gateway ): string {
		$record = $this->get( $gateway );

		if ( array() === $record ) {
			return __( 'هنوز ارسالی ثبت نشده است.', 'signa' );
		}

		$ago     = human_time_diff( (int) $record['at'], time() );
		$blocked = $this->blockedUntil( $gateway );

		/*
		 * The site's own block, said as such: «این سامانه خطا نداد؛ خودِ سایت
		 * اجازهٔ خروج درخواست را نمیدهد». The gateway is not resting and never
		 * will be for this, so the row must not promise a retry in ten minutes.
		 */
		if ( ! empty( $record['blocked'] ) ) {
			return '' !== (string) $record['detail']
				? sprintf(
					/* translators: 1: the sentence WordPress gave, 2: human readable time difference */
					__( 'خودِ سایت درخواست خروجی را می‌بندد (%1$s) — %2$s پیش. این سامانه خطا نداد؛ در wp-config.php دامنه را در WP_ACCESSIBLE_HOSTS بگذارید یا «ارسال مستقیم» را روشن کنید.', 'signa' ),
					(string) $record['detail'],
					$ago
				)
				: sprintf(
					/* translators: %s: human readable time difference */
					__( 'خودِ سایت درخواست خروجی را می‌بندد — %s پیش.', 'signa' ),
					$ago
				);
		}

		if ( 0 < $blocked ) {
			return sprintf(
				/* translators: 1: failure count, 2: minutes until the next attempt, 3: last error */
				__( 'موقتاً کنار گذاشته شده است: %1$d شکست پیاپی، %2$d دقیقه دیگر دوباره امتحان می‌شود (آخرین خطا: %3$s)', 'signa' ),
				(int) $record['count'],
				max( 1, (int) ceil( ( $blocked - time() ) / 60 ) ),
				'' !== (string) $record['error'] ? (string) $record['error'] : __( 'نامشخص', 'signa' )
			);
		}

		if ( ! empty( $record['ok'] ) ) {
			return sprintf(
				/* translators: %s: human readable time difference */
				__( 'آخرین ارسال موفق: %s پیش', 'signa' ),
				$ago
			);
		}

		$line = sprintf(
			/* translators: 1: error code, 2: human readable time difference */
			__( 'آخرین خطا: %1$s — %2$s پیش', 'signa' ),
			'' !== (string) $record['error'] ? (string) $record['error'] : __( 'نامشخص', 'signa' ),
			$ago
		);

		if ( ! empty( $record['detail'] ) ) {
			$line .= ' (' . (string) $record['detail'] . ')';
		}

		return $line;
	}

	/**
	 * @param array<string,mixed> $state
	 */
	private function save( array $state ): void {
		// Keep only the newest entries; this option must never grow unbounded.
		if ( count( $state ) > self::HISTORY ) {
			uasort(
				$state,
				static function ( $a, $b ) {
					return (int) $b['at'] <=> (int) $a['at'];
				}
			);

			$state = array_slice( $state, 0, self::HISTORY, true );
		}

		update_option( self::OPTION, $state, false );
	}
}
