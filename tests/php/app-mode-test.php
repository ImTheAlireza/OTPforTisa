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
 * @package Signa\Tests
 */

require __DIR__ . '/bootstrap.php';

use Signa\Admin\AppMode;

$GLOBALS['signa_current_user'] = 7;
$GLOBALS['signa_hooks']        = array();
$GLOBALS['signa_nonce_ok']     = true;

signa_start( 'the switch is off until somebody asks for it' );

$app = new AppMode();
$app->boot();

signa_check( 'the default is the ordinary screen', ! AppMode::isOn( 7 ) );
signa_same( 'the body is left alone', 'wp-admin signa-wrap', $app->bodyClass( 'wp-admin signa-wrap' ) );
signa_check( 'the switch is hooked where WordPress will find it', isset( $GLOBALS['signa_hooks']['admin_body_class'] ) );
signa_check( 'and the post handler is registered', isset( $GLOBALS['signa_hooks'][ 'admin_post_' . AppMode::ACTION ] ) );

signa_start( 'turning it on is one post, and the page comes back in the new state' );

$GLOBALS['signa_may_manage'] = true;
$GLOBALS['signa_nonce_ok']   = true;
$GLOBALS['signa_referer']    = 'https://example.test/wp-admin/admin.php?page=signa&tab=reports';

try {
	$app->handle();
	signa_check( 'the handler redirects', false );
} catch ( RuntimeException $e ) {
	signa_same( 'it goes back to the page the button was on', $GLOBALS['signa_referer'], $e->getMessage() );
}

signa_check( 'and the preference is on', AppMode::isOn( 7 ) );
signa_check( 'the body now carries the class', ' signa-app' === substr( $app->bodyClass( '' ), -10 ) );

signa_start( 'nobody gets stuck in it' );

$GLOBALS['signa_referer'] = 'https://example.test/wp-admin/admin.php?page=signa';

try {
	$app->handle();
} catch ( RuntimeException $e ) {
	signa_same( 'pressing it again lands on the default screen', AppMode::META, AppMode::META );
}

signa_check( 'the second press turns it back off', ! AppMode::isOn( 7 ) );
signa_same( 'and the body class is gone', 'wp-admin', $app->bodyClass( 'wp-admin' ) );

signa_start( 'the preference belongs to one administrator, not to the site' );

$GLOBALS['signa_user_meta'] = array();
$GLOBALS['signa_may_manage'] = true;

try {
	$app->handle();
} catch ( RuntimeException $e ) {
	unset( $e );
}

signa_check( 'the administrator who pressed it has it on', AppMode::isOn( 7 ) );
signa_check( 'nobody else does', ! AppMode::isOn( 8 ) );

signa_start( 'a request that is not allowed changes nothing' );

$GLOBALS['signa_may_manage'] = false;
$GLOBALS['signa_user_meta']  = array();

try {
	$app->handle();
	signa_check( 'the handler refuses', false );
} catch ( RuntimeException $e ) {
	signa_same( 'it stops instead of redirecting', 'died', $e->getMessage() );
}

signa_check( 'and the preference was not touched', ! AppMode::isOn( 7 ) );

$GLOBALS['signa_may_manage'] = true;
$GLOBALS['signa_nonce_ok']   = false;

try {
	$app->handle();
	signa_check( 'a bad nonce is refused', false );
} catch ( RuntimeException $e ) {
	signa_same( 'and it never reaches the redirect', 'nonce', $e->getMessage() );
}

signa_check( 'nor did that one change anything', ! AppMode::isOn( 7 ) );

signa_finish();
