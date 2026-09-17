<?php
/**
 * Resolves the active registration form schema.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Registration;

use TisaOtp\Config\Settings;

defined( 'ABSPATH' ) || exit;

final class FieldSchema {

	/** @var Settings */
	private $settings;

	/** @var array<int,array<string,mixed>>|null */
	private $cached;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function enabled(): bool {
		return $this->settings->bool( 'registration_enabled', true );
	}

	/**
	 * Fields before the code (`fields_then_code`) or after it (`code_then_fields`).
	 */
	public function flow(): string {
		$flow = $this->settings->str( 'registration_flow', 'fields_then_code' );

		return in_array( $flow, array( 'fields_then_code', 'code_then_fields' ), true ) ? $flow : 'fields_then_code';
	}

	public function isCodeFirst(): bool {
		return 'code_then_fields' === $this->flow();
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function active(): array {
		if ( null !== $this->cached ) {
			return $this->cached;
		}

		$preset = $this->settings->str( 'field_preset', 'minimal' );
		$fields = 'custom' === $preset ? $this->settings->arr( 'fields' ) : array();

		if ( array() === $fields ) {
			$presets = FieldCatalog::presets();
			$fields  = isset( $presets[ $preset ] ) ? $presets[ $preset ] : FieldCatalog::presets()['minimal'];
		}

		$fields = array_map( array( __CLASS__, 'normalize' ), (array) $fields );
		$fields = array_values(
			array_filter(
				$fields,
				static function ( array $field ): bool {
					return '1' === $field['enabled'];
				}
			)
		);

		usort(
			$fields,
			static function ( array $a, array $b ): int {
				return $a['priority'] <=> $b['priority'];
			}
		);

		$fields = $this->applyEmailPolicy( $fields );

		/**
		 * Filter the resolved registration schema.
		 *
		 * @param array $fields Normalised, sorted field definitions.
		 */
		$this->cached = array_values( (array) apply_filters( 'tisa_otp_registration_fields', $fields ) );

		return $this->cached;
	}

	/**
	 * Slimmed-down payload for the browser.
	 */
	public function forClient(): array {
		return array_map(
			static function ( array $field ): array {
				return array(
					'id'          => $field['id'],
					'label'       => $field['label'],
					'type'        => $field['type'],
					'required'    => '1' === $field['required'],
					'placeholder' => $field['placeholder'],
					'hint'        => $field['hint'],
					'width'       => $field['width'],
					'options'     => $field['options'],
				);
			},
			$this->active()
		);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function find( string $id ): ?array {
		foreach ( $this->active() as $field ) {
			if ( $field['id'] === $id ) {
				return $field;
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $field
	 * @return array<string,mixed>
	 */
	public static function normalize( array $field ): array {
		$type = isset( $field['type'] ) ? sanitize_key( (string) $field['type'] ) : 'text';

		if ( ! in_array( $type, FieldCatalog::types(), true ) ) {
			$type = 'text';
		}

		$target = isset( $field['target'] ) ? sanitize_key( (string) $field['target'] ) : 'meta';

		if ( ! in_array( $target, array( 'core', 'meta', 'wc' ), true ) ) {
			$target = 'meta';
		}

		$options = array();
		if ( isset( $field['options'] ) && is_array( $field['options'] ) ) {
			$options = array_values( array_filter( array_map( 'sanitize_text_field', $field['options'] ) ) );
		}

		return array(
			'id'          => isset( $field['id'] ) ? sanitize_key( (string) $field['id'] ) : '',
			'label'       => isset( $field['label'] ) ? sanitize_text_field( (string) $field['label'] ) : '',
			'type'        => $type,
			'target'      => $target,
			'meta_key'    => isset( $field['meta_key'] ) ? sanitize_key( (string) $field['meta_key'] ) : '',
			'required'    => ! empty( $field['required'] ) ? '1' : '0',
			'enabled'     => array_key_exists( 'enabled', $field ) && empty( $field['enabled'] ) ? '0' : '1',
			'placeholder' => isset( $field['placeholder'] ) ? sanitize_text_field( (string) $field['placeholder'] ) : '',
			'hint'        => isset( $field['hint'] ) ? sanitize_text_field( (string) $field['hint'] ) : '',
			'width'       => isset( $field['width'] ) && 'half' === $field['width'] ? 'half' : 'full',
			'priority'    => isset( $field['priority'] ) ? max( 0, min( 999, (int) $field['priority'] ) ) : 10,
			'options'     => $options,
		);
	}

	/**
	 * The `email_mode` option overrides whatever the preset declares.
	 *
	 * @param array<int,array<string,mixed>> $fields
	 * @return array<int,array<string,mixed>>
	 */
	private function applyEmailPolicy( array $fields ): array {
		$mode = $this->settings->str( 'email_mode', 'optional' );

		foreach ( $fields as $index => $field ) {
			$isEmail = in_array( $field['id'], array( 'user_email', 'email', 'billing_email' ), true ) || 'email' === $field['type'];

			if ( ! $isEmail ) {
				continue;
			}

			if ( 'off' === $mode ) {
				unset( $fields[ $index ] );
				continue;
			}

			$fields[ $index ]['required'] = 'required' === $mode ? '1' : '0';
		}

		return array_values( $fields );
	}
}
