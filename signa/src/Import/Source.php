<?php
/**
 * Where legacy phone numbers can be imported from.
 *
 * @package Signa
 */

namespace Signa\Import;

defined( 'ABSPATH' ) || exit;

interface Source {

	public function id(): string;

	public function label(): string;

	/**
	 * Whether this source has anything to offer on the current site.
	 */
	public function available(): bool;

	/**
	 * Rough number of users this source could contribute.
	 */
	public function total(): int;

	/**
	 * @return int[]
	 */
	public function userIds( int $limit, int $offset ): array;

	/**
	 * Raw phone value stored by the source for one user.
	 */
	public function phoneFor( int $userId ): string;
}
