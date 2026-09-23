<?php
/**
 * The screen switcher.
 *
 * Five admin screens, one row of links, and the row has to agree with the
 * WordPress sidebar at all times — a screen you can see in one place and not
 * the other is exactly the bug this class exists to prevent. The tests render
 * the real class (with the escaping stubs from the bootstrap) and check the
 * three things that can silently break: the slugs, the order, and the marker
 * that says "you are here".
 *
 * @package TisaOtp\Tests
 */

require __DIR__ . '/bootstrap.php';

use TisaOtp\Admin\AccessScreen;
use TisaOtp\Admin\AppMode;
use TisaOtp\Admin\LogsScreen;
use TisaOtp\Admin\Menu;
use TisaOtp\Admin\ReportScreen;
use TisaOtp\Admin\ScreenNav;
use TisaOtp\Admin\SettingsScreen;
use TisaOtp\Admin\ToolsScreen;

/**
 * Render the switcher for one screen and hand back the markup.
 */
function tisa_nav( string $current ): string {
	ob_start();
	ScreenNav::render( $current );

	return (string) ob_get_clean();
}

tisa_start( 'the row lists every screen the plugin registers' );

$screens = ScreenNav::screens();
$slugs   = array_keys( $screens );

tisa_same(
	'five screens, settings first (same order as the WordPress menu)',
	array( Menu::ROOT, ReportScreen::SLUG, LogsScreen::SLUG, ToolsScreen::SLUG, AccessScreen::SLUG ),
	$slugs
);

tisa_check(
	'every label is non-empty',
	count( array_filter( $screens, static function ( $label ) {
		return '' !== trim( (string) $label );
	} ) ) === count( $screens )
);

tisa_check(
	'every screen class owns its own slug',
	count( array_unique( $slugs ) ) === count( $slugs ) && ! in_array( '', $slugs, true )
);

tisa_start( 'the row links to the screens, and marks the current one' );

$markup = tisa_nav( ReportScreen::SLUG );

foreach ( $slugs as $slug ) {
	tisa_check(
		'a link to ' . $slug,
		false !== strpos( $markup, 'href="' . ScreenNav::url( $slug ) . '"' )
	);
}

tisa_same(
	'the reports row opens the settings tab, so the plugin keeps one home for it',
	SettingsScreen::tabUrl( 'reports' ),
	ScreenNav::url( ReportScreen::SLUG )
);

tisa_check(
	'the other four screens are still their own pages',
	false !== strpos( ScreenNav::url( LogsScreen::SLUG ), 'page=' . LogsScreen::SLUG )
		&& false !== strpos( ScreenNav::url( ToolsScreen::SLUG ), 'page=' . ToolsScreen::SLUG )
		&& false !== strpos( ScreenNav::url( AccessScreen::SLUG ), 'page=' . AccessScreen::SLUG )
);

tisa_same( 'one "you are here" marker', 1, substr_count( $markup, 'aria-current="page"' ) );
tisa_same( 'and it is on the screen being rendered', 1, substr_count( $markup, 'class="tisa-screen is-current" aria-current="page"' ) );
tisa_check(
	'the marker sits on the reports link, not on some other one',
	false !== strpos( $markup, 'href="' . ScreenNav::url( ReportScreen::SLUG ) . '" class="tisa-screen is-current" aria-current="page"' )
);

tisa_start( 'every screen gets its own marker and only its own' );

foreach ( $slugs as $slug ) {
	$html = tisa_nav( $slug );

	tisa_same( 'exactly one marker on ' . $slug, 1, substr_count( $html, 'aria-current="page"' ) );
	tisa_check(
		'the marker is on ' . $slug,
		false !== strpos( $html, 'href="' . ScreenNav::url( $slug ) . '" class="tisa-screen is-current" aria-current="page"' )
	);
	tisa_same( 'the switcher always shows all five screens on ' . $slug, 5, substr_count( $html, '<li>' ) );
}

tisa_start( 'the same row carries the full-screen switch' );

$html = tisa_nav( Menu::ROOT );

tisa_check( 'the switch is a post, so a link cannot flip it', false !== strpos( $html, 'method="post"' ) && false !== strpos( $html, 'value="' . AppMode::ACTION . '"' ) );
tisa_check( 'it carries a nonce', false !== strpos( $html, 'value="nonce-' . AppMode::ACTION . '"' ) );
tisa_check( 'and it offers the state it is not in', false !== strpos( $html, 'حالت اپ' ) );

$GLOBALS['tisa_current_user'] = 7;
$GLOBALS['tisa_user_meta']    = array( 7 => array( AppMode::META => '1' ) );

$html = tisa_nav( Menu::ROOT );

tisa_check( 'once it is on, the same button says how to get back', false !== strpos( $html, 'نمای پیشخوان' ) && false === strpos( $html, '>حالت اپ<' ) );
tisa_check( 'and the body is marked for the stylesheet', false !== strpos( ( new AppMode() )->bodyClass( 'wp-admin' ), 'tisa-app' ) );

$GLOBALS['tisa_user_meta'] = array();

tisa_start( 'the row is accessible, not just clickable' );

$html = tisa_nav( Menu::ROOT );

tisa_check( 'it is a nav landmark with a name', (bool) preg_match( '/<nav class="tisa-screens" aria-label="[^"]+"/u', $html ) );
tisa_check( 'it is a list, so a screen reader counts the items', false !== strpos( $html, '<ul>' ) && false !== strpos( $html, '</ul>' ) );
tisa_check( 'and it is labelled in Persian, not in the code', false === strpos( $html, 'Screen' ) );

tisa_finish();
