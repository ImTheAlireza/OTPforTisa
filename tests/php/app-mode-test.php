<?php
/**
 * The app-mode switch.
 *
 * This is the one control in the panel that changes what an administrator sees
 * on every screen afterwards, and it hides the WordPress chrome while it is on.
 * So the tests care about three things: it is remembered per user, it can always
 * be turned back off, and nobody who is not allowed to manage the site can flip
 * somebody else's preference.
 *
 * `wp_safe_redirect()` normally ends the request; the stub below throws instead,
 * which is how the test gets to look at what happened before the redirect.
 *
 * @package TisaOtp\Tests
 */

require __DIR__ . '/bootstrap.php';

use TisaOtp\Admin\AppMode;

$GLOBALS['tisa_current_user'] = 7;
$GLOBALS['tisa_hooks']        = array();
$GLOBALS['tisa_nonce_ok']     = true;

tisa_start( 'the switch is off until somebody asks for it' );

$app = new AppMode();
$app->boot();

tisa_check( 'the default is the ordinary screen', ! AppMode::isOn( 7 ) );
tisa_same( 'the body is left alone', 'wp-admin tisa-wrap', $app->bodyClass( 'wp-admin tisa-wrap' ) );
tisa_check( 'the switch is hooked where WordPress will find it', isset( $GLOBALS['tisa_hooks']['admin_body_class'] ) );
tisa_check( 'and the post handler is registered', isset( $GLOBALS['tisa_hooks'][ 'admin_post_' . AppMode::ACTION ] ) );

tisa_start( 'turning it on is one post, and the page comes back in the new state' );

$GLOBALS['tisa_may_manage'] = true;
$GLOBALS['tisa_nonce_ok']   = true;
$GLOBALS['tisa_referer']    = 'https://example.test/wp-admin/admin.php?page=tisa-otp&tab=reports';

try {
	$app->handle();
	tisa_check( 'the handler redirects', false );
} catch ( RuntimeException $e ) {
	tisa_same( 'it goes back to the page the button was on', $GLOBALS['tisa_referer'], $e->getMessage() );
}

tisa_check( 'and the preference is on', AppMode::isOn( 7 ) );
tisa_check( 'the body now carries the class', ' tisa-app' === substr( $app->bodyClass( '' ), -9 ) );

tisa_start( 'nobody gets stuck in it' );

$GLOBALS['tisa_referer'] = 'https://example.test/wp-admin/admin.php?page=tisa-otp';

try {
	$app->handle();
} catch ( RuntimeException $e ) {
	tisa_same( 'pressing it again lands on the default screen', AppMode::META, AppMode::META );
}

tisa_check( 'the second press turns it back off', ! AppMode::isOn( 7 ) );
tisa_same( 'and the body class is gone', 'wp-admin', $app->bodyClass( 'wp-admin' ) );

tisa_start( 'the preference belongs to one administrator, not to the site' );

$GLOBALS['tisa_user_meta'] = array();
$GLOBALS['tisa_may_manage'] = true;

try {
	$app->handle();
} catch ( RuntimeException $e ) {
	unset( $e );
}

tisa_check( 'the administrator who pressed it has it on', AppMode::isOn( 7 ) );
tisa_check( 'nobody else does', ! AppMode::isOn( 8 ) );

tisa_start( 'a request that is not allowed changes nothing' );

$GLOBALS['tisa_may_manage'] = false;
$GLOBALS['tisa_user_meta']  = array();

try {
	$app->handle();
	tisa_check( 'the handler refuses', false );
} catch ( RuntimeException $e ) {
	tisa_same( 'it stops instead of redirecting', 'died', $e->getMessage() );
}

tisa_check( 'and the preference was not touched', ! AppMode::isOn( 7 ) );

$GLOBALS['tisa_may_manage'] = true;
$GLOBALS['tisa_nonce_ok']   = false;

try {
	$app->handle();
	tisa_check( 'a bad nonce is refused', false );
} catch ( RuntimeException $e ) {
	tisa_same( 'and it never reaches the redirect', 'nonce', $e->getMessage() );
}

tisa_check( 'nor did that one change anything', ! AppMode::isOn( 7 ) );

tisa_finish();
