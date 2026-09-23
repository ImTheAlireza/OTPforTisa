<?php
/**
 * Normalised outcome of a gateway attempt.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Gateway;

defined( 'ABSPATH' ) || exit;

final class GatewayResult {

	/** @var bool */
	private $sent;

	/** @var string */
	private $gateway;

	/** @var string */
	private $errorCode;

	/** @var string */
	private $message;

	/** @var string */
	private $reference;

	/** @var int */
	private $httpStatus;

	/** @var array<string,mixed> */
	private $meta;

	private function __construct( bool $sent, string $gateway, string $errorCode = '', string $message = '', string $reference = '', int $httpStatus = 0, array $meta = array() ) {
		$this->sent       = $sent;
		$this->gateway    = $gateway;
		$this->errorCode  = $errorCode;
		$this->message    = $message;
		$this->reference  = $reference;
		$this->httpStatus = $httpStatus;
		$this->meta       = $meta;
	}

	public static function sent( string $gateway, string $reference = '', int $httpStatus = 200, array $meta = array() ): self {
		return new self( true, $gateway, '', '', $reference, $httpStatus, $meta );
	}

	public static function failed( string $gateway, string $errorCode, string $message = '', int $httpStatus = 0, array $meta = array() ): self {
		return new self( false, $gateway, $errorCode, $message, '', $httpStatus, $meta );
	}

	public function isSent(): bool {
		return $this->sent;
	}

	public function gateway(): string {
		return $this->gateway;
	}

	public function errorCode(): string {
		return $this->sent ? '' : $this->errorCode;
	}

	public function message(): string {
		if ( $this->sent ) {
			return __( 'کد با موفقیت ارسال شد.', 'tisa-otp' );
		}

		return '' !== $this->message ? $this->message : __( 'ارسال کد ناموفق بود.', 'tisa-otp' );
	}

	/**
	 * What the visitor in front of the form may be told.
	 *
	 * `message()` is written for the administrator: it names the gateway's host,
	 * the wp-config constant and the person to call. For three releases that
	 * sentence was handed to whoever was trying to log in, because it was the
	 * only message the send path had. A visitor cannot edit wp-config.php, so the
	 * sentence is not just noise — it is the site's plumbing, published to
	 * strangers.
	 *
	 * Two cases keep their own words, because a visitor can act on both: asking
	 * again a moment later, and the panel refusing the number. Everything else is
	 * one calm sentence; the technical reason stays in the log, the gateway trace
	 * and the admin screens, where somebody can use it.
	 */
	public function visitorMessage(): string {
		if ( $this->sent ) {
			return __( 'کد تأیید ارسال شد.', 'tisa-otp' );
		}

		if ( 'rate_limited' === $this->errorCode ) {
			return __( 'تعداد درخواست‌ها زیاد بود؛ یک دقیقه بعد دوباره تلاش کنید.', 'tisa-otp' );
		}

		return __( 'امکان ارسال کد در این لحظه نیست. کمی بعد دوباره تلاش کنید و اگر تکرار شد به مدیر سایت بگویید.', 'tisa-otp' );
	}

	public function reference(): string {
		return $this->reference;
	}

	public function httpStatus(): int {
		return $this->httpStatus;
	}

	public function meta(): array {
		return $this->meta;
	}

	/**
	 * Transport-level or provider-side hiccup worth retrying on another gateway.
	 */
	public function isTransient(): bool {
		if ( $this->sent ) {
			return false;
		}

		if ( in_array( $this->errorCode, array( 'timeout', 'transport', 'rate_limited', 'no_credit', 'upstream', 'bad_response' ), true ) ) {
			return true;
		}

		return $this->httpStatus >= 500 && $this->httpStatus <= 599;
	}

	/**
	 * Configuration or credential problems must never trigger a failover,
	 * otherwise a typo silently burns the backup gateway's quota.
	 *
	 * A transient failure is never a configuration problem, even when the panel
	 * chooses to report it with a 4xx status: an empty account (402/400 "no
	 * credit") or a rate limit (429) is exactly the case the backup gateway
	 * exists for, and the status range used to swallow both.
	 */
	public function isConfigurationProblem(): bool {
		if ( in_array( $this->errorCode, array( 'not_configured', 'unauthorized', 'forbidden', 'bad_credentials' ), true ) ) {
			return true;
		}

		if ( '' !== $this->errorCode && $this->isTransient() ) {
			return false;
		}

		return $this->httpStatus >= 400 && $this->httpStatus <= 499;
	}

	public function toWpError(): \WP_Error {
		return new \WP_Error(
			$this->sent ? 'sent' : ( '' !== $this->errorCode ? $this->errorCode : 'delivery_failed' ),
			$this->message(),
			array(
				'gateway' => $this->gateway,
				'status'  => $this->httpStatus,
				'meta'    => $this->meta,
			)
		);
	}
}
