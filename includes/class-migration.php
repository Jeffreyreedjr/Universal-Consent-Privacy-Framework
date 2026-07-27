<?php
/**
 * Database migrations.
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Migration handler.
 */
class Migration {

	/**
	 * Run upgrades if needed.
	 */
	public static function maybe_upgrade() {
		// Clear a stuck scanner lock so PHP workers cannot stay wedged.
		delete_transient( 'ucpf_scan_running' );

		// Ensure schema exists after zip updates without deactivate/reactivate.
		Activator::create_tables();

		$installed = get_option( 'ucpf_db_version', '0' );
		if ( version_compare( (string) $installed, UCPF_VERSION, '<' ) ) {
			self::run_safe_mode_fixes();
			update_option( 'ucpf_db_version', UCPF_VERSION, false );
		}
	}

	/**
	 * Stability fixes for live sites (safe to re-run; values are intentional defaults).
	 */
	private static function run_safe_mode_fixes() {
		// OB HTML rewriting has caused Cloudflare 502s on Elementor/large pages.
		Settings::update(
			array(
				'output_buffer_blocking' => false,
				'show_powered_by'        => false,
			)
		);

		// Normalize unknown / retired theme keys → classic.
		$theme  = Settings::get( 'banner_theme' );
		$known  = Theme_Manager::instance()->get_preset_keys();
		if ( $theme && ! in_array( (string) $theme, $known, true ) ) {
			Settings::update( array( 'banner_theme' => 'classic' ) );
		}

		// Bump previous default retention (180) → 360 and extend existing log expiry.
		$log_days = (int) Settings::get( 'log_retention_days', 360 );
		if ( 180 === $log_days ) {
			Settings::update( array( 'log_retention_days' => 360 ) );
			Audit_Log::instance()->recompute_expires( 360 );
		}
	}
}
