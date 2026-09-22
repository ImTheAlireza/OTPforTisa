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
 * @package TisaOtp
 */

namespace TisaOtp\Gateway;

defined( 'ABSPATH' ) || exit;

final class Health {

	const OPTION = 'tisa_otp_gateway_health';

	/** How many entries are kept in the shared detail map. */
	const HISTORY = 5;

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

	public function forget(): void {
		delete_option( self::OPTION );
	}

	/**
	 * A short, human sentence for the admin screen.
	 */
	public function describe( string $gateway ): string {
		$record = $this->get( $gateway );

		if ( array() === $record ) {
			return __( 'هنوز ارسالی ثبت نشده است.', 'tisa-otp' );
		}

		$ago = human_time_diff( (int) $record['at'], time() );

		if ( ! empty( $record['ok'] ) ) {
			return sprintf(
				/* translators: %s: human readable time difference */
				__( 'آخرین ارسال موفق: %s پیش', 'tisa-otp' ),
				$ago
			);
		}

		$line = sprintf(
			/* translators: 1: error code, 2: human readable time difference */
			__( 'آخرین خطا: %1$s — %2$s پیش', 'tisa-otp' ),
			'' !== (string) $record['error'] ? (string) $record['error'] : __( 'نامشخص', 'tisa-otp' ),
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
