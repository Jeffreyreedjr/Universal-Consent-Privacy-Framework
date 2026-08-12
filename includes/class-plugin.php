<?php
/**
 * Main plugin orchestrator.
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin singleton.
 */
class Plugin {

	/**
	 * Instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether the banner markup was already printed this request.
	 *
	 * @var bool
	 */
	private $banner_rendered = false;

	/**
	 * Get instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initialize plugin components.
	 */
	public function init() {
		try {
			Migration::maybe_upgrade();

			Integrations\Wp_Consent_Api_Shim::instance()->init();
			Jurisdiction::instance()->init();
			// Cloudflare sites: turn geo pack routing on as soon as CF is known.
			Jurisdiction::instance()->maybe_auto_enable_geo_for_cloudflare();
			Consent_Manager::instance()->init();
			Privacy_State::instance()->init();
			Script_Registry::instance()->init();
			Script_Blocker::instance()->init();
			Theme_Manager::instance()->init();
			Shortcodes::instance()->init();
			Privacy_Tools::instance()->init();
			Vendor_Connectors::instance()->init();
			Rest_Api::instance()->init();
			Audit_Log::instance()->init();
			Login_Notice::instance()->init();
			Page_Generator::instance()->init();
			Cookie_Scanner::instance()->init();
			Scheduled_Scan::instance()->init();
			Active_Scan::instance()->init();
			Agency_Scanner::instance()->init();
			Cloudflare_Cache::instance()->init();
			Integrations::instance()->init();

			if ( is_admin() ) {
				Admin::instance()->init();
			} else {
				$this->init_frontend();
			}

			add_action( 'ucpf_daily_cleanup', array( Audit_Log::instance(), 'purge_expired' ) );
			add_action( 'upgrader_process_complete', array( $this, 'on_upgrader_complete' ), 10, 2 );
			add_action( 'switch_theme', array( $this, 'on_switch_theme' ) );
			add_action( 'elementor/core/files/clear_cache', array( $this, 'on_elementor_clear_cache' ) );
			// Never regenerate Elementor CSS via WP-Cron (external cron runners break it).
			// clear_cache is sync; missing files heal on enqueue; CF purge runs on shutdown.
			add_action( 'init', array( $this, 'maybe_run_pending_elementor_css_clear' ), 30 );
			add_action( 'elementor/css-file/before_enqueue', array( $this, 'heal_missing_elementor_css_file' ), 5 );

			/**
			 * Fires when UCPF is fully loaded.
			 */
			do_action( 'ucpf_loaded' );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'UCPF Plugin::init failed: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * After plugins/themes update — bust UCPF assets when needed; schedule CF edge purge.
	 *
	 * Do not purge LiteSpeed / Rocket / Autoptimize / full page caches here.
	 * Those nukes delete optimized CSS files while Cloudflare Cache Files can still
	 * pin the old URL (or cache a soft-404 HTML body) for a year.
	 *
	 * @param \WP_Upgrader $upgrader Upgrader instance.
	 * @param array        $options  Bulk upgrade options.
	 * @return void
	 */
	public function on_upgrader_complete( $upgrader, $options ) {
		unset( $upgrader );
		if ( empty( $options['type'] ) ) {
			return;
		}
		$type = (string) $options['type'];
		$ucpf = ( 'plugin' === $type && self::upgrader_touched_ucpf( $options ) );

		if ( $ucpf ) {
			ucpf_bust_asset_cache();
			if ( Settings::get( 'cloudflare_purge_on_ucpf_update', true ) ) {
				Cloudflare_Cache::instance()->schedule_purge( 'ucpf_update' );
			}
		}

		if ( Settings::get( 'cloudflare_purge_on_updates', true ) ) {
			if ( 'plugin' === $type ) {
				Cloudflare_Cache::instance()->schedule_purge( 'plugin_update' );
			} elseif ( 'theme' === $type ) {
				Cloudflare_Cache::instance()->schedule_purge( 'theme_update' );
			}
		}

		if ( in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			self::maybe_clear_elementor_css_after_update( $ucpf ? 'ucpf_update' : $type . '_update' );
		}
	}

	/**
	 * Whether this upgrader run included UCPF.
	 *
	 * @param array $options Upgrader options.
	 * @return bool
	 */
	private static function upgrader_touched_ucpf( array $options ) {
		$needle = defined( 'UCPF_PLUGIN_BASENAME' ) ? UCPF_PLUGIN_BASENAME : 'universal-consent-privacy-framework/universal-consent-privacy-framework.php';
		$plugins = array();
		if ( ! empty( $options['plugins'] ) && is_array( $options['plugins'] ) ) {
			$plugins = $options['plugins'];
		} elseif ( ! empty( $options['plugin'] ) ) {
			$plugins = array( $options['plugin'] );
		}
		foreach ( $plugins as $plugin ) {
			if ( $needle === (string) $plugin || false !== strpos( (string) $plugin, 'universal-consent-privacy-framework/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * After theme switch — UCPF banner assets + optional CF edge purge.
	 *
	 * @return void
	 */
	public function on_switch_theme() {
		ucpf_bust_asset_cache();
		if ( Settings::get( 'cloudflare_purge_on_updates', true ) ) {
			Cloudflare_Cache::instance()->schedule_purge( 'theme_switch' );
		}
		self::maybe_clear_elementor_css_after_update( 'theme_switch' );
	}

	/**
	 * Elementor regenerated its CSS — schedule CF edge purge (no origin optimizer nuke).
	 *
	 * @return void
	 */
	public function on_elementor_clear_cache() {
		if ( Settings::get( 'cloudflare_purge_on_updates', true ) ) {
			Cloudflare_Cache::instance()->schedule_purge( 'elementor_css' );
		}
	}

	/**
	 * Ask Elementor to rebuild generated CSS after updates.
	 *
	 * Clears Elementor CSS cache only (fast). Does not regenerate dozens of posts
	 * synchronously (that starved consent UI) and does not use WP-Cron / spawn_cron
	 * (unreliable with external cron runners). Missing post-*.css files heal on
	 * enqueue; Cloudflare purge runs on shutdown of this request.
	 *
	 * @param string $reason Reason slug for logging / notice.
	 * @return bool True when Elementor clear ran.
	 */
	public static function maybe_clear_elementor_css_after_update( $reason = '' ) {
		$reason = sanitize_key( (string) $reason );
		if ( ! Settings::get( 'elementor_clear_css_on_updates', true ) ) {
			delete_option( 'ucpf_pending_elementor_css_clear' );
			return false;
		}

		/**
		 * Filter whether to clear Elementor CSS after this update event.
		 *
		 * @param bool   $clear  Default true when setting enabled + Elementor present.
		 * @param string $reason Reason slug.
		 */
		if ( ! apply_filters( 'ucpf_clear_elementor_css_on_update', true, $reason ) ) {
			return false;
		}

		// Drop leftover regen cron from older builds.
		wp_clear_scheduled_hook( 'ucpf_elementor_css_regen' );

		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			update_option(
				'ucpf_pending_elementor_css_clear',
				array(
					'reason' => $reason ? $reason : 'deferred',
					'time'   => time(),
				),
				false
			);
			return false;
		}

		try {
			$plugin = \Elementor\Plugin::$instance;
			if ( ! $plugin || empty( $plugin->files_manager ) || ! is_object( $plugin->files_manager ) ) {
				update_option(
					'ucpf_pending_elementor_css_clear',
					array(
						'reason' => $reason ? $reason : 'deferred',
						'time'   => time(),
					),
					false
				);
				return false;
			}
			if ( ! method_exists( $plugin->files_manager, 'clear_cache' ) ) {
				return false;
			}
			$plugin->files_manager->clear_cache();
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			return false;
		}

		delete_option( 'ucpf_pending_elementor_css_clear' );

		if ( in_array( $reason, array( 'ucpf_update', 'plugin_update', 'theme_update', 'theme_switch', 'activate', 'deferred' ), true ) ) {
			update_option( 'ucpf_elementor_css_notice', 1, false );
		}

		if ( Settings::get( 'cloudflare_purge_on_updates', true ) || Settings::get( 'cloudflare_purge_on_ucpf_update', true ) ) {
			Cloudflare_Cache::instance()->schedule_purge( 'elementor_css' );
		}

		return true;
	}

	/**
	 * Regenerate Elementor CSS files on origin (capped batch).
	 *
	 * Kept for manual / filter use. Not called from update or front-end paths
	 * (sync batch starved consent UI; WP-Cron is avoided for reliability).
	 *
	 * @return string[] Public CSS URLs that were written (for Cloudflare file purge).
	 */
	public static function regenerate_elementor_css_on_origin() {
		$urls = array();
		if ( ! class_exists( '\Elementor\Plugin' ) || ! class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			return $urls;
		}

		$max = (int) apply_filters( 'ucpf_elementor_css_regen_max_posts', 25 );
		if ( $max < 3 ) {
			$max = 3;
		}
		if ( $max > 80 ) {
			$max = 80;
		}

		$post_ids = self::get_elementor_post_ids_for_css_regen( $max );

		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			if ( $post_id < 1 ) {
				continue;
			}
			try {
				$css = \Elementor\Core\Files\CSS\Post::create( $post_id );
				if ( ! $css || ! method_exists( $css, 'update' ) ) {
					continue;
				}
				$css->update();
				if ( method_exists( $css, 'get_url' ) ) {
					$url = (string) $css->get_url();
					if ( $url ) {
						$urls[] = $url;
					}
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				continue;
			}
		}

		// Global CSS (kit / defaults) when available.
		if ( class_exists( '\Elementor\Core\Files\CSS\Global_CSS' ) ) {
			try {
				$global = \Elementor\Core\Files\CSS\Global_CSS::create( 'global' );
				if ( $global && method_exists( $global, 'update' ) ) {
					$global->update();
					if ( method_exists( $global, 'get_url' ) ) {
						$gurl = (string) $global->get_url();
						if ( $gurl ) {
							$urls[] = $gurl;
						}
					}
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Ignore.
			}
		}

		$urls = array_values( array_unique( array_filter( $urls ) ) );

		/**
		 * Filter regenerated Elementor CSS URLs before Cloudflare file purge.
		 *
		 * @param string[] $urls    Absolute CSS URLs.
		 * @param int[]    $post_ids Post IDs regenerated.
		 */
		return apply_filters( 'ucpf_elementor_regenerated_css_urls', $urls, $post_ids );
	}

	/**
	 * Prefer front page, kit, theme-builder templates, then other Elementor posts.
	 *
	 * @param int $max Max IDs.
	 * @return int[]
	 */
	private static function get_elementor_post_ids_for_css_regen( $max ) {
		$priority = array();

		$front = (int) get_option( 'page_on_front' );
		if ( $front > 0 ) {
			$priority[] = $front;
		}
		$kit = (int) get_option( 'elementor_active_kit' );
		if ( $kit > 0 ) {
			$priority[] = $kit;
		}

		$library = get_posts(
			array(
				'post_type'              => 'elementor_library',
				'post_status'            => 'publish',
				'posts_per_page'         => min( 20, $max ),
				'fields'                 => 'ids',
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		if ( is_array( $library ) ) {
			foreach ( $library as $id ) {
				$priority[] = (int) $id;
			}
		}

		$meta_key = '_elementor_edit_mode';
		if ( class_exists( '\Elementor\Core\Base\Document' ) ) {
			try {
				$ref = new \ReflectionClass( '\Elementor\Core\Base\Document' );
				if ( $ref->hasConstant( 'BUILT_WITH_ELEMENTOR_META_KEY' ) ) {
					$meta_key = (string) $ref->getConstant( 'BUILT_WITH_ELEMENTOR_META_KEY' );
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				$meta_key = '_elementor_edit_mode';
			}
		}

		$built = get_posts(
			array(
				'post_type'              => 'any',
				'post_status'            => 'publish',
				'posts_per_page'         => $max,
				'fields'                 => 'ids',
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => $meta_key,
						'compare' => 'EXISTS',
					),
				),
			)
		);
		if ( is_array( $built ) ) {
			foreach ( $built as $id ) {
				$priority[] = (int) $id;
			}
		}

		$out = array();
		foreach ( $priority as $id ) {
			if ( $id < 1 || isset( $out[ $id ] ) ) {
				continue;
			}
			$out[ $id ] = $id;
			if ( count( $out ) >= $max ) {
				break;
			}
		}
		return array_values( $out );
	}

	/**
	 * If a pending Elementor clear was recorded before Elementor booted, clear cache
	 * only (no cron, no bulk regen on the front-end).
	 *
	 * @return void
	 */
	public function maybe_run_pending_elementor_css_clear() {
		wp_clear_scheduled_hook( 'ucpf_elementor_css_regen' );
		$pending = get_option( 'ucpf_pending_elementor_css_clear', null );
		if ( empty( $pending ) ) {
			return;
		}
		$reason = is_array( $pending ) && ! empty( $pending['reason'] ) ? (string) $pending['reason'] : 'deferred';
		$when   = is_array( $pending ) && ! empty( $pending['time'] ) ? (int) $pending['time'] : 0;
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			if ( $when && ( time() - $when ) > HOUR_IN_SECONDS ) {
				delete_option( 'ucpf_pending_elementor_css_clear' );
			}
			return;
		}
		// Clear the pending flag first so front-end requests do not re-enter.
		delete_option( 'ucpf_pending_elementor_css_clear' );
		self::maybe_clear_elementor_css_after_update( $reason );
	}

	/**
	 * If Elementor CSS is missing on disk at enqueue time, rebuild before link is printed.
	 *
	 * Stops the first visitor after a clear from receiving WordPress HTML at the CSS URL
	 * (which Cloudflare can year-cache as text/html).
	 *
	 * @param object $css_file Elementor CSS file object.
	 * @return void
	 */
	public function heal_missing_elementor_css_file( $css_file ) {
		if ( wp_doing_ajax() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( ! is_object( $css_file ) || ! method_exists( $css_file, 'get_path' ) || ! method_exists( $css_file, 'update' ) ) {
			return;
		}
		static $healed = 0;
		if ( $healed >= 8 ) {
			return;
		}
		try {
			$path = (string) $css_file->get_path();
			if ( '' === $path || file_exists( $path ) ) {
				return;
			}
			++$healed;
			$css_file->update();
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Never break front-end enqueue.
		}
	}

	/**
	 * Frontend hooks.
	 */
	private function init_frontend() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_public_assets' ), 20 );
		add_action( 'wp_head', array( $this, 'print_discover_bootstrap' ), 0 );
		add_action( 'wp_head', array( $this, 'print_early_privacy_gate' ), 1 );
		// Early print so guests still get the banner when footers/optimizers are flaky.
		add_action( 'wp_body_open', array( $this, 'render_banner' ), 5 );
		add_action( 'wp_footer', array( $this, 'render_banner' ), 5 );
		add_action( 'wp_footer', array( $this, 'render_floating_button' ), 6 );
		add_filter( 'script_loader_tag', array( $this, 'protect_consent_scripts' ), 5, 3 );
		add_filter( 'style_loader_tag', array( $this, 'protect_consent_styles' ), 5, 4 );
	}

	/**
	 * Earliest possible Consent Mode grant during guest discover crawl (before Site Kit / GTM).
	 */
	public function print_discover_bootstrap() {
		if ( is_admin() || ! Consent_Manager::instance()->is_discover_mode() ) {
			return;
		}
		echo "<script id=\"ucpf-discover-bootstrap\">\n";
		echo "window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}\n";
		echo "gtag('consent','default',{ad_storage:'granted',analytics_storage:'granted',ad_user_data:'granted',ad_personalization:'granted',functionality_storage:'granted',personalization_storage:'granted',security_storage:'granted',wait_for_update:500});\n";
		echo "gtag('consent','update',{ad_storage:'granted',analytics_storage:'granted',ad_user_data:'granted',ad_personalization:'granted',functionality_storage:'granted',personalization_storage:'granted',security_storage:'granted'});\n";
		echo "window.__ucpfDiscover=true;\n";
		echo "</script>\n";
	}

	/**
	 * Print denied Consent Mode defaults + network/script/link hard-gate before third-party tags.
	 *
	 * Google Consent Mode "denied" alone still allows cookieless /g/collect (click events included).
	 * The network gate blocks analytics/marketing/functional/security URL patterns until consent.
	 */
	public function print_early_privacy_gate() {
		if ( is_admin() || Consent_Manager::instance()->is_discover_mode() ) {
			return;
		}

		$gcm = Settings::get( 'google_consent_mode' );
		if ( $gcm && 'off' !== $gcm ) {
			$defaults = apply_filters(
				'ucpf_google_consent_mode_defaults',
				array(
					'ad_storage'         => 'denied',
					'analytics_storage'  => 'denied',
					'ad_user_data'       => 'denied',
					'ad_personalization' => 'denied',
					'wait_for_update'    => 500,
				)
			);
			echo '<script id="ucpf-gcm-bootstrap" data-cfasync="false" data-no-optimize="1" data-no-defer="1">' . "\n";
			echo "window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}\n";
			echo "gtag('consent','default'," . wp_json_encode( $defaults ) . ");\n";
			$cookie = Consent_Manager::instance()->read_cookie();
			if ( is_array( $cookie ) && ! empty( $cookie['categories'] ) && is_array( $cookie['categories'] ) ) {
				echo Integrations\Google_Consent_Mode::instance()->build_update_script( $cookie['categories'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON from wp_json_encode.
				echo "\n";
			}
			echo "</script>\n";
		}

		$gate = UCPF_PLUGIN_DIR . 'public/js/network-gate.js';
		if ( ! is_readable( $gate ) ) {
			return;
		}

		// Boot config stays tiny + inline. The gate itself MUST be an external src=
		// file — never file_get_contents into HTML. Inlining embeds the whole gate in
		// every page; during zip upload a partial read produces a SyntaxError that
		// Cloudflare then year/hour-caches as the document. External src 404s briefly
		// (gate off for one request) but cannot poison theme HTML/CSS/JS.
		$privacy  = Privacy_State::instance()->get_state_for_js();
		$extras   = Catalog_Suggestions::gate_extra_patterns();
		$pack     = Jurisdiction::instance()->resolve();
		$defaults = isset( $pack['category_defaults'] ) && is_array( $pack['category_defaults'] ) ? $pack['category_defaults'] : array();
		echo '<script id="ucpf-network-gate-boot" data-cfasync="false" data-no-optimize="1" data-no-defer="1">' . "\n";
		echo 'window.__ucpfPrivacy=' . wp_json_encode( $privacy ) . ";\n";
		echo 'window.__ucpfConsentType=' . wp_json_encode( Jurisdiction::instance()->get_consent_type() ) . ";\n";
		echo 'window.__ucpfCategoryDefaults=' . wp_json_encode( $defaults ) . ";\n";
		echo 'window.__ucpfGateExtra=' . wp_json_encode( $extras ) . ";\n";
		echo "</script>\n";

		// Enqueue + print early so the gate runs before third-party tags (Plugin Check compliant).
		wp_register_script(
			'ucpf-network-gate',
			UCPF_PLUGIN_URL . 'public/js/network-gate.js',
			array(),
			ucpf_asset_version( 'public/js/network-gate.js' ),
			false
		);
		wp_enqueue_script( 'ucpf-network-gate' );
		wp_print_scripts( 'ucpf-network-gate' );
	}

	/**
	 * Enqueue public CSS/JS.
	 */
	public function enqueue_public_assets() {
		Theme_Manager::instance()->enqueue_styles();

		wp_enqueue_script(
			'ucpf-consent',
			UCPF_PLUGIN_URL . 'public/js/consent.js',
			array(),
			ucpf_asset_version( 'public/js/consent.js' ),
			false
		);

		wp_enqueue_script(
			'ucpf-consent-motion',
			UCPF_PLUGIN_URL . 'public/js/consent-motion.js',
			array( 'ucpf-consent' ),
			ucpf_asset_version( 'public/js/consent-motion.js' ),
			false
		);

		wp_enqueue_script(
			'ucpf-loader',
			UCPF_PLUGIN_URL . 'public/js/loader.js',
			array( 'ucpf-consent' ),
			ucpf_asset_version( 'public/js/loader.js' ),
			false
		);

		wp_enqueue_script(
			'ucpf-form-captcha-guard',
			UCPF_PLUGIN_URL . 'public/js/form-captcha-guard.js',
			array( 'ucpf-consent' ),
			ucpf_asset_version( 'public/js/form-captcha-guard.js' ),
			false
		);

		$jurisdiction = Jurisdiction::instance();
		$pack_cfg     = $jurisdiction->get_config_for_js();
		$pack_copy    = isset( $pack_cfg['copy'] ) && is_array( $pack_cfg['copy'] ) ? $pack_cfg['copy'] : array();

		$config = array(
			'restUrl'         => rest_url( 'ucpf/v1/' ),
			'nonce'           => wp_create_nonce( 'wp_rest' ),
			'consentVersion'  => Settings::get( 'consent_version' ),
			'policyVersion'   => Settings::get( 'policy_version' ),
			'cookieLifetime'  => (int) apply_filters( 'ucpf_consent_cookie_lifetime', Settings::get( 'cookie_lifetime_days' ) ) * DAY_IN_SECONDS,
			'cookieDomain'    => ucpf_cookie_domain(),
			'cookiePath'      => ucpf_cookie_path(),
			'storageSuffix'   => ucpf_storage_suffix(),
			'bannerLayout'    => in_array( Settings::get( 'banner_layout' ), array( 'bar', 'modal', 'corner' ), true ) ? Settings::get( 'banner_layout' ) : 'bar',
			'bannerPosition'  => in_array( Settings::get( 'banner_position' ), array( 'left', 'center', 'right' ), true ) ? Settings::get( 'banner_position' ) : 'left',
			'bannerTheme'     => Theme_Manager::instance()->resolve_preset( Settings::get( 'banner_theme' ) ),
			'themeTokens'     => Theme_Manager::instance()->get_resolved_css_variables(),
			'showRejectAll'   => (bool) Settings::get( 'show_reject_all' ),
			'showAcceptAll'   => (bool) Settings::get( 'show_accept_all' ),
			'showCustomize'   => (bool) Settings::get( 'show_customize' ),
			'discoverMode'    => Consent_Manager::instance()->is_discover_mode(),
			'categories'      => Consent_Manager::instance()->get_categories_for_js(),
			'services'          => Script_Registry::instance()->get_services_for_js(),
			'managedServices' => Script_Blocker::instance()->get_managed_services_for_js(),
			'cookiePolicyUrl' => Page_Generator::instance()->get_page_url( 'cookie_policy' ),
			'privacyPolicyUrl'=> Page_Generator::instance()->get_page_url( 'privacy_policy' ),
			'privacy'         => Privacy_State::instance()->get_state_for_js(),
			'consentType'     => $jurisdiction->get_consent_type(),
			// leaveBuildersAlone: skip Elementor Motion FX / sticky layout recovery only.
			// Consent overlays, captcha covers, and video parking always run on every builder.
			'leaveBuildersAlone' => (bool) apply_filters( 'ucpf_leave_builders_alone', true ),
			'jurisdiction'    => $pack_cfg,
			'i18n'            => array(
				'cookies'       => ! empty( $pack_copy['banner_title'] ) ? $pack_copy['banner_title'] : __( 'Cookies', 'universal-consent-privacy-framework' ),
				'description'   => ! empty( $pack_copy['banner_text'] ) ? $pack_copy['banner_text'] : __( 'We use essential cookies for security and optional cookies based on your choices. You can withdraw or manage consent later via Cookie Settings. See our cookie policy for details.', 'universal-consent-privacy-framework' ),
				'manage'        => __( 'Customize', 'universal-consent-privacy-framework' ),
				'rejectAll'     => __( 'Reject All', 'universal-consent-privacy-framework' ),
				'acceptAll'     => __( 'Accept All', 'universal-consent-privacy-framework' ),
				'savePrefs'     => __( 'Save Preferences', 'universal-consent-privacy-framework' ),
				'cookieSettings'=> ! empty( $pack_copy['fab_label'] ) ? $pack_copy['fab_label'] : __( 'Cookie Settings', 'universal-consent-privacy-framework' ),
				'cookiePolicy'  => __( 'Cookie Policy', 'universal-consent-privacy-framework' ),
				'readCookiePolicy' => __( 'Read our Cookie Policy', 'universal-consent-privacy-framework' ),
				'necessary'     => __( 'Necessary', 'universal-consent-privacy-framework' ),
				'alwaysOn'      => __( 'Always active', 'universal-consent-privacy-framework' ),
				'privacyChoices'=> ! empty( $pack_copy['privacy_choices_label'] ) ? $pack_copy['privacy_choices_label'] : __( 'Your Privacy Choices', 'universal-consent-privacy-framework' ),
				'prefsTitle'    => ! empty( $pack_copy['prefs_title'] ) ? $pack_copy['prefs_title'] : __( 'Cookie Preferences', 'universal-consent-privacy-framework' ),
				'prefsIntro'    => ! empty( $pack_copy['prefs_intro'] ) ? $pack_copy['prefs_intro'] : '',
				'captchaGuardTitle' => __( 'CAPTCHA required before you can use this form', 'universal-consent-privacy-framework' ),
				/* translators: Shown when Security consent is off and the form uses CAPTCHA / Turnstile. */
				'captchaGuardBody'  => __( 'This form uses anti-spam protection (CAPTCHA / Turnstile) that needs Security cookies. Enable Security before filling fields so your entries are not lost.', 'universal-consent-privacy-framework' ),
				'captchaGuardEnable'=> __( 'Enable Security & continue', 'universal-consent-privacy-framework' ),
				'captchaGuardPrefs' => __( 'Cookie Settings', 'universal-consent-privacy-framework' ),
				'embedGuardMapTitle' => __( 'Map blocked until you allow Embeds & Widgets', 'universal-consent-privacy-framework' ),
				'embedGuardMapBody'  => __( 'This map needs Embeds & Widgets cookies to load tiles and scripts. Enable Embeds & Widgets to continue — nothing loads until then.', 'universal-consent-privacy-framework' ),
				'embedGuardVideoTitle' => __( 'Video blocked until you allow Marketing & Embeds', 'universal-consent-privacy-framework' ),
				'embedGuardVideoBody'  => __( 'This embedded video needs Marketing and Embeds & Widgets cookies. Enable both to load the player.', 'universal-consent-privacy-framework' ),
				'embedGuardVimeoTitle' => __( 'Video blocked until you allow Marketing & Embeds', 'universal-consent-privacy-framework' ),
				'embedGuardVimeoBody'  => __( 'This embedded video needs Marketing and Embeds & Widgets cookies. Enable both to load the player.', 'universal-consent-privacy-framework' ),
				'embedGuardMarketingTitle' => __( 'Content blocked until you allow Marketing', 'universal-consent-privacy-framework' ),
				'embedGuardMarketingBody'  => __( 'This content needs Marketing cookies before it can load. Enable Marketing to continue.', 'universal-consent-privacy-framework' ),
				'embedGuardGenericTitle' => __( 'Content blocked until you allow the required cookies', 'universal-consent-privacy-framework' ),
				'embedGuardGenericBody'  => __( 'This content needs additional cookies before it can load. Enable the required category to continue.', 'universal-consent-privacy-framework' ),
				'embedGuardEnableFunctional' => __( 'Enable Embeds & Widgets & continue', 'universal-consent-privacy-framework' ),
				'embedGuardEnableMarketing'  => __( 'Enable Marketing & continue', 'universal-consent-privacy-framework' ),
				'embedGuardEnableVideo'      => __( 'Enable Marketing & Embeds & continue', 'universal-consent-privacy-framework' ),
				/* translators: %s: consent category label (e.g. Preferences). */
				'embedGuardEnableCategory'   => __( 'Enable %s & continue', 'universal-consent-privacy-framework' ),
				'checkoutGuardTitle' => __( 'Checkout needs Embeds & Widgets', 'universal-consent-privacy-framework' ),
				'checkoutGuardBody'  => __( 'Payment and shipping widgets (PayPal, Stripe, Square, Shippo, UPS, USPS, and similar) need Embeds & Widgets cookies before checkout can request rates, validate addresses, or load payment buttons. Enable them before entering details so nothing is lost.', 'universal-consent-privacy-framework' ),
				'checkoutGuardEnable'=> __( 'Enable Embeds & Widgets & continue', 'universal-consent-privacy-framework' ),
				'checkoutGuardCombinedTitle'  => __( 'Checkout needs Security & Embeds', 'universal-consent-privacy-framework' ),
				'checkoutGuardCombinedBody'   => __( 'Checkout uses anti-spam protection (CAPTCHA) and payment / shipping widgets. Enable Security and Embeds & Widgets together before entering details so nothing is lost.', 'universal-consent-privacy-framework' ),
				'checkoutGuardCombinedEnable' => __( 'Enable required cookies & continue', 'universal-consent-privacy-framework' ),
			),
		);

		wp_add_inline_script(
			'ucpf-consent',
			'window.ucpfConfig = ' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_SLASHES ) . ';',
			'before'
		);

		Integrations\Google_Consent_Mode::instance()->maybe_enqueue();
	}

	/**
	 * Keep consent scripts out of Cloudflare Rocket Loader / similar optimizers.
	 *
	 * @param string $tag    Script tag.
	 * @param string $handle Handle.
	 * @param string $src    Source URL.
	 * @return string
	 */
	public function protect_consent_scripts( $tag, $handle, $src ) {
		$protected = array(
			'ucpf-consent',
			'ucpf-consent-motion',
			'ucpf-loader',
			'ucpf-network-gate',
			'ucpf-form-captcha-guard',
		);
		if ( ! in_array( $handle, $protected, true ) ) {
			return $tag;
		}
		if ( false === strpos( $tag, 'data-cfasync' ) ) {
			$tag = str_replace( '<script ', '<script data-cfasync="false" ', $tag );
		}
		if ( false === strpos( $tag, 'data-no-optimize' ) ) {
			$tag = str_replace( '<script ', '<script data-no-optimize="1" data-no-defer="1" ', $tag );
		}
		return $tag;
	}

	/**
	 * Keep UCPF stylesheets out of Autoptimize-style optimizers.
	 *
	 * @param string $tag    Style tag.
	 * @param string $handle Handle.
	 * @param string $href   Href.
	 * @param string $media  Media.
	 * @return string
	 */
	public function protect_consent_styles( $tag, $handle, $href, $media ) {
		unset( $href, $media );
		if ( ! in_array( $handle, array( 'ucpf-banner', 'ucpf-legal' ), true ) ) {
			return $tag;
		}
		if ( false === strpos( $tag, 'data-no-optimize' ) ) {
			$tag = str_replace( '<link ', '<link data-no-optimize="1" ', $tag );
		}
		return $tag;
	}

	/**
	 * Render consent banner markup.
	 */
	public function render_banner() {
		if ( $this->banner_rendered || is_admin() || ! Settings::get( 'banner_enabled', true ) ) {
			return;
		}

		if ( Consent_Manager::instance()->is_discover_mode() ) {
			return;
		}

		/**
		 * Fires before banner render.
		 */
		do_action( 'ucpf_before_banner_render' );

		include UCPF_PLUGIN_DIR . 'templates/banner.php';
		$this->banner_rendered = true;

		/**
		 * Fires after banner render.
		 */
		do_action( 'ucpf_after_banner_render' );
	}

	/**
	 * Floating button is rendered inside #ucpf-root (banner template) for theme tokens.
	 */
	public function render_floating_button() {
		// Intentionally empty — FAB ships with templates/banner.php.
	}
}
