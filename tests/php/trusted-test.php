<?php
/**
 * The trusted-number list.
 *
 * The point of this list is to keep a site owner out of their own throttle, so
 * the tests care about two things: that the list matches what an administrator
 * would expect to type, and that it can never loosen a rule it must not touch —
 * a trusted number still needs a code, and a blocked number stays blocked.
 *
 * `Settings` reads the plugin option through `get_option()`, which the test
 * bootstrap stubs, so the real classes run here.
 *
 * @package Signa\Tests
 */

require __DIR__ . '/bootstrap.php';

use Signa\Blocklist\Trusted;
use Signa\Config\Settings;

/**
 * Build a Settings object holding exactly the keys a scenario needs.
 *
 * @param array<string,mixed> $values
 */
function signa_settings( array $values ): Settings {
	$GLOBALS['signa_options']['signa_settings'] = array_merge(
		array(
			'trusted_enabled' => '1',
			'trusted_numbers' => '',
			'trusted_skip'    => 'captcha,throttle',
		),
		$values
	);

	return new Settings();
}

signa_start( 'a list that is switched off trusts nobody' );

$off = new Trusted( signa_settings( array( 'trusted_enabled' => '0', 'trusted_numbers' => '09121234567' ) ) );
signa_check( 'the list is off', ! $off->enabled() );
signa_check( 'and the number is not trusted', ! $off->matches( '09121234567' ) );
signa_check( 'the rule is still parsed', 1 === count( $off->rules() ) );

signa_start( 'numbers, prefixes and patterns' );

$list = new Trusted( signa_settings( array( 'trusted_numbers' => "09121234567\n0912*\n0935*4567" ) ) );

signa_check( 'an exact number is trusted', $list->matches( '09121234567' ) );
signa_check( 'the same number in international form is trusted', $list->matches( '+98 912 123 4567' ) );
signa_check( 'the same number in Persian digits is trusted', $list->matches( '۰۹۱۲۱۲۳۴۵۶۷' ) );
signa_check( 'a prefix covers its range', $list->matches( '09125550000' ) );
signa_check( 'a prefix stops at its boundary', ! $list->matches( '09355550000' ) );
signa_check( 'a pattern matches its middle', $list->matches( '09351114567' ) );
signa_check( 'a pattern rejects a wrong tail', ! $list->matches( '09351114568' ) );
signa_check( 'a stranger is not trusted', ! $list->matches( '09011112222' ) );
signa_check( 'an empty number is not trusted', ! $list->matches( '' ) );

signa_start( 'what an entry may not do' );

$careless = new Trusted( signa_settings( array( 'trusted_numbers' => "*" ) ) );
signa_check( 'a bare star is refused, so nobody trusts everyone by accident', ! $careless->matches( '09121234567' ) );
signa_same( 'and it is not stored as a rule either', array(), $careless->rules() );

$mixed = new Trusted( signa_settings( array( 'trusted_numbers' => "09121234567,\n  , 0936*" ) ) );
signa_same( 'blank entries are skipped, commas allowed', 2, count( $mixed->rules() ) );
signa_check( 'the prefix on the second line still works', $mixed->matches( '09361234567' ) );

signa_start( 'the blocklist is never skipped' );

$skip = new Trusted( signa_settings( array( 'trusted_skip' => 'captcha, throttle, blocklist, bot' ) ) );

signa_check( 'captcha is skipped for a trusted number', $skip->skipsGuard( 'captcha' ) );
signa_check( 'throttle is skipped too, even with a space in the setting', $skip->skipsGuard( 'throttle' ) );
signa_check( 'bot is skipped because the administrator asked', $skip->skipsGuard( 'bot' ) );
signa_check( 'the blocklist is never skipped', ! $skip->skipsGuard( 'blocklist' ) );
signa_check( 'a guard that was not named is not skipped', ! $skip->skipsGuard( 'something_else' ) );

$default = new Trusted( signa_settings( array() ) );
signa_check( 'the default list skips the captcha', $default->skipsGuard( 'captcha' ) );
signa_check( 'the default list skips the throttle', $default->skipsGuard( 'throttle' ) );
signa_check( 'and never the blocklist', ! $default->skipsGuard( 'blocklist' ) );

$empty = new Trusted( signa_settings( array( 'trusted_skip' => '' ) ) );
signa_same( 'an empty setting skips nothing', array(), $empty->skips() );

signa_finish();
