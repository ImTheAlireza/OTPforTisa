<?php
/**
 * Uninstall handler.
 *
 * Settings, tables and transient state are removed only when the site owner
 * asked for it. Phone numbers stored on user profiles are always kept, because
 * they belong to the site's user data, not to this plugin.
 *
 * @package Signa
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$settings = get_option( 'signa_settings', array() );
$wipe     = is_array( $settings ) && ! empty( $settings['wipe_on_uninstall'] ) && '0' !== $settings['wipe_on_uninstall'];

delete_option( 'signa_settings' );
delete_option( 'signa_db_version' );
delete_option( 'signa_pepper' );

// The blocklist and any armed emergency code are security material: they never
// survive the plugin, regardless of the wipe setting. The code is stored as a
// hash, but a dead option row can only cause confusion later.
delete_option( 'signa_blocklist' );
delete_option( 'signa_emergency' );

wp_clear_scheduled_hook( 'signa_maintenance' );

$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_signa\_otp\_%' OR option_name LIKE '\_transient\_timeout\_signa\_otp\_%'"
);

if ( $wipe ) {
	foreach ( array( 'signa_codes', 'signa_state', 'signa_logs' ) as $suffix ) {
		$table = $wpdb->prefix . $suffix;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $table ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	}

	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		"DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ('signa_signup_channel','signa_last_signin','signa_signin_count','signa_phone_imported_from')"
	);
}
