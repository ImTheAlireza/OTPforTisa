<?php
/**
 * Uninstall handler.
 *
 * Settings, tables and transient state are removed only when the site owner
 * asked for it. Phone numbers stored on user profiles are always kept, because
 * they belong to the site's user data, not to this plugin.
 *
 * @package TisaOtp
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$settings = get_option( 'tisa_otp_settings', array() );
$wipe     = is_array( $settings ) && ! empty( $settings['wipe_on_uninstall'] ) && '0' !== $settings['wipe_on_uninstall'];

delete_option( 'tisa_otp_settings' );
delete_option( 'tisa_otp_db_version' );
delete_option( 'tisa_otp_pepper' );

wp_clear_scheduled_hook( 'tisa_otp_maintenance' );

$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_tisa\_otp\_%' OR option_name LIKE '\_transient\_timeout\_tisa\_otp\_%'"
);

if ( $wipe ) {
	foreach ( array( 'tisa_otp_codes', 'tisa_otp_state', 'tisa_otp_logs' ) as $suffix ) {
		$table = $wpdb->prefix . $suffix;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $table ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	}

	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		"DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ('tisa_signup_channel','tisa_last_signin','tisa_signin_count','tisa_phone_imported_from')"
	);
}
