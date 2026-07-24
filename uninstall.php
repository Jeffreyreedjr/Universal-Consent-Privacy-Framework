<?php
/**
 * Uninstall handler.
 *
 * @package UCPF
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$delete_data = get_option( 'ucpf_delete_data_on_uninstall', false );

if ( ! $delete_data ) {
	return;
}

$tables = array(
	$wpdb->prefix . 'ucpf_consent_logs',
	$wpdb->prefix . 'ucpf_script_registry',
	$wpdb->prefix . 'ucpf_data_requests',
);

foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

$options = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
		'ucpf_%'
	)
);

foreach ( $options as $option ) {
	delete_option( $option );
}

wp_clear_scheduled_hook( 'ucpf_daily_cleanup' );
wp_clear_scheduled_hook( 'ucpf_scheduled_scan_start' );
wp_clear_scheduled_hook( 'ucpf_scheduled_scan_poll' );
