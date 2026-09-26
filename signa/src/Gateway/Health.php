<?php

namespace Signa\Gateway;

defined( 'ABSPATH' ) || exit;

final class Health {
	const OPTION = 'signa_gateway_health';
	const HISTORY = 5;
	const BREAKER = 3;
	const REST = 600;

	public function success( string $gateway, string $reference = '', int $status = 0 ): void {
		$state  = $this->all();
		$record = isset( $state[ $gateway ] ) ? $state[ $gateway ] : array();

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

	public function get( string $gateway ): array {
		$state = $this->all();

		return isset( $state[ $gateway ] ) && is_array( $state[ $gateway ] ) ? $state[ $gateway ] : array();
	}

	public function all(): array {
		$state = get_option( self::OPTION, array() );

		return is_array( $state ) ? $state : array();
	}

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

	public function resting( string $gateway ): bool {
		return 0 < $this->blockedUntil( $gateway );
	}

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

	public function describe( string $gateway ): string {
		$record = $this->get( $gateway );

		if ( array() === $record ) {
			return __( 'هنوز ارسالی ثبت نشده است.', 'signa' );
		}

		$ago     = human_time_diff( (int) $record['at'], time() );
		$blocked = $this->blockedUntil( $gateway );

		if ( ! empty( $record['blocked'] ) ) {
			return '' !== (string) $record['detail']
				? sprintf(
					__( 'خودِ سایت درخواست خروجی را می‌بندد (%1$s)، %2$s پیش. این سامانه خطا نداد. در wp-config.php دامنه را در WP_ACCESSIBLE_HOSTS بگذارید یا «ارسال مستقیم» را روشن کنید.', 'signa' ),
					(string) $record['detail'],
					$ago
				)
				: sprintf(
					__( 'خودِ سایت درخواست خروجی را می‌بندد — %s پیش.', 'signa' ),
					$ago
				);
		}

		if ( 0 < $blocked ) {
			return sprintf(
				__( 'موقتاً کنار گذاشته شده است: %1$d شکست پیاپی، %2$d دقیقه دیگر دوباره امتحان می‌شود (آخرین خطا: %3$s)', 'signa' ),
				(int) $record['count'],
				max( 1, (int) ceil( ( $blocked - time() ) / 60 ) ),
				'' !== (string) $record['error'] ? (string) $record['error'] : __( 'نامشخص', 'signa' )
			);
		}

		if ( ! empty( $record['ok'] ) ) {
			return sprintf(
				__( 'آخرین ارسال موفق: %s پیش', 'signa' ),
				$ago
			);
		}

		$line = sprintf(
			__( 'آخرین خطا: %1$s — %2$s پیش', 'signa' ),
			'' !== (string) $record['error'] ? (string) $record['error'] : __( 'نامشخص', 'signa' ),
			$ago
		);

		if ( ! empty( $record['detail'] ) ) {
			$line .= ' (' . (string) $record['detail'] . ')';
		}

		return $line;
	}

	private function save( array $state ): void {
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
