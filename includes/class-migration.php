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

		// dbDelta can miss indexes on older installs; ensure purge path has expires_at.
		self::ensure_consent_logs_expires_at_index();

		// Zip reinstalls often keep UCPF_VERSION unchanged — still bust CF/browser caches.
		self::maybe_refresh_asset_cache();

		// Encrypt legacy plaintext API tokens in ucpf_settings (idempotent).
		Secrets::migrate_plaintext_at_rest();

		// Same-version zip reinstalls still need these (Amelia / UserWay must never stay gated).
		self::normalize_amelia_booking_service();
		self::normalize_userway_accessibility_service();

		$installed = get_option( 'ucpf_db_version', '0' );
		if ( self::needs_upgrade( (string) $installed ) ) {
			self::run_safe_mode_fixes();
			// Bust UCPF ?ver= only — full page/optimizer flushes race Cloudflare Cache Files
			// and uniquely break site CSS after frequent alpha zip uploads.
			ucpf_bust_asset_cache();
			update_option( 'ucpf_db_version', UCPF_VERSION, false );
			if ( Settings::get( 'cloudflare_purge_on_ucpf_update', true ) ) {
				Cloudflare_Cache::instance()->schedule_purge( 'ucpf_update' );
			}
			Plugin::maybe_clear_elementor_css_after_update( 'ucpf_update' );
		}
	}

	/**
	 * Add KEY expires_at on consent_logs when missing (helps purge_expired deletes).
	 *
	 * Idempotent; safe to call on every maybe_upgrade.
	 *
	 * @return void
	 */
	private static function ensure_consent_logs_expires_at_index() {
		global $wpdb;

		$table_name = ucpf_table( 'consent_logs' );
		$table      = esc_sql( $table_name );
		if ( '' === $table || '' === $table_name ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) );
		if ( $exists !== $table_name ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from esc_sql( ucpf_table() ).
		$has_index = $wpdb->get_results( "SHOW INDEX FROM `{$table}` WHERE Key_name = 'expires_at'" );
		if ( ! empty( $has_index ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- additive index for retention purge.
		$wpdb->query( "ALTER TABLE `{$table}` ADD KEY expires_at (expires_at)" );
	}

	/**
	 * Detect plugin file overwrite (same Version header) and bump asset ?ver=.
	 *
	 * @return void
	 */
	private static function maybe_refresh_asset_cache() {
		if ( ! defined( 'UCPF_PLUGIN_DIR' ) ) {
			return;
		}
		$paths = array(
			UCPF_PLUGIN_DIR . 'universal-consent-privacy-framework.php',
			UCPF_PLUGIN_DIR . 'public/js/consent.js',
			UCPF_PLUGIN_DIR . 'public/js/network-gate.js',
			UCPF_PLUGIN_DIR . 'public/js/form-captcha-guard.js',
			UCPF_PLUGIN_DIR . 'public/js/loader.js',
			UCPF_PLUGIN_DIR . 'public/css/banner.css',
		);
		$max = 0;
		foreach ( $paths as $path ) {
			if ( is_readable( $path ) ) {
				$max = max( $max, (int) filemtime( $path ) );
			}
		}
		if ( $max <= 0 ) {
			return;
		}
		$stored = (int) get_option( 'ucpf_assets_fingerprint', 0 );
		if ( $stored !== $max ) {
			update_option( 'ucpf_assets_fingerprint', $max, false );
			ucpf_bust_asset_cache();
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

		// Layout webfonts must never stay Embeds-gated (breaks any theme, not just Elementor).
		self::normalize_layout_font_services();
		self::normalize_amelia_booking_service();
		self::normalize_userway_accessibility_service();
	}

	/**
	 * Force Google Fonts / Typekit / Font Awesome to necessary + never blocked.
	 *
	 * Clears stale service_overrides and script_registry rows that still treat them as Embeds.
	 *
	 * @return void
	 */
	private static function normalize_layout_font_services() {
		self::force_services_necessary(
			array( 'google_fonts', 'adobe_fonts', 'font_awesome' )
		);
	}

	/**
	 * Amelia Booking is a first-party WP form (Gravity Forms model).
	 * Never gate /ameliabooking/ scripts — Security overlay covers reCAPTCHA only.
	 *
	 * @return void
	 */
	private static function normalize_amelia_booking_service() {
		self::force_services_necessary( array( 'amelia' ) );
	}

	/**
	 * UserWay accessibility toolbar must never wait on Preferences / Embeds / Marketing.
	 *
	 * @return void
	 */
	private static function normalize_userway_accessibility_service() {
		self::force_services_necessary( array( 'userway' ) );
	}

	/**
	 * Force listed services to necessary + never blocked (overrides + DB rows).
	 *
	 * @param string[] $keys Service keys.
	 * @return void
	 */
	private static function force_services_necessary( array $keys ) {
		$overrides = Settings::get( 'service_overrides', array() );
		if ( ! is_array( $overrides ) ) {
			$overrides = array();
		}
		$changed = false;
		foreach ( $keys as $key ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			$overrides[ $key ] = array(
				'category'         => 'necessary',
				'treatment'        => 'necessary',
				'default_blocking' => false,
			);
			$changed = true;
		}
		if ( $changed ) {
			Settings::update( array( 'service_overrides' => $overrides ) );
		}

		global $wpdb;
		$table_name = ucpf_table( 'script_registry' );
		$table      = esc_sql( $table_name );
		if ( '' === $table || '' === $table_name ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) );
		if ( $exists !== $table_name ) {
			return;
		}
		foreach ( $keys as $key ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table_name,
				array(
					'category'        => 'necessary',
					'default_enabled' => 0,
				),
				array( 'service_key' => $key ),
				array( '%s', '%d' ),
				array( '%s' )
			);
		}
	}
}
