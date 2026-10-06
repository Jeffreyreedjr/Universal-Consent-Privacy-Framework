<?php
/**
 * Global helper functions.
 *
 * @package UCPF
 */

defined( 'ABSPATH' ) || exit;

/**
 * Check consent for a category or service.
 *
 * @param string $category_or_service Category or service key.
 * @return bool
 */
function ucpf_has_consent( $category_or_service ) {
	return UCPF\Consent_Manager::instance()->has_consent( $category_or_service );
}

/**
 * Register a tracking service.
 *
 * @param array $args Service definition.
 * @return bool|\WP_Error
 */
function ucpf_register_service( array $args ) {
	return UCPF\Script_Registry::instance()->register_service( $args );
}

/**
 * Get current consent state.
 *
 * @return array
 */
function ucpf_get_consent_state() {
	return UCPF\Consent_Manager::instance()->get_consent_state();
}

/**
 * Get consent categories.
 *
 * @return array
 */
function ucpf_get_categories() {
	return UCPF\Consent_Manager::instance()->get_categories();
}

/**
 * Get registered services.
 *
 * @return array
 */
function ucpf_get_registered_services() {
	return UCPF\Script_Registry::instance()->get_services();
}

/**
 * Get plugin option with default.
 *
 * @param string $key     Option key (without prefix).
 * @param mixed  $default Default value.
 * @return mixed
 */
function ucpf_get_option( $key, $default = null ) {
	return UCPF\Settings::get( $key, $default );
}

/**
 * Get authoritative privacy enforcement state (GPC / DNS / central).
 *
 * @return array
 */
function ucpf_get_privacy_state() {
	return UCPF\Privacy_State::instance()->get_state();
}

/**
 * Whether Sec-GPC (or Nginx UCPF_GPC) is present on this request.
 *
 * @return bool
 */
function ucpf_gpc_signal_present() {
	return UCPF\Privacy_State::gpc_signal_present();
}

/**
 * Keyed HMAC for an email (privacy preference lookups).
 *
 * @param string $email Email.
 * @return string
 */
function ucpf_privacy_hmac_email( $email ) {
	return UCPF\Privacy_Identity::hmac_email( $email );
}

/**
 * Plugin table name with prefix.
 *
 * @param string $table Short table name (whitelist only).
 * @return string Empty string if not allowed.
 */
function ucpf_table( $table ) {
	global $wpdb;

	$table   = sanitize_key( (string) $table );
	$allowed = array( 'consent_logs', 'script_registry' );
	if ( ! in_array( $table, $allowed, true ) ) {
		return '';
	}

	return $wpdb->prefix . 'ucpf_' . $table;
}

/**
 * Consent cookie Path attribute (WordPress COOKIEPATH — isolates subdirectory multisite blogs).
 *
 * @return string Path beginning with /.
 */
function ucpf_cookie_path() {
	$path = defined( 'COOKIEPATH' ) ? (string) COOKIEPATH : '/';
	if ( '' === $path ) {
		$path = '/';
	}
	if ( '/' !== $path[0] ) {
		$path = '/' . $path;
	}
	/**
	 * Filter the ucpf_consent cookie path.
	 *
	 * @param string $path Cookie path.
	 */
	return (string) apply_filters( 'ucpf_cookie_path', $path );
}

/**
 * Consent cookie Domain attribute (empty = host-only; safer for subdomain multisite).
 *
 * @return string
 */
function ucpf_cookie_domain() {
	$domain = ( defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ) ? (string) COOKIE_DOMAIN : '';
	/**
	 * Filter the ucpf_consent cookie domain. Prefer empty on multisite so each host keeps its own consent.
	 *
	 * @param string $domain Cookie domain.
	 */
	return (string) apply_filters( 'ucpf_cookie_domain', $domain );
}

/**
 * Suffix for localStorage / sessionStorage consent backup keys (blog id on multisite).
 *
 * @return string Empty on single site; blog id string on multisite.
 */
