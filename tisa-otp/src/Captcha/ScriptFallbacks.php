<?php
/**
 * Optional contract for providers that can be served from more than one host.
 *
 * `CaptchaProvider` stays untouched so third-party providers keep working: a
 * provider may additionally implement this interface, and the manager will hand
 * every URL to the browser, which walks them in order until one loads.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Captcha;

defined( 'ABSPATH' ) || exit;

interface ScriptFallbacks {

	/**
	 * Alternative script URLs, tried in order when the primary one fails —
	 * an ad-blocker, a corporate proxy or a national filter can kill the first
	 * host while a mirror still answers.
	 *
	 * @return string[]
	 */
	public function fallbackScriptUrls(): array;
}
