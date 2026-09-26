<?php

namespace Signa\Otp;

defined( 'ABSPATH' ) || exit;

final class CodeRecord {
	private $id;
	private $fingerprint;
	private $channel;
	private $codeHash;
	private $attempts;
	private $issuedAt;
	private $expiresAt;
	private $consumed;

	public function __construct(
		int $id,
		string $fingerprint,
		string $channel,
		string $codeHash,
		int $attempts,
		int $issuedAt,
		int $expiresAt,
		bool $consumed = false
	) {
		$this->id          = $id;
		$this->fingerprint = $fingerprint;
		$this->channel     = $channel;
		$this->codeHash    = $codeHash;
		$this->attempts    = $attempts;
		$this->issuedAt    = $issuedAt;
		$this->expiresAt   = $expiresAt;
		$this->consumed    = $consumed;
	}

	public static function fromRow( $row ): self {
		$row = is_array( $row ) ? (object) $row : $row;

		return new self(
			isset( $row->id ) ? (int) $row->id : 0,
			isset( $row->fingerprint ) ? (string) $row->fingerprint : '',
			isset( $row->channel ) ? (string) $row->channel : 'sms',
			isset( $row->code_hash ) ? (string) $row->code_hash : '',
			isset( $row->attempts ) ? (int) $row->attempts : 0,
			isset( $row->issued_at ) ? (int) $row->issued_at : time(),
			isset( $row->expires_at ) ? (int) $row->expires_at : time(),
			! empty( $row->consumed )
		);
	}

	public function id(): int {
		return $this->id;
	}

	public function fingerprint(): string {
		return $this->fingerprint;
	}

	public function channel(): string {
		return $this->channel;
	}

	public function codeHash(): string {
		return $this->codeHash;
	}

	public function attempts(): int {
		return $this->attempts;
	}

	public function issuedAt(): int {
		return $this->issuedAt;
	}

	public function expiresAt(): int {
		return $this->expiresAt;
	}

	public function isConsumed(): bool {
		return $this->consumed;
	}

	public function isExpired(): bool {
		return $this->expiresAt <= time();
	}

	public function secondsLeft(): int {
		return max( 0, $this->expiresAt - time() );
	}
}
