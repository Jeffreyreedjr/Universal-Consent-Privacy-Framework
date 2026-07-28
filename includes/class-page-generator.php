<?php
/**
 * Privacy page generator.
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Page generator.
 */
class Page_Generator {

	/**
	 * Instance.
	 *
	 * @var Page_Generator|null
	 */
	private static $instance = null;

	/**
	 * Page definitions (auto-generated catalog).
	 * Do Not Sell / Data Request are not generated — link external home-site URLs instead.
	 *
	 * @var array
	 */
	private $pages = array(
		'privacy_policy'      => array(
			'title'    => 'Privacy Policy',
			'template' => 'privacy-policy-template.php',
			'shortcode'=> '[ucpf_privacy_disclosures]',
		),
		'cookie_policy'       => array(
			'title'    => 'Cookie Policy',
			'template' => 'cookie-policy-template.php',
			'shortcode'=> '[ucpf_cookie_table]',
		),
		'consent_preferences' => array(
			'title'    => 'Consent Preferences',
			'template' => 'consent-preferences-template.php',
			'shortcode'=> '[ucpf_consent_preferences]',
		),
	);

	/**
	 * Get instance.
	 *
	 * @return Page_Generator
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Init.
	 */
	public function init() {
		// Pages created on demand.
		add_filter( 'body_class', array( $this, 'body_class' ) );
		// Do NOT dequeue Elementor frontend assets — Theme Builder header/footer/nav depend on them.
		add_filter( 'the_content', array( $this, 'wrap_legal_content' ), 5 );
		add_action( 'init', array( $this, 'ensure_generated_page_meta' ), 20 );
	}

	/**
	 * Stamp existing generated page IDs with UCPF meta (for pages created before this flag).
	 */
	public function ensure_generated_page_meta() {
		$generated = Settings::get( 'generated_pages' );
		if ( ! is_array( $generated ) ) {
			return;
		}
		foreach ( $generated as $page_id ) {
			$page_id = (int) $page_id;
			if ( ! $page_id || ! get_post( $page_id ) ) {
				continue;
			}
			if ( ! get_post_meta( $page_id, '_ucpf_generated_page', true ) ) {
				update_post_meta( $page_id, '_ucpf_generated_page', '1' );
			}
			// Prefer theme default template so header/footer locations render.
			$tpl = (string) get_post_meta( $page_id, '_wp_page_template', true );
			if ( $tpl && in_array( $tpl, array( 'elementor_canvas', 'elementor_header_footer', 'canvas.php' ), true ) ) {
				update_post_meta( $page_id, '_wp_page_template', 'default' );
			}
		}
	}

	/**
	 * Whether current singular page is UCPF-generated.
	 *
	 * @return bool
	 */
	public function is_ucpf_legal_page() {
		if ( ! is_singular( 'page' ) ) {
			return false;
		}
		return (bool) get_post_meta( get_the_ID(), '_ucpf_generated_page', true );
	}

	/**
	 * Body class for legal pages.
	 *
	 * @param array $classes Classes.
	 * @return array
	 */
	public function body_class( $classes ) {
		if ( $this->is_ucpf_legal_page() ) {
			$classes[] = 'ucpf-legal-page';
			$classes[] = 'ucpf-theme-classic';
		}
		return $classes;
	}

	/**
	 * Previously dequeued Elementor on legal pages to avoid content CSS collisions.
	 * That also broke Theme Builder header / footer / navbar. Kept as a no-op for BC
	 * if anything still hooks this method name.
	 */
	public function dequeue_elementor_on_legal_pages() {
		// Intentionally empty — site chrome must load on privacy / cookie / consent pages.
	}

