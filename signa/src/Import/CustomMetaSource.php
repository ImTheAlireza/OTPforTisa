<?php
/**
 * Imports from any user meta key the administrator names.
 *
 * @package Signa
 */

namespace Signa\Import;

defined( 'ABSPATH' ) || exit;

final class CustomMetaSource extends MetaQuerySource {

	/** @var string */
	private $metaKey;

	public function __construct( string $metaKey ) {
		$this->metaKey = sanitize_key( $metaKey );
	}

	public function id(): string {
		return 'custom:' . $this->metaKey;
	}

	public function label(): string {
		return sprintf(
			/* translators: %s: meta key */
			__( 'متای دلخواه: %s', 'signa' ),
			$this->metaKey
		);
	}

	public function available(): bool {
		return '' !== $this->metaKey && parent::available();
	}

	protected function keys(): array {
		return '' === $this->metaKey ? array() : array( $this->metaKey );
	}
}
