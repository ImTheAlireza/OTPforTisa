<?php

namespace Signa\Import;

defined( 'ABSPATH' ) || exit;

final class DigitsSource extends MetaQuerySource {
	public function id(): string {
		return 'digits';
	}

	public function label(): string {
		return __( 'افزونه Digits', 'signa' );
	}

	protected function keys(): array {
		$keys = array( 'digits_phone', 'digits_phone_no', 'wcfmmp_phone' );

		return array_values( (array) apply_filters( 'signa_digits_meta_keys', $keys ) );
	}
}
