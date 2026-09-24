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
 * @package Signa\Tests
 */

require __DIR__ . '/bootstrap.php';

use Signa\Admin\AccessScreen;
use Signa\Admin\AppMode;
use Signa\Admin\LogsScreen;
use Signa\Admin\Menu;
use Signa\Admin\ReportScreen;
use Signa\Admin\ScreenNav;
use Signa\Admin\SettingsScreen;
use Signa\Admin\ToolsScreen;

/**
 * Render the switcher for one screen and hand back the markup.
 */
function signa_nav( string $current ): string {
	ob_start();
	ScreenNav::render( $current );

	return (string) ob_get_clean();
}

signa_start( 'the row lists every screen the plugin registers' );

$screens = ScreenNav::screens();
$slugs   = array_keys( $screens );

signa_same(
	'five screens, settings first (same order as the WordPress menu)',
	array( Menu::ROOT, ReportScreen::SLUG, LogsScreen::SLUG, ToolsScreen::SLUG, AccessScreen::SLUG ),
	$slugs
);

signa_check(
	'every label is non-empty',
	count( array_filter( $screens, static function ( $label ) {
		return '' !== trim( (string) $label );
	} ) ) === count( $screens )
);

signa_check(
	'every screen class owns its own slug',
	count( array_unique( $slugs ) ) === count( $slugs ) && ! in_array( '', $slugs, true )
);

signa_start( 'the row links to the screens, and marks the current one' );

$markup = signa_nav( ReportScreen::SLUG );

foreach ( $slugs as $slug ) {
	signa_check(
		'a link to ' . $slug,
		false !== strpos( $markup, 'href="' . ScreenNav::url( $slug ) . '"' )
	);
}

signa_same(
	'the reports row opens the settings tab, so the plugin keeps one home for it',
	SettingsScreen::tabUrl( 'reports' ),
	ScreenNav::url( ReportScreen::SLUG )
);

signa_check(
	'the other four screens are still their own pages',
	false !== strpos( ScreenNav::url( LogsScreen::SLUG ), 'page=' . LogsScreen::SLUG )
		&& false !== strpos( ScreenNav::url( ToolsScreen::SLUG ), 'page=' . ToolsScreen::SLUG )
		&& false !== strpos( ScreenNav::url( AccessScreen::SLUG ), 'page=' . AccessScreen::SLUG )
);

signa_same( 'one "you are here" marker', 1, substr_count( $markup, 'aria-current="page"' ) );
signa_same( 'and it is on the screen being rendered', 1, substr_count( $markup, 'class="signa-screen is-current" aria-current="page"' ) );
signa_check(
	'the marker sits on the reports link, not on some other one',
	false !== strpos( $markup, 'href="' . ScreenNav::url( ReportScreen::SLUG ) . '" class="signa-screen is-current" aria-current="page"' )
);

signa_start( 'every screen gets its own marker and only its own' );

foreach ( $slugs as $slug ) {
	$html = signa_nav( $slug );

	signa_same( 'exactly one marker on ' . $slug, 1, substr_count( $html, 'aria-current="page"' ) );
	signa_check(
		'the marker is on ' . $slug,
		false !== strpos( $html, 'href="' . ScreenNav::url( $slug ) . '" class="signa-screen is-current" aria-current="page"' )
	);
	signa_same( 'the switcher always shows all five screens on ' . $slug, 5, substr_count( $html, '<li>' ) );
}

signa_start( 'the same row carries the full-screen switch' );

$html = signa_nav( Menu::ROOT );

signa_check( 'the switch is a post, so a link cannot flip it', false !== strpos( $html, 'method="post"' ) && false !== strpos( $html, 'value="' . AppMode::ACTION . '"' ) );
signa_check( 'it carries a nonce', false !== strpos( $html, 'value="nonce-' . AppMode::ACTION . '"' ) );
signa_check( 'and it offers the state it is not in', false !== strpos( $html, 'حالت اپ' ) );

$GLOBALS['signa_current_user'] = 7;
$GLOBALS['signa_user_meta']    = array( 7 => array( AppMode::META => '1' ) );

$html = signa_nav( Menu::ROOT );

signa_check( 'once it is on, the same button says how to get back', false !== strpos( $html, 'نمای پیشخوان' ) && false === strpos( $html, '>حالت اپ<' ) );
signa_check( 'and the body is marked for the stylesheet', false !== strpos( ( new AppMode() )->bodyClass( 'wp-admin' ), 'signa-app' ) );

$GLOBALS['signa_user_meta'] = array();

signa_start( 'the row is accessible, not just clickable' );

$html = signa_nav( Menu::ROOT );

signa_check( 'it is a nav landmark with a name', (bool) preg_match( '/<nav class="signa-screens" aria-label="[^"]+"/u', $html ) );
signa_check( 'it is a list, so a screen reader counts the items', false !== strpos( $html, '<ul>' ) && false !== strpos( $html, '</ul>' ) );
signa_check( 'and it is labelled in Persian, not in the code', false === strpos( $html, 'Screen' ) );

signa_finish();