function ucpf_storage_suffix() {
	if ( is_multisite() ) {
		return (string) get_current_blog_id();
	}
	return '';
}

/**
 * Escaped SQL table identifier from the UCPF whitelist (for interpolated FROM/INTO clauses).
 *
 * @param string $table Short table name.
 * @return string Backtick-quoted identifier, or empty string if invalid.
 */
function ucpf_sql_table( $table ) {
	$name = ucpf_table( $table );
	if ( '' === $name ) {
		return '';
	}
	// Identifier only: strip backticks then re-wrap; esc_sql for Plugin Check UnescapedDBParameter.
	$name = str_replace( '`', '', $name );
	return '`' . esc_sql( $name ) . '`';
}

/**
 * Version query for enqueued UCPF assets.
 *
 * Format: UCPF_VERSION[.size.crc32b][.ucpf_assets_rev]
 * Size+crc track file contents so zip overwrites that preserve mtimes still
 * change ?ver=. Rev is an extra stamp from ucpf_bust_asset_cache().
 *
 * @param string $relative Optional path under the plugin dir (e.g. public/js/consent.js).
 * @return string
 */
function ucpf_asset_version( $relative = '' ) {
	static $versions = array();
	static $rev      = null;

	$base = defined( 'UCPF_VERSION' ) ? (string) UCPF_VERSION : '0';

	$relative = ltrim( str_replace( '\\', '/', (string) $relative ), '/' );
	if ( isset( $versions[ $relative ] ) ) {
		return $versions[ $relative ];
	}

	if ( null === $rev ) {
		$rev = (string) get_option( 'ucpf_assets_rev', '' );
	}

	$version = $base;
	if ( $relative && defined( 'UCPF_PLUGIN_DIR' ) ) {
		$path = UCPF_PLUGIN_DIR . $relative;
		if ( is_file( $path ) ) {
			$size = (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$crc  = @hash_file( 'crc32b', $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $size > 0 && is_string( $crc ) && '' !== $crc ) {
				$version .= '.' . $size . '.' . $crc;
			} else {
				$mtime = (int) @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( $mtime > 0 ) {
					$version .= '.' . $mtime;
				}
			}
		}
	}
	if ( '' !== $rev && ctype_digit( $rev ) ) {
		$version .= '.' . $rev;
	}

	$versions[ $relative ] = $version;
	return $version;
}

/**
 * Bust front-end asset cache after zip overwrite / upgrade.
 *
 * Also invalidates Redis Object Cache group `ucpf` and OPcache for UCPF PHP
 * (targeted — never a full Redis FLUSHDB / wp_cache_flush).
 *
 * @return void
 */
function ucpf_bust_asset_cache() {
	update_option( 'ucpf_assets_rev', (string) time(), false );
	if ( class_exists( 'UCPF\\Script_Registry' ) ) {
		UCPF\Script_Registry::bust_catalog_cache();
	}
	if ( class_exists( 'UCPF\\Cookie_Scanner' ) ) {
		UCPF\Cookie_Scanner::bust_policy_inventory_cache();
	}
	ucpf_invalidate_runtime_caches( 'asset_bust' );
	// Hummingbird AO may still serve pre-exclusion combined bundles until cleared.
	if ( class_exists( 'UCPF\\Integrations\\Optimizer_Exclusions' ) ) {
		UCPF\Integrations\Optimizer_Exclusions::clear_hummingbird_ao();
	}
}

/**
 * Targeted Redis Object Cache + OPcache invalidation after UCPF zip/update.
 *
 * Does not call wp_cache_flush() (site-wide stampede). Consent cookie skips are
 * for page caches only — Redis object cache is not full-page HTML.
 *
 * @param string $reason Short reason for hooks / logs.
 * @return void
 */
function ucpf_invalidate_runtime_caches( $reason = '' ) {
	$reason = is_string( $reason ) ? $reason : '';

	// Redis Object Cache / object-cache.php: flush UCPF group when supported.
	if ( function_exists( 'wp_cache_flush_group' ) ) {
		wp_cache_flush_group( 'ucpf' );
	} elseif ( function_exists( 'wp_cache_delete' ) ) {
		// Fallback: known keys in group ucpf (catalog already deleted by bust_catalog_cache).
		wp_cache_delete( 'ucpf_vendor_catalog_v2', 'ucpf' );
	}

	ucpf_invalidate_opcache_plugin_files();

	/**
	 * After UCPF invalidates Redis group + OPcache for plugin PHP.
	 *
	 * @param string $reason Flush reason slug.
	 */
	do_action( 'ucpf_invalidate_runtime_caches', $reason );
}

/**
 * Invalidate OPcache entries for UCPF PHP files (same-version zip overwrites).
 *
 * @return void
 */
function ucpf_invalidate_opcache_plugin_files() {
	if ( ! function_exists( 'opcache_invalidate' ) || ! defined( 'UCPF_PLUGIN_DIR' ) ) {
		return;
	}

	/**
	 * Whether to call opcache_reset() instead of per-file invalidate (shared hosts: keep false).
	 *
	 * @param bool $reset Default false.
	 */
	if ( (bool) apply_filters( 'ucpf_opcache_reset_on_bust', false ) && function_exists( 'opcache_reset' ) ) {
		opcache_reset();
		return;
	}

	$root = rtrim( str_replace( '\\', '/', (string) UCPF_PLUGIN_DIR ), '/' ) . '/';
	if ( ! is_dir( $root ) ) {
		return;
	}

	$paths = array();

	// Plugin bootstrap PHP in root (e.g. universal-consent-privacy-framework.php).
	$root_files = glob( $root . '*.php' );
	if ( is_array( $root_files ) ) {
		foreach ( $root_files as $file ) {
			$paths[] = $file;
		}
	}

	$includes = $root . 'includes';
	if ( is_dir( $includes ) ) {
		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $includes, \FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $iterator as $fileinfo ) {
				if ( ! $fileinfo instanceof \SplFileInfo || ! $fileinfo->isFile() ) {
					continue;
				}
				$path = $fileinfo->getPathname();
				$norm = str_replace( '\\', '/', $path );
				if ( false !== strpos( $norm, '/vendor/' ) ) {
					continue;
				}
				if ( 'php' !== strtolower( $fileinfo->getExtension() ) ) {
					continue;
				}
				$paths[] = $path;
			}
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Ignore iterator failures; root files still invalidated.
		}
	}

	$max = (int) apply_filters( 'ucpf_opcache_invalidate_max_files', 400 );
	if ( $max < 1 ) {
		$max = 400;
	}
	$count = 0;
	foreach ( $paths as $path ) {
		if ( $count >= $max ) {
			break;
		}
		if ( ! is_string( $path ) || '' === $path || ! is_file( $path ) ) {
			continue;
		}
		@opcache_invalidate( $path, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_opcache_invalidate
		++$count;
	}
}

