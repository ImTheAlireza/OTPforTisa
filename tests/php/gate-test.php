<?php
/**
 * The licensed file is loaded only where it can run.
 *
 * RTL-Theme encodes Menu.php with ionCube. An encoded file on a host without
 * the loader ends the request — the whole page, login form included. The gate
 * must notice that before it loads anything, keep the visitor side untouched,
 * and tell the administrator what to do.
 *
 * @package Signa\Tests
 */

require __DIR__ . '/bootstrap.php';

use Signa\Admin\Gate;
use Signa\Admin\Page;
use Signa\Bootable;

if ( ! function_exists( 'add_menu_page' ) ) {
	function add_menu_page( ...$args ) {
		$GLOBALS['signa_menu_pages'][] = $args;

		return '';
	}
}

/**
 * A stand-in for Menu that only remembers it was started.
 */
final class Signa_Fake_Menu implements Bootable {
	/** @var int */
	public static $booted = 0;

	public function boot(): void {
		self::$booted++;
	}
}

/**
 * Write a throwaway file and return its path.
 *
 * @param string $body File contents.
 * @return string
 */
function signa_gate_file( string $body ): string {
	$path = sys_get_temp_dir() . '/signa-gate-' . bin2hex( random_bytes( 4 ) ) . '.php';
	file_put_contents( $path, $body );

	return $path;
}

/**
 * @param string $file Licensed file for this gate.
 * @return array{0:Gate,1:int} The gate, and how many menus were built.
 */
function signa_gate( string $file ): array {
	$GLOBALS['signa_hooks'] = array();
	$built                  = 0;

	$gate = new Gate(
		static function () use ( &$built ) {
			$built++;

			return new Signa_Fake_Menu();
		},
		$file
	);

	$gate->boot();

	return array( $gate, $built );
}

$plain   = signa_gate_file( "<?php\nnamespace Signa\\Admin;\nfinal class Menu {}\n" );
$ioncube = signa_gate_file( "<?php //0046a\n// Copyright\nif(!extension_loaded('ionCube Loader')){\$__oc=strtolower(substr(php_uname(),0,3));die('Site error: the file requires the ionCube PHP Loader');}\n?>\nHR+cPrZ...\n" );
$sg      = signa_gate_file( "<?php\n@\"SourceGuardian\";\nif(!function_exists('sg_load')){die('loader');}\nreturn sg_load('ABCD');\n" );

signa_start( 'telling encoded files from plain ones' );

signa_same( 'plain PHP is plain', '', Gate::encoder( $plain ) );
signa_same( 'an ionCube stub is recognised', 'ioncube', Gate::encoder( $ioncube ) );
signa_same( 'so is a SourceGuardian one', 'sourceguardian', Gate::encoder( $sg ) );
signa_same( 'a file that is not there is not encoded', '', Gate::encoder( '/nonexistent/signa.php' ) );
signa_same( 'the real Menu.php in this repository is plain', '', Gate::encoder( SIGNA_PATH . Gate::LICENSED ) );

signa_start( 'the loader a file needs and this server lacks' );

signa_same( 'plain PHP needs nothing', '', Gate::missingLoader( $plain ) );
signa_same(
	'ionCube is asked for only when it is not loaded',
	extension_loaded( 'ionCube Loader' ) ? '' : 'ionCube Loader',
	Gate::missingLoader( $ioncube )
);

signa_start( 'outside the admin the gate does nothing at all' );

$GLOBALS['signa_admin_screen'] = false;
Signa_Fake_Menu::$booted        = 0;

list( , $built ) = signa_gate( $ioncube );

signa_same( 'no menu is built on the front end', 0, $built );
signa_check( 'and no hook is added', empty( $GLOBALS['signa_hooks'] ) );

signa_start( 'in the admin, a plain Menu.php opens the panel as before' );

$GLOBALS['signa_admin_screen'] = true;
Signa_Fake_Menu::$booted        = 0;

list( , $built ) = signa_gate( $plain );

signa_same( 'the menu is built once', 1, $built );
signa_same( 'and started', 1, Signa_Fake_Menu::$booted );
signa_check( 'no fallback page is registered', empty( $GLOBALS['signa_hooks']['admin_notices'] ) );

if ( ! extension_loaded( 'ionCube Loader' ) ) {
	signa_start( 'in the admin, an encoded Menu.php without its loader is never loaded' );

	Signa_Fake_Menu::$booted = 0;

	list( $gate, $built ) = signa_gate( $ioncube );

	signa_same( 'the licensed file is not touched', 0, $built );
	signa_check( 'a fallback page takes its place', ! empty( $GLOBALS['signa_hooks']['admin_menu'] ) );
	signa_check( 'and a notice says why', ! empty( $GLOBALS['signa_hooks']['admin_notices'] ) );

	$GLOBALS['signa_menu_pages'] = array();
	$gate->registerFallback();

	signa_same( 'the fallback sits where the panel usually is', Page::ROOT, $GLOBALS['signa_menu_pages'][0][3] );
	signa_same( 'behind the same capability', Page::CAPABILITY, $GLOBALS['signa_menu_pages'][0][2] );

	ob_start();
	$gate->render();
	$page = (string) ob_get_clean();

	signa_check( 'the page names the missing loader', false !== strpos( $page, 'ionCube Loader' ) );
	signa_check( 'it says the login form keeps working', false !== strpos( $page, 'فرم ورود و عضویت سایت همچنان' ) );
	signa_check( 'and it says how to fix it', false !== strpos( $page, 'پشتیبانی هاست' ) );
	signa_check( 'it mentions the marketplace license manager', false !== strpos( $page, 'مدیریت هوشمند راست‌چین' ) );

	$GLOBALS['signa_may_manage'] = true;

	ob_start();
	$gate->notice();
	$notice = (string) ob_get_clean();

	signa_check( 'the notice is an error an administrator will see', false !== strpos( $notice, 'notice-error' ) );
	signa_check( 'and it names the extension', false !== strpos( $notice, 'ionCube Loader' ) );

	$GLOBALS['signa_may_manage'] = false;

	ob_start();
	$gate->notice();

	signa_same( 'nobody else sees it', '', (string) ob_get_clean() );
}

signa_start( 'nothing else reaches into the licensed file' );

$reach = array();

foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( SIGNA_PATH . 'src' ) ) as $file ) {
	if ( ! $file->isFile() || '.php' !== substr( $file->getFilename(), -4 ) ) {
		continue;
	}

	$relative = str_replace( SIGNA_PATH, '', $file->getPathname() );

	if ( in_array( $relative, array( Gate::LICENSED, 'src/Plugin.php' ), true ) ) {
		continue;
	}

	if ( preg_match( '/\bMenu::/', (string) file_get_contents( $file->getPathname() ) ) ) {
		$reach[] = $relative;
	}
}

signa_same( 'only the container builds Menu; everything else asks Page', array(), $reach );

$boot = (string) file_get_contents( SIGNA_PATH . 'src/Plugin.php' );

signa_check( 'the plugin boots the gate', false !== strpos( $boot, 'Admin\\Gate::class,' ) );
signa_check( 'and never boots Menu directly', false === strpos( $boot, "\t\t\tAdmin\\Menu::class,\n" ) );

$GLOBALS['signa_admin_screen'] = false;

foreach ( array( $plain, $ioncube, $sg ) as $path ) {
	unlink( $path );
}

signa_finish();
