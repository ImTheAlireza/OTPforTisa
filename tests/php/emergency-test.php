<?php
/**
 * Behaviour of the break-glass emergency code.
 *
 * @package Signa\Tests
 */

require __DIR__ . '/bootstrap.php';

use Signa\Access\EmergencyToken;

/**
 * Put a token row straight into the option table, as if it had been issued.
 *
 * @param array<string,mixed> $overrides
 */
function signa_seed( array $overrides ): EmergencyToken {
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

	$GLOBALS['signa_options'][ EmergencyToken::OPTION ] = $state;

	return new EmergencyToken();
}

signa_start( 'issue and inspect' );

$token = new EmergencyToken();
signa_check( 'a fresh install is not armed', ! $token->isArmed() );
signa_same( 'an unarmed token ignores any input', 'none', $token->inspect( '123456', '09121234567', '1.1.1.1' )['status'] );

signa_check( 'a code below the minimum length is refused', array() === $token->issue( '123', 30, 1, false, array(), '' ) );
signa_same( 'the refusal left nothing behind', '', (string) $token->state()['hash'] );

$issued = $token->issue( '44173290', 30, 2, false, array( '09121234567' ), '10.0.0.1' );
signa_same( 'the issued code is echoed back once', '44173290', $issued['code'] );
signa_check( 'the secret is armed', $token->isArmed() );
signa_check( 'the plaintext is not stored', false === strpos( wp_json_encode_stub( $token->state() ), '44173290' ) );

$summary = $token->summary();
signa_same( 'the use budget is recorded', 2, $summary['uses_left'] );
signa_check( 'the phone restriction is recorded', $token->phoneAllowed( '09121234567' ) );
signa_check( 'other numbers are outside the scope', ! $token->phoneAllowed( '09351234567' ) );

signa_start( 'the wrong guess burns the secret' );

$armed = signa_seed( array() );

$wrong = $armed->inspect( '00000000', '09121234567', '1.1.1.1' );
signa_same( 'a wrong code is not a match', 'mismatch', $wrong['status'] );
signa_same( 'two attempts remain', 2, $wrong['attempts_left'] );

$second = $armed->inspect( '11111111', '09121234567', '1.1.1.1' );
signa_same( 'one attempt remains', 1, $second['attempts_left'] );

$third = $armed->inspect( '22222222', '09121234567', '1.1.1.1' );
signa_same( 'the last attempt spends the budget', 0, $third['attempts_left'] );
signa_check( 'the secret disarmed itself', ! $armed->isArmed() );

signa_start( 'the right guess is narrow' );

$right = signa_seed( array() );
$verdict = $right->inspect( '12345678', '09121234567', '1.1.1.1' );
signa_same( 'the right code matches', 'match', $verdict['status'] );

$locked = signa_seed(
	array(
		'ip_lock' => true,
		'ip_hash' => hash_hmac( 'sha256', 'emergency-ip|10.0.0.1', wp_salt( 'auth' ) ),
	)
);

signa_same( 'the same address passes the lock', 'match', $locked->inspect( '12345678', '09121234567', '10.0.0.1' )['status'] );
signa_same( 'another address is refused', 'wrong_ip', $locked->inspect( '12345678', '09121234567', '10.0.0.2' )['status'] );

signa_start( 'an expired secret cannot be revived' );

$stale = signa_seed( array( 'expires_at' => time() - 1 ) );

signa_check( 'it reports itself as expired', $stale->isExpired() );
signa_check( 'it is not armed', ! $stale->isArmed() );
signa_same( 'even the correct code gets no special treatment', 'none', $stale->inspect( '12345678', '09121234567', '1.1.1.1' )['status'] );
signa_same( 'and it was wiped while being refused', '', (string) $stale->state()['hash'] );

signa_start( 'uses are spent one by one' );

$multi = signa_seed( array( 'uses_left' => 2, 'use_limit' => 2 ) );
signa_check( 'the first use is spent', $multi->consume() );
signa_check( 'the secret is still armed', $multi->isArmed() );
signa_same( 'one use left', 1, $multi->summary()['uses_left'] );
signa_check( 'the last use is spent', $multi->consume() );
signa_check( 'the secret is disarmed', ! $multi->isArmed() );
signa_check( 'a spent secret refuses further use', ! $multi->consume() );

signa_start( 'revocation and the reveal hold' );

$live = signa_seed( array() );
$live->hold( '99887766', 7 );
signa_same( 'the held code is handed over once', '99887766', $live->pull( 7 ) );
signa_same( 'and never twice', '', $live->pull( 7 ) );
signa_same( 'another user gets nothing', '', $live->pull( 8 ) );

$live->revoke();
signa_check( 'revoking disarms the secret', ! $live->isArmed() );
signa_same( 'the hash is gone', '', (string) $live->state()['hash'] );

signa_start( 'generator' );

$suggested = EmergencyToken::suggest();
signa_same( 'the generator returns eight digits', 8, strlen( $suggested ) );
signa_check( 'the generator returns digits only', (bool) preg_match( '/^\d{8}$/', $suggested ) );

/**
 * JSON-encode the stored state without pulling in the WordPress helper.
 *
 * @param mixed $value
 */
function wp_json_encode_stub( $value ): string {
	return (string) json_encode( $value );
}

signa_finish();
