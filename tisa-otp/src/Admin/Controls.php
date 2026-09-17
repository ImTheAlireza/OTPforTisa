<?php
/**
 * Small rendering helpers shared by the admin screens.
 *
 * Every control writes into `tisa_otp_settings[...]` so the native Settings API
 * (options.php) can persist the whole screen in one POST — no custom AJAX save.
 *
 * @package TisaOtp
 */

namespace TisaOtp\Admin;

use TisaOtp\Config\Sanitizer;
use TisaOtp\Config\Settings;

defined( 'ABSPATH' ) || exit;

final class Controls {

	/** @var Settings */
	private $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function name( string $key ): string {
		return Settings::OPTION . '[' . $key . ']';
	}

	public function id( string $key ): string {
		return 'tisa-' . str_replace( '_', '-', $key );
	}

	/**
	 * Wrap a control in the shared row markup.
	 */
	public function row( string $label, callable $control, string $hint = '' ): void {
		echo '<div class="tisa-row">';
		echo '<div class="tisa-row__head"><span class="tisa-row__label">' . esc_html( $label ) . '</span>';

		if ( '' !== trim( $hint ) ) {
			echo '<p class="tisa-row__hint">' . esc_html( $hint ) . '</p>';
		}

		echo '</div><div class="tisa-row__control">';
		$control();
		echo '</div></div>';
	}

	public function text( string $key, string $placeholder = '', string $type = 'text' ): void {
		printf(
			'<input class="regular-text tisa-input" type="%1$s" id="%2$s" name="%3$s" value="%4$s" placeholder="%5$s" dir="ltr">',
			esc_attr( $type ),
			esc_attr( $this->id( $key ) ),
			esc_attr( $this->name( $key ) ),
			esc_attr( $this->settings->str( $key ) ),
			esc_attr( $placeholder )
		);
	}

	/**
	 * Secrets are never echoed back; the stored value survives a masked submit.
	 */
	public function secret( string $key, string $placeholder = '' ): void {
		printf(
			'<input class="regular-text tisa-input tisa-input--secret" type="password" id="%1$s" name="%2$s" value="%3$s" placeholder="%4$s" autocomplete="new-password" dir="ltr">',
			esc_attr( $this->id( $key ) ),
			esc_attr( $this->name( $key ) ),
			esc_attr( '' !== $this->settings->str( $key ) ? Sanitizer::placeholder() : '' ),
			esc_attr( $placeholder )
		);

		if ( '' !== $this->settings->str( $key ) ) {
			echo '<p class="tisa-note">' . esc_html__( 'مقدار ذخیره شده است؛ برای تغییر، مقدار تازه را وارد کنید.', 'tisa-otp' ) . '</p>';
		}
	}

	public function number( string $key, int $min, int $max, string $suffix = '' ): void {
		printf(
			'<span class="tisa-number"><input class="small-text tisa-input" type="number" id="%1$s" name="%2$s" value="%3$s" min="%4$d" max="%5$d" step="1" dir="ltr">%6$s</span>',
			esc_attr( $this->id( $key ) ),
			esc_attr( $this->name( $key ) ),
			esc_attr( $this->settings->str( $key ) ),
			$min,
			$max,
			'' !== $suffix ? '<em>' . esc_html( $suffix ) . '</em>' : ''
		);
	}

	public function textarea( string $key, int $rows = 3, string $placeholder = '' ): void {
		printf(
			'<textarea class="large-text tisa-input" id="%1$s" name="%2$s" rows="%3$d" placeholder="%4$s">%5$s</textarea>',
			esc_attr( $this->id( $key ) ),
			esc_attr( $this->name( $key ) ),
			$rows,
			esc_attr( $placeholder ),
			esc_textarea( $this->settings->str( $key ) )
		);
	}

	public function toggle( string $key, string $label, string $hint = '' ): void {
		$on = $this->settings->bool( $key );

		// Tells the sanitizer this toggle was rendered, so "off" is stored.
		printf( '<input type="hidden" name="%s" value="%s">', esc_attr( $this->name( '_fields' ) . '[]' ), esc_attr( $key ) );

		printf(
			'<label class="tisa-toggle%1$s" for="%2$s"><input type="checkbox" id="%2$s" name="%3$s" value="1"%4$s><span class="tisa-toggle__track" aria-hidden="true"></span><span class="tisa-toggle__text">%5$s%6$s</span></label>',
			$on ? ' is-on' : '',
			esc_attr( $this->id( $key ) ),
			esc_attr( $this->name( $key ) ),
			$on ? ' checked' : '',
			esc_html( $label ),
			'' !== trim( $hint ) ? '<em>' . esc_html( $hint ) . '</em>' : ''
		);
	}

	/**
	 * @param array<string,string> $options
	 */
	public function select( string $key, array $options ): void {
		$current = $this->settings->str( $key );

		echo '<select class="tisa-input tisa-select" id="' . esc_attr( $this->id( $key ) ) . '" name="' . esc_attr( $this->name( $key ) ) . '">';

		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) $value ),
				selected( $current, (string) $value, false ),
				esc_html( (string) $label )
			);
		}

		echo '</select>';
	}

	/**
	 * @param array<string,array<string,string>> $options id => label/desc
	 */
	public function cards( string $key, array $options ): void {
		$current = $this->settings->str( $key );

		echo '<div class="tisa-cards">';

		foreach ( $options as $value => $option ) {
			$label = isset( $option['label'] ) ? $option['label'] : (string) $value;
			$desc  = isset( $option['desc'] ) ? $option['desc'] : '';

			printf(
				'<label class="tisa-card%1$s"><input type="radio" name="%2$s" value="%3$s"%4$s><span class="tisa-card__title">%5$s</span>%6$s</label>',
				$current === (string) $value ? ' is-selected' : '',
				esc_attr( $this->name( $key ) ),
				esc_attr( (string) $value ),
				checked( $current, (string) $value, false ),
				esc_html( $label ),
				'' !== $desc ? '<span class="tisa-card__desc">' . esc_html( $desc ) . '</span>' : ''
			);
		}

		echo '</div>';
	}

	/**
	 * @param array<string,string> $options
	 */
	public function checkboxList( string $key, array $options ): void {
		$current = (array) $this->settings->arr( $key );

		echo '<div class="tisa-checks">';

		foreach ( $options as $value => $label ) {
			printf(
				'<label class="tisa-check"><input type="checkbox" name="%1$s" value="%2$s"%3$s><span>%4$s</span></label>',
				esc_attr( $this->name( $key ) . '[]' ),
				esc_attr( (string) $value ),
				checked( in_array( (string) $value, $current, true ), true, false ),
				esc_html( (string) $label )
			);
		}

		echo '</div>';
	}

	public function color( string $key ): void {
		printf(
			'<input type="text" class="tisa-input tisa-color" id="%1$s" name="%2$s" value="%3$s" data-default-color="%3$s" dir="ltr">',
			esc_attr( $this->id( $key ) ),
			esc_attr( $this->name( $key ) ),
			esc_attr( $this->settings->str( $key ) )
		);
	}

	public function notice( string $text, string $type = 'info' ): void {
		printf(
			'<div class="tisa-notice tisa-notice--%1$s"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $text )
		);
	}

	public function description( string $text ): void {
		echo '<p class="tisa-desc">' . wp_kses_post( $text ) . '</p>';
	}
}
