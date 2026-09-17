<?php
/**
 * Captcha provider contract. Any vendor can be plugged in.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Captcha;

defined( 'ABSPATH' ) || exit;

interface CaptchaProvider {

	public function id(): string;

	public function label(): string;

	/**
	 * Front-end script URL, or an empty string when no script is needed.
	 */
	public function scriptUrl(): string;

	/**
	 * Data handed to the browser (site key, action, theme, …).
	 */
	public function clientConfig(): array;

	public function verify( string $token, string $ip ): CaptchaResult;
}
