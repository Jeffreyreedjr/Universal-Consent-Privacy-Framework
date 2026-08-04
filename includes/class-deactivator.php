<?php
/**
 * Plugin deactivation.
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Deactivator class.
 */
class Deactivator {

	/**
	 * Run on plugin deactivation (single site or network-wide).
	 *
	 * @param bool $network_wide Whether the plugin is network-deactivated.
	 */
	public static function deactivate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( (array) $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::deactivate_site();
				restore_current_blog();
			}
			return;
		}

		self::deactivate_site();
	}

	/**
	 * Clear scheduled events for the current blog.
	 */
	public static function deactivate_site() {
		wp_clear_scheduled_hook( 'ucpf_daily_cleanup' );
		wp_clear_scheduled_hook( Scheduled_Scan::HOOK_START );
		wp_clear_scheduled_hook( Scheduled_Scan::HOOK_POLL );
		flush_rewrite_rules();
	}
}
