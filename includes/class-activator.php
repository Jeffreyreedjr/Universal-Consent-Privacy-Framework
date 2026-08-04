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
	 * Run on plugin activation (single site or network-wide).
	 *
	 * @param bool $network_wide Whether the plugin is network-activated.
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( (array) $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::activate_site();
				restore_current_blog();
			}
			return;
		}

		self::activate_site();
	}

	/**
	 * Provision tables, defaults, and cron for the current blog.
	 */
	public static function activate_site() {
		self::create_tables();
		Settings::set_defaults();
		Migration::maybe_upgrade();

		if ( ! wp_next_scheduled( 'ucpf_daily_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'ucpf_daily_cleanup' );
		}

		Scheduled_Scan::instance()->ensure_schedule();

		ucpf_flush_site_caches( 'activate' );
		flush_rewrite_rules();
	}

	/**
	 * When a new multisite blog is created and UCPF is network-active, provision it.
	 *
	 * @param \WP_Site $new_site New site object.
	 */
	public static function on_initialize_site( $new_site ) {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active_for_network( UCPF_PLUGIN_BASENAME ) ) {
			return;
		}

		$blog_id = 0;
		if ( is_object( $new_site ) && isset( $new_site->blog_id ) ) {
			$blog_id = (int) $new_site->blog_id;
		} elseif ( is_numeric( $new_site ) ) {
			$blog_id = (int) $new_site;
		}
		if ( $blog_id < 1 ) {
			return;
		}

		switch_to_blog( $blog_id );
		self::activate_site();
		restore_current_blog();
	}

	/**
	 * Create or upgrade custom database tables (safe to re-run via dbDelta).
	 */
	public static function create_tables() {
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

		update_option( 'ucpf_db_version', UCPF_VERSION );
	}
}
