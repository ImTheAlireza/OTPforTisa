<?php
/**
 * A single inspection step in the request pipeline.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Guard;

use TisaOtp\Http\Request;

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
	 * @throws \TisaOtp\Support\Rejection When the request must be refused.
	 */
	public function inspect( Request $request, string $stage ): void;
}
