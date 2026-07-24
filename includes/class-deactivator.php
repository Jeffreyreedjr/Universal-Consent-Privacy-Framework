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
	 * Run on plugin deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'ucpf_daily_cleanup' );
		wp_clear_scheduled_hook( Scheduled_Scan::HOOK_START );
		wp_clear_scheduled_hook( Scheduled_Scan::HOOK_POLL );
		flush_rewrite_rules();
	}
}