	/**
	 * Wrap generated page content for isolated styling.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public function wrap_legal_content( $content ) {
		if ( ! $this->is_ucpf_legal_page() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		if ( false !== strpos( $content, 'ucpf-legal-shell' ) ) {
			return $content;
		}
		return '<div id="ucpf-legal-shell" class="ucpf-legal-shell">' . $content . '</div>';
	}

	/**
	 * Generate all pages.
	 *
	 * @param bool $overwrite Overwrite existing.
	 * @return array
	 */
	public function generate_all( $overwrite = false ) {
		$generated = Settings::get( 'generated_pages' );
		if ( ! is_array( $generated ) ) {
			$generated = array();
		}

		$results = array();

		foreach ( $this->pages as $key => $page ) {
			$results[ $key ] = $this->generate_page( $key, $overwrite );
			if ( ! is_wp_error( $results[ $key ] ) ) {
				$generated[ $key ] = $results[ $key ];
			}
		}

		Settings::update( array( 'generated_pages' => $generated ) );

		/**
		 * Fires after pages generated.
		 *
		 * @param array $results Page IDs or errors.
		 */
		do_action( 'ucpf_pages_generated', $results );

		return $results;
	}

	/**
	 * Generate single page.
	 *
	 * @param string $key       Page key.
	 * @param bool   $overwrite Overwrite.
	 * @return int|\WP_Error
	 */
	public function generate_page( $key, $overwrite = false ) {
		if ( ! isset( $this->pages[ $key ] ) ) {
			return new \WP_Error( 'ucpf_invalid_page', __( 'Unknown page type.', 'universal-consent-privacy-framework' ) );
		}

		$page_def   = $this->pages[ $key ];
		$generated  = Settings::get( 'generated_pages' );
		$existing_id = isset( $generated[ $key ] ) ? (int) $generated[ $key ] : 0;

		if ( $existing_id && get_post( $existing_id ) && ! $overwrite ) {
			return $existing_id;
		}

		$content = $this->render_template( $page_def['template'] );
		$shortcode = $this->resolve_page_shortcode( $key );
		if ( $shortcode ) {
			$content .= "\n\n" . $shortcode;
		}

		$post_data = array(
			'post_title'   => $page_def['title'],
			'post_content' => $content,
			'post_status'  => 'publish',
			'post_type'    => 'page',
		);

		if ( $existing_id && $overwrite ) {
			$post_data['ID'] = $existing_id;
			$post_id = wp_update_post( $post_data, true );
		} else {
			$post_id = wp_insert_post( $post_data, true );
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, '_ucpf_generated_page', '1' );
		// Classic page content (not Elementor canvas) so the theme / Theme Builder chrome wraps it.
		update_post_meta( $post_id, '_elementor_edit_mode', '' );
		delete_post_meta( $post_id, '_elementor_data' );
		delete_post_meta( $post_id, '_elementor_css' );
		delete_post_meta( $post_id, '_wp_page_template' );
		update_post_meta( $post_id, '_wp_page_template', 'default' );

		if ( 'privacy_policy' === $key ) {
			update_option( 'wp_page_for_privacy_policy', $post_id );
		}

		return (int) $post_id;
	}

	/**
	 * Refresh (create or overwrite) the Cookie Policy page from the latest scan/templates.
	 *
	 * @return bool True when a page was created or updated.
	 */
	public function refresh_cookie_policy_page() {
		return $this->refresh_generated_page( 'cookie_policy' );
	}

	/**
	 * Refresh Privacy Policy page (static template + live disclosures shortcode).
	 *
	 * @return bool
	 */
	public function refresh_privacy_policy_page() {
		return $this->refresh_generated_page( 'privacy_policy' );
	}

	/**
	 * Refresh a generated legal page from template + shortcode.
	 *
	 * @param string $key Page key.
	 * @return bool
	 */
	public function refresh_generated_page( $key ) {
		$generated   = Settings::get( 'generated_pages' );
		$existing_id = isset( $generated[ $key ] ) ? (int) $generated[ $key ] : 0;
		$overwrite   = (bool) ( $existing_id && get_post( $existing_id ) );

		$result = $this->generate_page( $key, $overwrite );
		if ( is_wp_error( $result ) || ! $result ) {
			return false;
		}

		if ( ! is_array( $generated ) ) {
			$generated = array();
		}
		$generated[ $key ] = (int) $result;
		Settings::update( array( 'generated_pages' => $generated ) );

		return true;
	}

