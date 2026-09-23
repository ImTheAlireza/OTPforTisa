<?php
/**
 * The signup form's own questions: what they collect and where it lands.
 *
 * The address and postal code are the first fields whose value has to survive
 * the registration — they are written into the user's profile record, not just
 * logged — so this test follows a value from the validator to the profile.
 *
 * @package TisaOtp\Tests
 */

require __DIR__ . '/bootstrap.php';

use TisaOtp\Config\Settings;
use TisaOtp\Registration\FieldCatalog;
use TisaOtp\Registration\FieldSchema;
use TisaOtp\Registration\FieldValidator;
use TisaOtp\User\AccountFactory;

/**
 * @param array<string,mixed> $values
 */
function tisa_fields_settings( array $values = array() ): Settings {
	$GLOBALS['tisa_options']['tisa_otp_settings'] = array_merge(
		array(
			'field_preset'         => 'identity',
			'registration_enabled' => '1',
		),
		$values
	);

	return new Settings();
}

tisa_start( 'the identity preset collects an address and a postal code' );

$preset = FieldCatalog::presets()['identity'];
$byId   = array();

foreach ( $preset as $field ) {
	$byId[ $field['id'] ] = $field;
}

tisa_check( 'the preset has an address field', isset( $byId['address'] ) );
tisa_check( 'the address is a textarea, because addresses have lines', isset( $byId['address'] ) && 'textarea' === $byId['address']['type'] );
tisa_same( 'the address is stored in tisa_address', 'tisa_address', $byId['address']['meta_key'] );
tisa_check( 'the preset has a postal code field', isset( $byId['postcode'] ) );
tisa_same( 'the postal code has its own type', 'postcode', $byId['postcode']['type'] );
tisa_same( 'the postal code is stored in tisa_postcode', 'tisa_postcode', $byId['postcode']['meta_key'] );

$order = array_keys( $byId );
tisa_check( 'the postcode comes before the address', array_search( 'postcode', $order, true ) < array_search( 'address', $order, true ) );
tisa_check( 'a postcode is a type the admin can pick', in_array( 'postcode', FieldCatalog::types(), true ) );

tisa_start( 'a postal code is ten digits, however it is typed' );

$validator = new FieldValidator( tisa_fields_settings() );
$schema    = new FieldSchema( tisa_fields_settings() );
$fields    = $schema->active();

tisa_same( 'the identity preset is what the schema returns', 'tisa_postcode', $fields[ count( $fields ) - 2 ]['meta_key'] );

$cases = array(
	'1234567890'      => '1234567890',
	'۱۲۳۴۵۶۷۸۹۰'      => '1234567890',
	'12345-67890'     => '1234567890',
	'1234 567 890'    => '1234567890',
);

foreach ( $cases as $typed => $expected ) {
	$result = $validator->validate( array( 'postcode' => $typed ), array( $byId['postcode'] ) );
	tisa_same( '«' . $typed . '» becomes ' . $expected, $expected, isset( $result['values']['postcode'] ) ? $result['values']['postcode'] : '(none)' );
}

foreach ( array( '12345', '12345678901', 'abc' ) as $bad ) {
	$result = $validator->validate( array( 'postcode' => $bad ), array( $byId['postcode'] ) );
	tisa_check( '«' . $bad . '» is refused with a sentence, not silently dropped', isset( $result['errors']['postcode'] ) && false !== strpos( $result['errors']['postcode'], '۱۰ رقم' ) );
}

$empty = $validator->validate( array( 'postcode' => '' ), array( $byId['postcode'] ) );
tisa_check( 'an optional postcode may stay empty', $empty['ok'] && '' === $empty['values']['postcode'] );

$address = $validator->validate( array( 'address' => "خیابان ولیعصر\nپلاک ۱۲، واحد ۳" ), array( $byId['address'] ) );
tisa_check( 'an address keeps its line break', false !== strpos( $address['values']['address'], "ولیعصر\n" ) );

tisa_start( 'WooCommerce billing is filled once, and never overwritten' );

class WooCommerce {}

$GLOBALS['tisa_user_meta'] = array();
$factory = ( new ReflectionClass( AccountFactory::class ) )->newInstanceWithoutConstructor();
$mirror  = ( new ReflectionClass( AccountFactory::class ) )->getMethod( 'mirrorBilling' );
$mirror->setAccessible( true );

$mirror->invoke( $factory, 7, 'tisa_postcode', '1234567890' );
tisa_same( 'the postcode lands in billing_postcode', '1234567890', get_user_meta( 7, 'billing_postcode', true ) );

$mirror->invoke( $factory, 7, 'tisa_postcode', '9999999999' );
tisa_same( 'a second write does not replace what is already there', '1234567890', get_user_meta( 7, 'billing_postcode', true ) );

$mirror->invoke( $factory, 7, 'tisa_address', 'خیابان آزادی، پلاک ۵' );
tisa_same( 'the address lands in billing_address_1', 'خیابان آزادی، پلاک ۵', get_user_meta( 7, 'billing_address_1', true ) );

$mirror->invoke( $factory, 7, 'tisa_first_name', 'علی' );
tisa_same( 'a key WooCommerce does not share is left alone', '', get_user_meta( 7, 'tisa_first_name', true ) );

$mirror->invoke( $factory, 8, 'tisa_postcode', '   ' );
tisa_same( 'an empty answer mirrors nothing', '', get_user_meta( 8, 'billing_postcode', true ) );

tisa_finish();
