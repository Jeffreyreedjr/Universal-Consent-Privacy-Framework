<?php
/**
 * Uninstall handler.
 *
 * @package UCPF
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$ucpf_delete_data = get_option( 'ucpf_delete_data_on_uninstall', false );

if ( ! $ucpf_delete_data ) {
	return;
}

$ucpf_tables = array(
	$wpdb->prefix . 'ucpf_consent_logs',
	$wpdb->prefix . 'ucpf_script_registry',
	$wpdb->prefix . 'ucpf_data_requests',
);

foreach ( $ucpf_tables as $ucpf_table ) {
	$ucpf_table = esc_sql( $ucpf_table );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- uninstall drop; table name escaped.
	$wpdb->query( "DROP TABLE IF EXISTS `{$ucpf_table}`" );
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall option cleanup.
$ucpf_options = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
		'ucpf_%'
	)
);

foreach ( (array) $ucpf_options as $ucpf_option ) {
	delete_option( $ucpf_option );
}

wp_clear_scheduled_hook( 'ucpf_daily_cleanup' );
wp_clear_scheduled_hook( 'ucpf_scheduled_scan_start' );
wp_clear_scheduled_hook( 'ucpf_scheduled_scan_poll' );
