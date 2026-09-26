<?php

namespace Signa\Gateway;

defined( 'ABSPATH' ) || exit;

final class GatewayResult {
	private $sent;
	private $gateway;
	private $errorCode;
	private $message;
	private $reference;
	private $httpStatus;
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
			return __( 'کد با موفقیت ارسال شد.', 'signa' );
		}

		return '' !== $this->message ? $this->message : __( 'ارسال کد ناموفق بود.', 'signa' );
	}

	public function visitorMessage(): string {
		if ( $this->sent ) {
			return __( 'کد تأیید ارسال شد.', 'signa' );
		}

		if ( 'rate_limited' === $this->errorCode ) {
			return __( 'تعداد درخواست‌ها زیاد بود؛ یک دقیقه بعد دوباره تلاش کنید.', 'signa' );
		}

		return __( 'امکان ارسال کد در این لحظه نیست. کمی بعد دوباره تلاش کنید و اگر تکرار شد به مدیر سایت بگویید.', 'signa' );
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

	public function isTransient(): bool {
		if ( $this->sent ) {
			return false;
		}

		if ( in_array( $this->errorCode, array( 'timeout', 'transport', 'rate_limited', 'no_credit', 'upstream', 'bad_response' ), true ) ) {
			return true;
		}

		return $this->httpStatus >= 500 && $this->httpStatus <= 599;
	}

	public function isConfigurationProblem(): bool {
		if ( in_array( $this->errorCode, array( 'not_configured', 'unauthorized', 'forbidden', 'bad_credentials' ), true ) ) {
			return true;
		}

		if ( '' !== $this->errorCode && $this->isTransient() ) {
			return false;
		}

		return $this->httpStatus >= 400 && $this->httpStatus <= 499;
	}

	public function worthFailover(): bool {
		if ( $this->sent ) {
			return false;
		}

		return 'invalid_recipient' !== $this->errorCode;
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
