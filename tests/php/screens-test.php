<?php
/**
 * The screen switcher.
 *
 * Eight settings sections and three tool screens, one side navigation, and it
 * has to agree with the WordPress sidebar at all times — a screen you can see
 * in one place and not the other is exactly the bug this class exists to
 * prevent. The tests render
 * the real class (with the escaping stubs from the bootstrap) and check the
 * three things that can silently break: the slugs, the order, and the marker
 * that says "you are here".
 *
 * @package Signa\Tests
 */

require __DIR__ . '/bootstrap.php';

use Signa\Admin\AccessScreen;
use Signa\Admin\AppMode;
use Signa\Admin\Icons;
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

signa_start( 'the nav lists every section and every screen the plugin registers' );

$sections = ScreenNav::sections();
$tools    = ScreenNav::tools();

signa_same(
	'eight sections, dashboard first, in the order of the design',
	array( 'dash', 'login', 'channels', 'formskin', 'security', 'integ', 'reports', 'advanced' ),
	array_keys( $sections )
);

signa_same(
	'three tool screens, each a page of its own in the WordPress menu',
	array( LogsScreen::SLUG, ToolsScreen::SLUG, AccessScreen::SLUG ),
	array_keys( $tools )
);

signa_check(
	'every label and icon is non-empty, and every icon exists',
	count( array_filter( $sections + $tools, static function ( $item ) {
		return '' !== trim( (string) $item[0] ) && in_array( $item[1], Icons::names(), true );
	} ) ) === count( $sections ) + count( $tools )
);

signa_same(
	'the in-form sections are the sections minus the two pages',
	array_values( array_diff( array_keys( $sections ), array( 'dash', 'reports' ) ) ),
	ScreenNav::formSections()
);

signa_start( 'the nav links to the screens, and marks the current one' );

$markup = signa_nav( 'reports' );
$ids    = array_merge( array_keys( $sections ), array_keys( $tools ) );

foreach ( $ids as $id ) {
	signa_check( 'a link to ' . $id, false !== strpos( $markup, 'href="' . ScreenNav::url( $id ) . '"' ) );
}

signa_same(
	'the reports screen opens the settings section, so the plugin keeps one home for it',
	SettingsScreen::tabUrl( 'reports' ),
	ScreenNav::url( ReportScreen::SLUG )
);

signa_same( 'the plugin root opens the dashboard', SettingsScreen::tabUrl( 'dash' ), ScreenNav::url( Menu::ROOT ) );

signa_check(
	'the three tool screens are still their own pages',
	false !== strpos( ScreenNav::url( LogsScreen::SLUG ), 'page=' . LogsScreen::SLUG )
		&& false !== strpos( ScreenNav::url( ToolsScreen::SLUG ), 'page=' . ToolsScreen::SLUG )
		&& false !== strpos( ScreenNav::url( AccessScreen::SLUG ), 'page=' . AccessScreen::SLUG )
);

signa_same( 'one "you are here" marker', 1, substr_count( $markup, 'aria-current="page"' ) );
signa_check(
	'the marker sits on the reports link, not on some other one',
	false !== strpos( $markup, 'href="' . ScreenNav::url( 'reports' ) . '" class="signa-screen is-current" aria-current="page"' )
);
signa_check( 'the reports screen\'s own slug marks the same link', signa_nav( ReportScreen::SLUG ) === $markup );
signa_check( 'and the root marks the dashboard', signa_nav( Menu::ROOT ) === signa_nav( 'dash' ) );

signa_start( 'every screen gets its own marker and only its own' );

foreach ( $ids as $id ) {
	$html = signa_nav( $id );

	signa_same( 'exactly one marker on ' . $id, 1, substr_count( $html, 'aria-current="page"' ) );
	signa_check(
		'the marker is on ' . $id,
		false !== strpos( $html, 'href="' . ScreenNav::url( $id ) . '" class="signa-screen is-current" aria-current="page"' )
	);
	signa_same( 'the nav always shows all eleven entries on ' . $id, 11, substr_count( $html, '<li>' ) );
}

signa_start( 'only the sections inside the form switch in place' );

$html = signa_nav( 'dash' );

foreach ( ScreenNav::formSections() as $id ) {
	signa_check( $id . ' carries the in-place hook', false !== strpos( $html, 'data-signa-section="' . $id . '"' ) );
}

signa_check(
	'the dashboard, the reports and the tools load as pages',
	false === strpos( $html, 'data-signa-section="dash"' )
		&& false === strpos( $html, 'data-signa-section="reports"' )
		&& false === strpos( $html, 'data-signa-section="' . LogsScreen::SLUG . '"' )
);

signa_start( 'the same row carries the full-screen switch' );

function signa_toggle(): string {
	ob_start();
	ScreenNav::appToggle();

	return (string) ob_get_clean();
}

$html = signa_toggle();

signa_check( 'the switch is a post, so a link cannot flip it', false !== strpos( $html, 'method="post"' ) && false !== strpos( $html, 'value="' . AppMode::ACTION . '"' ) );
signa_check( 'it carries a nonce', false !== strpos( $html, 'value="nonce-' . AppMode::ACTION . '"' ) );
signa_check( 'and it offers the state it is not in', false !== strpos( $html, 'حالت اپ' ) );

$GLOBALS['signa_current_user'] = 7;
$GLOBALS['signa_user_meta']    = array( 7 => array( AppMode::META => '1' ) );

$html = signa_toggle();

signa_check( 'once it is on, the same button says how to get back', false !== strpos( $html, 'نمای پیشخوان' ) && false === strpos( $html, '>حالت اپ<' ) );
signa_check( 'and the body is marked for the stylesheet', false !== strpos( ( new AppMode() )->bodyClass( 'wp-admin' ), 'signa-app' ) );

$GLOBALS['signa_user_meta'] = array();

signa_start( 'the row is accessible, not just clickable' );

$html = signa_nav( Menu::ROOT );

signa_check( 'it is a nav landmark with a name', (bool) preg_match( '/<nav class="signa-screens" aria-label="[^"]+"/u', $html ) );
signa_check( 'it is a list, so a screen reader counts the items', 2 === substr_count( $html, '<ul class="signa-screens__list"' ) && 2 === substr_count( $html, '</ul>' ) );
signa_check( 'the tools list is named by its caption', false !== strpos( $html, 'aria-labelledby="signa-screens-tools"' ) && false !== strpos( $html, 'id="signa-screens-tools"' ) );
signa_check( 'every link carries an icon that the reader skips', substr_count( $html, 'aria-hidden="true"' ) >= 11 );
signa_check( 'and it is labelled in Persian, not in the code', false === strpos( $html, 'Screen' ) );

signa_finish();
