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
		load_plugin_textdomain(
			'universal-consent-privacy-framework',
			false,
			dirname( UCPF_PLUGIN_BASENAME ) . '/languages'
		);

		Migration::maybe_upgrade();

		Integrations\Wp_Consent_Api_Shim::instance()->init();
		Jurisdiction::instance()->init();
		Consent_Manager::instance()->init();
		Privacy_State::instance()->init();
		Script_Registry::instance()->init();
		Script_Blocker::instance()->init();
		Theme_Manager::instance()->init();
		Shortcodes::instance()->init();
		Privacy_Tools::instance()->init();
		Vendor_Connectors::instance()->init();
		Rights_Inbox::instance()->init();
		Rest_Api::instance()->init();
		Audit_Log::instance()->init();
		Page_Generator::instance()->init();
		Cookie_Scanner::instance()->init();
		Scheduled_Scan::instance()->init();
		Agency_Scanner::instance()->init();
		Integrations::instance()->init();

		if ( is_admin() ) {
			Admin::instance()->init();
		} else {
			$this->init_frontend();
		}

		add_action( 'ucpf_daily_cleanup', array( Audit_Log::instance(), 'purge_expired' ) );

		/**
		 * Fires when UCPF is fully loaded.
		 */
		do_action( 'ucpf_loaded' );
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
	 * Print denied Consent Mode defaults + network/script hard-gate before third-party tags.
	 *
	 * Google Consent Mode "denied" alone still allows cookieless /g/collect (click events included).
	 * The network gate blocks those until analytics (or marketing) consent is granted.
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
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.
		$js = file_get_contents( $gate );
		if ( ! is_string( $js ) || '' === $js ) {
			return;
		}

		$privacy = Privacy_State::instance()->get_state_for_js();
		$extras  = Catalog_Suggestions::gate_extra_patterns();
		echo '<script id="ucpf-network-gate-boot" data-cfasync="false" data-no-optimize="1" data-no-defer="1">' . "\n";
		echo 'window.__ucpfPrivacy=' . wp_json_encode( $privacy ) . ";\n";
		echo 'window.__ucpfGateExtra=' . wp_json_encode( $extras ) . ";\n";
		echo "</script>\n";

		echo '<script id="ucpf-network-gate" data-cfasync="false" data-no-optimize="1" data-no-defer="1">' . "\n";
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted local JS asset.
		echo $js;
		echo "\n</script>\n";
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
			UCPF_VERSION,
			false
		);

		wp_enqueue_script(
			'ucpf-gsap',
			UCPF_PLUGIN_URL . 'public/js/lib/gsap.min.js',
			array(),
			UCPF_VERSION,
			false
		);

		wp_enqueue_script(
			'ucpf-consent-motion',
			UCPF_PLUGIN_URL . 'public/js/consent-motion.js',
			array( 'ucpf-consent', 'ucpf-gsap' ),
			UCPF_VERSION,
			false
		);

		wp_enqueue_script(
			'ucpf-loader',
			UCPF_PLUGIN_URL . 'public/js/loader.js',
			array( 'ucpf-consent' ),
			UCPF_VERSION,
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
			'bannerLayout'    => in_array( Settings::get( 'banner_layout' ), array( 'bar', 'modal', 'corner' ), true ) ? Settings::get( 'banner_layout' ) : 'bar',
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
			'jurisdiction'    => $pack_cfg,
			'i18n'            => array(
				'cookies'       => ! empty( $pack_copy['banner_title'] ) ? $pack_copy['banner_title'] : __( 'Cookies', 'universal-consent-privacy-framework' ),
				'description'   => ! empty( $pack_copy['banner_text'] ) ? $pack_copy['banner_text'] : __( 'We use essential cookies for security and optional cookies based on your choices. See our cookie policy for details.', 'universal-consent-privacy-framework' ),
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
		if ( ! in_array( $handle, array( 'ucpf-consent', 'ucpf-loader', 'ucpf-network-gate' ), true ) ) {
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
