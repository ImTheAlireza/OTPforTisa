<?php

namespace Signa\Admin;

defined( 'ABSPATH' ) || exit;

final class Icons {
	private static function paths(): array {
		return array(
			'home'     => '<path d="M3.5 10.5 12 3.5l8.5 7V20a1.5 1.5 0 0 1-1.5 1.5H5A1.5 1.5 0 0 1 3.5 20z"/><path d="M9.5 21v-6h5v6"/>',
			'login'    => '<path d="M14 3.5h4.5A1.5 1.5 0 0 1 20 5v14a1.5 1.5 0 0 1-1.5 1.5H14"/><path d="M3.5 12H14m0 0-3.5-3.5M14 12l-3.5 3.5"/>',
			'send'     => '<path d="M21 3 10.5 13.5M21 3l-6.8 18-3.7-7.5L3 9.8z"/>',
			'palette'  => '<circle cx="12" cy="12" r="8.6"/><circle cx="8.4" cy="10" r="1.15" fill="currentColor" stroke="none"/><circle cx="12" cy="7.6" r="1.15" fill="currentColor" stroke="none"/><circle cx="15.6" cy="10" r="1.15" fill="currentColor" stroke="none"/><path d="M12 20.6a2.4 2.4 0 0 0 2-3.8c-.9-1.4.4-2.8 2-2.8h1"/>',
			'shield'   => '<path d="M12 2.8 19.2 5.3v5.6c0 4.8-3.2 8.2-7.2 9.3-4-1.1-7.2-4.5-7.2-9.3V5.3z"/><path d="m8.8 11.6 2.2 2.2 4.2-4.4"/>',
			'link'     => '<path d="M9.5 14.5 15 9"/><path d="M11 6.5 13 4.7a3.8 3.8 0 0 1 5.4 5.4l-2 2"/><path d="M13 17.5l-2 1.8a3.8 3.8 0 0 1-5.4-5.4l2-2"/>',
			'chart'    => '<path d="M3.5 3.5v17h17"/><path d="M8.5 15.5v-4M13.5 15.5V8M18.5 15.5v-7"/>',
			'sliders'  => '<path d="M5 6.5h8.2M17.5 6.5H19M5 12h2.2M11.5 12H19M5 17.5h10.2M19 17.5h0"/><circle cx="15.2" cy="6.5" r="2.1"/><circle cx="9.2" cy="12" r="2.1"/><circle cx="17" cy="17.5" r="2.1"/>',
			'mail'     => '<rect x="3" y="5.5" width="18" height="13" rx="2"/><path d="m4 7.5 8 6 8-6"/>',
			'user-add' => '<circle cx="9" cy="8" r="3.4"/><path d="M3.2 20.2c0-3.9 2.6-6 5.8-6s5.8 2.1 5.8 6"/><path d="M17.8 5.5v5M15.3 8h5"/>',
			'key'      => '<circle cx="8" cy="10" r="3.6"/><path d="M10.8 12.7 19 21m-3.2-1.2 2.2 2.2M14 15.6l2.2 2.2"/>',
			'bolt'     => '<path d="M13 2.5 4.5 13.5H11l-1 8L18.5 10.5H12z"/>',
			'check'    => '<path d="m4.5 12.5 5 5 10-11"/>',
			'alert'    => '<path d="M12 3.5 22 20H2z"/><path d="M12 10v4.2M12 17.4v.1"/>',
			'list'     => '<path d="M8.5 6.5H20M8.5 12H20M8.5 17.5H20M4 6.5h.1M4 12h.1M4 17.5h.1"/>',
			'eye'      => '<path d="M2.5 12S6 5.8 12 5.8 21.5 12 21.5 12 18 18.2 12 18.2 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/>',
			'cart'     => '<circle cx="9" cy="20" r="1.5"/><circle cx="17" cy="20" r="1.5"/><path d="M2.5 3.5h2.4l2.6 11.2a1.6 1.6 0 0 0 1.6 1.3h7.6a1.6 1.6 0 0 0 1.6-1.2L20.5 7H6"/>',
			'globe'    => '<circle cx="12" cy="12" r="8.6"/><path d="M3.4 12h17.2M12 3.4c2.4 2.2 3.6 5.2 3.6 8.6s-1.2 6.4-3.6 8.6c-2.4-2.2-3.6-5.2-3.6-8.6S9.6 5.6 12 3.4z"/>',
			'refresh'  => '<path d="M20 12a8 8 0 1 1-2.4-5.7"/><path d="M20 3.5v4.3h-4.3"/>',
			'info'     => '<circle cx="12" cy="12" r="8.6"/><path d="M12 11v5.2M12 7.6v.1"/>',
			'chevron'  => '<path d="m6 9.5 6 6 6-6"/>',
			'lock'     => '<rect x="5" y="10.5" width="14" height="9.5" rx="2"/><path d="M8 10.5V7.8a4 4 0 0 1 8 0v2.7"/><path d="M12 14.5v2"/>',
			'download' => '<path d="M12 3.5V15m0 0 4.5-4.5M12 15 7.5 10.5"/><path d="M4 17.5V19a1.5 1.5 0 0 0 1.5 1.5h13A1.5 1.5 0 0 0 20 19v-1.5"/>',
			'database' => '<ellipse cx="12" cy="5" rx="8" ry="2.8"/><path d="M4 5v14c0 1.6 3.6 2.8 8 2.8s8-1.2 8-2.8V5"/><path d="M4 12c0 1.6 3.6 2.8 8 2.8s8-1.2 8-2.8"/>',
			'phone'    => '<rect x="6" y="2.5" width="12" height="19" rx="3"/><path d="M10.5 5.5h3"/><path d="M9 12.5l2 2 4-4.5"/>',
			'tools'    => '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.2L3.8 17a1.8 1.8 0 0 0 2.5 2.5l5.5-5.5a4 4 0 0 0 5.2-5.4l-2.4 2.4-2.3-.6-.6-2.3z"/>',
			'ban'      => '<circle cx="12" cy="12" r="8.6"/><path d="m5.9 5.9 12.2 12.2"/>',
			'code'     => '<path d="m8.5 7.5-5 4.5 5 4.5M15.5 7.5l5 4.5-5 4.5"/>',
			'fields'   => '<rect x="3.5" y="4.5" width="17" height="5" rx="1.5"/><rect x="3.5" y="14.5" width="17" height="5" rx="1.5"/>',
			'trash'    => '<path d="M4.5 7h15M9.5 7V4.5h5V7M6.5 7l1 13h9l1-13"/>',
			'expand'   => '<path d="M4 9.5V4h5.5M20 9.5V4h-5.5M4 14.5V20h5.5M20 14.5V20h-5.5"/>',
			'clock'    => '<circle cx="12" cy="12" r="8.6"/><path d="M12 7.5V12l3 2"/>',
			'x'        => '<path d="m6.5 6.5 11 11M17.5 6.5l-11 11"/>',
		);
	}

	public static function names(): array {
		return array_keys( self::paths() );
	}

	public static function svg( string $name, int $size = 16, string $class = '' ): string {
		$paths = self::paths();

		if ( ! isset( $paths[ $name ] ) ) {
			return '';
		}

		return sprintf(
			'<svg class="signa-ic%1$s" width="%2$d" height="%2$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%3$s</svg>',
			'' !== $class ? ' ' . $class : '',
			$size,
			$paths[ $name ]
		);
	}

	public static function out( string $name, int $size = 16, string $class = '' ): void {
		echo self::svg( $name, $size, $class );
	}
}
