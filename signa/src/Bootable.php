<?php

namespace Signa;

defined( 'ABSPATH' ) || exit;

interface Bootable {
	public function boot(): void;
}
