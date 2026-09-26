<?php

namespace Signa\Support;

defined( 'ABSPATH' ) || exit;

class Rejection extends \RuntimeException {
	private $errorCode;
	private $payload;
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
