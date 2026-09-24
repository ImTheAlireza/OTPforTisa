<?php
/**
 * QA helper for tools/wp-qa/run.sh. Never ship this: it logs every PHP
 * error, fakes the sms.ir answer and exposes setup/uninstall switches.
 */
// QA only: record every PHP error, warning, notice and deprecation raised anywhere.
error_reporting( E_ALL );
$GLOBALS['signa_qa_log'] = WP_CONTENT_DIR . '/signa-qa.log';
set_error_handler( function ( $no, $str, $file, $line ) {
	// Only the plugin's own code: an old WordPress on a new PHP has noise of its own.
	if ( false === strpos( $file, '/plugins/signa/' ) && false === strpos( $file, '/mu-plugins/' ) ) {
		return false;
	}
	file_put_contents( $GLOBALS['signa_qa_log'], gmdate( 'H:i:s' ) . " [$no] $str @ $file:$line " . ( $_SERVER['REQUEST_URI'] ?? '' ) . "\n", FILE_APPEND );
	return false;
} );
register_shutdown_function( function () {
	$e = error_get_last();
	if ( $e && in_array( $e['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
		file_put_contents( $GLOBALS['signa_qa_log'], "FATAL {$e['message']} @ {$e['file']}:{$e['line']} " . ( $_SERVER['REQUEST_URI'] ?? '' ) . "\n", FILE_APPEND );
	}
} );
if ( isset( $_GET["signa_qa_ping"] ) ) { trigger_error( "qa ping", E_USER_NOTICE ); }

// QA: point sms.ir at a fake answer and keep the code it would have sent.
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	if ( false === strpos( $url, 'api.sms.ir' ) ) {
		return $pre;
	}
	file_put_contents( WP_CONTENT_DIR . '/signa-qa-sms.log', $url . ' ' . ( is_string( $args['body'] ?? '' ) ? $args['body'] : wp_json_encode( $args['body'] ) ) . "\n", FILE_APPEND );
	return array(
		'headers'  => array(),
		'body'     => wp_json_encode( array( 'status' => 1, 'message' => 'موفق', 'data' => array( 'messageId' => 1, 'cost' => 1, 'credit' => 100, 'line' => 3000 ) ) ),
		'response' => array( 'code' => 200, 'message' => 'OK' ),
		'cookies'  => array(),
		'filename' => null,
	);
}, 10, 3 );
if ( isset( $_GET['signa_qa_setup'] ) ) {
	add_action( 'init', function () {
		$o = (array) get_option( 'signa_settings', array() );
		$o['smsir_api_key']     = 'qa-key';
		$o['smsir_template_id'] = '123456';
		$o['sms_gateway']       = 'smsir';
		update_option( 'signa_settings', $o );
		echo 'setup ok'; exit;
	} );
}
if ( isset( $_GET['signa_qa_uninstall'] ) ) {
	add_action( 'init', function () {
		global $wpdb;
		$o = (array) get_option( 'signa_settings', array() );
		$o['wipe_on_uninstall'] = '1';
		update_option( 'signa_settings', $o );
		$before = $wpdb->get_col( "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '%signa%'" );
		if ( ! $before ) { $before = $wpdb->get_col( "SHOW TABLES LIKE '%signa%'" ); }
		define( 'WP_UNINSTALL_PLUGIN', 'signa/signa.php' );
		include WP_PLUGIN_DIR . '/signa/uninstall.php';
		$tables = $wpdb->get_col( "SHOW TABLES LIKE '%signa%'" );
		$opts   = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%signa%'" );
		$meta   = $wpdb->get_col( "SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE 'signa%'" );
		echo wp_json_encode( compact( 'before', 'tables', 'opts', 'meta' ) );
		exit;
	} );
}
if ( isset( $_GET['signa_qa_php'] ) ) {
	add_action( 'plugins_loaded', function () {
		echo PHP_VERSION . ' WP ' . $GLOBALS['wp_version'] . ( defined( 'WC_VERSION' ) ? ' WC ' . WC_VERSION : '' );
		exit;
	}, 99 );
}
if ( isset( $_GET['signa_qa_woo'] ) ) {
	add_action( 'wp_loaded', function () {
		$hpos = array();
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			$hpos = \Automattic\WooCommerce\Utilities\FeaturesUtil::get_compatible_plugins_for_feature( 'custom_order_tables' );
		}
		echo wp_json_encode( array(
			'account' => function_exists( 'wc_get_page_id' ) ? get_permalink( wc_get_page_id( 'myaccount' ) ) : '',
			'hpos'    => $hpos,
		) );
		exit;
	} );
}
if ( isset( $_GET['signa_qa_opts'] ) ) {
	add_action( 'init', function () {
		echo wp_json_encode( get_option( 'signa_settings' ) );
		exit;
	} );
}
