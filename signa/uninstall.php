<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$settings = get_option( 'signa_settings', array() );
$wipe     = is_array( $settings ) && ! empty( $settings['wipe_on_uninstall'] ) && '0' !== $settings['wipe_on_uninstall'];

delete_option( 'signa_settings' );
delete_option( 'signa_db_version' );
delete_option( 'signa_pepper' );
delete_option( 'signa_gateway_health' );

delete_option( 'signa_blocklist' );
delete_option( 'signa_emergency' );

wp_clear_scheduled_hook( 'signa_maintenance' );

$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_signa\_%' OR option_name LIKE '\_transient\_timeout\_signa\_%'"
);

if ( $wipe ) {
	foreach ( array( 'signa_codes', 'signa_state', 'signa_logs' ) as $suffix ) {
		$table = $wpdb->prefix . $suffix;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $table );
	}

	$wpdb->query(
		"DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ('signa_signup_channel','signa_last_signin','signa_signin_count','signa_phone_imported_from')"
	);
}
