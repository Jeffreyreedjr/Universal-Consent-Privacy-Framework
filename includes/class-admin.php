<?php
/**
 * Admin UI.
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Admin screens.
 */
class Admin {

	/**
	 * Instance.
	 *
	 * @var Admin|null
	 */
	private static $instance = null;

	/**
	 * Whether Cloudflare zone resolve should run on shutdown.
	 *
	 * @var bool
	 */
	private $cf_zone_resolve_pending = false;

	/**
	 * Re-entry guard for zone resolve.
	 *
	 * @var bool
	 */
	private static $cf_zone_resolve_running = false;

	/**
	 * Menu slug.
	 *
	 * @var string
	 */
	const MENU_SLUG = 'ucpf-dashboard';

	/**
	 * Get instance.
	 *
	 * @return Admin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Init admin.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'network_admin_menu', array( $this, 'register_network_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_menu_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'maybe_active_scan_notice' ) );
		add_action( 'admin_notices', array( $this, 'maybe_elementor_css_notice' ) );
		add_action( 'admin_init', array( $this, 'maybe_dismiss_elementor_css_notice' ) );
		add_action( 'admin_post_ucpf_save_wizard', array( $this, 'handle_wizard_save' ) );
		add_action( 'admin_post_ucpf_export_logs', array( $this, 'handle_export_logs' ) );
		add_action( 'admin_post_ucpf_purge_cloudflare', array( $this, 'handle_purge_cloudflare' ) );
		add_action( 'admin_post_ucpf_save_network_settings', array( $this, 'handle_save_network_settings' ) );
		add_action( 'admin_post_ucpf_promote_network_settings', array( $this, 'handle_promote_network_settings' ) );
		add_action( 'admin_post_ucpf_clear_network_overrides', array( $this, 'handle_clear_network_overrides' ) );
		add_action( 'admin_post_ucpf_promote_network_from_site', array( $this, 'handle_promote_network_from_site' ) );
		add_action( 'update_option_' . Settings::OPTION_KEY, array( $this, 'maybe_schedule_cloudflare_zone_resolve' ), 20, 2 );
		add_action( 'shutdown', array( $this, 'maybe_run_cloudflare_zone_resolve' ), 20 );
	}

	/**
	 * After Cloudflare credentials save, schedule Zone ID resolve on shutdown (never inline).
	 *
	 * Inline resolve + Settings::update during options.php previously looped until OOM
	 * because form sanitize discarded programmatic cloudflare_zone_id writes.
	 *
	 * @param mixed $old Previous option.
	 * @param mixed $new New option.
	 * @return void
	 */
	public function maybe_schedule_cloudflare_zone_resolve( $old, $new ) {
		unset( $old );
		if ( Settings::is_internal_update() ) {
			return;
		}
		if ( ! is_array( $new ) || empty( $new['cloudflare_purge_enabled'] ) ) {
			return;
		}
		$zone = isset( $new['cloudflare_zone_id'] ) ? preg_replace( '/[^a-zA-Z0-9]/', '', (string) $new['cloudflare_zone_id'] ) : '';
		if ( is_string( $zone ) && '' !== $zone ) {
			return;
		}
		$domain = Cloudflare_Cache::sanitize_domain( isset( $new['cloudflare_domain'] ) ? (string) $new['cloudflare_domain'] : '' );
		if ( '' === $domain ) {
			$domain = Cloudflare_Cache::sanitize_domain( Cloudflare_Cache::default_domain() );
		}
		if ( '' === $domain || ! Settings::secret_is_set( 'cloudflare_api_token' ) ) {
			return;
		}
		$this->cf_zone_resolve_pending = true;
	}

	/**
	 * Resolve Cloudflare Zone ID once per request after options are stable.
	 *
	 * @return void
	 */
	public function maybe_run_cloudflare_zone_resolve() {
		if ( ! $this->cf_zone_resolve_pending || self::$cf_zone_resolve_running ) {
			return;
		}
		$this->cf_zone_resolve_pending = false;
		self::$cf_zone_resolve_running = true;
		try {
			Cloudflare_Cache::instance()->resolve_and_store_zone_id( true );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Never take down admin saves if CF API misbehaves.
		} finally {
			self::$cf_zone_resolve_running = false;
		}
	}

	/**
	 * Register admin menus.
	 */
	public function register_menus() {
		// Dashicon only — custom PNG/SVG has been unreliable in the WP admin sidebar slot.
		add_menu_page(
			Brand::product_name(),
			Brand::menu_title(),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-shield',
			58
		);

		$pages = array(
			'dashboard'   => array( __( 'Dashboard', 'universal-consent-privacy-framework' ), 'render_dashboard' ),
			'wizard'      => array( __( 'Setup Wizard', 'universal-consent-privacy-framework' ), 'render_wizard' ),
			'banner'      => array( __( 'Banner & Branding', 'universal-consent-privacy-framework' ), 'render_banner_settings' ),
			'registry'    => array( __( 'Script Registry', 'universal-consent-privacy-framework' ), 'render_registry' ),
			'scanner'     => array( __( 'Cookie Scanner', 'universal-consent-privacy-framework' ), 'render_scanner' ),
			'pages'       => array( __( 'Generated Pages', 'universal-consent-privacy-framework' ), 'render_pages' ),
			'logs'        => array( __( 'Consent Logs', 'universal-consent-privacy-framework' ), 'render_logs' ),
			'integrations'=> array( __( 'Integrations', 'universal-consent-privacy-framework' ), 'render_integrations' ),
			'developer'   => array( __( 'Developer API', 'universal-consent-privacy-framework' ), 'render_developer' ),
			'advanced'    => array( __( 'Advanced Settings', 'universal-consent-privacy-framework' ), 'render_advanced' ),
		);

		foreach ( $pages as $slug => $page ) {
			if ( 'dashboard' === $slug ) {
				continue;
			}
			add_submenu_page(
				self::MENU_SLUG,
				$page[0],
				$page[0],
				'manage_options',
				'ucpf-' . $slug,
				array( $this, $page[1] )
			);
		}
	}

	/**
	 * Network Admin menu (multisite connection defaults).
	 */
	public function register_network_menus() {
		if ( ! is_multisite() ) {
			return;
		}
		add_menu_page(
			Brand::product_name(),
			Brand::menu_title(),
			'manage_network_options',
			'ucpf-network',
			array( $this, 'render_network_settings' ),
			'dashicons-shield',
			58
		);
	}