	/**
	 * Resolve shortcode for a generated page (Gravity Forms when configured).
	 *
	 * @param string $key Page key.
	 * @return string
	 */
	public function resolve_page_shortcode( $key ) {
		if ( isset( $this->pages[ $key ]['shortcode'] ) ) {
			return (string) $this->pages[ $key ]['shortcode'];
		}
		return '';
	}

	/**
	 * Build Gravity Forms shortcode or fall back to built-in UCPF form shortcode.
	 *
	 * @param string $which    data_request|do_not_sell.
	 * @param string $fallback Built-in shortcode.
	 * @return string
	 */
	public function resolve_form_embed( $which, $fallback ) {
		$custom = trim( (string) Settings::get( 'gf_' . $which . '_shortcode', '' ) );
		if ( '' !== $custom ) {
			return $custom;
		}

		$form_id = absint( Settings::get( 'gf_' . $which . '_form_id', 0 ) );
		if ( $form_id > 0 ) {
			return sprintf(
				'[gravityform id="%d" title="false" description="false" ajax="true"]',
				$form_id
			);
		}

		return $fallback;
	}

	/**
	 * Render template file.
	 *
	 * @param string $template Template filename.
	 * @return string
	 */
	private function render_template( $template ) {
		$path = UCPF_PLUGIN_DIR . 'templates/' . $template;
		if ( ! file_exists( $path ) ) {
			return '';
		}

		$scan = Cookie_Scanner::instance()->get_last_scan();
		$last = ! empty( $scan['date'] ) ? $scan['date'] : gmdate( 'Y-m-d' );

		$vars = apply_filters(
			'ucpf_policy_template_variables',
			array(
				'site_name'         => get_bloginfo( 'name' ),
				'business_name'     => Settings::get( 'business_name' ) ?: get_bloginfo( 'name' ),
				'contact_email'     => Settings::get( 'contact_email' ) ?: get_option( 'admin_email' ),
				'contact_phone'     => Settings::get( 'business_phone' ),
				'business_address'  => Settings::get( 'business_address' ),
				'last_updated'      => mysql2date( 'F j, Y', $last, true ),
				'retention_days'    => (int) Settings::get( 'legal_retention_days', 365 ),
				'cookie_policy_url'   => $this->get_page_url( 'cookie_policy' ),
				'privacy_policy_url'  => $this->get_page_url( 'privacy_policy' ),
				'data_request_url'    => $this->get_rights_url( 'data_request' ),
				'dns_url'             => $this->get_rights_url( 'do_not_sell' ),
			)
		);

		ob_start();
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $vars, EXTR_SKIP );
		include $path;
		return ob_get_clean();
	}

	/**
	 * Rights page URL (Data Request / Do Not Sell).
	 * Prefers external home-site URL settings; falls back to legacy generated page IDs.
	 *
	 * @param string $key data_request|do_not_sell.
	 * @return string
	 */
	public function get_rights_url( $key ) {
		$key = sanitize_key( $key );
		$setting_map = array(
			'data_request' => 'data_request_page_url',
			'do_not_sell'  => 'do_not_sell_page_url',
		);
		if ( isset( $setting_map[ $key ] ) ) {
			$external = trim( (string) Settings::get( $setting_map[ $key ], '' ) );
			if ( '' !== $external ) {
				return esc_url_raw( $external );
			}
		}
		return $this->get_generated_page_url( $key );
	}

	/**
	 * Get page URL by key (generated pages, or rights URLs for DSAR/DNS).
	 *
	 * @param string $key Page key.
	 * @return string
	 */
	public function get_page_url( $key ) {
		$key = sanitize_key( $key );
		if ( in_array( $key, array( 'data_request', 'do_not_sell' ), true ) ) {
			return $this->get_rights_url( $key );
		}
		return $this->get_generated_page_url( $key );
	}

	/**
	 * Permalink for a previously generated page ID.
	 *
	 * @param string $key Page key.
	 * @return string
	 */
	private function get_generated_page_url( $key ) {
		$generated = Settings::get( 'generated_pages' );
		if ( ! empty( $generated[ $key ] ) ) {
			$url = get_permalink( (int) $generated[ $key ] );
			if ( $url ) {
				return $url;
			}
		}
		return '';
	}
}
