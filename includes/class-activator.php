<?php
/**
 * Plugin activation.
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Activator class.
 */
class Activator {

	/**
	 * Run on plugin activation.
	 */
	public static function activate() {
		self::create_tables();
		Settings::set_defaults();
		Migration::maybe_upgrade();

		if ( ! wp_next_scheduled( 'ucpf_daily_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'ucpf_daily_cleanup' );
		}

		Scheduled_Scan::instance()->ensure_schedule();

		flush_rewrite_rules();
	}

	/**
	 * Create custom database tables.
	 */
	private static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		$logs = ucpf_table( 'consent_logs' );
		$sql  = "CREATE TABLE {$logs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			consent_uuid char(36) NOT NULL,
			user_id bigint(20) unsigned DEFAULT NULL,
			session_hash char(64) DEFAULT NULL,
			ip_hash char(64) DEFAULT NULL,
			user_agent_hash char(64) DEFAULT NULL,
			region varchar(32) DEFAULT NULL,
			policy_version varchar(50) NOT NULL,
			consent_version varchar(50) NOT NULL,
			action varchar(40) NOT NULL,
			categories longtext NOT NULL,
			services longtext DEFAULT NULL,
			created_at datetime NOT NULL,
			expires_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY consent_uuid (consent_uuid),
			KEY user_id (user_id),
			KEY created_at (created_at),
			KEY action (action)
		) {$charset};";
		dbDelta( $sql );

		$registry = ucpf_table( 'script_registry' );
		$sql      = "CREATE TABLE {$registry} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			service_key varchar(100) NOT NULL,
			service_name varchar(190) NOT NULL,
			provider varchar(190) DEFAULT '',
			category varchar(50) NOT NULL,
			description text,
			privacy_url text,
			cookie_patterns longtext,
			script_patterns longtext,
			iframe_patterns longtext,
			default_enabled tinyint(1) DEFAULT 0,
			source varchar(50) DEFAULT 'core',
			created_at datetime DEFAULT NULL,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY service_key (service_key)
		) {$charset};";
		dbDelta( $sql );

		$requests = ucpf_table( 'data_requests' );
		$sql      = "CREATE TABLE {$requests} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			request_type varchar(50) NOT NULL,
			email_hash char(64) NOT NULL,
			status varchar(30) NOT NULL DEFAULT 'pending',
			user_request_id bigint(20) unsigned DEFAULT NULL,
			meta longtext,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY email_hash (email_hash),
			KEY status (status)
		) {$charset};";
		dbDelta( $sql );

		update_option( 'ucpf_db_version', UCPF_VERSION );
	}
}
