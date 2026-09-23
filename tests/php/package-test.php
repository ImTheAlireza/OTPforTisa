<?php
/**
 * The package knows what it is, and can prove it.
 *
 * A version number cannot answer "is this install complete?", because two
 * halves of a mixed install both carry the same number — that is exactly how a
 * site ended up fataling on every request. A hash per file can.
 *
 * The last check in this file runs against the real plugin directory, so it
 * also fails whenever somebody edits a plugin file and forgets to rebuild. That
 * is deliberate: it is the same mistake as shipping a stale zip, caught in CI.
 *
 * @package TisaOtp\Tests
 */

require __DIR__ . '/bootstrap.php';

use TisaOtp\Install\Guard;
use TisaOtp\Install\Package;

/**
 * Build a throwaway plugin directory with a manifest for its two files.
 *
 * @param string $note Text appended to one file, to make it stale.
 * @param bool   $drop Remove one file, to make it missing.
 * @return string Root path, with a trailing slash.
 */
function tisa_fake_package( string $note = '', bool $drop = false ): string {
	$root = sys_get_temp_dir() . '/tisa-package-' . bin2hex( random_bytes( 4 ) ) . '/';

	mkdir( $root . 'src', 0777, true );

	file_put_contents( $root . 'src/One.php', "<?php\n// one\n" . $note );
	file_put_contents( $root . 'src/Two.php', "<?php\n// two\n" );

	$manifest = array(
		'name'    => 'tisa-otp',
		'version' => '9.9.9',
		'files'   => array(
			'src/One.php' => hash( 'sha256', "<?php\n// one\n" ),
			'src/Two.php' => hash( 'sha256', "<?php\n// two\n" ),
		),
	);

	file_put_contents( $root . 'build.json', json_encode( $manifest ) );

	if ( $drop ) {
		unlink( $root . 'src/Two.php' );
	}

	return $root;
}

tisa_start( 'a package that is all one version says so' );

$root  = tisa_fake_package();
$state = Package::verify( $root, true );

tisa_check( 'the files match the manifest', $state['ok'] );
tisa_same( 'both were checked', 2, $state['checked'] );
tisa_same( 'the version comes from the manifest', '9.9.9', $state['version'] );
tisa_same( 'and nothing is named', array(), Package::offenders( $state ) );

tisa_start( 'one file from somewhere else is caught, by name' );

$state = Package::verify( tisa_fake_package( '// edited by hand' ), true );

tisa_check( 'the package is not what it says it is', ! $state['ok'] );
tisa_same( 'and the file is named', array( 'src/One.php' ), $state['stale'] );

tisa_start( 'a file that never arrived is caught too' );

$state = Package::verify( tisa_fake_package( '', true ), true );

tisa_same( 'the missing file is named as missing, not as changed', array( 'src/Two.php' ), $state['missing'] );

tisa_start( 'a package with no manifest cannot claim to be complete' );

$bare = sys_get_temp_dir() . '/tisa-bare-' . bin2hex( random_bytes( 4 ) ) . '/';
mkdir( $bare, 0777, true );

$state = Package::verify( $bare, true );

tisa_check( 'it is not ok', ! $state['ok'] );
tisa_same( 'and it says why', 'manifest_missing', $state['note'] );

tisa_start( 'the helper that lists what is wrong, lists everything' );

$state = Package::verify( tisa_fake_package( '// stale', true ), true );

tisa_same( 'missing and changed files in one list', array( 'src/Two.php', 'src/One.php' ), Package::offenders( $state ) );

tisa_start( 'the shipped package is the package in this repository' );

$real = Package::verify( TISA_OTP_PATH, true );

tisa_check(
	'build.json describes every file, and matches it (run tools/build_package.py after editing the plugin)',
	$real['ok']
);

if ( ! $real['ok'] ) {
	$offence = Package::offenders( $real );

	echo '   manifest note: ' . $real['note'] . ', checked ' . $real['checked'] . "\n";
	echo '   offenders: ' . implode( ', ', array_slice( $offence, 0, 10 ) ) . "\n";
}

tisa_same( 'and the version in it is the version of the plugin', TISA_OTP_VERSION, $real['version'] );

tisa_start( 'the guard turns a broken service into a sentence, not a fatal' );

tisa_check( 'a healthy plugin records no failures', empty( Guard::failures() ) );

Guard::record( 'TisaOtp\Http\SomeService', new RuntimeException( 'boom' ) );

tisa_check( 'a failure is remembered', Guard::failed( 'TisaOtp\Http\SomeService' ) );
tisa_check( 'and only that one is', ! Guard::failed( 'TisaOtp\Log\Logger' ) );

$notice = new ReflectionMethod( Guard::class, 'printFailures' );
$notice->setAccessible( true );

ob_start();
$notice->invoke( new Guard() );
$markup = (string) ob_get_clean();

tisa_check( 'the notice names the service that did not start', false !== strpos( $markup, 'TisaOtp\Http\SomeService' ) );
tisa_check( 'it explains the likely cause in Persian', false !== strpos( $markup, 'بستهٔ نصب‌شده کامل جایگزین نشده' ) );
tisa_check( 'and it says what to do', false !== strpos( $markup, 'جایگزینی با نسخهٔ بارگذاری‌شده' ) );
tisa_check( 'it is an error, not something to scroll past', false !== strpos( $markup, 'notice-error' ) );
tisa_check( 'and the raw message stays out of the page unless debugging', false === strpos( $markup, 'boom' ) );

tisa_finish();
