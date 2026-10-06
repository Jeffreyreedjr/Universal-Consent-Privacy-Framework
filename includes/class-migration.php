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
	 * Option: schema maintenance completed for this plugin version.
	 */
	const SCHEMA_READY_OPTION = 'ucpf_schema_ready';

	/**
	 * Run upgrades if needed.
	 *
	 * Heavy work (dbDelta, SHOW INDEX, plaintext migrate) runs only when the
	 * DB version needs an upgrade or schema is not marked ready for UCPF_VERSION.
	 * Same-version zip overwrites still get asset fingerprint + cheap service normalize.
	 */
	public static function maybe_upgrade() {
		$installed = (string) get_option( 'ucpf_db_version', '0' );
		$needs     = self::needs_upgrade( $installed );

		// Fingerprint first — may delete schema_ready / bust assets / schedule CF purge.
		$fingerprint_changed = self::maybe_refresh_asset_cache();

		$schema_ok = ( (string) get_option( self::SCHEMA_READY_OPTION, '' ) === (string) UCPF_VERSION );

		if ( ! $needs && $schema_ok ) {
			// Cheap: no-op when overrides/DB rows already necessary.
			self::normalize_amelia_booking_service();
			self::normalize_userway_accessibility_service();
			self::normalize_smart_slider_service();
			self::retire_scheduled_scans();
			Tracking_Templates::maybe_persist_gtm_legacy_migration();
			return;
		}

		// Ensure schema exists after zip updates without deactivate/reactivate.
		Activator::create_tables();

		// dbDelta can miss indexes on older installs; ensure purge path has expires_at.
		self::ensure_consent_logs_expires_at_index();

		// Encrypt legacy plaintext API tokens in ucpf_settings (idempotent).
		Secrets::migrate_plaintext_at_rest();

		// Same-version zip reinstalls still need these (Amelia / UserWay / Smart Slider must never stay gated).
		self::normalize_amelia_booking_service();
		self::normalize_userway_accessibility_service();
		self::normalize_smart_slider_service();

		self::retire_scheduled_scans();

		Tracking_Templates::maybe_persist_gtm_legacy_migration();

		update_option( self::SCHEMA_READY_OPTION, UCPF_VERSION, false );

		if ( $needs ) {
			self::run_safe_mode_fixes();
			// Bust UCPF ?ver= only — full page/optimizer flushes race Cloudflare Cache Files
			// and uniquely break site CSS after frequent alpha zip uploads.
			ucpf_bust_asset_cache();
			update_option( 'ucpf_db_version', UCPF_VERSION, false );
			self::maybe_purge_after_deploy( 'ucpf_update', self::ucpf_public_asset_urls() );
		} elseif ( $fingerprint_changed ) {
			// Same-version zip: assets already busted in maybe_refresh_asset_cache.
			self::maybe_purge_after_deploy( 'ucpf_zip_overwrite', self::ucpf_public_asset_urls() );
		}
	}

	/**
	 * Optional Cloudflare + Elementor clear after zip/upgrade when settings allow.
	 *
	 * @param string   $reason Purge reason tag.
	 * @param string[] $files  Optional absolute asset URLs to purge by file.
	 * @return void
	 */
	private static function maybe_purge_after_deploy( $reason, $files = array() ) {
		// HTML at the edge still points at old ?ver= until purged / revalidated.
		Cloudflare_Cache::mark_deploy_revalidate( 300 );

		if ( Settings::get( 'cloudflare_purge_on_ucpf_update', true ) ) {
			Cloudflare_Cache::instance()->schedule_purge( $reason, $files );
		} else {
			// Soft-hook Cloudflare WP plugin only (no UCPF API purge_everything).
			Cloudflare_Cache::instance()->soft_purge_only( $reason );
		}
		Plugin::maybe_clear_elementor_css_after_update( $reason );
	}

	/**
	 * Force-disable WP-Cron Deep scans; keep manual admin/Playwright only.
	 *
	 * @return void
	 */
	private static function retire_scheduled_scans() {
		$enabled = (bool) Settings::get( 'scheduled_scan_enabled', false );
		if ( $enabled ) {
			Settings::update( array( 'scheduled_scan_enabled' => false ) );
		}
		if ( $enabled || wp_next_scheduled( Scheduled_Scan::HOOK_START ) || wp_next_scheduled( Scheduled_Scan::HOOK_POLL ) ) {
			Scheduled_Scan::instance()->clear_schedule();
		}
	}

	/**
	 * Add KEY expires_at on consent_logs when missing (helps purge_expired deletes).
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
	 * Uses size + mtime + crc32b per critical file so FTP/OneDrive zip deploys that
	 * preserve mtimes still invalidate Cloudflare / browser caches.
	 *
	 * @return bool True when fingerprint changed (zip overwrite / asset churn).
	 */
	private static function maybe_refresh_asset_cache() {
		if ( ! defined( 'UCPF_PLUGIN_DIR' ) ) {
			return false;
		}

		$fp = self::compute_assets_fingerprint();
		if ( '' === $fp ) {
			return false;
		}

		$stored = (string) get_option( 'ucpf_assets_fingerprint', '' );
		if ( $stored === $fp ) {
			return false;
		}

		update_option( 'ucpf_assets_fingerprint', $fp, false );
		ucpf_bust_asset_cache();
		// Force schema re-check after zip overwrite (same Version header).
		delete_option( self::SCHEMA_READY_OPTION );
		return true;
	}

	/**
	 * Content fingerprint of public assets + main plugin file.
	 *
	 * @return string md5 hex or empty.
	 */
	private static function compute_assets_fingerprint() {
		$paths = array(
			UCPF_PLUGIN_DIR . 'universal-consent-privacy-framework.php',
			UCPF_PLUGIN_DIR . 'public/js/consent.js',
			UCPF_PLUGIN_DIR . 'public/js/consent-motion.js',
			UCPF_PLUGIN_DIR . 'public/js/network-gate.js',
			UCPF_PLUGIN_DIR . 'public/js/form-captcha-guard.js',
			UCPF_PLUGIN_DIR . 'public/js/loader.js',
			UCPF_PLUGIN_DIR . 'public/css/banner.css',
			UCPF_PLUGIN_DIR . 'public/css/tokens.css',
			UCPF_PLUGIN_DIR . 'public/css/legal.css',
		);

		$parts = array();
		foreach ( $paths as $path ) {
			if ( ! is_readable( $path ) ) {
				continue;
			}
			$size  = (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$mtime = (int) @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			// crc32b is cheap and catches content swaps when mtime/size are preserved.
			$crc = @hash_file( 'crc32b', $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! is_string( $crc ) || '' === $crc ) {
				$crc = '0';
			}
			$parts[] = basename( $path ) . ':' . $size . ':' . $mtime . ':' . $crc;
		}

		$catalog_dir = UCPF_PLUGIN_DIR . 'assets/vendor-catalog/';
		if ( is_dir( $catalog_dir ) ) {
			$json_files = glob( $catalog_dir . '*.json' );
			if ( is_array( $json_files ) ) {
				sort( $json_files );
				foreach ( $json_files as $file ) {
					$size  = (int) @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					$mtime = (int) @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					$parts[] = basename( $file ) . ':' . $size . ':' . $mtime;
				}
			}
		}

		if ( ! $parts ) {
			return '';
		}

		return md5( implode( '|', $parts ) );
	}

	/**
	 * Bare UCPF public asset URLs for Cloudflare file purge.
	 *
	 * @return string[]
	 */
	public static function ucpf_public_asset_urls() {
		if ( ! defined( 'UCPF_PLUGIN_URL' ) ) {
			return array();
		}
		$rels = array(
			'public/js/consent.js',
			'public/js/consent-motion.js',
			'public/js/network-gate.js',
			'public/js/form-captcha-guard.js',
			'public/js/loader.js',
			'public/css/banner.css',
			'public/css/tokens.css',
			'public/css/legal.css',
			'public/css/themes/classic.css',
			'public/css/themes/studio-dark.css',
			'public/css/themes/studio-neon.css',
			'public/css/themes/studio-ocean.css',
			'public/css/themes/studio-light.css',
		);
		$urls = array();
		foreach ( $rels as $rel ) {
			$urls[] = UCPF_PLUGIN_URL . $rel;
		}
		return $urls;
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
				'scheduled_scan_enabled' => false,
			)
		);

		// Normalize unknown / retired theme keys → classic.
		$theme = Settings::get( 'banner_theme' );
		$known = Theme_Manager::instance()->get_preset_keys();
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
		self::normalize_smart_slider_service();
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
	 * Smart Slider 3 / Nextend is layout chrome — never gate.
	 * Re-activating parked n2/ss3 scripts double-defines custom elements and kills the hero.
	 *
	 * @return void
	 */
	private static function normalize_smart_slider_service() {
		self::force_services_necessary( array( 'smart_slider' ) );
	}

	/**
	 * Force listed services to necessary + never blocked (overrides + DB rows).
	 *
	 * Skips Settings::update / $wpdb->update when already correct.
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
		$want    = array(
			'category'         => 'necessary',
			'treatment'        => 'necessary',
			'default_blocking' => false,
		);
		foreach ( $keys as $key ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			$cur = isset( $overrides[ $key ] ) && is_array( $overrides[ $key ] ) ? $overrides[ $key ] : array();
			$cat = isset( $cur['category'] ) ? sanitize_key( (string) $cur['category'] ) : '';
			$trt = isset( $cur['treatment'] ) ? sanitize_key( (string) $cur['treatment'] ) : '';
			$blk = array_key_exists( 'default_blocking', $cur ) ? (bool) $cur['default_blocking'] : true;
			if ( 'necessary' === $cat && 'necessary' === $trt && false === $blk ) {
				continue;
			}
			$overrides[ $key ] = $want;
			$changed           = true;
		}
		if ( $changed ) {
			Settings::update( array( 'service_overrides' => $overrides ) );
		} else {
			// Overrides already correct — skip registry table chatter on the hot path.
			return;
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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- whitelist table via esc_sql( ucpf_table() ).
			$row = $wpdb->get_row(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from esc_sql( ucpf_table() ) whitelist.
					"SELECT category, default_enabled FROM `{$table}` WHERE service_key = %s LIMIT 1",
					$key
				),
				ARRAY_A
			);
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( 'necessary' === (string) $row['category'] && (int) $row['default_enabled'] === 0 ) {
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
