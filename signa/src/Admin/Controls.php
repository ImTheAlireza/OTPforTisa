<?php

namespace Signa\Admin;

use Signa\Config\Sanitizer;
use Signa\Config\Settings;

defined( 'ABSPATH' ) || exit;

final class Controls {
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

	public function grid( int $columns, callable $body ): void {
		echo '<div class="signa-grid signa-grid--' . (int) max( 1, min( 3, $columns ) ) . '">';
		$body();
		echo '</div>';
	}

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

	public function toggle( string $key, string $label = '', string $describedBy = '' ): void {
		$on = $this->settings->bool( $key );
		$id = $this->id( $key );

		printf( '<input type="hidden" name="%s" value="%s">', esc_attr( $this->name( '_fields' ) . '[]' ), esc_attr( $key ) );

		$input = sprintf(
			'<input type="checkbox" role="switch" class="signa-tgl" id="%1$s" name="%2$s" value="1"%3$s%4$s>',
			esc_attr( $id ),
			esc_attr( $this->name( $key ) ),
			$on ? ' checked' : '',
			'' !== $describedBy ? ' aria-describedby="' . esc_attr( $describedBy ) . '"' : ''
		);

		if ( '' === $label ) {
			echo $input;
			return;
		}

		printf(
			'<label class="signa-toggle" for="%1$s">%2$s<span class="signa-toggle__text">%3$s</span></label>',
			esc_attr( $id ),
			$input,
			esc_html( $label )
		);
	}

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

		echo '</select>' . Icons::svg( 'chevron', 16, 'signa-sel__chev' ) . '</span>';
	}

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

	public function notice( string $text, string $type = 'info' ): void {
		printf(
			'<div class="signa-notice signa-notice--%1$s">%2$s<p>%3$s</p></div>',
			esc_attr( $type ),
			Icons::svg( self::noticeIcon( $type ) ),
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

	public function richNotice( string $html, string $type = 'info' ): void {
		printf(
			'<div class="signa-notice signa-notice--%1$s">%2$s<p>%3$s</p></div>',
			esc_attr( $type ),
			Icons::svg( self::noticeIcon( $type ) ),
			wp_kses_post( $html )
		);
	}

	public function description( string $text ): void {
		echo '<p class="signa-desc">' . wp_kses_post( $text ) . '</p>';
	}
}
