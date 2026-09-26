<?php

namespace Signa\Captcha;

defined( 'ABSPATH' ) || exit;

interface CaptchaProvider {
	public function id(): string;

	public function label(): string;

	public function scriptUrl(): string;

	public function clientConfig(): array;

	public function verify( string $token, string $ip ): CaptchaResult;
}
