<?php

namespace Signa\Import;

defined( 'ABSPATH' ) || exit;

interface Source {
	public function id(): string;

	public function label(): string;

	public function available(): bool;

	public function total(): int;

	public function userIds( int $limit, int $offset ): array;

	public function phoneFor( int $userId ): string;
}