	/**
	 * Network settings screen.
	 */
	public function render_network_settings() {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage network settings.', 'universal-consent-privacy-framework' ) );
		}
		$file = UCPF_PLUGIN_DIR . 'admin/views/network-settings.php';
		if ( ! is_readable( $file ) ) {
			wp_die( esc_html__( 'Admin view missing.', 'universal-consent-privacy-framework' ) );
		}
		include $file;
	}

	/**
	 * Save network connection settings.
	 */
	public function handle_save_network_settings() {
		if ( ! is_multisite() || ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'universal-consent-privacy-framework' ) );
		}
		check_admin_referer( 'ucpf_save_network_settings' );
		$ok = Network_Settings::update( Network_Settings::sanitize( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in Network_Settings::sanitize.
		$redirect = add_query_arg(
			'ucpf_net',
			$ok ? 'saved' : 'error',
			network_admin_url( 'admin.php?page=ucpf-network' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Promote a chosen blog's connection settings to the network option.
	 */
	public function handle_promote_network_settings() {
		if ( ! is_multisite() || ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'universal-consent-privacy-framework' ) );
		}
		check_admin_referer( 'ucpf_promote_network_settings' );
		$blog_id = isset( $_POST['blog_id'] ) ? (int) $_POST['blog_id'] : 0;
		$ok      = false;
		if ( $blog_id > 0 && get_site( $blog_id ) ) {
			switch_to_blog( $blog_id );
			$ok = Network_Settings::promote_from_current_site();
			restore_current_blog();
		}
		$redirect = add_query_arg(
			'ucpf_net',
			$ok ? 'promoted' : 'error',
			network_admin_url( 'admin.php?page=ucpf-network' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Promote from the current site (Advanced settings button).
	 */
	public function handle_promote_network_from_site() {
		if ( ! is_multisite() || ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'universal-consent-privacy-framework' ) );
		}
		check_admin_referer( 'ucpf_promote_network_from_site' );
		$ok = Network_Settings::promote_from_current_site();
		$redirect = add_query_arg(
			array(
				'page'     => 'ucpf-advanced',
				'ucpf_net' => $ok ? 'promoted' : 'error',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Clear network-capable keys on every blog.
	 */
	public function handle_clear_network_overrides() {
		if ( ! is_multisite() || ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'universal-consent-privacy-framework' ) );
		}
		check_admin_referer( 'ucpf_clear_network_overrides' );
		Network_Settings::clear_all_site_overrides();
		$redirect = add_query_arg(
			'ucpf_net',
			'cleared',
			network_admin_url( 'admin.php?page=ucpf-network' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Sidebar menu icon CSS on every admin screen (icon must look right outside UCPF pages).
	 */
	public function enqueue_menu_assets() {
		wp_enqueue_style(
			'ucpf-admin-menu',
			UCPF_PLUGIN_URL . 'admin/css/admin-menu.css',
			array(),
			ucpf_asset_version( 'admin/css/admin-menu.css' )
		);
	}

	/**
	 * Site-wide notice while an interactive Playwright scan is running.
	 */
	public function maybe_active_scan_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$active = Active_Scan::instance()->get_for_rest();
		if ( empty( $active['active'] ) || empty( $active['job']['job_id'] ) ) {
			return;
		}
		$job     = $active['job'];
		$job_id  = (string) $job['job_id'];
		$msg     = ! empty( $job['progress']['message'] )
			? (string) $job['progress']['message']
			: ( ! empty( $job['message'] ) ? (string) $job['message'] : __( 'Playwright scan in progress.', 'universal-consent-privacy-framework' ) );
		$pct     = isset( $job['progress']['percent'] ) ? (int) round( (float) $job['progress']['percent'] ) : 0;
		$scanner = admin_url( 'admin.php?page=ucpf-scanner' );
		$line    = sprintf(
			/* translators: 1: percent complete, 2: job id, 3: status message */
			__( 'UCPF Playwright scan running (%1$d%% · job %2$s): %3$s', 'universal-consent-privacy-framework' ),
			$pct,
			$job_id,
			$msg
		);
		echo '<div class="notice notice-info is-dismissible ucpf-active-scan-notice" id="ucpf-active-scan-notice"><p>';
		echo esc_html( $line );
		echo ' <a href="' . esc_url( $scanner ) . '">' . esc_html__( 'Open Cookie Scanner', 'universal-consent-privacy-framework' ) . '</a>';
		echo ' · <button type="button" class="button-link" id="ucpf-active-scan-notice-stop">' . esc_html__( 'Stop scan', 'universal-consent-privacy-framework' ) . '</button>';
		echo '</p></div>';

		// Lightweight stop handler on screens that may not load full admin.js (non-UCPF pages).
		$hook = isset( $GLOBALS['hook_suffix'] ) ? (string) $GLOBALS['hook_suffix'] : '';
		if ( false === strpos( $hook, 'ucpf' ) ) {
			$rest = esc_url( rest_url( 'ucpf/v1/scan/cancel' ) );
			$nonce = wp_create_nonce( 'wp_rest' );
			echo '<script>(function(){var b=document.getElementById("ucpf-active-scan-notice-stop");if(!b)return;b.addEventListener("click",function(){b.disabled=true;fetch(' . wp_json_encode( $rest ) . ',{method:"POST",headers:{"Content-Type":"application/json","X-WP-Nonce":' . wp_json_encode( $nonce ) . '},body:JSON.stringify({job_id:' . wp_json_encode( $job_id ) . '})}).then(function(){window.location.reload();}).catch(function(){b.disabled=false;});});})();</script>';
		}
	}

	/**
	 * After UCPF / plugin updates cleared Elementor CSS cache — one dismissible tip.
	 *
	 * @return void
	 */
	public function maybe_elementor_css_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! get_option( 'ucpf_elementor_css_notice', false ) ) {
			return;
		}
		$dismiss = wp_nonce_url(
			add_query_arg( 'ucpf_dismiss_elementor_css', '1' ),
			'ucpf_dismiss_elementor_css'
		);
		echo '<div class="notice notice-warning is-dismissible"><p>';
		echo esc_html__(
			'UCPF cleared Elementor CSS cache after an update and queued a Cloudflare purge. Hard-refresh the front end once. If styles still show MIME text/html: enable Automatic Cloudflare purge (API token) under Advanced → Cloudflare, Purge Everything once, and keep an explicit Bypass (not only a Cache Everything exclusion) for /wp-content/uploads/elementor/css/ — see docs/CLOUDFLARE-CACHE.md.',
			'universal-consent-privacy-framework'
		);
		echo ' <a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Dismiss', 'universal-consent-privacy-framework' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * Dismiss Elementor CSS rebuild notice.
	 *
	 * @return void
	 */
	public function maybe_dismiss_elementor_css_notice() {
		if ( empty( $_GET['ucpf_dismiss_elementor_css'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'ucpf_dismiss_elementor_css' );
		delete_option( 'ucpf_elementor_css_notice' );
		wp_safe_redirect( remove_query_arg( array( 'ucpf_dismiss_elementor_css', '_wpnonce' ) ) );
		exit;
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'ucpf' ) ) {
			return;
		}

		$style_deps = array( 'ucpf-admin-tokens' );
		wp_enqueue_style(
			'ucpf-admin-tokens',
			UCPF_PLUGIN_URL . 'admin/css/admin-tokens.css',
			array(),
			ucpf_asset_version( 'admin/css/admin-tokens.css' )
		);

		if ( false !== strpos( $hook, 'ucpf-banner' ) ) {
			Theme_Manager::instance()->enqueue_admin_preview_styles();
			$style_deps[] = 'ucpf-banner';
		}

		wp_enqueue_style(
			'ucpf-admin',
			UCPF_PLUGIN_URL . 'admin/css/admin.css',
			$style_deps,
			ucpf_asset_version( 'admin/css/admin.css' )
		);

		wp_enqueue_script(
			'ucpf-admin',
			UCPF_PLUGIN_URL . 'admin/js/admin.js',
			array( 'jquery' ),
			ucpf_asset_version( 'admin/js/admin.js' ),
			true
		);

		wp_localize_script(
			'ucpf-admin',
			'ucpfAdmin',
			array(
				'restUrl'            => rest_url( 'ucpf/v1/' ),
				'nonce'              => wp_create_nonce( 'wp_rest' ),
				'homeUrl'            => untrailingslashit( home_url( '/' ) ),
				'maxCrawl'           => Cookie_Scanner::MAX_BROWSER_URLS,
				'maxServer'          => Cookie_Scanner::MAX_SERVER_URLS,
				'scannerApiUrl'      => Privacy_Scan_Importer::api_base(),
				'scannerConfigured'  => (bool) Privacy_Scan_Importer::api_base(),
				'advancedSettingsUrl'=> admin_url( 'admin.php?page=ucpf-advanced' ),
				'scannerPageUrl'     => admin_url( 'admin.php?page=ucpf-scanner' ),
				'contributeIssueUrl' => Cookie_Knowledge::contribute_issue_url(),
				'reverifyHint'       => Privacy_Scan_Importer::reverify_hint_from_last_scan(),
				'activeScan'         => Active_Scan::instance()->get_for_rest(),
			)
		);

		// React dashboard (built assets).
		$is_dashboard = ( false !== strpos( $hook, 'ucpf-dashboard' ) ) || ( false !== strpos( $hook, 'toplevel_page_ucpf' ) );
		$built_js     = UCPF_PLUGIN_DIR . 'admin/build/index.js';
		if ( $is_dashboard && is_readable( $built_js ) ) {
			$asset = UCPF_PLUGIN_DIR . 'admin/build/index.asset.php';
			$deps  = array();
			$ver   = ucpf_asset_version( 'admin/build/index.js' );
			if ( is_readable( $asset ) ) {
				$meta = include $asset;
				if ( is_array( $meta ) ) {
					// Ignore WP-script externals (react) — bundle uses --webpack-no-externals.
					$ver = isset( $meta['version'] ) ? $meta['version'] : $ver;
				}
			}
			wp_enqueue_script(
				'ucpf-admin-app',
				UCPF_PLUGIN_URL . 'admin/build/index.js',
				$deps,
				$ver,
				true
			);
			if ( is_readable( UCPF_PLUGIN_DIR . 'admin/build/index.css' ) ) {
				wp_enqueue_style(
					'ucpf-admin-app',
					UCPF_PLUGIN_URL . 'admin/build/index.css',
					array( 'ucpf-admin' ),
					$ver
				);
			}
			$settings   = Settings::all();
			$last_scan  = Cookie_Scanner::instance()->get_last_scan();
			$scan_date  = ! empty( $last_scan['date'] ) ? (string) $last_scan['date'] : '';
			$known_n    = ! empty( $last_scan['cookies'] ) && is_array( $last_scan['cookies'] ) ? count( $last_scan['cookies'] ) : 0;
			$unknown_n  = ! empty( $last_scan['unknown_cookies'] ) && is_array( $last_scan['unknown_cookies'] ) ? count( $last_scan['unknown_cookies'] ) : 0;
			wp_localize_script(
				'ucpf-admin-app',
				'ucpfDashboard',
				array(
					'productName'      => Brand::product_name(),
					'brandMarkUrl'     => UCPF_PLUGIN_URL . 'assets/branding/icon-128x128.png',
					'wizardCompleted'  => (bool) Settings::get( 'wizard_completed' ),
					'healthChecks'     => $this->get_health_checks(),
					'warnings'         => $this->get_warnings(),
					'scanSummary'      => array(
						'lastScanDate'   => $scan_date,
						'knownCookies'   => $known_n,
						'unknownCookies' => $unknown_n,
					),
					'settings'         => array(
						'compliance_mode'  => isset( $settings['compliance_mode'] ) ? $settings['compliance_mode'] : '',
						'policy_version'   => isset( $settings['policy_version'] ) ? $settings['policy_version'] : '',
						'banner_enabled'   => ! empty( $settings['banner_enabled'] ),
						'blocker_enabled'  => ! empty( $settings['blocker_enabled'] ),
					),
					'wpConsentApi'     => function_exists( 'wp_has_consent' ),
					'urls'             => array(
						'wizard'  => admin_url( 'admin.php?page=ucpf-wizard' ),
						'scanner' => admin_url( 'admin.php?page=ucpf-scanner' ),
						'banner'  => admin_url( 'admin.php?page=ucpf-banner' ),
						'pages'   => admin_url( 'admin.php?page=ucpf-pages' ),
						'advanced'=> admin_url( 'admin.php?page=ucpf-advanced' ),
					),
					'i18n'             => array(
						'title'         => __( 'Privacy Consent Dashboard', 'universal-consent-privacy-framework' ),
						'lede'          => __( 'Helps support privacy compliance. Final legal review is the site owner\'s responsibility. Local-first: no phone-home.', 'universal-consent-privacy-framework' ),
						'getStarted'    => __( 'Get started', 'universal-consent-privacy-framework' ),
						'getStartedBody'=> __( 'Run the setup wizard to scan cookies, choose services, generate policies, and enable the banner.', 'universal-consent-privacy-framework' ),
						'openWizard'    => __( 'Open Setup Wizard', 'universal-consent-privacy-framework' ),
						'reopenWizard'  => __( 'Re-open Setup Wizard', 'universal-consent-privacy-framework' ),
						'openScanner'   => __( 'Cookie Scanner', 'universal-consent-privacy-framework' ),
						'openBanner'    => __( 'Banner & Branding', 'universal-consent-privacy-framework' ),
						'openPages'     => __( 'Generated Pages', 'universal-consent-privacy-framework' ),
						'quickActions'  => __( 'Quick actions', 'universal-consent-privacy-framework' ),
						'healthTitle'   => __( 'Install health', 'universal-consent-privacy-framework' ),
						'healthLede'    => __( 'Quick checklist for deploys. Fix anything marked warn or fail before handoff.', 'universal-consent-privacy-framework' ),
						'statusReady'   => __( 'Ready', 'universal-consent-privacy-framework' ),
						'statusAttention' => __( 'Needs attention', 'universal-consent-privacy-framework' ),
						'statusBlocked' => __( 'Blocked', 'universal-consent-privacy-framework' ),
						/* translators: 1: passing count, 2: total checks */
						'passingOf'     => __( '%1$d of %2$d passing', 'universal-consent-privacy-framework' ),
						'chipOk'        => __( 'OK', 'universal-consent-privacy-framework' ),
						'chipWarn'      => __( 'Warn', 'universal-consent-privacy-framework' ),
						'chipFail'      => __( 'Fail', 'universal-consent-privacy-framework' ),
						'needsAttention'=> __( 'Needs attention', 'universal-consent-privacy-framework' ),
						'allPassing'    => __( 'All checks passing — good to hand off after a final legal review.', 'universal-consent-privacy-framework' ),
						'passingChecks' => __( 'Passing checks', 'universal-consent-privacy-framework' ),
						'fix'         => __( 'Fix', 'universal-consent-privacy-framework' ),
						'scanInventory' => __( 'Cookie inventory', 'universal-consent-privacy-framework' ),
						/* translators: 1: known count, 2: unknown count */
						'scanInventoryDetail' => __( '%1$d known · %2$d unknown', 'universal-consent-privacy-framework' ),
						'noScanYet'     => __( 'No scan yet', 'universal-consent-privacy-framework' ),
						'groupConsent'  => __( 'Consent UI', 'universal-consent-privacy-framework' ),
						'groupPages'    => __( 'Legal pages', 'universal-consent-privacy-framework' ),
						'groupScan'     => __( 'Scanning', 'universal-consent-privacy-framework' ),
						'groupSignals'  => __( 'Signals & jurisdiction', 'universal-consent-privacy-framework' ),
						'groupStack'    => __( 'Stack', 'universal-consent-privacy-framework' ),
						'compliance'    => __( 'Compliance mode', 'universal-consent-privacy-framework' ),
						'policy'        => __( 'Policy version', 'universal-consent-privacy-framework' ),
						'bannerBlocker' => __( 'Banner / blocker', 'universal-consent-privacy-framework' ),
						'wpConsent'     => __( 'WP Consent API', 'universal-consent-privacy-framework' ),
						'wpConsentYes'  => __( 'Compatible (shim active)', 'universal-consent-privacy-framework' ),
						'wpConsentShim' => __( 'Bundled shim only', 'universal-consent-privacy-framework' ),
						'bannerOn'      => __( 'Banner on', 'universal-consent-privacy-framework' ),
						'bannerOff'     => __( 'Banner off', 'universal-consent-privacy-framework' ),
						'blockerOn'     => __( 'Blocker on', 'universal-consent-privacy-framework' ),
						'blockerOff'    => __( 'Blocker off', 'universal-consent-privacy-framework' ),
					),
				)
			);
			wp_add_inline_script(
				'ucpf-admin-app',
				'document.getElementById("ucpf-admin-root")&&document.getElementById("ucpf-admin-root").removeAttribute("aria-busy");',
				'after'
			);
		}
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		register_setting( 'ucpf_settings_group', Settings::OPTION_KEY, array( $this, 'sanitize_settings' ) );
	}

	/**
	 * Sanitize settings on save.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		// Programmatic Settings::update() — accept the already-merged allowlisted row.
		if ( Settings::is_internal_update() ) {
			if ( ! is_array( $input ) ) {
				return Settings::raw();
			}
			$allowed = array_flip( array_keys( Settings::defaults() ) );
			$clean   = array_intersect_key( $input, $allowed );
			$clean   = wp_parse_args( $clean, Settings::defaults() );
			return Settings::prepare_for_storage( $clean );
		}

		if ( ! is_array( $input ) ) {
			return Settings::prepare_for_storage( Settings::all() );
		}

		$current_raw = Settings::raw();
		$current     = Settings::all();
		// Start from current settings only — never merge raw unsanitized input.
		$clean       = $current;

		$clean['banner_layout'] = isset( $input['banner_layout'] ) && in_array( $input['banner_layout'], array( 'bar', 'modal', 'corner' ), true )
			? $input['banner_layout']
			: ( isset( $current['banner_layout'] ) ? $current['banner_layout'] : 'bar' );

		$clean['banner_position'] = isset( $input['banner_position'] ) && in_array( $input['banner_position'], array( 'left', 'center', 'right' ), true )
			? $input['banner_position']
			: ( isset( $current['banner_position'] ) ? $current['banner_position'] : 'left' );

		if ( isset( $input['banner_theme'] ) ) {
			$theme = sanitize_key( $input['banner_theme'] );
			$known = Theme_Manager::instance()->get_preset_keys();
			if ( ! in_array( $theme, $known, true ) ) {
				$theme = 'classic';
			}
			$clean['banner_theme'] = $theme;
		}

		// Checkbox fields: only rewrite when the banner settings form posted them.
		$checkbox_keys = array( 'show_reject_all', 'show_accept_all', 'show_customize', 'floating_prefs_button' );
		$posted_banner = ! empty( $input['_ucpf_banner_form'] ) || isset( $input['banner_layout'] );
		if ( $posted_banner ) {
			foreach ( $checkbox_keys as $key ) {
				$clean[ $key ] = ! empty( $input[ $key ] );
			}
			// Powered-by attribution removed from banner UX.
			$clean['show_powered_by'] = false;
		}

		if ( isset( $input['service_ids'] ) || ! empty( $input['_ucpf_tracking_form'] ) ) {
			$clean['service_ids'] = Tracking_Templates::sanitize_service_ids(
				isset( $input['service_ids'] ) ? $input['service_ids'] : array(),
				isset( $current['service_ids'] ) ? $current['service_ids'] : array()
			);
		}

		if ( ! empty( $input['_ucpf_advanced_form'] ) ) {
			$clean['output_buffer_blocking']   = ! empty( $input['output_buffer_blocking'] );
			$clean['remote_registry_enabled']  = ! empty( $input['remote_registry_enabled'] );
			$clean['consent_logging']          = ! empty( $input['consent_logging'] );
			$clean['login_security_notice']    = ! empty( $input['login_security_notice'] );
			$clean['delete_data_on_uninstall'] = ! empty( $input['delete_data_on_uninstall'] );
			if ( isset( $input['site_profile'] ) ) {
				$profile = Site_Profiles::sanitize( $input['site_profile'] );
				if ( Site_Profiles::WOOCOMMERCE === $profile && ! Cookie_Scanner::instance()->is_woo_active() ) {
					$profile = Site_Profiles::BASIC;
				}
				$prev_profile = isset( $current['site_profile'] ) ? (string) $current['site_profile'] : '';
				$clean['site_profile'] = $profile;
				// Never call Settings::update / Site_Profiles::apply inside sanitize (recursion + OOM).
				if ( $profile !== $prev_profile ) {
					$pending_profile = $profile;
					add_action(
						'update_option_' . Settings::OPTION_KEY,
						static function () use ( $pending_profile ) {
							Site_Profiles::apply_scan_defaults( $pending_profile );
						},
						5,
						0
					);
				}
			}
			if ( isset( $input['remote_registry_url'] ) ) {
				$clean['remote_registry_url'] = esc_url_raw( $input['remote_registry_url'] );
			}
			if ( isset( $input['log_retention_days'] ) ) {
				$days = max( 1, min( 3650, (int) $input['log_retention_days'] ) );
				$clean['log_retention_days'] = $days;
				// Backfill expires_at so longer retention applies to existing light log rows.
				Audit_Log::instance()->recompute_expires( $days );
			}
			if ( isset( $input['scanner_api_url'] ) ) {
				$clean['scanner_api_url'] = Settings::normalize_url( (string) $input['scanner_api_url'] );
			}
			if ( array_key_exists( 'scanner_api_key', $input ) ) {
				$key_in = Settings::sanitize_secret( (string) $input['scanner_api_key'] );
				// Empty password field = keep existing key (do not wipe).
				if ( '' !== $key_in ) {
					$clean['scanner_api_key'] = $key_in;
				}
			}
			if ( isset( $input['registry_mode'] ) ) {
				$rm = sanitize_key( (string) $input['registry_mode'] );
				if ( is_multisite() && '' === $rm ) {
					$clean['registry_mode'] = '';
				} else {
					$clean['registry_mode'] = in_array( $rm, array( 'local', 'agency', 'community', 'disabled' ), true ) ? $rm : 'local';
				}
			}
			if ( isset( $input['privacy_api_url'] ) ) {
				$clean['privacy_api_url'] = Settings::normalize_url( (string) $input['privacy_api_url'] );
			}
			if ( array_key_exists( 'privacy_api_key', $input ) ) {
				$key_in = Settings::sanitize_secret( (string) $input['privacy_api_key'] );
				// Empty password field = keep existing key (do not wipe).
				if ( '' !== $key_in ) {
					$clean['privacy_api_key'] = $key_in;
				}
			}
			if ( isset( $input['privacy_controller_id'] ) ) {
				$clean['privacy_controller_id'] = sanitize_key( (string) $input['privacy_controller_id'] );
			}
			if ( isset( $input['gpc_enforcement'] ) ) {
				$ge = sanitize_key( (string) $input['gpc_enforcement'] );
				$clean['gpc_enforcement'] = in_array( $ge, array( 'sale_share', 'nonessential' ), true ) ? $ge : 'nonessential';
			}
			$clean['privacy_fail_closed'] = ! empty( $input['privacy_fail_closed'] );
			$clean['geo_jurisdiction_routing'] = ! empty( $input['geo_jurisdiction_routing'] );
			// Cloudflare sites keep geo routing on (cannot accidentally disable via save).
			if ( Jurisdiction::instance()->cloudflare_detected_for_geo() ) {
				$clean['geo_jurisdiction_routing'] = true;
			}
			$clean['output_buffer_safe_iframes'] = ! empty( $input['output_buffer_safe_iframes'] );
			$clean['scheduled_scan_enabled']    = ! empty( $input['scheduled_scan_enabled'] );
			$clean['scheduled_scan_auto_apply'] = ! empty( $input['scheduled_scan_auto_apply'] );
			$interval = isset( $input['scheduled_scan_interval'] ) ? sanitize_key( $input['scheduled_scan_interval'] ) : 'monthly';
			$clean['scheduled_scan_interval'] = in_array( $interval, array( 'weekly', 'monthly' ), true ) ? $interval : 'monthly';
			if ( isset( $input['scheduled_scan_paths'] ) ) {
				$clean['scheduled_scan_paths'] = sanitize_text_field( (string) $input['scheduled_scan_paths'] );
			}
			if ( isset( $input['scheduled_scan_notify_email'] ) ) {
				$emails = array_filter(
					array_map(
						'sanitize_email',
						array_map( 'trim', explode( ',', (string) $input['scheduled_scan_notify_email'] ) )
					)
				);
				$clean['scheduled_scan_notify_email'] = implode( ', ', $emails );
			}

			$clean['cloudflare_purge_enabled']        = ! empty( $input['cloudflare_purge_enabled'] );
			$clean['cloudflare_purge_on_updates']     = ! empty( $input['cloudflare_purge_on_updates'] );
			$clean['cloudflare_purge_on_ucpf_update'] = ! empty( $input['cloudflare_purge_on_ucpf_update'] );
			$clean['elementor_clear_css_on_updates']  = ! empty( $input['elementor_clear_css_on_updates'] );
			if ( isset( $input['cloudflare_domain'] ) ) {
				$clean['cloudflare_domain'] = Cloudflare_Cache::sanitize_domain( (string) $input['cloudflare_domain'] );
				// Domain change invalidates cached Zone ID until next resolve.
				if ( $clean['cloudflare_domain'] !== Cloudflare_Cache::sanitize_domain( (string) Settings::get( 'cloudflare_domain', '' ) ) ) {
					$clean['cloudflare_zone_id'] = '';
				}
			}
			if ( array_key_exists( 'cloudflare_api_token', $input ) ) {
				$token_in = Settings::sanitize_secret( (string) $input['cloudflare_api_token'] );
				// Empty password field = keep existing token (do not wipe).
				if ( '' !== $token_in ) {
					$clean['cloudflare_api_token'] = $token_in;
					$clean['cloudflare_zone_id']   = '';
				}
			}
		}

		if ( ! empty( $input['_ucpf_pages_form'] ) ) {
			$clean['auto_refresh_cookie_policy_after_scan'] = ! empty( $input['auto_refresh_cookie_policy_after_scan'] );
			$clean['data_request_page_url']                 = isset( $input['data_request_page_url'] ) ? esc_url_raw( (string) $input['data_request_page_url'] ) : '';
			$clean['do_not_sell_page_url']                  = isset( $input['do_not_sell_page_url'] ) ? esc_url_raw( (string) $input['do_not_sell_page_url'] ) : '';
		}

		if ( isset( $input['google_consent_mode'] ) ) {
			$mode = sanitize_key( $input['google_consent_mode'] );
			$clean['google_consent_mode'] = in_array( $mode, array( 'off', 'basic', 'advanced' ), true ) ? $mode : 'basic';
		}

		$allowed_modes = Jurisdiction::instance()->get_pack_ids();
		if ( isset( $input['compliance_mode'] ) ) {
			$mode = sanitize_key( (string) $input['compliance_mode'] );
			$clean['compliance_mode'] = in_array( $mode, $allowed_modes, true ) ? $mode : 'strict_gdpr';
		}

		if ( array_key_exists( 'contact_email', $input ) ) {
			$clean['contact_email'] = sanitize_email( (string) $input['contact_email'] );
		}
		if ( array_key_exists( 'business_name', $input ) ) {
			$clean['business_name'] = sanitize_text_field( (string) $input['business_name'] );
		}
		if ( array_key_exists( 'custom_css', $input ) ) {
			$clean['custom_css'] = Theme_Manager::instance()->sanitize_custom_css( $input['custom_css'] );
		}

		if ( array_key_exists( 'logo_url', $input ) ) {
			$clean['logo_url'] = esc_url_raw( (string) $input['logo_url'] );
		}

		if ( array_key_exists( 'accent_2_color', $input ) ) {
			$raw2 = is_string( $input['accent_2_color'] ) ? $input['accent_2_color'] : '';
			$hex2 = $raw2 ? sanitize_hex_color( $raw2 ) : '';
			$clean['accent_2_color'] = $hex2 ? $hex2 : '';
		}

		if ( array_key_exists( 'surface_color', $input ) ) {
			$raw_s = is_string( $input['surface_color'] ) ? $input['surface_color'] : '';
			$hex_s = $raw_s ? sanitize_hex_color( $raw_s ) : '';
			$clean['surface_color'] = $hex_s ? $hex_s : '';
		}

		if ( array_key_exists( 'accent_color', $input ) ) {
			$raw = is_string( $input['accent_color'] ) ? $input['accent_color'] : '';
			$hex = $raw ? sanitize_hex_color( $raw ) : '';
			$clean['accent_color'] = $hex ? $hex : '';
		}

		// Drop any non-setting keys that may have been present on prior merges / forms.
		$allowed_keys = array_keys( Settings::defaults() );
		$clean        = array_intersect_key( $clean, array_flip( $allowed_keys ) );

		$clean = wp_parse_args( $clean, Settings::defaults() );

		// Keep already-sealed secrets from the DB unless this form posted a new plaintext value.
		// Re-revealing then re-sealing every save changes ciphertext and fights update_option.
		foreach ( Secrets::KEYS as $secret_key ) {
			$posted_new = false;
			if ( array_key_exists( $secret_key, $input ) ) {
				$candidate = Settings::sanitize_secret( (string) $input[ $secret_key ] );
				if ( '' !== $candidate ) {
					$posted_new           = true;
					$clean[ $secret_key ] = $candidate;
				}
			}
			if ( ! $posted_new && array_key_exists( $secret_key, $current_raw ) ) {
				$clean[ $secret_key ] = $current_raw[ $secret_key ];
			}
		}

		// Seal API secrets before WordPress writes the option row.
		return Settings::prepare_for_storage( $clean );
	}

	/**
	 * Allow Gravity Forms-style shortcodes only (no scripts).
	 *
	 * @param mixed $raw Raw shortcode.
	 * @return string
	 */
	private function sanitize_form_shortcode( $raw ) {
		$raw = is_string( $raw ) ? trim( wp_unslash( $raw ) ) : '';
		if ( '' === $raw ) {
			return '';
		}
		// Strip tags; keep shortcode brackets and attributes.
		$raw = wp_strip_all_tags( $raw );
		if ( ! preg_match( '/^\[[a-zA-Z0-9_-]+(\s|\])/', $raw ) ) {
			return '';
		}
		return $raw;
	}

	/**
	 * Render view helper (wraps pages in shared shell chrome).
	 *
	 * @param string $view View name.
	 * @param array  $vars Variables.
	 */
	private function render( $view, array $vars = array() ) {
		$file = UCPF_PLUGIN_DIR . 'admin/views/' . $view . '.php';
		if ( ! is_readable( $file ) ) {
			wp_die( esc_html__( 'Admin view missing.', 'universal-consent-privacy-framework' ) );
		}

		$vars['settings'] = Settings::all();
		if ( ! is_array( $vars['settings'] ) ) {
			$vars['settings'] = Settings::defaults();
		}
		$vars['warnings'] = $this->get_warnings();

		$render = static function ( $ucpf_view_file, array $ucpf_view_vars ) {
			// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- scoped to closure only.
			extract( $ucpf_view_vars, EXTR_OVERWRITE );
			include $ucpf_view_file;
		};

		// Dashboard ships its own shell + React mount.
		if ( 'dashboard' === $view ) {
			$render( $file, $vars );
			return;
		}

		ob_start();
		$render( $file, $vars );
		$html = (string) ob_get_clean();

		$meta = $this->shell_meta_for_view( $view );
		$body = $html;
		if ( preg_match( '/<div class="wrap\s+ucpf-admin"[^>]*>(.*)<\/div>\s*\z/is', $html, $m ) ) {
			$body = $m[1];
			$body = preg_replace( '/<h1\b[^>]*>.*?<\/h1>/is', '', $body, 1 );
		}

		$ucpf_shell_current = $meta['current'];
		$ucpf_shell_title   = $meta['title'];
		$ucpf_shell_lede    = $meta['lede'];
		$ucpf_shell_body    = $body;
		include UCPF_PLUGIN_DIR . 'admin/views/partials/shell.php';
	}

	/**
	 * Shell title/nav key per view.
	 *
	 * @param string $view View.
	 * @return array{current:string,title:string,lede:string}
	 */
	private function shell_meta_for_view( $view ) {
		$map = array(
			'wizard'            => array(
				'current' => 'wizard',
				'title'   => __( 'Setup Wizard', 'universal-consent-privacy-framework' ),
				'lede'    => __( 'Scan, classify, generate policies, and go live.', 'universal-consent-privacy-framework' ),
			),
			'settings'          => array(
				'current' => 'banner',
				'title'   => __( 'Banner & Branding', 'universal-consent-privacy-framework' ),
				'lede'    => __( 'Theme, logo, colors, and consent banner layout.', 'universal-consent-privacy-framework' ),
			),
			'scripts'           => array(
				'current' => 'registry',
				'title'   => __( 'Script Registry', 'universal-consent-privacy-framework' ),
				'lede'    => __( 'Local catalog of known services and patterns.', 'universal-consent-privacy-framework' ),
			),
			'scanner'           => array(
				'current' => 'scanner',
				'title'   => __( 'Cookie Scanner', 'universal-consent-privacy-framework' ),
				'lede'    => __( 'Technical inventory and deep privacy scan import — not a legal determination.', 'universal-consent-privacy-framework' ),
			),
			'pages'             => array(
				'current' => 'pages',
				'title'   => __( 'Generated Pages', 'universal-consent-privacy-framework' ),
				'lede'    => __( 'Policy pages from templates, plus links to external rights request pages.', 'universal-consent-privacy-framework' ),
			),
			'logs'              => array(
				'current' => 'logs',
				'title'   => __( 'Consent Logs', 'universal-consent-privacy-framework' ),
				'lede'    => __( 'Privacy-minimized consent action history.', 'universal-consent-privacy-framework' ),
			),
			'integrations'      => array(
				'current' => 'integrations',
				'title'   => __( 'Integrations', 'universal-consent-privacy-framework' ),
				'lede'    => __( 'Connect analytics and marketing tags after consent.', 'universal-consent-privacy-framework' ),
			),
			'developer'         => array(
				'current' => 'developer',
				'title'   => __( 'Developer API', 'universal-consent-privacy-framework' ),
				'lede'    => __( 'Register services and import/export registry JSON.', 'universal-consent-privacy-framework' ),
			),
			'settings-advanced' => array(
				'current' => 'advanced',
				'title'   => __( 'Advanced Settings', 'universal-consent-privacy-framework' ),
				'lede'    => __( 'Scanner API, logging, and power-user options.', 'universal-consent-privacy-framework' ),
			),
		);
		if ( isset( $map[ $view ] ) ) {
			return $map[ $view ];
		}
		return array(
			'current' => 'dashboard',
			'title'   => Brand::product_name(),
			'lede'    => '',
		);
	}

	/**
	 * Dashboard warnings.
	 *
	 * @return array
	 */
	public function get_warnings() {
		$warnings = array();
		$settings = Settings::all();
		if ( ! is_array( $settings ) ) {
			return $warnings;
		}

		if ( empty( $settings['show_reject_all'] ) && ! empty( $settings['show_accept_all'] ) ) {
			$warnings[] = __( 'Reject All is disabled while Accept All is enabled. This may not meet strict GDPR expectations.', 'universal-consent-privacy-framework' );
		}

		$pages = isset( $settings['generated_pages'] ) && is_array( $settings['generated_pages'] ) ? $settings['generated_pages'] : array();
		if ( empty( $pages['privacy_policy'] ) ) {
			$warnings[] = __( 'Privacy policy page has not been generated.', 'universal-consent-privacy-framework' );
		}
		if ( empty( $pages['cookie_policy'] ) ) {
			$warnings[] = __( 'Cookie policy page has not been generated.', 'universal-consent-privacy-framework' );
		}

		if ( ! empty( $settings['output_buffer_blocking'] ) ) {
			$warnings[] = __( 'Output buffer blocking is enabled. This advanced mode may break some themes.', 'universal-consent-privacy-framework' );
		}

		if ( ! empty( $settings['remote_registry_enabled'] ) ) {
			$warnings[] = __( 'Remote registry sync is enabled. Ensure this is disclosed to users.', 'universal-consent-privacy-framework' );
		}

		$service_ids = isset( $settings['service_ids'] ) && is_array( $settings['service_ids'] ) ? $settings['service_ids'] : array();
		if ( class_exists( __NAMESPACE__ . '\\Tracking_Templates' ) ) {
			$templates = Tracking_Templates::all();
			foreach ( $templates as $key => $meta ) {
				if ( empty( $service_ids[ $key ] ) || ! is_array( $service_ids[ $key ] ) || empty( $service_ids[ $key ]['enabled'] ) ) {
					continue;
				}
				if ( empty( $service_ids[ $key ]['id'] ) && empty( $service_ids[ $key ]['code'] ) ) {
					$label = isset( $meta['label'] ) ? $meta['label'] : $key;
					$warnings[] = sprintf(
						/* translators: %s: service label */
						__( '%s is enabled but has no Measurement/Container/Pixel ID (or custom JS). Add it under Integrations.', 'universal-consent-privacy-framework' ),
						$label
					);
				}
			}
		}

		return $warnings;
	}

	/**
	 * Install health checks for the dashboard (client deploy checklist).
	 *
	 * @return array<int, array{id:string,label:string,status:string,detail:string,group:string,action_url?:string,action_label?:string}>
	 */
	public function get_health_checks() {
		$settings = Settings::all();
		$pages    = isset( $settings['generated_pages'] ) && is_array( $settings['generated_pages'] ) ? $settings['generated_pages'] : array();
		$scan     = Cookie_Scanner::instance()->get_last_scan();
		$checks   = array();

		$banner_on = ! empty( $settings['banner_enabled'] );
		$checks[]  = array(
			'id'           => 'banner',
			'group'        => 'consent',
			'label'        => __( 'Consent banner', 'universal-consent-privacy-framework' ),
			'status'       => $banner_on ? 'ok' : 'fail',
			'detail'       => $banner_on
				? __( 'Banner is enabled for visitors without consent.', 'universal-consent-privacy-framework' )
				: __( 'Banner is disabled — visitors will not see the consent UI.', 'universal-consent-privacy-framework' ),
			'action_url'   => admin_url( 'admin.php?page=ucpf-wizard' ),
			'action_label' => __( 'Open wizard', 'universal-consent-privacy-framework' ),
		);

		$blocker_on = ! empty( $settings['blocker_enabled'] );
		$checks[]   = array(
			'id'     => 'blocker',
			'group'  => 'consent',
			'label'  => __( 'Script blocker', 'universal-consent-privacy-framework' ),
			'status' => $blocker_on ? 'ok' : 'warn',
			'detail' => $blocker_on
				? __( 'Optional scripts stay blocked until consent.', 'universal-consent-privacy-framework' )
				: __( 'Blocker is off — optional tags may load before consent.', 'universal-consent-privacy-framework' ),
		);

		$cookie_id = ! empty( $pages['cookie_policy'] ) ? (int) $pages['cookie_policy'] : 0;
		$cookie_ok = $cookie_id && get_post( $cookie_id );
		$checks[]  = array(
			'id'           => 'cookie_policy',
			'group'        => 'pages',
			'label'        => __( 'Cookie Policy page', 'universal-consent-privacy-framework' ),
			'status'       => $cookie_ok ? 'ok' : 'fail',
			'detail'       => $cookie_ok
				? __( 'Generated Cookie Policy page exists.', 'universal-consent-privacy-framework' )
				: __( 'Cookie Policy page is missing. Generate it so the public cookie table can publish.', 'universal-consent-privacy-framework' ),
			'action_url'   => admin_url( 'admin.php?page=ucpf-pages' ),
			'action_label' => __( 'Generated Pages', 'universal-consent-privacy-framework' ),
		);

		$privacy_id = ! empty( $pages['privacy_policy'] ) ? (int) $pages['privacy_policy'] : 0;
		$privacy_ok = $privacy_id && get_post( $privacy_id );
		$checks[]   = array(
			'id'           => 'privacy_policy',
			'group'        => 'pages',
			'label'        => __( 'Privacy Policy page', 'universal-consent-privacy-framework' ),
			'status'       => $privacy_ok ? 'ok' : 'warn',
			'detail'       => $privacy_ok
				? __( 'Generated Privacy Policy page exists.', 'universal-consent-privacy-framework' )
				: __( 'Privacy Policy page has not been generated yet.', 'universal-consent-privacy-framework' ),
			'action_url'   => admin_url( 'admin.php?page=ucpf-pages' ),
			'action_label' => __( 'Generated Pages', 'universal-consent-privacy-framework' ),
		);

		$scan_date = ! empty( $scan['date'] ) ? (string) $scan['date'] : '';
		$cookie_n  = ! empty( $scan['cookies'] ) && is_array( $scan['cookies'] ) ? count( $scan['cookies'] ) : 0;
		$unknown_n = ! empty( $scan['unknown_cookies'] ) && is_array( $scan['unknown_cookies'] ) ? count( $scan['unknown_cookies'] ) : 0;

		if ( '' === $scan_date ) {
			$checks[] = array(
				'id'           => 'last_scan',
				'group'        => 'scan',
				'label'        => __( 'Cookie scan', 'universal-consent-privacy-framework' ),
				'status'       => 'fail',
				'detail'       => __( 'No scan stored yet. Run Cookie Scanner so the policy table has inventory.', 'universal-consent-privacy-framework' ),
				'action_url'   => admin_url( 'admin.php?page=ucpf-scanner' ),
				'action_label' => __( 'Run scan', 'universal-consent-privacy-framework' ),
			);
		} else {
			$ts    = strtotime( $scan_date );
			$age_d = ( false !== $ts ) ? max( 0, (int) floor( ( time() - $ts ) / DAY_IN_SECONDS ) ) : 0;
			if ( $age_d > 90 ) {
				$status = 'warn';
				$detail = sprintf(
					/* translators: 1: scan datetime, 2: days */
					__( 'Last scan %1$s (%2$d days ago). Consider re-scanning after site or plugin changes.', 'universal-consent-privacy-framework' ),
					$scan_date,
					$age_d
				);
			} else {
				$status = 'ok';
				$detail = sprintf(
					/* translators: 1: scan datetime, 2: known cookie count, 3: unknown count */
					__( 'Last scan %1$s — %2$d known cookie(s), %3$d unknown.', 'universal-consent-privacy-framework' ),
					$scan_date,
					$cookie_n,
					$unknown_n
				);
			}
			$checks[] = array(
				'id'           => 'last_scan',
				'group'        => 'scan',
				'label'        => __( 'Cookie scan', 'universal-consent-privacy-framework' ),
				'status'       => $status,
				'detail'       => $detail,
				'action_url'   => admin_url( 'admin.php?page=ucpf-scanner' ),
				'action_label' => __( 'Scanner', 'universal-consent-privacy-framework' ),
			);
		}

		if ( $unknown_n > 0 ) {
			$checks[] = array(
				'id'           => 'unknown_cookies',
				'group'        => 'scan',
				'label'        => __( 'Unknown cookies (coverage gap)', 'universal-consent-privacy-framework' ),
				'status'       => 'fail',
				'detail'       => sprintf(
					/* translators: %d: count */
					_n( '%d unknown cookie still needs a category before go-live coverage is complete.', '%d unknown cookies still need categories before go-live coverage is complete.', $unknown_n, 'universal-consent-privacy-framework' ),
					$unknown_n
				),
				'action_url'   => admin_url( 'admin.php?page=ucpf-scanner#ucpf-cookie-review' ),
				'action_label' => __( 'Review on Scanner', 'universal-consent-privacy-framework' ),
			);
		}

		$sus_n = 0;
		if ( ! empty( $scan['suspicious_scripts'] ) && is_array( $scan['suspicious_scripts'] ) ) {
			$ignored = Suspicion::get_ignored_patterns();
			foreach ( $scan['suspicious_scripts'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$pat = isset( $row['pattern'] ) ? strtolower( (string) $row['pattern'] ) : '';
				if ( $pat && in_array( $pat, $ignored, true ) ) {
					continue;
				}
				++$sus_n;
			}
		}
		if ( $sus_n > 0 ) {
			$checks[] = array(
				'id'           => 'suspicious_scripts',
				'group'        => 'scan',
				'label'        => __( 'Suspicious scripts', 'universal-consent-privacy-framework' ),
				'status'       => 'fail',
				'detail'       => sprintf(
					/* translators: %d: count */
					_n( '%d tracking-like script needs Apply override or Ignore.', '%d tracking-like scripts need Apply override or Ignore.', $sus_n, 'universal-consent-privacy-framework' ),
					$sus_n
				),
				'action_url'   => admin_url( 'admin.php?page=ucpf-scanner#ucpf-suspicious-scripts' ),
				'action_label' => __( 'Review scripts', 'universal-consent-privacy-framework' ),
			);
		}

		if ( $blocker_on && ( $unknown_n > 0 || $sus_n > 0 ) ) {
			$checks[] = array(
				'id'           => 'blocker_coverage',
				'group'        => 'consent',
				'label'        => __( 'Blocker vs coverage', 'universal-consent-privacy-framework' ),
				'status'       => 'warn',
				'detail'       => __( 'Script blocker is on, but Cookie Review still has unclassified cookies or unresolved suspicious scripts. Finish reviewing so coverage matches the inventory.', 'universal-consent-privacy-framework' ),
				'action_url'   => admin_url( 'admin.php?page=ucpf-scanner#ucpf-cookie-review' ),
				'action_label' => __( 'Open Cookie Review', 'universal-consent-privacy-framework' ),
			);
		}

		$auto     = ! empty( $settings['auto_refresh_cookie_policy_after_scan'] );
		$checks[] = array(
			'id'           => 'auto_refresh',
			'group'        => 'scan',
			'label'        => __( 'Cookie Policy auto-refresh', 'universal-consent-privacy-framework' ),
			'status'       => $auto ? 'ok' : 'warn',
			'detail'       => $auto
				? __( 'Scans create/overwrite the Cookie Policy page so inventory stays current.', 'universal-consent-privacy-framework' )
				: __( 'Auto-refresh is off. Refresh the Cookie Policy manually after scans.', 'universal-consent-privacy-framework' ),
			'action_url'   => admin_url( 'admin.php?page=ucpf-pages' ),
			'action_label' => __( 'Generated Pages', 'universal-consent-privacy-framework' ),
		);

		$wp_consent = function_exists( 'wp_has_consent' );
		$checks[]   = array(
			'id'     => 'wp_consent_api',
			'group'  => 'stack',
			'label'  => __( 'WP Consent API', 'universal-consent-privacy-framework' ),
			'status' => 'ok',
			'detail' => $wp_consent
				? __( 'Official WP Consent API detected; UCPF syncs categories to it.', 'universal-consent-privacy-framework' )
				: __( 'Bundled shim active (no separate WP Consent API plugin required).', 'universal-consent-privacy-framework' ),
		);

		$remote   = ! empty( $settings['remote_registry_enabled'] );
		$checks[] = array(
			'id'     => 'remote_registry',
			'group'  => 'stack',
			'label'  => __( 'Remote registry', 'universal-consent-privacy-framework' ),
			'status' => $remote ? 'warn' : 'ok',
			'detail' => $remote
				? __( 'Remote registry sync is ON. Internal default is off (local catalog only).', 'universal-consent-privacy-framework' )
				: __( 'Off — local vendor catalog only (recommended).', 'universal-consent-privacy-framework' ),
		);

		$theme    = isset( $settings['banner_theme'] ) ? (string) $settings['banner_theme'] : 'classic';
		$checks[] = array(
			'id'     => 'theme',
			'group'  => 'consent',
			'label'  => __( 'Banner theme', 'universal-consent-privacy-framework' ),
			'status' => 'ok',
			'detail' => sprintf(
				/* translators: %s: theme key */
				__( 'Active preset: %s', 'universal-consent-privacy-framework' ),
				$theme
			),
		);

		$pack    = Jurisdiction::instance()->resolve();
		$pack_id = isset( $pack['id'] ) ? (string) $pack['id'] : 'strict_gdpr';
		$checks[] = array(
			'id'           => 'jurisdiction',
			'group'        => 'signals',
			'label'        => __( 'Jurisdiction pack', 'universal-consent-privacy-framework' ),
			'status'       => 'ok',
			'detail'       => sprintf(
				/* translators: 1: pack id, 2: consent model */
				__( 'Active pack: %1$s (%2$s). Not a compliance certification.', 'universal-consent-privacy-framework' ),
				$pack_id,
				isset( $pack['consent_model'] ) ? $pack['consent_model'] : 'optin'
			),
			'action_url'   => admin_url( 'admin.php?page=ucpf-advanced' ),
			'action_label' => __( 'Advanced', 'universal-consent-privacy-framework' ),
		);

		$geo_on = Jurisdiction::instance()->geo_routing_enabled();
		$cf_ok  = Jurisdiction::instance()->cloudflare_detected_for_geo();
		if ( $geo_on && $cf_ok ) {
			$checks[] = array(
				'id'     => 'geo_routing',
				'group'  => 'signals',
				'label'  => __( 'Geo pack routing', 'universal-consent-privacy-framework' ),
				'status' => 'ok',
				'detail' => __( 'On (Cloudflare detected) — US → us_baseline; EEA/UK/CH → strict_gdpr; unknown → strict_gdpr.', 'universal-consent-privacy-framework' ),
			);
		} elseif ( $geo_on && ! $cf_ok ) {
			$checks[] = array(
				'id'           => 'geo_routing',
				'group'        => 'signals',
				'label'        => __( 'Geo pack routing', 'universal-consent-privacy-framework' ),
				'status'       => 'warn',
				'detail'       => __( 'On, but Cloudflare was not detected yet — visitors may fail closed to strict GDPR until CF-IPCountry is present.', 'universal-consent-privacy-framework' ),
				'action_url'   => admin_url( 'admin.php?page=ucpf-advanced' ),
				'action_label' => __( 'Advanced', 'universal-consent-privacy-framework' ),
			);
		} else {
			$checks[] = array(
				'id'           => 'geo_routing',
				'group'        => 'signals',
				'label'        => __( 'Geo pack routing', 'universal-consent-privacy-framework' ),
				'status'       => 'warn',
				'detail'       => __( 'Off — every visitor uses the default pack. Put the site behind Cloudflare (auto-enables) or turn geo routing on under Advanced.', 'universal-consent-privacy-framework' ),
				'action_url'   => admin_url( 'admin.php?page=ucpf-advanced' ),
				'action_label' => __( 'Advanced', 'universal-consent-privacy-framework' ),
			);
		}

		$gpc_on   = ! empty( $settings['respect_dnt_gpc'] );
		$checks[] = array(
			'id'     => 'gpc',
			'group'  => 'signals',
			'label'  => __( 'GPC / Do Not Track signals', 'universal-consent-privacy-framework' ),
			'status' => $gpc_on ? 'ok' : 'warn',
			'detail' => $gpc_on
				? __( 'GPC signals are respected and enforced via Privacy State.', 'universal-consent-privacy-framework' )
				: __( 'GPC respect is off — enable for California / US packs.', 'universal-consent-privacy-framework' ),
		);

		$reject_on = ! empty( $settings['show_reject_all'] );
		$checks[]  = array(
			'id'     => 'reject_parity',
			'group'  => 'consent',
			'label'  => __( 'Reject All visible', 'universal-consent-privacy-framework' ),
			'status' => $reject_on ? 'ok' : 'fail',
			'detail' => $reject_on
				? __( 'Reject All is shown (equal choice with Accept All).', 'universal-consent-privacy-framework' )
				: __( 'Reject All is hidden — restore for GDPR-style fairness.', 'universal-consent-privacy-framework' ),
		);

		$gate_file = UCPF_PLUGIN_DIR . 'public/js/network-gate.js';
		$checks[]  = array(
			'id'     => 'network_gate',
			'group'  => 'stack',
			'label'  => __( 'Analytics network gate', 'universal-consent-privacy-framework' ),
			'status' => is_readable( $gate_file ) ? 'ok' : 'fail',
			'detail' => is_readable( $gate_file )
				? __( 'Early network gate present (blocks analytics/marketing/functional/security until consent).', 'universal-consent-privacy-framework' )
				: __( 'Network gate file missing.', 'universal-consent-privacy-framework' ),
		);

		$dns_url  = Page_Generator::instance()->get_rights_url( 'do_not_sell' );
		$dns_ok   = ( '' !== $dns_url );
		$need_dns = ! empty( $pack['dns_required'] );
		$checks[] = array(
			'id'           => 'do_not_sell',
			'group'        => 'pages',
			'label'        => __( 'Do Not Sell / privacy choices page', 'universal-consent-privacy-framework' ),
			'status'       => ( $dns_ok || ! $need_dns ) ? ( $dns_ok ? 'ok' : 'warn' ) : 'warn',
			'detail'       => $dns_ok
				? __( 'Do Not Sell / privacy choices URL is configured.', 'universal-consent-privacy-framework' )
				: __( 'Set a Do Not Sell page URL under Generated Pages (home-site page + form shortcode).', 'universal-consent-privacy-framework' ),
			'action_url'   => admin_url( 'admin.php?page=ucpf-pages' ),
			'action_label' => __( 'Generated Pages', 'universal-consent-privacy-framework' ),
		);

		return $checks;
	}

	/**
	 * Dashboard page.
	 */
	public function render_dashboard() {
		$this->render(
			'dashboard',
			array(
				'wizard_step'      => (int) Settings::get( 'wizard_step' ),
				'wizard_completed' => (bool) Settings::get( 'wizard_completed' ),
				'health_checks'    => $this->get_health_checks(),
			)
		);
	}

	/**
	 * Setup wizard.
	 */
	public function render_wizard() {
		$wizard_max = 11;
		$step       = max( 1, (int) Settings::get( 'wizard_step' ) );

		// Honor redirect hint after Save and Continue (defense if option write races).
		if ( isset( $_GET['ucpf_wstep'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display sync only.
			$hint = max( 1, min( $wizard_max, (int) wp_unslash( $_GET['ucpf_wstep'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( $hint !== $step && current_user_can( 'manage_options' ) ) {
				// Prefer stored step when it already advanced; otherwise adopt hint.
				if ( $hint > $step ) {
					Settings::update( array( 'wizard_step' => $hint ) );
					$step = $hint;
				}
			}
		}

		// One-time: Scanner API step inserted before Website Scan (old steps 5–10 → 6–11).
		if ( ! get_option( 'ucpf_wizard_api_step_v1', false ) ) {
			if ( $step >= 5 && $step <= 10 && ! Settings::get( 'wizard_completed' ) ) {
				$step = min( $wizard_max, $step + 1 );
				Settings::update( array( 'wizard_step' => $step ) );
			} elseif ( $step > $wizard_max ) {
				$step = $wizard_max;
				Settings::update( array( 'wizard_step' => $step ) );
			}
			update_option( 'ucpf_wizard_api_step_v1', 1, false );
		}

		$this->render(
			'wizard',
			array(
				'wizard_step' => max( 1, min( $wizard_max, $step ) ),
				'last_scan'   => Cookie_Scanner::instance()->get_last_scan(),
				'services'    => Script_Registry::instance()->get_services(),
			)
		);
	}

	/**
	 * Banner settings.
	 */
	public function render_banner_settings() {
		$this->render( 'settings', array(
			'presets' => Theme_Manager::instance()->get_preset_options(),
		) );
	}

	/**
	 * Script registry.
	 */
	public function render_registry() {
		$this->render( 'scripts', array(
			'services' => Script_Registry::instance()->get_services(),
		) );
	}

	/**
	 * Scanner page.
	 */
	public function render_scanner() {
		$this->render( 'scanner', array(
			'last_scan'   => Cookie_Scanner::instance()->get_last_scan(),
			'services'    => Script_Registry::instance()->get_services(),
			'active_scan' => Active_Scan::instance()->get_for_rest(),
		) );
	}

	/**
	 * Generated pages.
	 */
	public function render_pages() {
		$this->render( 'pages' );
	}

	/**
	 * Consent logs.
	 */
	public function render_logs() {
		// Prefer WP admin `paged`; also accept `page_num` for bookmarks.
		$page = 1;
		if ( isset( $_GET['paged'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list nav.
			$page = max( 1, (int) wp_unslash( $_GET['paged'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		} elseif ( isset( $_GET['page_num'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$page = max( 1, (int) wp_unslash( $_GET['page_num'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		$args = $this->parse_log_list_args_from_request();
		$logs = Audit_Log::instance()->get_logs( $page, 50, $args );
		$this->render(
			'logs',
			array(
				'logs'           => $logs,
				'retention_days' => max( 1, (int) Settings::get( 'log_retention_days', 360 ) ),
			)
		);
	}

	/**
	 * Parse consent log filters from GET/POST (admin list + CSV export).
	 *
	 * @return array<string,string>
	 */
	private function parse_log_list_args_from_request() {
		$view = 'by_day';
		$uuid = '';
		$action = '';
		$date_from = '';
		$date_to = '';

		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- list filters are read-only; export checks nonce in handle_export_logs.
		if ( isset( $_REQUEST['view'] ) ) {
			$view = sanitize_key( wp_unslash( (string) $_REQUEST['view'] ) );
		}
		if ( isset( $_REQUEST['uuid'] ) ) {
			$uuid = sanitize_text_field( wp_unslash( (string) $_REQUEST['uuid'] ) );
		}
		if ( isset( $_REQUEST['log_action'] ) ) {
			$action = sanitize_key( wp_unslash( (string) $_REQUEST['log_action'] ) );
		} elseif ( isset( $_REQUEST['filter_action'] ) ) {
			$action = sanitize_key( wp_unslash( (string) $_REQUEST['filter_action'] ) );
		}
		if ( isset( $_REQUEST['date_from'] ) ) {
			$date_from = sanitize_text_field( wp_unslash( (string) $_REQUEST['date_from'] ) );
		}
		if ( isset( $_REQUEST['date_to'] ) ) {
			$date_to = sanitize_text_field( wp_unslash( (string) $_REQUEST['date_to'] ) );
		}
		// phpcs:enable

		return array(
			'view'      => $view,
			'uuid'      => $uuid,
			'action'    => $action,
			'date_from' => $date_from,
			'date_to'   => $date_to,
		);
	}

	/**
	 * Integrations page.
	 */
	public function render_integrations() {
		$this->render( 'integrations' );
	}

	/**
	 * Developer API page.
	 */
	public function render_developer() {
		$this->render( 'developer' );
	}

	/**
	 * Advanced settings.
	 */
	public function render_advanced() {
		$this->render( 'settings-advanced' );
	}

	/**
	 * Handle wizard form.
	 */
	public function handle_wizard_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'universal-consent-privacy-framework' ) );
		}

		check_admin_referer( 'ucpf_wizard' );

		$step      = isset( $_POST['wizard_step'] ) ? (int) $_POST['wizard_step'] : 1;
		$direction = isset( $_POST['wizard_direction'] ) ? sanitize_key( wp_unslash( $_POST['wizard_direction'] ) ) : 'stay';
		$goto      = isset( $_POST['wizard_goto'] ) ? (int) $_POST['wizard_goto'] : 0;

		$update = array();

		$text_fields = array( 'business_name', 'business_address', 'business_country', 'business_phone', 'banner_layout', 'banner_position', 'banner_theme' );
		foreach ( $text_fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$update[ $field ] = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
			}
		}
		if ( isset( $_POST['compliance_mode'] ) ) {
			$mode = sanitize_key( wp_unslash( $_POST['compliance_mode'] ) );
			$allowed = Jurisdiction::instance()->get_pack_ids();
			$update['compliance_mode'] = in_array( $mode, $allowed, true ) ? $mode : 'strict_gdpr';
		}

		if ( isset( $_POST['site_profile'] ) ) {
			$profile = Site_Profiles::sanitize( sanitize_key( wp_unslash( $_POST['site_profile'] ) ) );
			if ( Site_Profiles::WOOCOMMERCE === $profile && ! Cookie_Scanner::instance()->is_woo_active() ) {
				$profile = Site_Profiles::BASIC;
			}
			$update['site_profile'] = $profile;
			Site_Profiles::apply( $profile );
		}

		if ( isset( $_POST['selected_statistics'] ) && is_array( $_POST['selected_statistics'] ) ) {
			$update['selected_statistics'] = array_values( array_filter( array_map( 'sanitize_key', wp_unslash( $_POST['selected_statistics'] ) ) ) );
		} elseif ( 7 === $step ) {
			$update['selected_statistics'] = array();
		}

		if ( isset( $_POST['contact_email'] ) ) {
			$update['contact_email'] = sanitize_email( wp_unslash( $_POST['contact_email'] ) );
		}

		// Scanner API (wizard step before Website Scan) — same keys as Advanced Settings.
		if ( isset( $_POST['scanner_api_url'] ) ) {
			$update['scanner_api_url'] = Settings::normalize_url( sanitize_text_field( wp_unslash( $_POST['scanner_api_url'] ) ) );
		}
		if ( array_key_exists( 'scanner_api_key', $_POST ) ) {
			$key_in = Settings::sanitize_secret( sanitize_text_field( wp_unslash( $_POST['scanner_api_key'] ) ) );
			// Empty password field = keep existing key (do not wipe).
			if ( '' !== $key_in ) {
				$update['scanner_api_key'] = $key_in;
			}
		}

		// Cloudflare purge (wizard Visitors step — optional) — same keys as Advanced.
		if ( 1 === $step || isset( $_POST['cloudflare_domain'] ) || isset( $_POST['cloudflare_purge_enabled'] ) ) {
			if ( 1 === $step ) {
				$update['cloudflare_purge_enabled']        = ! empty( $_POST['cloudflare_purge_enabled'] );
				$update['cloudflare_purge_on_updates']     = ! empty( $_POST['cloudflare_purge_on_updates'] );
				$update['cloudflare_purge_on_ucpf_update'] = ! empty( $_POST['cloudflare_purge_on_ucpf_update'] );
			}
			if ( isset( $_POST['cloudflare_domain'] ) ) {
				$domain = Cloudflare_Cache::sanitize_domain( sanitize_text_field( wp_unslash( $_POST['cloudflare_domain'] ) ) );
				$update['cloudflare_domain'] = $domain;
				if ( $domain !== Cloudflare_Cache::sanitize_domain( (string) Settings::get( 'cloudflare_domain', '' ) ) ) {
					$update['cloudflare_zone_id'] = '';
				}
			}
			if ( array_key_exists( 'cloudflare_api_token', $_POST ) ) {
				$token_in = Settings::sanitize_secret( sanitize_text_field( wp_unslash( $_POST['cloudflare_api_token'] ) ) );
				if ( '' !== $token_in ) {
					$update['cloudflare_api_token'] = $token_in;
					$update['cloudflare_zone_id']   = '';
				}
			}
		}

		if ( isset( $_POST['data_request_page_url'] ) ) {
			$update['data_request_page_url'] = esc_url_raw( wp_unslash( $_POST['data_request_page_url'] ) );
		}
		if ( isset( $_POST['do_not_sell_page_url'] ) ) {
			$update['do_not_sell_page_url'] = esc_url_raw( wp_unslash( $_POST['do_not_sell_page_url'] ) );
		}

		$coverage_blocked = 0;
		$bool_fields = array( 'consent_logging', 'respect_dnt_gpc', 'banner_enabled', 'blocker_enabled' );
		foreach ( $bool_fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$update[ $field ] = (bool) (int) $_POST[ $field ];
			}
		}

		// Coverage gate: do not enable blocker while unclassified cookies remain.
		if ( ! empty( $update['blocker_enabled'] ) ) {
			$scan_chk = Cookie_Scanner::instance()->get_last_scan();
			$unk      = ( is_array( $scan_chk ) && ! empty( $scan_chk['unknown_cookies'] ) && is_array( $scan_chk['unknown_cookies'] ) )
				? count( $scan_chk['unknown_cookies'] )
				: 0;
			if ( $unk > 0 ) {
				$update['blocker_enabled'] = false;
				$coverage_blocked          = $unk;
			}
		}

		if ( isset( $_POST['document_sources'] ) && is_array( $_POST['document_sources'] ) ) {
			$update['document_sources'] = $this->sanitize_document_sources(
				map_deep( wp_unslash( $_POST['document_sources'] ), 'sanitize_text_field' )
			);
		}

		if ( isset( $_POST['selected_services'] ) && is_array( $_POST['selected_services'] ) ) {
			$update['selected_services'] = array_map( 'sanitize_key', wp_unslash( $_POST['selected_services'] ) );
		} elseif ( 8 === $step ) {
			$update['selected_services'] = array();
		}

		if ( ! empty( $_POST['service_overrides'] ) && is_array( $_POST['service_overrides'] ) ) {
			$update['service_overrides'] = $this->sanitize_service_overrides(
				map_deep( wp_unslash( $_POST['service_overrides'] ), 'sanitize_text_field' )
			);
		}

		if ( ! empty( $_POST['unknown_cookies'] ) && is_array( $_POST['unknown_cookies'] ) ) {
			$this->apply_unknown_cookie_reviews(
				map_deep( wp_unslash( $_POST['unknown_cookies'] ), 'sanitize_text_field' )
			);
			Cookie_Scanner::refresh_policy_pages_after_review();
		}

		$service_ids = Settings::get( 'service_ids', array() );
		if ( ! is_array( $service_ids ) ) {
			$service_ids = array();
		}
		$service_ids_changed = false;

		// Statistics step: enable each checked analytics tool + IDs (multi-select).
		if ( 7 === $step ) {
			$templates = Tracking_Templates::all();
			$selected  = isset( $update['selected_statistics'] ) && is_array( $update['selected_statistics'] )
				? $update['selected_statistics']
				: array();
			$partial   = array();
			foreach ( $templates as $key => $meta ) {
				if ( 'analytics' !== $meta['category'] ) {
					continue;
				}
				$partial[ $key ] = array(
					'enabled' => in_array( $key, $selected, true ),
				);
			}
			$service_ids         = Tracking_Templates::merge_service_ids( $partial, $service_ids );
			$service_ids_changed = true;
		}

		// Merge any posted ID fields (wizard Statistics / Services steps).
		if ( ! empty( $_POST['service_ids'] ) && is_array( $_POST['service_ids'] ) ) {
			$service_ids         = Tracking_Templates::merge_service_ids(
				map_deep( wp_unslash( $_POST['service_ids'] ), 'sanitize_text_field' ),
				$service_ids
			);
			$service_ids_changed = true;
		}

		// Services step: sync marketing tags only — analytics stay owned by Statistics.
		if ( 8 === $step && isset( $update['selected_services'] ) && is_array( $update['selected_services'] ) ) {
			$templates = Tracking_Templates::all();
			$partial   = array();
			$selected  = array_map( 'sanitize_key', $update['selected_services'] );
			foreach ( $templates as $key => $meta ) {
				if ( 'analytics' === $meta['category'] ) {
					continue;
				}
				$partial[ $key ] = array(
					'enabled' => in_array( $key, $selected, true ),
				);
			}
			$service_ids         = Tracking_Templates::merge_service_ids( $partial, $service_ids );
			$service_ids_changed = true;
		} elseif ( isset( $update['selected_services'] ) && is_array( $update['selected_services'] ) ) {
			$templates = Tracking_Templates::all();
			$partial   = array();
			foreach ( $update['selected_services'] as $key ) {
				if ( isset( $templates[ $key ] ) && 'analytics' !== $templates[ $key ]['category'] ) {
					$partial[ $key ] = array( 'enabled' => true );
				}
			}
			if ( $partial ) {
				$service_ids         = Tracking_Templates::merge_service_ids( $partial, $service_ids );
				$service_ids_changed = true;
			}
		}

		if ( $service_ids_changed ) {
			$update['service_ids'] = $service_ids;
		}

		$wizard_max = 11;
		if ( $goto >= 1 && $goto <= $wizard_max && ( $goto <= $step || Settings::get( 'wizard_completed' ) ) ) {
			$update['wizard_step'] = $goto;
		} elseif ( 'prev' === $direction ) {
			$update['wizard_step'] = max( 1, $step - 1 );
		} elseif ( 'finish' === $direction ) {
			$update['wizard_step']      = $wizard_max;
			$update['wizard_completed'] = true;
		} elseif ( 'next' === $direction ) {
			$update['wizard_step'] = min( $wizard_max, $step + 1 );
			if ( (int) $update['wizard_step'] >= $wizard_max ) {
				$update['wizard_completed'] = true;
			}
		} else {
			// Default: stay. If direction missing (some browsers drop submit name), advance when Continue intent is clear.
			$update['wizard_step'] = $step;
		}

		// Always persist step first so a later failure cannot strand the visitor on step 1.
		if ( isset( $update['wizard_step'] ) ) {
			$next_step = max( 1, min( $wizard_max, (int) $update['wizard_step'] ) );
			$update['wizard_step'] = $next_step;
		}

		Settings::update( $update );

		// Force-write step if update_option short-circuited as "unchanged".
		if ( isset( $update['wizard_step'] ) && (int) Settings::get( 'wizard_step' ) !== (int) $update['wizard_step'] ) {
			$all                = wp_parse_args( Settings::raw(), Settings::defaults() );
			$all['wizard_step'] = (int) $update['wizard_step'];
			if ( ! empty( $update['wizard_completed'] ) ) {
				$all['wizard_completed'] = true;
			}
			$all = array_intersect_key( $all, array_flip( array_keys( Settings::defaults() ) ) );
			update_option( Settings::OPTION_KEY, Settings::prepare_for_storage( $all ), null );
		}

		$redirect_step = isset( $update['wizard_step'] ) ? (int) $update['wizard_step'] : $step;
		$redirect      = add_query_arg(
			array(
				'page'       => 'ucpf-wizard',
				'ucpf_wstep' => $redirect_step,
				'ucpf_saved' => '1',
			),
			admin_url( 'admin.php' )
		);
		if ( ! empty( $coverage_blocked ) ) {
			$redirect = add_query_arg( 'ucpf_coverage_blocked', (int) $coverage_blocked, $redirect );
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Export logs CSV.
	 */
	public function handle_export_logs() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'universal-consent-privacy-framework' ) );
		}

		check_admin_referer( 'ucpf_export_logs' );

		$args          = $this->parse_log_list_args_from_request();
		$args['view']  = 'events';
		$csv           = Audit_Log::instance()->export_csv( $args );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=ucpf-consent-logs.csv' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download body; cells are escaped in Audit_Log::export_csv().
		echo $csv;
		exit;
	}

	/**
	 * Manual Cloudflare edge purge (admin-post).
	 *
	 * @return void
	 */
	public function handle_purge_cloudflare() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'universal-consent-privacy-framework' ) );
		}
		check_admin_referer( 'ucpf_purge_cloudflare' );

		// Manual: run immediately (still respects 10-minute API lock).
		$result = Cloudflare_Cache::instance()->purge_edge( 'manual' );
		$redirect = add_query_arg(
			array(
				'page'           => 'ucpf-advanced',
				'ucpf_tab'       => 'cloudflare',
				'ucpf_cf_purged' => ! empty( $result['ok'] ) ? '1' : '0',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Sanitize wizard document_sources map.
	 *
	 * @param array $raw Already map_deep-sanitized input.
	 * @return array
	 */
	private function sanitize_document_sources( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$sources = array();
		foreach ( $raw as $key => $value ) {
			$sources[ sanitize_key( (string) $key ) ] = sanitize_key( (string) $value );
		}
		return $sources;
	}

	/**
	 * Sanitize wizard service_overrides map.
	 *
	 * @param array $raw Already map_deep-sanitized input.
	 * @return array
	 */
	private function sanitize_service_overrides( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$overrides = array();
		foreach ( $raw as $key => $row ) {
			$key = sanitize_key( (string) $key );
			if ( ! is_array( $row ) ) {
				continue;
			}
			$overrides[ $key ] = array(
				'category'         => isset( $row['category'] ) ? sanitize_key( (string) $row['category'] ) : '',
				'treatment'        => isset( $row['treatment'] ) ? sanitize_key( (string) $row['treatment'] ) : 'consent',
				'default_blocking' => true,
			);
		}
		return $overrides;
	}

	/**
	 * Apply unknown cookie reviews from wizard POST.
	 *
	 * @param array $rows Already map_deep-sanitized rows.
	 */
	private function apply_unknown_cookie_reviews( $rows ) {
		if ( ! is_array( $rows ) ) {
			return;
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['name'] ) || empty( $row['category'] ) ) {
				continue;
			}
			$cat = sanitize_key( (string) $row['category'] );
			if ( ! in_array( $cat, Privacy_Scan_Importer::assignable_categories(), true ) ) {
				continue;
			}
			Cookie_Scanner::instance()->review_unknown_cookie(
				sanitize_text_field( (string) $row['name'] ),
				array(
					'category'   => $cat,
					'treatment'  => isset( $row['treatment'] ) ? sanitize_key( (string) $row['treatment'] ) : 'consent',
					'label'      => isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '',
					'purpose'    => isset( $row['purpose'] ) ? sanitize_textarea_field( (string) $row['purpose'] ) : '',
					'visibility' => isset( $row['visibility'] ) ? sanitize_key( (string) $row['visibility'] ) : 'show',
				)
			);
		}
	}
}
