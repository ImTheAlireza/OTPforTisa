<?php

namespace Signa\Support;

defined( 'ABSPATH' ) || exit;

final class Transport {
	const DNS     = 'dns';
	const CONNECT = 'connect';
	const TLS     = 'tls';
	const TIMEOUT = 'timeout';
	const BLOCKED = 'blocked';
	const UNKNOWN = 'unknown';

	public static function classify( string $code, string $message ): string {
		$hay = strtolower( $code . ' ' . $message );

		if (
			0 === strpos( ltrim( $hay ), 'blocked:' )
			|| false !== strpos( $hay, 'block_external' )
			|| false !== strpos( $hay, 'blocked_external' )
			|| false !== strpos( $hay, 'http_request_not_executed' )
			|| false !== strpos( $hay, 'blocked requests' )
			|| false !== strpos( $hay, 'بوکله' )
			|| false !== strpos( $hay, 'بلوکه' )
		) {
			return self::BLOCKED;
		}

		if ( false !== strpos( $hay, 'resolve host' ) || false !== strpos( $hay, 'getaddrinfo' ) || false !== strpos( $hay, 'name or service not known' ) || false !== strpos( $hay, 'nodename nor servname' ) ) {
			return self::DNS;
		}

		if ( false !== strpos( $hay, 'ssl' ) || false !== strpos( $hay, 'certificate' ) || false !== strpos( $hay, 'tls' ) ) {
			return self::TLS;
		}

		if ( false !== strpos( $hay, 'connection refused' ) || false !== strpos( $hay, 'failed to connect' ) || false !== strpos( $hay, 'could not connect' ) || false !== strpos( $hay, 'network is unreachable' ) || false !== strpos( $hay, 'connection reset' ) ) {
			return self::CONNECT;
		}

		if ( false !== strpos( $hay, 'timed out' ) || false !== strpos( $hay, 'timeout' ) ) {
			return self::TIMEOUT;
		}

		return self::UNKNOWN;
	}

	public static function reason( string $code, string $message ): string {
		$kind   = self::classify( $code, $message );
		$detail = trim( (string) preg_replace( '/\s+/', ' ', $message ) );

		if ( '' === $detail ) {
			$detail = $code;
		}

		$detail = (string) preg_replace( '/([?&](?:token|key|api_key|apikey|access_token|secret)=)[^&\s]+/i', '$1•••', $detail );

		if ( function_exists( 'mb_strlen' ) ) {
			if ( mb_strlen( $detail ) > 130 ) {
				$detail = mb_substr( $detail, 0, 129 ) . '…';
			}
		} elseif ( strlen( $detail ) > 130 ) {
			$detail = substr( $detail, 0, 127 ) . '…';
		}

		return strtoupper( $kind ) . ': ' . $detail;
	}

