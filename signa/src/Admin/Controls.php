<?php
/**
 * Rendering helpers shared by the settings screen.
 *
 * Every control writes into `signa_settings[...]`, so the native Settings API
 * (options.php) persists the whole page in one POST — with or without the
 * script that saves it in the background. Two shapes cover the page:
 *
 * - a setting row (`.signa-sr`): title and one line of explanation on one side,
 *   the control (a switch, a select, a number) on the other;
 * - a field (`.signa-f`): a label above an input and a hint below it, laid out
 *   two or three to a row with `grid()`.
 *
 * @package Signa
 */

namespace Signa\Admin;

use Signa\Config\Sanitizer;
use Signa\Config\Settings;

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
		return 'signa-' . str_replace( '_', '-', $key );
	}

	/* Layout ---------------------------------------------------------------- */

	/**
	 * A setting row: text on one side, any control on the other.
	 *
	 * @param string   $for The id of the control, so the title is its label.
	 */
	public function row( string $title, callable $control, string $hint = '', string $for = '' ): void {
		echo '<div class="signa-sr"><div class="signa-sr__text">';

		if ( '' !== $for ) {
			echo '<label class="signa-sr__t" for="' . esc_attr( $for ) . '">' . esc_html( $title ) . '</label>';
		} else {
			echo '<span class="signa-sr__t">' . esc_html( $title ) . '</span>';
		}

		if ( '' !== trim( $hint ) ) {
			echo '<p class="signa-sr__s">' . esc_html( $hint ) . '</p>';
		}

		echo '</div><div class="signa-sr__ctl">';
		$control();
		echo '</div></div>';
	}

	/**
	 * The most common row: a title, a hint and an on/off switch.
	 */
	public function toggleRow( string $key, string $title, string $hint = '' ): void {
		$id = $this->id( $key );

		echo '<div class="signa-sr"><div class="signa-sr__text">';
		echo '<label class="signa-sr__t" for="' . esc_attr( $id ) . '" id="' . esc_attr( $id ) . '-label">' . esc_html( $title ) . '</label>';

		if ( '' !== trim( $hint ) ) {
			echo '<p class="signa-sr__s" id="' . esc_attr( $id ) . '-hint">' . esc_html( $hint ) . '</p>';
		}

		echo '</div><div class="signa-sr__ctl">';
		$this->toggle( $key, '', '' !== trim( $hint ) ? $id . '-hint' : '' );
		echo '</div></div>';
	}

	/**
	 * A labelled field for the grid.
	 */
	public function field( string $label, callable $control, string $hint = '', string $for = '', string $class = '' ): void {
		echo '<div class="signa-f' . ( '' !== $class ? ' ' . esc_attr( $class ) : '' ) . '">';

		if ( '' !== $label ) {
			if ( '' !== $for ) {
				echo '<label class="signa-f__label" for="' . esc_attr( $for ) . '">' . esc_html( $label ) . '</label>';
			} else {
				echo '<span class="signa-f__label">' . esc_html( $label ) . '</span>';
			}
		}

		$control();

		if ( '' !== trim( $hint ) ) {
			echo '<p class="signa-f__hint">' . esc_html( $hint ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * Fields two or three to a row; one per row on narrow screens.
	 */
	public function grid( int $columns, callable $body ): void {
		echo '<div class="signa-grid signa-grid--' . (int) max( 1, min( 3, $columns ) ) . '">';
		$body();
		echo '</div>';
	}

	/* Inputs ---------------------------------------------------------------- */

	/**
	 * @param bool $ltr Keys, addresses and numbers read left-to-right; Persian copy does not.
	 */
	public function text( string $key, string $placeholder = '', string $type = 'text', bool $ltr = true ): void {
		printf(
			'<input class="signa-inp%1$s" type="%2$s" id="%3$s" name="%4$s" value="%5$s" placeholder="%6$s"%7$s>',
			$ltr ? ' signa-inp--mono' : '',
			esc_attr( $type ),
			esc_attr( $this->id( $key ) ),
			esc_attr( $this->name( $key ) ),
			esc_attr( $this->settings->str( $key ) ),
			esc_attr( $placeholder ),
			$ltr ? ' dir="ltr"' : ''
		);
	}

	/**
	 * Secrets are never echoed back; the stored value survives a masked submit.
	 */
	public function secret( string $key, string $placeholder = '' ): void {
		$stored = '' !== $this->settings->str( $key );

		printf(
			'<input class="signa-inp signa-inp--mono signa-input--secret" type="password" id="%1$s" name="%2$s" value="%3$s" placeholder="%4$s" autocomplete="new-password" dir="ltr"%5$s>',
			esc_attr( $this->id( $key ) ),
			esc_attr( $this->name( $key ) ),
			esc_attr( $stored ? Sanitizer::placeholder() : '' ),
			esc_attr( $placeholder ),
			$stored ? ' aria-describedby="' . esc_attr( $this->id( $key ) ) . '-stored"' : ''
		);

		if ( $stored ) {
			echo '<p class="signa-f__hint" id="' . esc_attr( $this->id( $key ) ) . '-stored">' . esc_html__( 'ذخیره شده؛ برای تغییر، مقدار تازه وارد کنید.', 'signa' ) . '</p>';
		}
	}

	public function number( string $key, int $min, int $max, string $unit = '' ): void {
		printf(
			'<span class="signa-unit"><input class="signa-inp signa-inp--num" type="number" id="%1$s" name="%2$s" value="%3$s" min="%4$d" max="%5$d" step="1" dir="ltr">%6$s</span>',
			esc_attr( $this->id( $key ) ),
			esc_attr( $this->name( $key ) ),
			esc_attr( $this->settings->str( $key ) ),
			$min,
			$max,
			'' !== $unit ? '<span class="signa-unit__u">' . esc_html( $unit ) . '</span>' : ''
		);
	}

	public function textarea( string $key, int $rows = 3, string $placeholder = '', bool $ltr = false ): void {
		printf(
			'<textarea class="signa-inp signa-inp--area%1$s" id="%2$s" name="%3$s" rows="%4$d" placeholder="%5$s"%6$s>%7$s</textarea>',
			$ltr ? ' signa-inp--mono' : '',
			esc_attr( $this->id( $key ) ),
			esc_attr( $this->name( $key ) ),
			$rows,
			esc_attr( $placeholder ),
			$ltr ? ' dir="ltr"' : '',
			esc_textarea( $this->settings->str( $key ) )
		);
	}

	/**
	 * An on/off switch: a real checkbox with the switch role, so it submits
	 * without script and a screen reader announces «روشن/خاموش».
	 *
	 * @param string $label       Visible text beside the switch; empty when a row title labels it.
	 * @param string $describedBy Id of the sentence that explains it.
	 */
	public function toggle( string $key, string $label = '', string $describedBy = '' ): void {
		$on = $this->settings->bool( $key );
		$id = $this->id( $key );

		// Tells the sanitizer this toggle was rendered, so "off" is stored.
		printf( '<input type="hidden" name="%s" value="%s">', esc_attr( $this->name( '_fields' ) . '[]' ), esc_attr( $key ) );

		$input = sprintf(
			'<input type="checkbox" role="switch" class="signa-tgl" id="%1$s" name="%2$s" value="1"%3$s%4$s>',
			esc_attr( $id ),
			esc_attr( $this->name( $key ) ),
			$on ? ' checked' : '',
			'' !== $describedBy ? ' aria-describedby="' . esc_attr( $describedBy ) . '"' : ''
		);

		if ( '' === $label ) {
			echo $input; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
			return;
		}

		printf(
			'<label class="signa-toggle" for="%1$s">%2$s<span class="signa-toggle__text">%3$s</span></label>',
			esc_attr( $id ),
			$input, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
			esc_html( $label )
		);
	}

	/**
	 * @param array<string,string> $options
	 */
	public function select( string $key, array $options ): void {
		$current = $this->settings->str( $key );

		echo '<span class="signa-sel"><select class="signa-inp" id="' . esc_attr( $this->id( $key ) ) . '" name="' . esc_attr( $this->name( $key ) ) . '">';

		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) $value ),
				selected( $current, (string) $value, false ),
				esc_html( (string) $label )
			);
		}

		echo '</select>' . Icons::svg( 'chevron', 16, 'signa-sel__chev' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
	}

	/**
	 * Radio cards: one option per card, the chosen one filled.
	 *
	 * @param array<string,array<string,string>> $options value => label/desc
	 * @param string                             $legend  Accessible name of the group.
	 */
	public function cards( string $key, array $options, string $legend = '' ): void {
		$current = $this->settings->str( $key );

		echo '<div class="signa-opts" role="radiogroup"' . ( '' !== $legend ? ' aria-label="' . esc_attr( $legend ) . '"' : '' ) . '>';

		foreach ( $options as $value => $option ) {
			$label = isset( $option['label'] ) ? $option['label'] : (string) $value;
			$desc  = isset( $option['desc'] ) ? $option['desc'] : '';

			printf(
				'<label class="signa-opt%1$s"><input class="signa-opt__input" type="radio" name="%2$s" value="%3$s"%4$s><span class="signa-opt__dot" aria-hidden="true"></span><span class="signa-opt__text"><span class="signa-opt__t">%5$s</span>%6$s</span></label>',
				$current === (string) $value ? ' is-selected' : '',
				esc_attr( $this->name( $key ) ),
				esc_attr( (string) $value ),
				checked( $current, (string) $value, false ),
				esc_html( $label ),
				'' !== $desc ? '<span class="signa-opt__s">' . esc_html( $desc ) . '</span>' : ''
			);
		}

		echo '</div>';
	}

	/**
	 * @param array<string,string> $options
	 */
	public function checkboxList( string $key, array $options ): void {
		$current = (array) $this->settings->arr( $key );

		echo '<div class="signa-checks">';

		foreach ( $options as $value => $label ) {
			printf(
				'<label class="signa-chip signa-check"><input type="checkbox" class="signa-chk" name="%1$s" value="%2$s"%3$s><span>%4$s</span></label>',
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
			'<input type="text" class="signa-inp signa-inp--mono signa-color" id="%1$s" name="%2$s" value="%3$s" data-default-color="%3$s" dir="ltr">',
			esc_attr( $this->id( $key ) ),
			esc_attr( $this->name( $key ) ),
			esc_attr( $this->settings->str( $key ) )
		);
	}

	/* Messages -------------------------------------------------------------- */

	public function notice( string $text, string $type = 'info' ): void {
		printf(
			'<div class="signa-notice signa-notice--%1$s">%2$s<p>%3$s</p></div>',
			esc_attr( $type ),
			Icons::svg( self::noticeIcon( $type ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
			esc_html( $text )
		);
	}

	private static function noticeIcon( string $type ): string {
		$icons = array(
			'info'    => 'info',
			'success' => 'check',
			'warning' => 'alert',
			'error'   => 'alert',
		);

		return isset( $icons[ $type ] ) ? $icons[ $type ] : 'info';
	}

	/**
	 * A notice whose text carries a little markup (code, bold, links).
	 */
	public function richNotice( string $html, string $type = 'info' ): void {
		printf(
			'<div class="signa-notice signa-notice--%1$s">%2$s<p>%3$s</p></div>',
			esc_attr( $type ),
			Icons::svg( self::noticeIcon( $type ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
			wp_kses_post( $html )
		);
	}

	public function description( string $text ): void {
		echo '<p class="signa-desc">' . wp_kses_post( $text ) . '</p>';
	}
}
