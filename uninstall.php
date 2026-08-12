<?php
/**
 * Uninstall handler.
 *
 * @package UCPF
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Whether the current blog opted into deleting data on uninstall.
 *
 * @return bool
 */
function ucpf_uninstall_should_delete() {
	$settings = get_option( 'ucpf_settings', array() );
	if ( is_array( $settings ) && ! empty( $settings['delete_data_on_uninstall'] ) ) {
		return true;
	}
	// Legacy top-level flag.
	return (bool) get_option( 'ucpf_delete_data_on_uninstall', false );
}

/**
 * Drop UCPF tables/options/cron for the current blog.
 */
function ucpf_uninstall_current_blog() {
	global $wpdb;

	if ( ! ucpf_uninstall_should_delete() ) {
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
	wp_clear_scheduled_hook( 'ucpf_active_scan_poll' );
	wp_clear_scheduled_hook( 'ucpf_cloudflare_purge_edge' );
}

if ( is_multisite() ) {
	$ucpf_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( (array) $ucpf_site_ids as $ucpf_site_id ) {
		switch_to_blog( (int) $ucpf_site_id );
		ucpf_uninstall_current_blog();
		restore_current_blog();
	}
	delete_site_option( 'ucpf_network_defaults' );
	delete_network_option( null, 'ucpf_network_settings' );
} else {
	ucpf_uninstall_current_blog();
}
