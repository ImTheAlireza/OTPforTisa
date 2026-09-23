<?php
/**
 * Why a gateway call did not leave the server.
 *
 * The logs said `transport`. That word is as useful as no word at all: it does
 * not name the host, the cause, or the person to call. Every one of these
 * failures arrives as a `WP_Error` carrying a real message — usually the cURL
 * sentence — and that sentence is thrown away one line before it would have
 * been read.
 *
 * This class keeps it. It classifies the failure, keeps the technical detail
 * for the log, and writes the Persian sentence an administrator can act on:
 * who to call, which constant to set, which button to press.
 *
 * The four causes below are the ones seen in practice on Iranian hosting,
 * in this order of frequency: a resolver that cannot resolve the panel, a
 * firewall that drops outbound 443, a site that blocked outbound HTTP in
 * wp-config, and a server whose CA bundle is too old for TLS.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Support;

defined( 'ABSPATH' ) || exit;

final class Transport {

	const DNS     = 'dns';
	const CONNECT = 'connect';
	const TLS     = 'tls';
	const TIMEOUT = 'timeout';
	const BLOCKED = 'blocked';
	const UNKNOWN = 'unknown';

	/**
	 * Which kind of failure is this?
	 *
	 * @param string $code    WP_Error code.
	 * @param string $message WP_Error message, usually the cURL sentence.
	 */
	public static function classify( string $code, string $message ): string {
		$hay = strtolower( $code . ' ' . $message );

		/*
		 * Two different blocks land here.
		 *
		 * `WP_HTTP_BLOCK_EXTERNAL` makes WordPress answer with
		 * `http_request_not_executed` and the sentence "User has blocked
		 * requests through HTTP." — on a Persian site, «کاربر درخواست HTTP را
		 * بوکله نمود.» — and cURL is never reached, so no cURL sentence exists
		 * to classify. That is the case an owner hit in 1.3.6: the panel said
		 * UNKNOWN and sent them to check DNS, while the answer was one constant
		 * in wp-config.php away. A security plugin that defines the same
		 * constant produces the same code.
		 */
		if (
			false !== strpos( $hay, 'block_external' )
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

		/*
		 * Connect comes before timeout on purpose: cURL reports a dropped
		 * outbound port as "Failed to connect … : Connection timed out", which
		 * is a firewall, not a slow panel. Classifying that as a timeout sends
		 * the administrator to wait and try again instead of calling their host.
		 */
		if ( false !== strpos( $hay, 'connection refused' ) || false !== strpos( $hay, 'failed to connect' ) || false !== strpos( $hay, 'could not connect' ) || false !== strpos( $hay, 'network is unreachable' ) || false !== strpos( $hay, 'connection reset' ) ) {
			return self::CONNECT;
		}

		if ( false !== strpos( $hay, 'timed out' ) || false !== strpos( $hay, 'timeout' ) ) {
			return self::TIMEOUT;
		}

		return self::UNKNOWN;
	}

	/**
	 * The short technical line: what failed, and against which host.
	 *
	 * It is written where the administrator already looks — the events screen —
	 * so it has to be readable at a glance and safe to store: it is a cURL
	 * sentence, and the cURL sentence never contains the API key (the key
	 * travels in a header or a POST body, neither of which WP_Error echoes).
	 *
	 * @param string $code    WP_Error code.
	 * @param string $message WP_Error message.
	 */
	public static function reason( string $code, string $message ): string {
		$kind   = self::classify( $code, $message );
		$detail = trim( (string) preg_replace( '/\s+/', ' ', $message ) );

		if ( '' === $detail ) {
			$detail = $code;
		}

		// A URL in the sentence must not carry a credential into the database.
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

	/**
	 * The sentence to show a person: what happened, and what to do about it.
	 *
	 * @param string $code    WP_Error code.
	 * @param string $message WP_Error message.
	 */
	public static function explain( string $code, string $message ): string {
		switch ( self::classify( $code, $message ) ) {
			case self::DNS:
				return __( 'نام دامنهٔ سامانه پیامکی از این سرور حل نشد (خطای DNS). یا DNS هاست خراب است یا دامنه روی این سرور فیلتر/تحریم شده. با هاست تماس بگیرید یا DNS سرور را به ۱.۱.۱.۱ و ۸.۸.۸.۸ تغییر دهید.', 'tisa-otp' );

			case self::CONNECT:
				return __( 'اتصال خروجی این سرور به سامانه پیامکی بسته است (پورت ۴۴۳ باز نمی‌شود). فایروال هاست باید دامنهٔ سامانه را برای این سایت باز کند؛ از پشتیبانی هاست بخواهید اتصال خروجی به این دامنه را باز کند.', 'tisa-otp' );

			case self::TLS:
				return __( 'اتصال امن (TLS) با سامانه برقرار نشد. معمولاً بستهٔ گواهی‌های قدیمی روی سرور است؛ از هاست بخواهید بستهٔ CA را به‌روز کند، یا اگر مسیر اتصال دستکاری می‌شود از سامانهٔ دیگری استفاده کنید.', 'tisa-otp' );

			case self::TIMEOUT:
				return __( 'پاسخ سامانه پیامکی در مهلت مقرر نرسید. یا سامانه کند است یا مسیر خروجی این سرور بسته است؛ چند دقیقه دیگر دوباره آزمایش کنید و اگر تکرار شد با هاست تماس بگیرید.', 'tisa-otp' );

			case self::BLOCKED:
				return sprintf(
					/* translators: %s: the host that was refused, or the words «دامنهٔ سامانهٔ پیامکی» when the message did not name one */
					__( 'خودِ وردپرس این درخواست را رد کرد، نه فایروال هاست: در wp-config.php گزینهٔ WP_HTTP_BLOCK_EXTERNAL روشن است و %s در WP_ACCESSIBLE_HOSTS نیست. یکی از این دو کار را بکنید — ۱) همان خط را false کنید: define( \'WP_HTTP_BLOCK_EXTERNAL\', false ); ۲) یا دامنه را مجاز کنید: define( \'WP_ACCESSIBLE_HOSTS\', \'%1$s\' );', 'tisa-otp' ),
					self::host( $message )
				);

			default:
				return sprintf(
					/* translators: %s: transport error code */
					__( 'ارتباط با سامانه پیامکی برقرار نشد (%s). میزبان، فایروال یا DNS این سرور را بررسی کنید.', 'tisa-otp' ),
					'' !== $code ? $code : __( 'نامشخص', 'tisa-otp' )
				);
		}
	}

	/**
	 * The host a transport sentence names, when it names one.
	 *
	 * Our own block message carries the host ("… api.sms.ir is not in
	 * WP_ACCESSIBLE_HOSTS"), which is what lets the advice print the exact
	 * `define()` line instead of a general instruction. Core's own sentence
	 * («کاربر درخواست HTTP را بوکله نمود.») names nobody, so the instruction
	 * falls back to a placeholder the owner replaces.
	 *
	 * @param string $message WP_Error message.
	 */
	public static function host( string $message ): string {
		if ( preg_match( '/\b((?:[a-z0-9-]+\.)+[a-z]{2,})\b/i', $message, $matches ) ) {
			return strtolower( $matches[1] );
		}

		return __( 'دامنهٔ سامانهٔ پیامکی', 'tisa-otp' );
	}

	/**
	 * May WordPress reach this host at all?
	 *
	 * Core keeps `WP_HTTP_BLOCK_EXTERNAL` and `WP_ACCESSIBLE_HOSTS` in
	 * wp-config.php, and both are read here rather than discovered from a
	 * failed request: the answer decides whether a test even bothers to knock.
	 *
	 * @param string $host Host name, without a scheme.
	 */
	public static function egressBlocked( string $host ): bool {
		$blocking = defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL;
		$allowed  = defined( 'WP_ACCESSIBLE_HOSTS' ) ? (string) WP_ACCESSIBLE_HOSTS : '';

		return self::blocked( $host, (bool) $blocking, $allowed );
	}

	/**
	 * The rule itself, with the constants passed in so it can be tested.
	 *
	 * Core's own matching: an exact host, `*` for everything, `*.example.com`
	 * and `.example.com` for a domain and its subdomains. Comma or space
	 * separated, the way wp-config.php is usually written.
	 *
	 * @param string $host     Host name.
	 * @param bool   $blocking Is external HTTP blocked at all?
	 * @param string $allowed  The WP_ACCESSIBLE_HOSTS value.
	 */
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

			/*
			 * `*.sms.ir` and `.sms.ir` mean the domain and its subdomains.
			 *
			 * Core's own rule, read from `WP_Http::block_request()`: a list
			 * that contains a `*` anywhere is turned into one regex with
			 * `*` → `.+`, so `*.sms.ir` matches `api.sms.ir` but not `sms.ir`
			 * itself; a list without a `*` is compared with `in_array()`, so
			 * its entries match the host exactly. This side reads both
			 * wildcard forms as "the domain and its subdomains", one step more
			 * forgiving than core. The cost of that step is a request core
			 * then refuses — and that refusal is classified as a block anyway.
			 * The other direction, calling a working panel blocked, is what
			 * turns a good install into a false alarm.
			 */
			if ( '*.' === substr( $rule, 0, 2 ) || '.' === substr( $rule, 0, 1 ) ) {
				$suffix = ltrim( $rule, '.*' );

				if ( '' !== $suffix && ( $suffix === $host || substr( $host, -strlen( $suffix ) - 1 ) === '.' . $suffix ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * The failure WordPress would answer with, built before it answers.
	 *
	 * @return array{kind:string,reason:string,message:string}|null
	 */
	public static function blockFailure( string $host ) {
		if ( ! self::egressBlocked( $host ) ) {
			return null;
		}

		return self::from(
			'http_request_not_executed',
			sprintf( 'WordPress blocks outbound HTTP: %s is not in WP_ACCESSIBLE_HOSTS.', $host )
		);
	}

	/**
	 * Everything about one failure, in the shape the callers need.
	 *
	 * @param string $code    WP_Error code.
	 * @param string $message WP_Error message.
	 * @return array{kind:string,reason:string,message:string}
	 */
	public static function from( string $code, string $message ): array {
		return array(
			'kind'    => self::classify( $code, $message ),
			'reason'  => self::reason( $code, $message ),
			'message' => self::explain( $code, $message ),
		);
	}

	/**
	 * Read one out of a `WP_Error`, which is the shape it always arrives in.
	 *
	 * @param mixed $error Usually a WP_Error.
	 * @return array{kind:string,reason:string,message:string}
	 */
	public static function fromError( $error ): array {
		if ( is_object( $error ) && method_exists( $error, 'get_error_code' ) ) {
			return self::from( (string) $error->get_error_code(), (string) $error->get_error_message() );
		}

		return self::from( '', is_string( $error ) ? $error : '' );
	}
}
