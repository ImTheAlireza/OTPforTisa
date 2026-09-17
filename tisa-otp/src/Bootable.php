<?php
/**
 * Services that need to attach WordPress hooks implement this contract.
 *
 * @package TisaOtp
 */

namespace TisaOtp;

defined( 'ABSPATH' ) || exit;

interface Bootable {

	/**
	 * Attach hooks. Called once per request by the plugin orchestrator.
	 */
	public function boot(): void;
}
