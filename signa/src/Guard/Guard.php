<?php

namespace Signa\Guard;

use Signa\Http\Request;

defined( 'ABSPATH' ) || exit;

interface Guard {
	public function name(): string;

	public function stages(): array;

	public function inspect( Request $request, string $stage ): void;
}
