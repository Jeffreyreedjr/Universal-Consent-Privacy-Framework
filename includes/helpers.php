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
 * Marketing Version (UCPF_VERSION) often stays on the same alpha string across zip
 * uploads, so Cloudflare / browsers keep stale consent.js / banner.css until a hard purge.
 * Append a stamp that changes whenever plugin files are overwritten.
 *
 * @param string $relative Optional path under the plugin dir (e.g. public/js/consent.js).
 * @return string
 */
function ucpf_asset_version( $relative = '' ) {
	$base = defined( 'UCPF_VERSION' ) ? (string) UCPF_VERSION : '0';
	$rev  = (string) get_option( 'ucpf_assets_rev', '' );

	$relative = ltrim( str_replace( '\\', '/', (string) $relative ), '/' );
	if ( $relative && defined( 'UCPF_PLUGIN_DIR' ) ) {
		$path = UCPF_PLUGIN_DIR . $relative;
		if ( is_readable( $path ) ) {
			$mtime = (int) filemtime( $path );
			if ( $mtime > 0 ) {
				return $base . '.' . $mtime . ( $rev ? '.' . $rev : '' );
			}
		}
	}

	return $rev ? $base . '.' . $rev : $base;
}

/**
 * Bust front-end asset cache after zip overwrite / upgrade.
 *
 * @return void
 */
function ucpf_bust_asset_cache() {
	update_option( 'ucpf_assets_rev', (string) time(), false );
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
	if ( ! apply_filters( 'ucpf_allow_full_site_cache_flush', false, $reason ) ) {
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
