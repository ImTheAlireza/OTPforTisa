<?php
/**
 * Behaviour of the break-glass emergency code.
 *
 * @package TisaOtp\Tests
 */

require __DIR__ . '/bootstrap.php';

use TisaOtp\Access\EmergencyToken;

/**
 * Put a token row straight into the option table, as if it had been issued.
 *
 * @param array<string,mixed> $overrides
 */
function tisa_seed( array $overrides ): EmergencyToken {
	$state = array_merge(
		array(
			'hash'       => hash_hmac( 'sha256', 'emergency|12345678', wp_salt( 'auth' ) ),
			'created_at' => time(),
			'expires_at' => time() + 600,
			'uses_left'  => 1,
			'use_limit'  => 1,
			'ip_hash'    => '',
			'ip_lock'    => false,
			'phones'     => array(),
			'fail_count' => 0,
			'used_at'    => 0,
		),
		$overrides
	);

	$GLOBALS['tisa_options'][ EmergencyToken::OPTION ] = $state;

	return new EmergencyToken();
}

tisa_start( 'issue and inspect' );

$token = new EmergencyToken();
tisa_check( 'a fresh install is not armed', ! $token->isArmed() );
tisa_same( 'an unarmed token ignores any input', 'none', $token->inspect( '123456', '09121234567', '1.1.1.1' )['status'] );

tisa_check( 'a code below the minimum length is refused', array() === $token->issue( '123', 30, 1, false, array(), '' ) );
tisa_same( 'the refusal left nothing behind', '', (string) $token->state()['hash'] );

$issued = $token->issue( '44173290', 30, 2, false, array( '09121234567' ), '10.0.0.1' );
tisa_same( 'the issued code is echoed back once', '44173290', $issued['code'] );
tisa_check( 'the secret is armed', $token->isArmed() );
tisa_check( 'the plaintext is not stored', false === strpos( wp_json_encode_stub( $token->state() ), '44173290' ) );

$summary = $token->summary();
tisa_same( 'the use budget is recorded', 2, $summary['uses_left'] );
tisa_check( 'the phone restriction is recorded', $token->phoneAllowed( '09121234567' ) );
tisa_check( 'other numbers are outside the scope', ! $token->phoneAllowed( '09351234567' ) );

tisa_start( 'the wrong guess burns the secret' );

$armed = tisa_seed( array() );

$wrong = $armed->inspect( '00000000', '09121234567', '1.1.1.1' );
tisa_same( 'a wrong code is not a match', 'mismatch', $wrong['status'] );
tisa_same( 'two attempts remain', 2, $wrong['attempts_left'] );

$second = $armed->inspect( '11111111', '09121234567', '1.1.1.1' );
tisa_same( 'one attempt remains', 1, $second['attempts_left'] );

$third = $armed->inspect( '22222222', '09121234567', '1.1.1.1' );
tisa_same( 'the last attempt spends the budget', 0, $third['attempts_left'] );
tisa_check( 'the secret disarmed itself', ! $armed->isArmed() );

tisa_start( 'the right guess is narrow' );

$right = tisa_seed( array() );
$verdict = $right->inspect( '12345678', '09121234567', '1.1.1.1' );
tisa_same( 'the right code matches', 'match', $verdict['status'] );

$locked = tisa_seed(
	array(
		'ip_lock' => true,
		'ip_hash' => hash_hmac( 'sha256', 'emergency-ip|10.0.0.1', wp_salt( 'auth' ) ),
	)
);

tisa_same( 'the same address passes the lock', 'match', $locked->inspect( '12345678', '09121234567', '10.0.0.1' )['status'] );
tisa_same( 'another address is refused', 'wrong_ip', $locked->inspect( '12345678', '09121234567', '10.0.0.2' )['status'] );

tisa_start( 'an expired secret cannot be revived' );

$stale = tisa_seed( array( 'expires_at' => time() - 1 ) );

tisa_check( 'it reports itself as expired', $stale->isExpired() );
tisa_check( 'it is not armed', ! $stale->isArmed() );
tisa_same( 'even the correct code gets no special treatment', 'none', $stale->inspect( '12345678', '09121234567', '1.1.1.1' )['status'] );
tisa_same( 'and it was wiped while being refused', '', (string) $stale->state()['hash'] );

tisa_start( 'uses are spent one by one' );

$multi = tisa_seed( array( 'uses_left' => 2, 'use_limit' => 2 ) );
tisa_check( 'the first use is spent', $multi->consume() );
tisa_check( 'the secret is still armed', $multi->isArmed() );
tisa_same( 'one use left', 1, $multi->summary()['uses_left'] );
tisa_check( 'the last use is spent', $multi->consume() );
tisa_check( 'the secret is disarmed', ! $multi->isArmed() );
tisa_check( 'a spent secret refuses further use', ! $multi->consume() );

tisa_start( 'revocation and the reveal hold' );

$live = tisa_seed( array() );
$live->hold( '99887766', 7 );
tisa_same( 'the held code is handed over once', '99887766', $live->pull( 7 ) );
tisa_same( 'and never twice', '', $live->pull( 7 ) );
tisa_same( 'another user gets nothing', '', $live->pull( 8 ) );

$live->revoke();
tisa_check( 'revoking disarms the secret', ! $live->isArmed() );
tisa_same( 'the hash is gone', '', (string) $live->state()['hash'] );

tisa_start( 'generator' );

$suggested = EmergencyToken::suggest();
tisa_same( 'the generator returns eight digits', 8, strlen( $suggested ) );
tisa_check( 'the generator returns digits only', (bool) preg_match( '/^\d{8}$/', $suggested ) );

/**
 * JSON-encode the stored state without pulling in the WordPress helper.
 *
 * @param mixed $value
 */
function wp_json_encode_stub( $value ): string {
	return (string) json_encode( $value );
}

tisa_finish();