/**
 * Whether a URL is first-party theme / Elementor / WP core layout (never consent-gate).
 *
 * @param string $url Script, stylesheet, or asset URL.
 * @return bool
 */
function ucpf_is_site_layout_asset( $url ) {
	$u = strtolower( (string) $url );
	if ( '' === $u ) {
		return false;
	}
	$needles = array(
		'/wp-includes/',
		'/wp-admin/',
		'/wp-content/themes/',
		'/wp-content/plugins/elementor/',
		'/wp-content/plugins/elementor-pro/',
		'/wp-content/plugins/hello-elementor',
		'/wp-content/plugins/pro-elements/',
		'/wp-content/plugins/the-plus-addons-for-elementor',
		'/wp-content/plugins/essential-addons-for-elementor',
		'/wp-content/plugins/elementskit',
		'/wp-content/plugins/header-footer-elementor',
		'/wp-content/uploads/elementor/',
		'/wp-content/plugins/smart-slider-3/',
		'/wp-content/plugins/smart-slider-3-pro/',
		'n2.min.js',
		'smartslider-frontend',
		'jquery.min.js',
		'jquery.js',
		'jquery-migrate',
	);
	/**
	 * Filter layout-asset path needles that UCPF must never gate.
	 *
	 * @param string[] $needles Lowercase path fragments.
	 * @param string   $url     Original URL.
	 */
	$needles = apply_filters( 'ucpf_site_layout_asset_needles', $needles, $url );
	foreach ( (array) $needles as $n ) {
		$n = strtolower( (string) $n );
		if ( $n && false !== strpos( $u, $n ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Purge origin HTML page caches only (no Autoptimize / minify file wipe).
 *
 * Use after Elementor CSS clear so clean-URL HTML that omitted post-{id}.css is not
 * served until Accept All adds ?_ucpf= and bypasses cache. Does not call Cloudflare API.
 *
 * @param string $reason Short reason for logs / filters.
 * @return bool True when at least one purge path ran.
 */
function ucpf_purge_page_html_caches( $reason = '' ) {
	$reason = is_string( $reason ) ? $reason : '';

	/**
	 * Whether to purge HTML page caches for this reason.
	 *
	 * @param bool   $allow  Default true.
	 * @param string $reason Reason slug.
	 */
	if ( ! (bool) apply_filters( 'ucpf_purge_page_html_caches', true, $reason ) ) {
		return false;
	}

	$lock = get_transient( 'ucpf_purge_page_html_lock' );
	if ( $lock ) {
		return false;
	}
	set_transient( 'ucpf_purge_page_html_lock', 1, 20 );

	$cleared = false;

	// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party page-cache APIs.

	// Hummingbird page cache (not AO minify).
	foreach ( array( '\Hummingbird\WP_Hummingbird', 'WP_Hummingbird' ) as $hb_class ) {
		if ( $cleared || ! class_exists( $hb_class ) || ! method_exists( $hb_class, 'get_instance' ) ) {
			continue;
		}
		try {
			$hb = call_user_func( array( $hb_class, 'get_instance' ) );
			if ( $hb && isset( $hb->core ) && isset( $hb->core->modules['page_cache'] ) ) {
				$mod = $hb->core->modules['page_cache'];
				if ( is_object( $mod ) && method_exists( $mod, 'clear_cache' ) ) {
					$mod->clear_cache();
					$cleared = true;
				}
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Ignore HB API differences.
		}
	}
	if ( ! $cleared && function_exists( 'wphb_clear_page_cache' ) ) {
		wphb_clear_page_cache();
		$cleared = true;
	} elseif ( ! $cleared && has_action( 'wphb_clear_page_cache' ) ) {
		do_action( 'wphb_clear_page_cache' );
		$cleared = true;
	}

	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
		$cleared = true;
	}
	if ( has_action( 'litespeed_purge_all' ) ) {
		do_action( 'litespeed_purge_all' );
		$cleared = true;
	} elseif ( class_exists( '\LiteSpeed\Purge' ) && method_exists( '\LiteSpeed\Purge', 'purge_all' ) ) {
		\LiteSpeed\Purge::purge_all();
		$cleared = true;
	}
	if ( function_exists( 'w3tc_flush_posts' ) ) {
		w3tc_flush_posts();
		$cleared = true;
	} elseif ( function_exists( 'w3tc_flush_all' ) ) {
		w3tc_flush_all();
		$cleared = true;
	}
	if ( function_exists( 'wp_cache_clear_cache' ) ) {
		wp_cache_clear_cache();
		$cleared = true;
	}
	if ( has_action( 'ce_clear_cache' ) ) {
		do_action( 'ce_clear_cache' );
		$cleared = true;
	}
	if ( has_action( 'cache_enabler_clear_complete_cache' ) ) {
		do_action( 'cache_enabler_clear_complete_cache' );
		$cleared = true;
	}
	if ( function_exists( 'wpfc_clear_all_cache' ) ) {
		wpfc_clear_all_cache( true );
		$cleared = true;
	}
	if ( has_action( 'sg_cachepress_purge_cache' ) ) {
		do_action( 'sg_cachepress_purge_cache' );
		$cleared = true;
	}

	// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

	/**
	 * After attempting HTML page-cache purge (no Autoptimize clearall).
	 *
	 * @param bool   $cleared Whether a purge path ran.
	 * @param string $reason  Reason slug.
	 */
	do_action( 'ucpf_purged_page_html_caches', $cleared, $reason );

	return $cleared;
}

/**
 * Flush UCPF asset ?ver= plus common origin / page caches (no Cloudflare API).
 *
 * DANGEROUS under Cloudflare Cache Files / Cache Everything: clearing Autoptimize /
 * Rocket / LiteSpeed deletes CSS bundles while the edge still serves HTML pointing
 * at those URLs (or caches soft-404 HTML as text/css). Routine zip uploads must
 * NEVER call the third-party purge path.
 *
 * Default: UCPF asset bust only. Full site purge requires explicit allow:
 * add_filter( 'ucpf_allow_full_site_cache_flush', '__return_true' );
 *
 * @param string $reason Short reason for logs / ucpf_flush_site_caches action.
 * @return void
 */
function ucpf_flush_site_caches( $reason = '' ) {
	$reason = is_string( $reason ) ? $reason : '';
	ucpf_bust_asset_cache();

	/**
	 * Whether to also purge Rocket / LiteSpeed / Autoptimize / etc.
	 *
	 * @param bool   $allow  Default false (edge-safe).
	 * @param string $reason Flush reason slug.
	 */
	$allow_full_flush = (bool) apply_filters( 'ucpf_allow_full_site_cache_flush', false, $reason );
	if ( ! $allow_full_flush ) {
		/**
		 * After UCPF asset bust only (full site purge skipped).
		 *
		 * @param string $reason Flush reason slug.
		 */
		do_action( 'ucpf_flush_site_caches', $reason );
		return;
	}

	$lock = get_transient( 'ucpf_flush_site_caches_lock' );
	if ( $lock ) {
		return;
	}
	set_transient( 'ucpf_flush_site_caches_lock', 1, 30 );

	if ( function_exists( 'wp_cache_flush' ) ) {
		wp_cache_flush();
	}

	// Common page / object cache plugins (no hard dependency).
	// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- intentional third-party purge hooks.
	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
	}
	if ( has_action( 'litespeed_purge_all' ) ) {
		do_action( 'litespeed_purge_all' );
	} elseif ( class_exists( '\LiteSpeed\Purge' ) && method_exists( '\LiteSpeed\Purge', 'purge_all' ) ) {
		\LiteSpeed\Purge::purge_all();
	}
	if ( function_exists( 'w3tc_flush_all' ) ) {
		w3tc_flush_all();
	}
	if ( function_exists( 'wp_cache_clear_cache' ) ) {
		wp_cache_clear_cache();
	}
	if ( has_action( 'ce_clear_cache' ) ) {
		do_action( 'ce_clear_cache' );
	}
	if ( has_action( 'cache_enabler_clear_complete_cache' ) ) {
		do_action( 'cache_enabler_clear_complete_cache' );
	}
	if ( function_exists( 'wpfc_clear_all_cache' ) ) {
		wpfc_clear_all_cache( true );
	}
	if ( class_exists( 'autoptimizeCache' ) && method_exists( 'autoptimizeCache', 'clearall' ) ) {
		autoptimizeCache::clearall();
	}
	if ( has_action( 'sg_cachepress_purge_cache' ) ) {
		do_action( 'sg_cachepress_purge_cache' );
	}
	// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

	/**
	 * After UCPF flushes origin / page caches (explicit full flush only).
	 *
	 * @param string $reason Flush reason slug.
	 */
	do_action( 'ucpf_flush_site_caches', $reason );
}

