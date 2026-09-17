<?php
/**
 * Imports numbers owned by the Digits plugin without touching its own meta.
 *
 * Digits keeps `digits_phone` and `digits_phone_no`; we only ever *read* them
 * and write our canonical key, so removing this plugin cannot break Digits.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Import;

defined( 'ABSPATH' ) || exit;

final class DigitsSource extends MetaQuerySource {

	public function id(): string {
		return 'digits';
	}

	public function label(): string {
		return __( 'افزونه Digits', 'tisa-otp' );
	}

	protected function keys(): array {
		$keys = array( 'digits_phone', 'digits_phone_no', 'wcfmmp_phone' );

		/**
		 * Filter the Digits-compatible meta keys read during import.
		 *
		 * @param string[] $keys Meta keys.
		 */
		return array_values( (array) apply_filters( 'tisa_otp_digits_meta_keys', $keys ) );
	}
}
