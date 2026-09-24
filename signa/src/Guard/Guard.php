<?php
/**
 * A single inspection step in the request pipeline.
 *
 * @package Signa
 */

namespace Signa\Guard;

use Signa\Http\Request;

defined( 'ABSPATH' ) || exit;

interface Guard {

	/**
	 * Identifier used in logs and diagnostics.
	 */
	public function name(): string;

	/**
	 * Stages this guard takes part in (`send`, `verify`, `register`).
	 *
	 * @return string[]
	 */
	public function stages(): array;

	/**
	 * Inspect the request for one stage; throw a Rejection to stop the pipeline.
	 *
	 * @param string $stage Stage currently running.
	 * @throws \Signa\Support\Rejection When the request must be refused.
	 */
	public function inspect( Request $request, string $stage ): void;
}
