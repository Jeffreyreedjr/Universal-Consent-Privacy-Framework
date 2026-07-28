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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_ucpf_save_wizard', array( $this, 'handle_wizard_save' ) );
		add_action( 'admin_post_ucpf_export_logs', array( $this, 'handle_export_logs' ) );
	}

	/**
	 * Register admin menus.
	 */
	public function register_menus() {
		$menu_icon = UCPF_PLUGIN_URL . 'assets/branding/icon-128x128.png';
		if ( ! is_readable( UCPF_PLUGIN_DIR . 'assets/branding/icon-128x128.png' ) ) {
			$menu_icon = 'dashicons-shield';
		}

		add_menu_page(
			Brand::product_name(),
			Brand::menu_title(),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_dashboard' ),
			$menu_icon,
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
			UCPF_VERSION
		);

		if ( false !== strpos( $hook, 'ucpf-banner' ) ) {
			Theme_Manager::instance()->enqueue_admin_preview_styles();
			$style_deps[] = 'ucpf-banner';
		}

		wp_enqueue_style(
			'ucpf-admin',
			UCPF_PLUGIN_URL . 'admin/css/admin.css',
			$style_deps,
			UCPF_VERSION
		);

		wp_enqueue_script(
			'ucpf-admin',
			UCPF_PLUGIN_URL . 'admin/js/admin.js',
			array( 'jquery' ),
			UCPF_VERSION,
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
				'contributeIssueUrl' => Cookie_Knowledge::contribute_issue_url(),
			)
		);

		// React dashboard (built assets).
		$is_dashboard = ( false !== strpos( $hook, 'ucpf-dashboard' ) ) || ( false !== strpos( $hook, 'toplevel_page_ucpf' ) );
		$built_js     = UCPF_PLUGIN_DIR . 'admin/build/index.js';
		if ( $is_dashboard && is_readable( $built_js ) ) {
			$asset = UCPF_PLUGIN_DIR . 'admin/build/index.asset.php';
			$deps  = array();
			$ver   = UCPF_VERSION;
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
			$settings = Settings::all();
			wp_localize_script(
				'ucpf-admin-app',
				'ucpfDashboard',
				array(
					'productName'      => Brand::product_name(),
					'wizardCompleted'  => (bool) Settings::get( 'wizard_completed' ),
					'healthChecks'     => $this->get_health_checks(),
					'warnings'         => $this->get_warnings(),
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
					),
					'i18n'             => array(
						'title'         => __( 'Privacy Consent Dashboard', 'universal-consent-privacy-framework' ),
						'lede'          => __( 'Helps support privacy compliance. Final legal review is the site owner\'s responsibility. Local-first: no phone-home.', 'universal-consent-privacy-framework' ),
						'getStarted'    => __( 'Get started', 'universal-consent-privacy-framework' ),
						'getStartedBody'=> __( 'Run the setup wizard to scan cookies, choose services, generate policies, and enable the banner.', 'universal-consent-privacy-framework' ),
						'openWizard'    => __( 'Open Setup Wizard', 'universal-consent-privacy-framework' ),
						'reopenWizard'  => __( 'Re-open Setup Wizard', 'universal-consent-privacy-framework' ),
						'openScanner'   => __( 'Cookie Scanner', 'universal-consent-privacy-framework' ),
						'healthTitle'   => __( 'Install health', 'universal-consent-privacy-framework' ),
						'healthLede'    => __( 'Quick checklist for deploys. Fix anything marked warn or fail before handoff.', 'universal-consent-privacy-framework' ),
						'compliance'    => __( 'Compliance mode', 'universal-consent-privacy-framework' ),
						'policy'        => __( 'Policy version', 'universal-consent-privacy-framework' ),
						'bannerBlocker' => __( 'Banner / blocker', 'universal-consent-privacy-framework' ),
						'wpConsent'     => __( 'WP Consent API', 'universal-consent-privacy-framework' ),
						'wpConsentYes'  => __( 'Compatible (shim active)', 'universal-consent-privacy-framework' ),
						'wpConsentShim' => __( 'Bundled shim only', 'universal-consent-privacy-framework' ),
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
		if ( ! is_array( $input ) ) {
			return Settings::all();
		}

		$current = Settings::all();
		$clean   = array_merge( $current, $input );

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
			$clean['delete_data_on_uninstall'] = ! empty( $input['delete_data_on_uninstall'] );
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
				$clean['scanner_api_url'] = esc_url_raw( $input['scanner_api_url'] );
			}
			if ( array_key_exists( 'scanner_api_key', $input ) ) {
				$clean['scanner_api_key'] = sanitize_text_field( (string) $input['scanner_api_key'] );
			}
			if ( isset( $input['registry_mode'] ) ) {
				$rm = sanitize_key( (string) $input['registry_mode'] );
				$clean['registry_mode'] = in_array( $rm, array( 'local', 'agency', 'community', 'disabled' ), true ) ? $rm : 'local';
			}
			if ( isset( $input['privacy_api_url'] ) ) {
				$clean['privacy_api_url'] = esc_url_raw( (string) $input['privacy_api_url'] );
			}
			if ( array_key_exists( 'privacy_api_key', $input ) ) {
				$clean['privacy_api_key'] = sanitize_text_field( (string) $input['privacy_api_key'] );
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
		}

		if ( ! empty( $input['_ucpf_pages_form'] ) ) {
			$clean['auto_refresh_cookie_policy_after_scan'] = ! empty( $input['auto_refresh_cookie_policy_after_scan'] );
			$clean['data_request_page_url']                 = isset( $input['data_request_page_url'] ) ? esc_url_raw( (string) $input['data_request_page_url'] ) : '';
			$clean['do_not_sell_page_url']                  = isset( $input['do_not_sell_page_url'] ) ? esc_url_raw( (string) $input['do_not_sell_page_url'] ) : '';
		}

		unset( $clean['_ucpf_tracking_form'], $clean['_ucpf_banner_form'], $clean['_ucpf_advanced_form'], $clean['_ucpf_pages_form'] );

		if ( isset( $input['google_consent_mode'] ) ) {
			$mode = sanitize_key( $input['google_consent_mode'] );
			$clean['google_consent_mode'] = in_array( $mode, array( 'off', 'basic', 'advanced' ), true ) ? $mode : 'basic';
		}

		$allowed_modes = Jurisdiction::instance()->get_pack_ids();
		$mode          = sanitize_key( isset( $clean['compliance_mode'] ) ? $clean['compliance_mode'] : 'strict_gdpr' );
		$clean['compliance_mode'] = in_array( $mode, $allowed_modes, true ) ? $mode : 'strict_gdpr';
		$clean['contact_email']   = sanitize_email( isset( $clean['contact_email'] ) ? $clean['contact_email'] : '' );
		$clean['business_name']   = sanitize_text_field( isset( $clean['business_name'] ) ? $clean['business_name'] : '' );
		$clean['custom_css']      = isset( $clean['custom_css'] ) ? wp_strip_all_tags( (string) $clean['custom_css'] ) : '';

		if ( array_key_exists( 'logo_url', $input ) ) {
			$clean['logo_url'] = esc_url_raw( (string) $input['logo_url'] );
		}

		if ( array_key_exists( 'accent_2_color', $input ) ) {
			$raw2 = is_string( $input['accent_2_color'] ) ? $input['accent_2_color'] : '';
			$hex2 = $raw2 ? sanitize_hex_color( $raw2 ) : '';
			$clean['accent_2_color'] = $hex2 ? $hex2 : sanitize_text_field( $raw2 );
		}

		if ( array_key_exists( 'surface_color', $input ) ) {
			$raw_s = is_string( $input['surface_color'] ) ? $input['surface_color'] : '';
			$hex_s = $raw_s ? sanitize_hex_color( $raw_s ) : '';
			$clean['surface_color'] = $hex_s ? $hex_s : sanitize_text_field( $raw_s );
		}

		if ( array_key_exists( 'accent_color', $input ) ) {
			$raw = is_string( $input['accent_color'] ) ? $input['accent_color'] : '';
			$hex = $raw ? sanitize_hex_color( $raw ) : '';
			$clean['accent_color'] = $hex ? $hex : sanitize_text_field( $raw );
		} else {
			$clean['accent_color'] = isset( $current['accent_color'] ) && is_string( $current['accent_color'] ) ? $current['accent_color'] : '';
		}

		return $clean;
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
	 * @return array<int, array{id:string,label:string,status:string,detail:string,action_url?:string,action_label?:string}>
	 */
	public function get_health_checks() {
		$settings = Settings::all();
		$pages    = isset( $settings['generated_pages'] ) && is_array( $settings['generated_pages'] ) ? $settings['generated_pages'] : array();
		$scan     = Cookie_Scanner::instance()->get_last_scan();
		$checks   = array();

		$banner_on = ! empty( $settings['banner_enabled'] );
		$checks[]  = array(
			'id'           => 'banner',
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
			'label'  => __( 'Script blocker', 'universal-consent-privacy-framework' ),
			'status' => $blocker_on ? 'ok' : 'warn',
			'detail' => $blocker_on
				? __( 'Optional scripts stay blocked until consent.', 'universal-consent-privacy-framework' )
				: __( 'Blocker is off — optional tags may load before consent.', 'universal-consent-privacy-framework' ),
		);

		$cookie_id  = ! empty( $pages['cookie_policy'] ) ? (int) $pages['cookie_policy'] : 0;
		$cookie_ok  = $cookie_id && get_post( $cookie_id );
		$checks[]   = array(
			'id'           => 'cookie_policy',
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
				'label'        => __( 'Unknown cookies', 'universal-consent-privacy-framework' ),
				'status'       => 'warn',
				'detail'       => sprintf(
					/* translators: %d: count */
					_n( '%d unknown cookie needs review in the wizard.', '%d unknown cookies need review in the wizard.', $unknown_n, 'universal-consent-privacy-framework' ),
					$unknown_n
				),
				'action_url'   => admin_url( 'admin.php?page=ucpf-wizard' ),
				'action_label' => __( 'Review in wizard', 'universal-consent-privacy-framework' ),
			);
		}

		$auto = ! empty( $settings['auto_refresh_cookie_policy_after_scan'] );
		$checks[] = array(
			'id'           => 'auto_refresh',
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
			'label'  => __( 'WP Consent API', 'universal-consent-privacy-framework' ),
			'status' => 'ok',
			'detail' => $wp_consent
				? __( 'Official WP Consent API detected; UCPF syncs categories to it.', 'universal-consent-privacy-framework' )
				: __( 'Bundled shim active (no separate WP Consent API plugin required).', 'universal-consent-privacy-framework' ),
		);

		$remote = ! empty( $settings['remote_registry_enabled'] );
		$checks[] = array(
			'id'     => 'remote_registry',
			'label'  => __( 'Remote registry', 'universal-consent-privacy-framework' ),
			'status' => $remote ? 'warn' : 'ok',
			'detail' => $remote
				? __( 'Remote registry sync is ON. Internal default is off (local catalog only).', 'universal-consent-privacy-framework' )
				: __( 'Off — local vendor catalog only (recommended).', 'universal-consent-privacy-framework' ),
		);

		$theme = isset( $settings['banner_theme'] ) ? (string) $settings['banner_theme'] : 'classic';
		$checks[] = array(
			'id'     => 'theme',
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
			'id'     => 'jurisdiction',
			'label'  => __( 'Jurisdiction pack', 'universal-consent-privacy-framework' ),
			'status' => 'ok',
			'detail' => sprintf(
				/* translators: 1: pack id, 2: consent model */
				__( 'Active pack: %1$s (%2$s). Not a compliance certification.', 'universal-consent-privacy-framework' ),
				$pack_id,
				isset( $pack['consent_model'] ) ? $pack['consent_model'] : 'optin'
			),
			'action_url'   => admin_url( 'admin.php?page=ucpf-wizard' ),
			'action_label' => __( 'Wizard', 'universal-consent-privacy-framework' ),
		);

		$gpc_on = ! empty( $settings['respect_dnt_gpc'] );
		$checks[] = array(
			'id'     => 'gpc',
			'label'  => __( 'GPC / Do Not Track signals', 'universal-consent-privacy-framework' ),
			'status' => $gpc_on ? 'ok' : 'warn',
			'detail' => $gpc_on
				? __( 'GPC signals are respected and enforced via Privacy State.', 'universal-consent-privacy-framework' )
				: __( 'GPC respect is off — enable for California / US packs.', 'universal-consent-privacy-framework' ),
		);

		$reject_on = ! empty( $settings['show_reject_all'] );
		$checks[]  = array(
			'id'     => 'reject_parity',
			'label'  => __( 'Reject All visible', 'universal-consent-privacy-framework' ),
			'status' => $reject_on ? 'ok' : 'fail',
			'detail' => $reject_on
				? __( 'Reject All is shown (equal choice with Accept All).', 'universal-consent-privacy-framework' )
				: __( 'Reject All is hidden — restore for GDPR-style fairness.', 'universal-consent-privacy-framework' ),
		);

		$gate_file = UCPF_PLUGIN_DIR . 'public/js/network-gate.js';
		$checks[]  = array(
			'id'     => 'network_gate',
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
			'last_scan' => Cookie_Scanner::instance()->get_last_scan(),
			'services'  => Script_Registry::instance()->get_services(),
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
		// Prefer WP admin `paged`; also accept `page` for REST-style bookmarks.
		$page = 1;
		if ( isset( $_GET['paged'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list nav.
			$page = max( 1, (int) wp_unslash( $_GET['paged'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		} elseif ( isset( $_GET['page_num'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$page = max( 1, (int) wp_unslash( $_GET['page_num'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		$logs = Audit_Log::instance()->get_logs( $page, 50 );
		$this->render(
			'logs',
			array(
				'logs'            => $logs,
				'retention_days'  => max( 1, (int) Settings::get( 'log_retention_days', 360 ) ),
			)
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

		if ( isset( $_POST['selected_statistics'] ) && is_array( $_POST['selected_statistics'] ) ) {
			$update['selected_statistics'] = array_values( array_filter( array_map( 'sanitize_key', wp_unslash( $_POST['selected_statistics'] ) ) ) );
		} elseif ( 7 === $step ) {
			$update['selected_statistics'] = array();
		}

		if ( isset( $_POST['contact_email'] ) ) {
			$update['contact_email'] = sanitize_email( wp_unslash( $_POST['contact_email'] ) );
		}

		// Scanner API (wizard step before Website Scan).
		if ( isset( $_POST['scanner_api_url'] ) ) {
			$update['scanner_api_url'] = esc_url_raw( wp_unslash( $_POST['scanner_api_url'] ) );
		}
		if ( array_key_exists( 'scanner_api_key', $_POST ) ) {
			$key_in = sanitize_text_field( wp_unslash( $_POST['scanner_api_key'] ) );
			// Empty password field = keep existing key (do not wipe).
			if ( '' !== $key_in ) {
				$update['scanner_api_key'] = $key_in;
			}
		}

		if ( isset( $_POST['data_request_page_url'] ) ) {
			$update['data_request_page_url'] = esc_url_raw( wp_unslash( $_POST['data_request_page_url'] ) );
		}
		if ( isset( $_POST['do_not_sell_page_url'] ) ) {
			$update['do_not_sell_page_url'] = esc_url_raw( wp_unslash( $_POST['do_not_sell_page_url'] ) );
		}

		$bool_fields = array( 'consent_logging', 'respect_dnt_gpc', 'banner_enabled', 'blocker_enabled' );
		foreach ( $bool_fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$update[ $field ] = (bool) (int) $_POST[ $field ];
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
			$update['wizard_step'] = $step;
		}

		Settings::update( $update );

		wp_safe_redirect( admin_url( 'admin.php?page=ucpf-wizard' ) );
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

		$csv = Audit_Log::instance()->export_csv();

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=ucpf-consent-logs.csv' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download body; cells are escaped in Audit_Log::export_csv().
		echo $csv;
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
