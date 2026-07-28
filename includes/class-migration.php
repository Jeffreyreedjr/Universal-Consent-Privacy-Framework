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
		if ( self::needs_upgrade( (string) $installed ) ) {
			self::run_safe_mode_fixes();
			update_option( 'ucpf_db_version', UCPF_VERSION, false );
		}
	}

	/**
	 * Whether stored DB version should run upgrade hooks for the current plugin version.
	 *
	 * Handles normal bumps and the intentional reset from 1.x builds to 0.x alpha.
	 *
	 * @param string $installed Stored ucpf_db_version.
	 * @return bool
	 */
	private static function needs_upgrade( $installed ) {
		if ( version_compare( $installed, UCPF_VERSION, '<' ) ) {
			return true;
		}
		// 1.4.x development builds renumbered to 0.1.0-alpha (not a downgrade skip).
		if ( version_compare( $installed, '1.0.0', '>=' ) && version_compare( UCPF_VERSION, '1.0.0', '<' ) ) {
			return true;
		}
		return false;
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

		// Clear factory-default classic colors so theme presets (neon/ocean/light) can show.
		// Sites that intentionally set a custom hex keep any non-default value.
		$color_clear = array();
		$accent      = Settings::get( 'accent_color' );
		$accent_2    = Settings::get( 'accent_2_color' );
		if ( is_string( $accent ) && 0 === strcasecmp( trim( $accent ), '#0b5cad' ) ) {
			$color_clear['accent_color'] = '';
		}
		if ( is_string( $accent_2 ) && 0 === strcasecmp( trim( $accent_2 ), '#094a8c' ) ) {
			$color_clear['accent_2_color'] = '';
		}
		if ( $color_clear ) {
			Settings::update( $color_clear );
		}

		// Bump previous default retention (180) → 360 and extend existing log expiry.
		$log_days = (int) Settings::get( 'log_retention_days', 360 );
		if ( 180 === $log_days ) {
			Settings::update( array( 'log_retention_days' => 360 ) );
			Audit_Log::instance()->recompute_expires( 360 );
		}
	}
}
