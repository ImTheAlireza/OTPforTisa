<?php

namespace Signa\Captcha;

defined( 'ABSPATH' ) || exit;

interface ScriptFallbacks {
	public function fallbackScriptUrls(): array;
}
