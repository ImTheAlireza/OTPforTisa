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
