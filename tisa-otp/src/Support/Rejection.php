<?php
/**
 * Exception carrying a user-facing rejection (thrown by guards and services).
 *
 * @package TisaOtp
 */

namespace TisaOtp\Support;

defined( 'ABSPATH' ) || exit;

class Rejection extends \RuntimeException {

	/** @var string */
	private $errorCode;

	/** @var array<string,mixed> */
	private $payload;

	/** @var int */
	private $status;

	public function __construct( string $errorCode, string $message, array $payload = array(), int $status = 200 ) {
		parent::__construct( $message );

		$this->errorCode = $errorCode;
		$this->payload   = $payload;
		$this->status    = $status;
	}

	public static function make( string $errorCode, string $message, array $payload = array(), int $status = 200 ): self {
		return new self( $errorCode, $message, $payload, $status );
	}

	public function errorCode(): string {
		return $this->errorCode;
	}

	public function payload(): array {
		return $this->payload;
	}

	public function status(): int {
		return $this->status;
	}

	public function toWpError(): \WP_Error {
		return new \WP_Error( $this->errorCode, $this->getMessage(), $this->payload );
	}
}
