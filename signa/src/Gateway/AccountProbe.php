<?php

namespace Signa\Gateway;

defined( 'ABSPATH' ) || exit;

interface AccountProbe {
	public function probe(): array;
}