	public static function explain( string $code, string $message ): string {
		switch ( self::classify( $code, $message ) ) {
			case self::DNS:
				return __( 'نام دامنهٔ سامانه پیامکی از این سرور حل نشد (خطای DNS). یا DNS هاست خراب است یا دامنه روی این سرور فیلتر/تحریم شده. با هاست تماس بگیرید یا DNS سرور را به ۱.۱.۱.۱ و ۸.۸.۸.۸ تغییر دهید.', 'signa' );

			case self::CONNECT:
				return __( 'اتصال خروجی این سرور به سامانه پیامکی بسته است (پورت ۴۴۳ باز نمی‌شود). فایروال هاست باید دامنهٔ سامانه را برای این سایت باز کند؛ از پشتیبانی هاست بخواهید اتصال خروجی به این دامنه را باز کند.', 'signa' );

			case self::TLS:
				return __( 'اتصال امن (TLS) با سامانه برقرار نشد. معمولاً بستهٔ گواهی‌های قدیمی روی سرور است؛ از هاست بخواهید بستهٔ CA را به‌روز کند، یا اگر مسیر اتصال دستکاری می‌شود از سامانهٔ دیگری استفاده کنید.', 'signa' );

			case self::TIMEOUT:
				return __( 'پاسخ سامانه پیامکی در مهلت مقرر نرسید. یا سامانه کند است یا مسیر خروجی این سرور بسته است؛ چند دقیقه دیگر دوباره آزمایش کنید و اگر تکرار شد با هاست تماس بگیرید.', 'signa' );

			case self::BLOCKED:
				return sprintf(
					__( 'خودِ وردپرس این درخواست را رد کرد، نه فایروال هاست: در wp-config.php گزینهٔ WP_HTTP_BLOCK_EXTERNAL روشن است و %s در WP_ACCESSIBLE_HOSTS نیست. یکی از این دو کار را بکنید: ۱) همان خط را false کنید: define( \'WP_HTTP_BLOCK_EXTERNAL\', false ); ۲) یا دامنه را مجاز کنید: define( \'WP_ACCESSIBLE_HOSTS\', \'%1$s\' );', 'signa' ),
					self::host( $message )
				);

			default:
				return sprintf(
					__( 'ارتباط با سامانه پیامکی برقرار نشد (%s). میزبان، فایروال یا DNS این سرور را بررسی کنید.', 'signa' ),
					'' !== $code ? $code : __( 'نامشخص', 'signa' )
				);
		}
	}

	public static function isBlocked( string $reason ): bool {
		return self::BLOCKED === self::classify( '', $reason );
	}

	public static function host( string $message ): string {
		if ( preg_match_all( '/\b((?:[a-z0-9-]+\.)+[a-z]{2,})\b/i', $message, $matches ) ) {
			foreach ( $matches[1] as $candidate ) {
				$candidate = strtolower( $candidate );

				if ( ! preg_match( '/\.(php|html?|js|css|txt|json)$/', $candidate ) ) {
					return $candidate;
				}
			}
		}

		return __( 'دامنهٔ سامانهٔ پیامکی', 'signa' );
	}

	public static function egressBlocked( string $host ): bool {
		$blocking = defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL;
		$allowed  = defined( 'WP_ACCESSIBLE_HOSTS' ) ? (string) WP_ACCESSIBLE_HOSTS : '';

		return self::blocked( $host, (bool) $blocking, $allowed );
	}

	public static function blocked( string $host, bool $blocking, string $allowed ): bool {
		return $blocking && ! self::allowed( $host, $allowed );
	}

	public static function allowed( string $host, string $allowed ): bool {
		$host = strtolower( trim( $host ) );

		foreach ( (array) preg_split( '/[,\s]+/', $allowed ) as $rule ) {
			$rule = strtolower( trim( (string) $rule ) );

			if ( '' === $rule ) {
				continue;
			}

			if ( '*' === $rule || $rule === $host ) {
				return true;
			}

			if ( '*.' === substr( $rule, 0, 2 ) || '.' === substr( $rule, 0, 1 ) ) {
				$suffix = ltrim( $rule, '.*' );

				if ( '' !== $suffix && ( $suffix === $host || substr( $host, -strlen( $suffix ) - 1 ) === '.' . $suffix ) ) {
					return true;
				}
			}
		}

		return false;
	}

	public static function blockFailure( string $host ) {
		if ( ! self::egressBlocked( $host ) ) {
			return null;
		}

		return self::from( 'http_request_not_executed', self::blockReason( $host ) );
	}

	public static function blockReason( string $host ): string {
		return sprintf( 'WordPress blocks outbound HTTP: %s is not in WP_ACCESSIBLE_HOSTS.', $host );
	}

	public static function from( string $code, string $message ): array {
		return array(
			'kind'    => self::classify( $code, $message ),
			'reason'  => self::reason( $code, $message ),
			'message' => self::explain( $code, $message ),
		);
	}

	public static function fromError( $error ): array {
		if ( is_object( $error ) && method_exists( $error, 'get_error_code' ) ) {
			return self::from( (string) $error->get_error_code(), (string) $error->get_error_message() );
		}

		return self::from( '', is_string( $error ) ? $error : '' );
	}
}
