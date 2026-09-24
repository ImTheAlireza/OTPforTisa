<?php
/**
 * The colour maths behind the design self-test.
 *
 * The design tab measures whatever accent and surface the site owner typed in,
 * and the CI gate measures the palette the plugin ships. If the two disagreed
 * about what 4.5:1 means, one of them would be lying to somebody. These checks
 * pin the PHP numbers to the same values the front-end gate passes with.
 *
 * @package Signa\Tests
 */

require __DIR__ . '/bootstrap.php';

use Signa\Support\Colour;

signa_start( 'the shipped palette measures the way the CI gate measures it' );

/*
 * The expected numbers are the ones `tests/contrast.js` prints for the same
 * pairs on white; the tolerance is there because both sides round to two
 * decimals.
 */
$palette = array(
	'#0f766e' => 5.47,
	'#2563eb' => 5.17,
	'#7c3aed' => 5.70,
	'#e11d48' => 4.70,
	'#ea580c' => 3.56,
	'#111827' => 17.74,
);

foreach ( $palette as $colour => $expected ) {
	$ratio = Colour::ratio( $colour, '#ffffff' );

	signa_check(
		$colour . ' on white is ' . $expected . ':1',
		null !== $ratio && abs( $ratio - $expected ) < 0.02
	);

	// Contrast is symmetric: a button label is the same pair the other way round.
	signa_check( $colour . ' measures the same on either side', abs( (float) $ratio - (float) Colour::ratio( '#ffffff', $colour ) ) < 0.01 );
}

signa_start( 'the threshold the design test uses is the threshold that matters' );

signa_same( 'the default accent passes 4.5:1', true, Colour::ratio( '#0f766e', '#ffffff' ) >= 4.5 );
signa_same( 'orange fails it, so the test has something to catch', true, Colour::ratio( '#ea580c', '#ffffff' ) < 4.5 );

signa_start( 'colours are read the way CSS writes them' );

signa_check( 'rgb() and hex agree', abs( (float) Colour::ratio( 'rgb(15, 118, 110)', '#fff' ) - (float) Colour::ratio( '#0f766e', '#ffffff' ) ) < 0.01 );
signa_check( 'uppercase and spaces do not change the answer', abs( (float) Colour::ratio( '#0F766E', ' #FFFFFF ' ) - 5.47 ) < 0.02 );
signa_check( 'a three-digit shorthand is expanded', abs( (float) Colour::ratio( '#fff', '#000' ) - 21.0 ) < 0.01 );
signa_check( 'black on white is the maximum ratio', abs( (float) Colour::ratio( '#000000', '#ffffff' ) - 21.0 ) < 0.01 );
signa_check( 'a colour equals itself at 1:1', abs( (float) Colour::ratio( '#0f766e', '#0f766e' ) - 1.0 ) < 0.01 );
signa_check( 'rgba() is flattened, not ignored', null !== Colour::ratio( 'rgba(15, 118, 110, 0.5)', '#ffffff' ) );
signa_check( 'four digits are the short form with alpha, and alpha is dropped', abs( (float) Colour::ratio( '#0f76', '#ffffff' ) - (float) Colour::ratio( '#00ff77', '#ffffff' ) ) < 0.01 );

signa_start( 'a colour nobody can read says so instead of guessing' );

signa_same( 'an empty string has no ratio', null, Colour::ratio( '', '#ffffff' ) );
signa_same( 'a word has no ratio', null, Colour::ratio( 'teal', '#ffffff' ) );
signa_same( 'a five-digit hex has no ratio', null, Colour::ratio( '#12345', '#ffffff' ) );
signa_same( 'a length has no ratio', null, Colour::ratio( '12px', '#ffffff' ) );
signa_same( 'and luminance agrees', null, Colour::luminance( 'var(--signa-accent)' ) );

signa_finish();
