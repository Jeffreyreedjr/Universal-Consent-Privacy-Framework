<?php
/**
 * REST API endpoints.
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * REST API handler.
 */
class Rest_Api {

	/**
	 * Instance.
	 *
	 * @var Rest_Api|null
	 */
	private static $instance = null;

	/**
	 * Namespace.
	 *
	 * @var string
	 */
	const NAMESPACE = 'ucpf/v1';

	/**
	 * Get instance.
	 *
	 * @return Rest_Api
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Init routes.
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_public_settings' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/privacy-state',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_privacy_state' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/privacy-revoke-cache',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_privacy_revoke_cache' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/consent',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_consent' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'post_consent' ),
					'permission_callback' => array( $this, 'public_nonce_permission' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/withdraw',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_withdraw' ),
				'permission_callback' => array( $this, 'public_nonce_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/services',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_services' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/scan',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_scan' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/scan/urls',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_scan_urls' ),
				'permission_callback' => array( $this, 'admin_permission' ),
				'args'                => array(
					'depth' => array(
						'type'              => 'string',
						'enum'              => array( 'quick', 'standard', 'deep' ),
						'default'           => 'standard',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/scan/selection',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_scan_selection' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/scan/discover-token',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'post_discover_token' ),
					'permission_callback' => array( $this, 'admin_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_discover_token' ),
					'permission_callback' => array( $this, 'admin_permission' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/scan/cancel',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_scan_cancel' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/scan/export',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_scan_export' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/scan/import',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_scan_import' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/scan/deep',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_scan_deep' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/scan/deep/(?P<id>[a-zA-Z0-9\-]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_scan_deep' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/scan/active',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_scan_active' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/scan/scheduled',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_scan_scheduled' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/logs',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_logs' ),
				'permission_callback' => array( $this, 'admin_permission' ),
				'args'                => array(
					'page'      => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page'  => array(
						'type'              => 'integer',
						'default'           => 50,
						'sanitize_callback' => 'absint',
					),
					'view'      => array(
						'type'              => 'string',
						'default'           => 'by_day',
						'sanitize_callback' => 'sanitize_key',
					),
					'uuid'      => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'action'    => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
					'date_from' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'date_to'   => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/pages/generate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_generate_pages' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/agency-preset',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_agency_preset' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/catalog-suggestions',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_catalog_suggestions' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/catalog-suggestions/apply',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_catalog_suggestions_apply' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/catalog-suggestions/(?P<key>[a-z0-9_\-]+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_catalog_suggestion' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/vendor-suppress-queue',
			array(
				'methods'             => array( \WP_REST_Server::READABLE, \WP_REST_Server::DELETABLE ),
				'callback'            => array( $this, 'handle_vendor_suppress_queue' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/vendor-suppress-queue/(?P<index>\d+)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_vendor_suppress_queue_item' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/registry/refresh',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_registry_refresh' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/registry/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_registry_status' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/registry/import',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_registry_import' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/registry/export',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_registry_export' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/theme/export',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_theme_export' ),
				'permission_callback' => array( $this, 'admin_permission' ),
				'args'                => array(
					'name' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/theme/import',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_theme_import' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/cookies/capture',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_cookie_capture' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/cookies/review',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_cookie_review' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/cookies/lookup',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_cookie_lookup' ),
				'permission_callback' => array( $this, 'admin_permission' ),
				'args'                => array(
					'q'     => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'limit' => array(
						'type'              => 'integer',
						'default'           => 25,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/knowledge/export',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_knowledge_export' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/knowledge/contribute',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_knowledge_contribute' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/knowledge/import',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_knowledge_import' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/cookies/overrides',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_cookie_overrides' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/services/override',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_service_override' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/services/overrides',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_service_overrides' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);
	}

	/**
	 * Public permission with REST nonce.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function public_nonce_permission( $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Admin permission.
	 *
	 * @return bool
	 */
	public function admin_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET public settings subset.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_public_settings() {
		$banner = apply_filters(
			'ucpf_default_banner_settings',
			array(
				'layout'        => Settings::get( 'banner_layout' ),
				'theme'         => Settings::get( 'banner_theme' ),
				'showRejectAll' => (bool) Settings::get( 'show_reject_all' ),
				'showAcceptAll' => (bool) Settings::get( 'show_accept_all' ),
				'showCustomize' => (bool) Settings::get( 'show_customize' ),
			)
		);

		return rest_ensure_response( $banner );
	}

	/**
	 * GET consent state.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_consent() {
		return rest_ensure_response( Consent_Manager::instance()->get_consent_state() );
	}

	/**
	 * GET privacy enforcement state (GPC / DNS / central).
	 *
	 * @return \WP_REST_Response
	 */
	public function get_privacy_state() {
		return rest_ensure_response( Privacy_State::instance()->get_state() );
	}

	/**
	 * POST purge central preference cache (agency webhook).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_privacy_revoke_cache( $request ) {
		$body    = $request->get_json_params();
		$subject = isset( $body['subject'] ) ? preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $body['subject'] ) ) : '';
		if ( ! $subject ) {
			return new \WP_Error( 'ucpf_bad_subject', __( 'subject HMAC required.', 'universal-consent-privacy-framework' ), array( 'status' => 400 ) );
		}
		Privacy_Preference_Client::purge_cache( $subject );
		return rest_ensure_response( array( 'purged' => true ) );
	}

	/**
	 * POST consent.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_consent( $request ) {
		$body   = $request->get_json_params();
		$action = isset( $body['action'] ) ? sanitize_key( $body['action'] ) : 'save_preferences';

		if ( ! $this->rate_limit( 'consent', 30 ) ) {
			return new \WP_Error( 'ucpf_rate_limit', __( 'Too many requests.', 'universal-consent-privacy-framework' ), array( 'status' => 429 ) );
		}

		$manager = Consent_Manager::instance();
		$uuid    = $this->sanitize_consent_uuid( isset( $body['uuid'] ) ? $body['uuid'] : '' );

		if ( 'accept_all' === $action ) {
			$result = $manager->save_consent(
				array(
					'state'      => 'accepted_all',
					'categories' => $manager->default_categories_accepted(),
					'services'   => array(),
					'uuid'       => $uuid,
				),
				'accept_all'
			);
		} elseif ( 'reject_all' === $action ) {
			$result = $manager->save_consent(
				array(
					'state'      => 'rejected_all',
					'categories' => $manager->default_categories_rejected(),
					'services'   => array(),
					'uuid'       => $uuid,
				),
				'reject_all'
			);
		} else {
			if ( ! is_array( $body ) ) {
				$body = array();
			}
			$body['uuid'] = $uuid;
			$result       = $manager->save_consent( $body, $action );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'consent' => $result,
			)
		);
	}

	/**
	 * POST withdraw.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_withdraw( $request ) {
		if ( ! $this->rate_limit( 'withdraw', 30 ) ) {
			return new \WP_Error( 'ucpf_rate_limit', __( 'Too many requests.', 'universal-consent-privacy-framework' ), array( 'status' => 429 ) );
		}

		$body    = $request->get_json_params();
		$payload = array();
		if ( is_array( $body ) && isset( $body['uuid'] ) && '' !== trim( (string) $body['uuid'] ) ) {
			$payload['uuid'] = $this->sanitize_consent_uuid( $body['uuid'] );
		}
		$result = Consent_Manager::instance()->withdraw_consent( $payload );
		return rest_ensure_response( array( 'success' => true, 'consent' => $result ) );
	}

	/**
	 * GET services.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_services() {
		return rest_ensure_response( Script_Registry::instance()->get_services_for_js() );
	}

	/**
	 * GET scan URL list + picker data for guest crawl.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_scan_urls( $request = null ) {
		$scanner  = Cookie_Scanner::instance();
		$saved    = $scanner->get_saved_selection();
		$depth    = 'standard';
		if ( $request instanceof \WP_REST_Request ) {
			$depth = sanitize_key( (string) $request->get_param( 'depth' ) );
		}
		if ( ! $depth && ! empty( $saved['depth'] ) ) {
			$depth = $saved['depth'];
		}
		if ( ! in_array( $depth, array( 'quick', 'standard', 'deep' ), true ) ) {
			$depth = 'standard';
		}
		$limit = $scanner->depth_limit( $depth );
		// Full catalog always — depth only gates how many selected URLs may be crawled.
		$urls  = $scanner->discover_site_paths( 'deep' );
		return rest_ensure_response(
			array(
				'urls'             => $urls,
				'available'        => $urls,
				'chips'            => $scanner->get_scan_chips(),
				'woo_active'       => $scanner->is_woo_active(),
				'woo_pack'         => $scanner->get_woocommerce_url_pack(),
				'site_profile'     => Site_Profiles::current(),
				'home_url'         => untrailingslashit( home_url( '/' ) ),
				'max_crawl'        => Cookie_Scanner::MAX_BROWSER_URLS,
				'max_server'       => min( Cookie_Scanner::MAX_SERVER_URLS, max( $limit, Cookie_Scanner::DEPTH_STANDARD ) ),
				'max_browser'      => Cookie_Scanner::MAX_BROWSER_URLS,
				'max_picker'       => Cookie_Scanner::MAX_PICKER_URLS,
				'depth'            => $depth,
				'depth_limit'      => $limit,
				'presets'          => array(
					'quick'    => Cookie_Scanner::DEPTH_QUICK,
					'standard' => Cookie_Scanner::DEPTH_STANDARD,
					'deep'     => min( Cookie_Scanner::DEPTH_DEEP, Cookie_Scanner::MAX_BROWSER_URLS ),
				),
				'groups'           => array(
					'home'               => __( 'Home', 'universal-consent-privacy-framework' ),
					'woocommerce'        => __( 'WooCommerce', 'universal-consent-privacy-framework' ),
					'products'           => __( 'Products', 'universal-consent-privacy-framework' ),
					'product_categories' => __( 'Product categories', 'universal-consent-privacy-framework' ),
					'pages'              => __( 'Pages', 'universal-consent-privacy-framework' ),
					'posts'              => __( 'Posts', 'universal-consent-privacy-framework' ),
					'categories'         => __( 'Blog categories', 'universal-consent-privacy-framework' ),
					'other'              => __( 'Other / discovered', 'universal-consent-privacy-framework' ),
				),
				'count'            => count( $urls ),
				'saved_selection'  => $saved,
			)
		);
	}

	/**
	 * POST remember scanner page picks + coverage for next visit.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function post_scan_selection( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}
		$saved = Cookie_Scanner::instance()->save_selection( $body );
		return rest_ensure_response(
			array(
				'saved'     => true,
				'selection' => $saved,
				'count'     => count( $saved['urls'] ),
			)
		);
	}

	/**
	 * POST create discover token for guest crawl.
	 *
	 * @return \WP_REST_Response
	 */
	public function post_discover_token() {
		return rest_ensure_response( Cookie_Scanner::instance()->create_discover_token() );
	}

	/**
	 * DELETE clear discover token.
	 *
	 * @return \WP_REST_Response
	 */
	public function delete_discover_token() {
		Cookie_Scanner::instance()->clear_discover_token();
		return rest_ensure_response( array( 'cleared' => true ) );
	}

	/**
	 * POST hard-stop a running scan (busy lock + discover token).
	 *
	 * @return \WP_REST_Response
	 */
	/**
	 * POST cancel in-progress scan (guest crawl lock + remote Playwright job).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_scan_cancel( $request ) {
		$body   = $request->get_json_params();
		$body   = is_array( $body ) ? $body : array();
		$job_id = ! empty( $body['job_id'] ) ? sanitize_text_field( (string) $body['job_id'] ) : '';
		if ( ! $job_id && ! empty( $body['id'] ) ) {
			$job_id = sanitize_text_field( (string) $body['id'] );
		}
		// Prefer persisted interactive job when the admin tab lost deepJobId.
		if ( ! $job_id ) {
			$job_id = Active_Scan::instance()->job_id();
		}

		$local = Cookie_Scanner::instance()->cancel_scan();
		$out   = is_array( $local ) ? $local : array( 'success' => true );

		if ( $job_id ) {
			Active_Scan::instance()->mark_cancelling( $job_id );
			$remote = Privacy_Scan_Importer::cancel_remote_scan( $job_id );
			if ( is_wp_error( $remote ) ) {
				$out['remote_error'] = $remote->get_error_message();
				$out['message']      = __( 'Local scan stopped. Remote scanner cancel failed — Chromium may still be running until you restart the scanner or call cancel-all.', 'universal-consent-privacy-framework' );
				Active_Scan::instance()->mark_finished( $job_id, 'failed', $out['message'] );
			} else {
				$out['remote'] = $remote;
				if ( ! empty( $remote['report'] ) ) {
					$previous = Cookie_Scanner::instance()->get_last_scan();
					$imported = Privacy_Scan_Importer::import_report( $remote['report'] );
					if ( ! is_wp_error( $imported ) ) {
						$out['imported']  = true;
						$out['partial']   = ! empty( $remote['partial'] ) || ! empty( $remote['report']['partial'] );
						$out['inventory'] = array(
							'cookies'         => isset( $imported['cookies'] ) ? count( $imported['cookies'] ) : 0,
							'unknown_cookies' => isset( $imported['unknown_cookies'] ) ? count( $imported['unknown_cookies'] ) : 0,
							'results'         => isset( $imported['results'] ) ? count( $imported['results'] ) : 0,
						);
						if ( Settings::get( 'scheduled_scan_auto_apply', true ) ) {
							$out['safe_apply'] = Privacy_Scan_Importer::apply_safe_updates( $imported, is_array( $previous ) ? $previous : array() );
						}
						$out['message'] = ! empty( $out['partial'] )
							? __( 'Scan stopped. Partial Playwright results were imported.', 'universal-consent-privacy-framework' )
							: __( 'Scan stopped. Results were imported.', 'universal-consent-privacy-framework' );
						Active_Scan::instance()->mark_finished(
							$job_id,
							'cancelled',
							$out['message'],
							array(
								'imported'  => true,
								'partial'   => ! empty( $out['partial'] ),
								'inventory' => $out['inventory'],
							)
						);
					} else {
						$out['import_error'] = $imported->get_error_message();
						$out['message']      = __( 'Scan stopped on the scanner, but importing the partial report failed.', 'universal-consent-privacy-framework' );
						Active_Scan::instance()->mark_finished( $job_id, 'failed', $out['message'] );
					}
				} else {
					$out['message'] = __( 'Scan stopped. Chromium closed; no partial report was ready yet.', 'universal-consent-privacy-framework' );
					Active_Scan::instance()->mark_finished( $job_id, 'cancelled', $out['message'] );
				}
			}
			$out['job_id'] = $job_id;
		} elseif ( ! empty( $body['cancel_all'] ) ) {
			// Shared agency scanners: require explicit confirm so a busy retry cannot wipe every tenant.
			if ( empty( $body['confirm_cancel_all'] ) ) {
				return new \WP_Error(
					'ucpf_cancel_all_confirm',
					__( 'Cancel-all on a shared scanner requires confirm_cancel_all=true. Prefer cancelling your own job_id only.', 'universal-consent-privacy-framework' ),
					array( 'status' => 400 )
				);
			}
			if ( ! current_user_can( 'manage_options' ) ) {
				return new \WP_Error(
					'ucpf_forbidden',
					__( 'Only administrators can reset all scanner jobs.', 'universal-consent-privacy-framework' ),
					array( 'status' => 403 )
				);
			}
			$remote = Privacy_Scan_Importer::cancel_all_remote_scans( true );
			if ( ! is_wp_error( $remote ) ) {
				$out['remote']  = $remote;
				$out['message'] = __( 'All remote scanner jobs cancel requested; concurrency slots reset. Use only on a dedicated scanner or as emergency recovery.', 'universal-consent-privacy-framework' );
			} else {
				$out['remote_error'] = $remote->get_error_message();
			}
		}

		return rest_ensure_response( $out );
	}

	/**
	 * GET last scan export for vendor-catalog merge.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_scan_export() {
		return rest_ensure_response( Cookie_Scanner::instance()->get_catalog_export() );
	}

	/**
	 * POST import Playwright privacy scan report.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_scan_import( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'ucpf_invalid', __( 'Invalid JSON body.', 'universal-consent-privacy-framework' ), array( 'status' => 400 ) );
		}
		// Allow { report: {...} } or raw report.
		$report   = isset( $body['report'] ) && is_array( $body['report'] ) ? $body['report'] : $body;
		$previous = Cookie_Scanner::instance()->get_last_scan();
		$result   = Privacy_Scan_Importer::import_report( $report );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( Settings::get( 'scheduled_scan_auto_apply', true ) ) {
			$result['safe_apply'] = Privacy_Scan_Importer::apply_safe_updates( $result, is_array( $previous ) ? $previous : array() );
		}
		return rest_ensure_response( $result );
	}

	/**
	 * POST start remote deep privacy scan.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_scan_deep( $request ) {
		$body    = $request->get_json_params();
		$body    = is_array( $body ) ? $body : array();
		$scanner = Cookie_Scanner::instance();

		// Force scan origin to same-site (home_url / site_url hosts only).
		$url = ! empty( $body['url'] ) ? esc_url_raw( (string) $body['url'] ) : '';
		if ( ! $url || ! $scanner->is_same_site_url( $url ) ) {
			$url = home_url( '/' );
		} else {
			$normalized = $scanner->normalize_scan_urls( array( $url ), 1 );
			$url        = ! empty( $normalized[0]['url'] ) ? $normalized[0]['url'] : home_url( '/' );
		}

		$paths = array();
		if ( ! empty( $body['paths'] ) && is_array( $body['paths'] ) ) {
			// Prefer explicit paths from the admin (most reliable).
			$paths = $body['paths'];
		}
		if ( ! $paths && ! empty( $body['urls'] ) && is_array( $body['urls'] ) ) {
			foreach ( $body['urls'] as $item ) {
				// Prefer explicit path from the admin picker (homepage often has no path in normalized URL).
				if ( is_array( $item ) && ! empty( $item['path'] ) && is_string( $item['path'] ) ) {
					$paths[] = $item['path'];
					continue;
				}
				$u = is_array( $item ) && ! empty( $item['url'] ) ? $item['url'] : (string) $item;
				// Relative path string.
				if ( is_string( $u ) && 0 === strpos( $u, '/' ) && ! preg_match( '#^[a-z][a-z0-9+.-]*:#i', $u ) && 0 !== strpos( $u, '//' ) ) {
					$paths[] = $u;
					continue;
				}
				$parsed = wp_parse_url( $u );
				if ( ! is_array( $parsed ) ) {
					continue;
				}
				// Absolute URLs must be same-site before we accept their path.
				if ( ! empty( $parsed['host'] ) ) {
					$abs = esc_url_raw( (string) $u );
					if ( ! $abs || ! $scanner->is_same_site_url( $abs ) ) {
						continue;
					}
					if ( ! empty( $parsed['path'] ) ) {
						$paths[] = $parsed['path'] . ( ! empty( $parsed['query'] ) ? '?' . $parsed['query'] : '' );
					} else {
						$paths[] = '/';
					}
					continue;
				}
				if ( ! empty( $parsed['path'] ) ) {
					$paths[] = $parsed['path'];
				}
			}
		}
		// Last resort: derive paths from normalized URL defs.
		if ( ! $paths && ! empty( $body['urls'] ) && is_array( $body['urls'] ) ) {
			foreach ( $scanner->normalize_scan_urls( $body['urls'], Cookie_Scanner::MAX_SERVER_URLS ) as $def ) {
				if ( empty( $def['url'] ) ) {
					continue;
				}
				$p = wp_parse_url( (string) $def['url'], PHP_URL_PATH );
				$paths[] = ( null === $p || '' === $p ) ? '/' : $p;
			}
		}
		$paths = $scanner->sanitize_scan_paths( $paths, Cookie_Scanner::MAX_PICKER_URLS );
		if ( ! $paths ) {
			$paths = array( '/' );
		}

		$options = array();
		if ( ! empty( $body['options'] ) && is_array( $body['options'] ) ) {
			$options = $body['options'];
		}
		if ( empty( $options['depth'] ) && ! empty( $body['depth'] ) ) {
			$options['depth'] = $body['depth'];
		}
		// Always honor the admin's full page list (never fall back to depth caps).
		$options['exactPaths'] = true;
		$options['maxPages']   = max(
			isset( $options['maxPages'] ) ? (int) $options['maxPages'] : 0,
			count( $paths ),
			1
		);

		if ( Active_Scan::instance()->is_active() ) {
			$active = Active_Scan::instance()->get();
			return new \WP_Error(
				'ucpf_scan_already_active',
				__( 'A Playwright scan is already running. Stop it before starting another, or wait for it to finish.', 'universal-consent-privacy-framework' ),
				array(
					'status' => 409,
					'job_id' => isset( $active['job_id'] ) ? $active['job_id'] : '',
					'active' => $active,
				)
			);
		}

		$result = Privacy_Scan_Importer::start_remote_scan( $url, $paths, $options );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$paths_sent   = count( $paths );
		$remote_count = is_array( $result ) ? Privacy_Scan_Importer::remote_paths_count( $result ) : 0;
		if ( $paths_sent > 1 && ( $remote_count < 1 || $remote_count < $paths_sent ) ) {
			if ( is_array( $result ) && ! empty( $result['id'] ) ) {
				Privacy_Scan_Importer::cancel_remote_scan( (string) $result['id'] );
			}
			return new \WP_Error(
				'ucpf_scanner_stale',
				Privacy_Scan_Importer::scanner_redeploy_message( $remote_count, $paths_sent ),
				array(
					'status'      => 409,
					'paths_sent'  => $paths_sent,
					'paths_count' => $remote_count,
				)
			);
		}

		if ( is_array( $result ) ) {
			$result['paths_sent'] = $paths_sent;
			$result['exactPaths'] = true;
		}

		$registered = Active_Scan::instance()->register(
			is_array( $result ) ? $result : array(),
			array(
				'url'             => $url,
				'paths'           => $paths,
				'depth'           => ! empty( $options['depth'] ) ? $options['depth'] : 'standard',
				'merge_logged_in' => ! empty( $body['merge_logged_in'] ) || ! empty( $options['merge_logged_in'] ),
			)
		);
		if ( is_wp_error( $registered ) ) {
			return $registered;
		}

		if ( is_array( $result ) ) {
			$result['persisted'] = true;
			$result['active']    = $registered;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * GET persisted interactive deep-scan job (reconnect after leaving Scanner).
	 *
	 * @return \WP_REST_Response
	 */
	public function get_scan_active() {
		return rest_ensure_response( Active_Scan::instance()->get_for_rest() );
	}

	/**
	 * GET remote deep scan job (poll) and auto-import when completed.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_scan_deep( $request ) {
		$id   = $request->get_param( 'id' );
		$job  = Privacy_Scan_Importer::get_remote_scan( $id );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		if ( is_array( $job ) ) {
			Active_Scan::instance()->sync_from_job( $job );
			$active_snap = Active_Scan::instance()->get();
			if ( ! empty( $active_snap['paths_sent'] ) ) {
				$job['paths_sent'] = (int) $active_snap['paths_sent'];
			}
			if ( isset( $job['paths_count'] ) ) {
				$job['paths_count'] = (int) $job['paths_count'];
			} elseif ( ! empty( $active_snap['paths_count'] ) ) {
				$job['paths_count'] = (int) $active_snap['paths_count'];
			}
			$sent         = ! empty( $job['paths_sent'] ) ? (int) $job['paths_sent'] : 0;
			$accepted     = isset( $job['paths_count'] ) ? (int) $job['paths_count'] : 0;
			$pages_total  = ( ! empty( $job['progress'] ) && is_array( $job['progress'] ) && isset( $job['progress']['pages_total'] ) )
				? (int) $job['progress']['pages_total']
				: 0;
			if ( $sent > 1 && ( ( $accepted > 0 && $accepted < $sent ) || 1 === $pages_total ) ) {
				$job['paths_mismatch'] = true;
			}
		}

		$auto_import = $request->get_param( 'import' );
		if ( is_array( $job ) && ! empty( $job['paths_mismatch'] ) ) {
			$auto_import = false;
		}
		$status      = isset( $job['status'] ) ? (string) $job['status'] : '';
		$importable  = in_array( $status, array( 'completed', 'cancelled' ), true );
		if ( ! empty( $job['report'] ) && ( null === $auto_import || $auto_import || '1' === $auto_import ) && $importable ) {
			$active = Active_Scan::instance()->get();
			$already = ! empty( $active['imported'] )
				&& ! empty( $active['job_id'] )
				&& (string) $active['job_id'] === (string) $id;

			if ( $already ) {
				$job['imported'] = true;
				$job['partial']  = ! empty( $active['partial'] ) || 'cancelled' === $status;
				if ( ! empty( $active['inventory'] ) ) {
					$job['inventory'] = $active['inventory'];
				}
			} else {
				$previous = Cookie_Scanner::instance()->get_last_scan();
				$imported = Privacy_Scan_Importer::import_report( $job['report'] );
				if ( ! is_wp_error( $imported ) ) {
					$job['imported'] = true;
					$job['partial']  = ! empty( $job['report']['partial'] ) || 'cancelled' === $status;
					$job['inventory'] = array(
						'cookies'         => isset( $imported['cookies'] ) ? count( $imported['cookies'] ) : 0,
						'unknown_cookies' => isset( $imported['unknown_cookies'] ) ? count( $imported['unknown_cookies'] ) : 0,
						'results'         => isset( $imported['results'] ) ? count( $imported['results'] ) : 0,
					);
					if ( Settings::get( 'scheduled_scan_auto_apply', true ) ) {
						$job['safe_apply'] = Privacy_Scan_Importer::apply_safe_updates( $imported, is_array( $previous ) ? $previous : array() );
					}
					Active_Scan::instance()->mark_finished(
						(string) $id,
						( 'cancelled' === $status ) ? 'cancelled' : 'completed',
						! empty( $job['partial'] )
							? __( 'Scan stopped. Partial Playwright results were imported.', 'universal-consent-privacy-framework' )
							: __( 'Playwright scan finished. Results were imported on this site.', 'universal-consent-privacy-framework' ),
						array(
							'imported'  => true,
							'partial'   => ! empty( $job['partial'] ),
							'inventory' => $job['inventory'],
							'progress'  => isset( $job['progress'] ) ? $job['progress'] : array(),
						)
					);
					if ( empty( $job['partial'] ) && 'cancelled' !== $status && ! empty( $active['merge_logged_in'] ) ) {
						$merged = Cookie_Scanner::instance()->merge_logged_in_homepage_into_last_scan();
						if ( ! is_wp_error( $merged ) && is_array( $merged ) ) {
							$job['inventory'] = array(
								'cookies'         => isset( $merged['cookies'] ) ? count( $merged['cookies'] ) : 0,
								'unknown_cookies' => isset( $merged['unknown_cookies'] ) ? count( $merged['unknown_cookies'] ) : 0,
								'results'         => isset( $merged['results'] ) ? count( $merged['results'] ) : 0,
							);
							$job['logged_in_merged'] = true;
							$fin = Active_Scan::instance()->get();
							$fin['inventory'] = $job['inventory'];
							$fin['message']   = __( 'Playwright scan finished. Logged-in helper cookies were merged into the inventory.', 'universal-consent-privacy-framework' );
							Active_Scan::instance()->set( $fin );
						}
					}
				} else {
					$job['import_error'] = $imported->get_error_message();
					Active_Scan::instance()->mark_finished( (string) $id, 'failed', $imported->get_error_message() );
				}
			}
		}

		return rest_ensure_response( $job );
	}

	/**
	 * POST start scheduled deep scan now (manual).
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_scan_scheduled() {
		$result = Scheduled_Scan::instance()->run_start( true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * POST scan.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_scan( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		$scanner = Cookie_Scanner::instance();
		$args    = array(
			'include_auth' => ! empty( $body['include_auth'] ),
			'live_cookies' => isset( $body['live_cookies'] ) && is_array( $body['live_cookies'] ) ? $body['live_cookies'] : array(),
			'limit'        => isset( $body['limit'] ) ? min( Cookie_Scanner::MAX_SERVER_URLS, (int) $body['limit'] ) : Cookie_Scanner::MAX_SERVER_URLS,
			'max_urls'     => Cookie_Scanner::MAX_SERVER_URLS,
		);

		if ( ! empty( $body['urls'] ) && is_array( $body['urls'] ) ) {
			$args['urls'] = $scanner->normalize_scan_urls( $body['urls'], Cookie_Scanner::MAX_SERVER_URLS );
		}

		$results = $scanner->scan( $args );
		if ( is_wp_error( $results ) ) {
			return $results;
		}
		return rest_ensure_response( $results );
	}

	/**
	 * POST live cookie capture names.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_cookie_capture( $request ) {
		$body    = $request->get_json_params();
		$cookies = isset( $body['cookies'] ) && is_array( $body['cookies'] ) ? $body['cookies'] : array();
		$cookies = array_values( array_filter( array_map( 'sanitize_text_field', $cookies ) ) );
		$context = isset( $body['context'] ) ? sanitize_key( $body['context'] ) : 'guest';
		if ( ! in_array( $context, array( 'guest', 'admin_tab', 'logged_in' ), true ) ) {
			$context = 'guest';
		}

		// Preserve prior server-scan results; capture only adds cookie names.
		$previous = Cookie_Scanner::instance()->get_last_scan();

		$capture = Cookie_Scanner::instance()->scan(
			array(
				'urls'         => array(),
				'live_cookies' => $cookies,
				'live_context' => $context,
				'live_only'    => true,
				'include_auth' => false,
				'skip_persist' => true,
			)
		);

		if ( is_wp_error( $capture ) ) {
			return $capture;
		}

		$base = ! empty( $previous['date'] ) ? $previous : $capture;

		$merged_cookies = array_merge(
			isset( $base['cookies'] ) ? $base['cookies'] : array(),
			isset( $capture['cookies'] ) ? $capture['cookies'] : array()
		);
		$merged_unknown = array_merge(
			isset( $base['unknown_cookies'] ) ? $base['unknown_cookies'] : array(),
			isset( $capture['unknown_cookies'] ) ? $capture['unknown_cookies'] : array()
		);
		$merged_results = array_merge(
			isset( $base['results'] ) ? $base['results'] : array(),
			isset( $capture['results'] ) ? $capture['results'] : array()
		);

		$seen_c      = array();
		$cookies_out = array();
		foreach ( $merged_cookies as $row ) {
			$k = strtolower( $row['name'] );
			if ( isset( $seen_c[ $k ] ) ) {
				continue;
			}
			$seen_c[ $k ]  = true;
			$cookies_out[] = $row;
		}
		$seen_u      = array();
		$unknown_out = array();
		foreach ( $merged_unknown as $row ) {
			$k = strtolower( $row['name'] );
			if ( isset( $seen_u[ $k ] ) ) {
				continue;
			}
			$seen_u[ $k ]  = true;
			$unknown_out[] = $row;
		}

		$detected = array();
		foreach ( $merged_results as $row ) {
			if ( ! empty( $row['service'] ) ) {
				$detected[ $row['service'] ] = true;
			}
		}
		foreach ( $cookies_out as $row ) {
			if ( ! empty( $row['service'] ) ) {
				$detected[ $row['service'] ] = true;
			}
		}

		$out = array(
			'date'              => current_time( 'mysql' ),
			'results'           => $merged_results,
			'cookies'           => $cookies_out,
			'unknown_cookies'   => $unknown_out,
			'detected_services' => array_keys( $detected ),
			'scanned_urls'      => isset( $base['scanned_urls'] ) ? $base['scanned_urls'] : 0,
		);

		$out = Cookie_Scanner::instance()->persist_scan_payload( $out );

		return rest_ensure_response( $out );
	}

	/**
	 * POST unknown cookie review.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_cookie_review( $request ) {
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();
		$name = isset( $body['name'] ) ? sanitize_text_field( $body['name'] ) : '';
		if ( ! $name ) {
			return new \WP_Error( 'ucpf_invalid', __( 'Cookie name required.', 'universal-consent-privacy-framework' ), array( 'status' => 400 ) );
		}
		$category = isset( $body['category'] ) ? sanitize_key( $body['category'] ) : '';
		if ( ! in_array( $category, Privacy_Scan_Importer::assignable_categories(), true ) ) {
			return new \WP_Error(
				'ucpf_category_required',
				__( 'Choose a category (Essential, Preferences, Analytics, Marketing, Embeds, or Security). Unclassified is not allowed.', 'universal-consent-privacy-framework' ),
				array( 'status' => 400 )
			);
		}
		$ok = Cookie_Scanner::instance()->review_unknown_cookie( $name, $body );
		if ( ! $ok ) {
			return new \WP_Error( 'ucpf_review_failed', __( 'Could not save cookie review.', 'universal-consent-privacy-framework' ), array( 'status' => 400 ) );
		}
		Cookie_Scanner::refresh_policy_pages_after_review();
		return rest_ensure_response( array( 'success' => true, 'name' => $name, 'category' => $category ) );
	}

	/**
	 * GET local cookie lookup (catalog + knowledge + OCD).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_cookie_lookup( $request ) {
		$q = $request->get_param( 'q' );
		$q = is_string( $q ) ? trim( $q ) : '';
		if ( strlen( $q ) < 2 ) {
			return new \WP_Error( 'ucpf_query_short', __( 'Enter at least 2 characters to search.', 'universal-consent-privacy-framework' ), array( 'status' => 400 ) );
		}
		$limit = (int) $request->get_param( 'limit' );
		if ( $limit < 1 ) {
			$limit = 25;
		}
		return rest_ensure_response( Script_Registry::instance()->lookup_cookie( $q, $limit ) );
	}

	/**
	 * GET site knowledge export pack (metadata only).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_knowledge_export( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return rest_ensure_response( Cookie_Knowledge::export_pack() );
	}

	/**
	 * GET scrubbed public contribution pack (no site URL; admin download only).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_knowledge_contribute( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return rest_ensure_response( Cookie_Knowledge::contribution_pack() );
	}

	/**
	 * POST merge a knowledge / registry pack into site knowledge.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_knowledge_import( $request ) {
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();
		if ( empty( $body['cookies'] ) && empty( $body['services'] ) ) {
			return new \WP_Error( 'ucpf_empty_pack', __( 'No cookies or services in knowledge pack.', 'universal-consent-privacy-framework' ), array( 'status' => 400 ) );
		}
		if ( ! empty( $body['schema'] ) ) {
			$check = Community_Registry::validate_catalog( $body );
			if ( is_wp_error( $check ) ) {
				// Allow sibling packs that only have cookies[].
				if ( empty( $body['cookies'] ) || ! is_array( $body['cookies'] ) ) {
					return $check;
				}
			}
		}
		$count = Cookie_Knowledge::import_pack( $body );
		return rest_ensure_response(
			array(
				'success' => true,
				'count'   => $count,
				'message' => sprintf(
					/* translators: %d: imported count */
					__( 'Imported %d knowledge cookie(s).', 'universal-consent-privacy-framework' ),
					$count
				),
			)
		);
	}

	/**
	 * POST batch cookie display overrides (known cookies labels / visibility).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_cookie_overrides( $request ) {
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();
		$map  = isset( $body['overrides'] ) && is_array( $body['overrides'] ) ? $body['overrides'] : $body;
		if ( ! is_array( $map ) || ! $map ) {
			return new \WP_Error( 'ucpf_invalid', __( 'No cookie overrides provided.', 'universal-consent-privacy-framework' ), array( 'status' => 400 ) );
		}
		$count = Cookie_Scanner::save_display_overrides_batch( $map );
		Cookie_Scanner::refresh_policy_pages_after_review();
		return rest_ensure_response(
			array(
				'success' => true,
				'count'   => $count,
			)
		);
	}

	/**
	 * POST service treatment override.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_service_override( $request ) {
		$body = $request->get_json_params();
		$key  = isset( $body['key'] ) ? sanitize_key( $body['key'] ) : '';
		if ( ! $key ) {
			return new \WP_Error( 'ucpf_invalid', __( 'Service key required.', 'universal-consent-privacy-framework' ), array( 'status' => 400 ) );
		}
		Script_Registry::instance()->save_override( $key, $body );
		Cookie_Scanner::refresh_policy_pages_after_review();
		return rest_ensure_response( array( 'success' => true, 'service' => Script_Registry::instance()->get_service( $key ) ) );
	}

	/**
	 * POST batch service treatment overrides (scanner cookie review).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_service_overrides( $request ) {
		$body      = $request->get_json_params();
		$body      = is_array( $body ) ? $body : array();
		$overrides = isset( $body['overrides'] ) && is_array( $body['overrides'] ) ? $body['overrides'] : array();
		if ( ! $overrides ) {
			return new \WP_Error( 'ucpf_invalid', __( 'No service overrides provided.', 'universal-consent-privacy-framework' ), array( 'status' => 400 ) );
		}

		$existing = Settings::get( 'service_overrides', array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$saved = array();
		foreach ( $overrides as $key => $row ) {
			$key = sanitize_key( is_string( $key ) ? $key : ( isset( $row['key'] ) ? $row['key'] : '' ) );
			if ( ! $key || ! is_array( $row ) ) {
				continue;
			}
			$existing[ $key ] = array(
				'category'         => isset( $row['category'] ) ? sanitize_key( $row['category'] ) : '',
				'treatment'        => isset( $row['treatment'] ) ? sanitize_key( $row['treatment'] ) : 'consent',
				'default_blocking' => true,
			);
			$saved[] = $key;
		}

		Settings::update( array( 'service_overrides' => $existing ) );
		Script_Registry::instance()->apply_site_overrides();

		// Ensure remediation targets are selected so the blocker/network gate can apply.
		$selected = Settings::get( 'selected_services', array() );
		if ( ! is_array( $selected ) ) {
			$selected = array();
		}
		$selected = array_values( array_unique( array_merge( array_map( 'sanitize_key', $selected ), $saved ) ) );
		Settings::update( array( 'selected_services' => $selected ) );

		Cookie_Scanner::refresh_policy_pages_after_review();

		return rest_ensure_response(
			array(
				'success' => true,
				'saved'   => $saved,
				'count'   => count( $saved ),
			)
		);
	}

	/**
	 * GET logs.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_logs( $request ) {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = max( 1, min( 200, (int) $request->get_param( 'per_page' ) ) );
		if ( $per_page < 1 ) {
			$per_page = 50;
		}
		$args = array(
			'view'      => (string) $request->get_param( 'view' ),
			'uuid'      => (string) $request->get_param( 'uuid' ),
			'action'    => (string) $request->get_param( 'action' ),
			'date_from' => (string) $request->get_param( 'date_from' ),
			'date_to'   => (string) $request->get_param( 'date_to' ),
		);
		return rest_ensure_response( Audit_Log::instance()->get_logs( $page, $per_page, $args ) );
	}

	/**
	 * POST generate pages.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_generate_pages( $request ) {
		$body      = $request->get_json_params();
		$overwrite = ! empty( $body['overwrite'] ) || (bool) $request->get_param( 'overwrite' );
		$page      = isset( $body['page'] ) ? sanitize_key( $body['page'] ) : '';

		if ( 'cookie_policy' === $page ) {
			$ok = Page_Generator::instance()->refresh_cookie_policy_page();
			Page_Generator::instance()->refresh_privacy_policy_page();
			return rest_ensure_response(
				array(
					'cookie_policy'  => $ok,
					'privacy_policy' => true,
					'refreshed'      => $ok,
				)
			);
		}

		if ( 'privacy_policy' === $page ) {
			$ok = Page_Generator::instance()->refresh_privacy_policy_page();
			return rest_ensure_response(
				array(
					'privacy_policy' => $ok,
					'refreshed'      => $ok,
				)
			);
		}

		$result = Page_Generator::instance()->generate_all( $overwrite );
		return rest_ensure_response( $result );
	}

	/**
	 * Apply recommended defaults (strict GDPR + local-first).
	 *
	 * @return \WP_REST_Response
	 */
	public function post_agency_preset() {
		$preset = Jurisdiction::apply_recommended_defaults();
		return rest_ensure_response(
			array(
				'success' => true,
				'preset'  => $preset,
				'message' => __( 'Recommended defaults applied: strict GDPR, GPC on, local catalog, network gate path intact. Review with legal counsel — not a compliance guarantee.', 'universal-consent-privacy-framework' ),
			)
		);
	}

	/**
	 * GET catalog suggestions from last scan.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_catalog_suggestions() {
		return rest_ensure_response(
			array(
				'suggestions'    => Catalog_Suggestions::compute(),
				'local_services' => Catalog_Suggestions::get_local_services(),
			)
		);
	}

	/**
	 * POST apply catalog suggestion (site-local only).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_catalog_suggestions_apply( $request ) {
		$body     = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}
		$pattern  = isset( $body['pattern'] ) ? (string) $body['pattern'] : '';
		$url      = isset( $body['url'] ) ? (string) $body['url'] : '';
		$host     = isset( $body['host'] ) ? (string) $body['host'] : '';
		$category = isset( $body['category'] ) ? (string) $body['category'] : '';
		$label    = isset( $body['label'] ) ? (string) $body['label'] : '';
		$action   = isset( $body['action'] ) ? sanitize_key( (string) $body['action'] ) : 'apply';

		if ( 'ignore' === $action ) {
			$needle = $pattern ? $pattern : Suspicion::suggest_pattern_from_url( $url ? $url : $host );
			Suspicion::ignore_pattern( $needle );
			return rest_ensure_response(
				array(
					'success' => true,
					'ignored' => $needle,
					'message' => __( 'Suspicion pattern ignored for this site (will not auto-gate).', 'universal-consent-privacy-framework' ),
				)
			);
		}

		if ( $pattern || $url ) {
			$needle = $pattern ? $pattern : Suspicion::suggest_pattern_from_url( $url );
			$result = Catalog_Suggestions::apply_path_pattern( $needle, $category ? $category : 'marketing', $label );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			self::mark_suspicious_script_resolved( $url ? $url : $needle );
			return rest_ensure_response(
				array(
					'success' => true,
					'service' => $result,
					'message' => __( 'Path pattern applied as a site-local gated service.', 'universal-consent-privacy-framework' ),
				)
			);
		}

		$result = Catalog_Suggestions::apply_host( $host, $category );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response(
			array(
				'success' => true,
				'service' => $result,
				'message' => __( 'Site-local catalog entry applied. Network gate will use this pattern. Merge into assets/vendor-catalog for fleet releases after review.', 'universal-consent-privacy-framework' ),
			)
		);
	}

	/**
	 * Remove a suspicious script from last-scan queue after apply.
	 *
	 * @param string $url_or_pattern URL or pattern.
	 */
	private static function mark_suspicious_script_resolved( $url_or_pattern ) {
		$needle = strtolower( (string) $url_or_pattern );
		if ( '' === $needle ) {
			return;
		}
		$scan = Cookie_Scanner::instance()->get_last_scan();
		if ( ! is_array( $scan ) || empty( $scan['suspicious_scripts'] ) || ! is_array( $scan['suspicious_scripts'] ) ) {
			return;
		}
		$kept = array();
		foreach ( $scan['suspicious_scripts'] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$url = strtolower( (string) ( isset( $row['url'] ) ? $row['url'] : '' ) );
			$pat = strtolower( (string) ( isset( $row['pattern'] ) ? $row['pattern'] : '' ) );
			if ( ( $url && false !== strpos( $url, $needle ) ) || ( $pat && false !== strpos( $needle, $pat ) ) || $pat === $needle ) {
				continue;
			}
			$kept[] = $row;
		}
		$scan['suspicious_scripts'] = $kept;
		Cookie_Scanner::instance()->persist_scan_payload( $scan );
	}

	/**
	 * DELETE site-local catalog service.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function delete_catalog_suggestion( $request ) {
		$key = isset( $request['key'] ) ? sanitize_key( $request['key'] ) : '';
		Catalog_Suggestions::remove_local( $key );
		return rest_ensure_response( array( 'success' => true, 'key' => $key ) );
	}

	/**
	 * GET or DELETE vendor suppress queue.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_vendor_suppress_queue( $request ) {
		if ( \WP_REST_Server::DELETABLE === $request->get_method() || 'DELETE' === $request->get_method() ) {
			$completed_only = (bool) $request->get_param( 'completed_only' );
			if ( $completed_only ) {
				Vendor_Connectors::clear_completed();
			} else {
				Vendor_Connectors::clear_all();
			}
			return rest_ensure_response(
				array(
					'success' => true,
					'jobs'    => Vendor_Connectors::list_jobs(),
				)
			);
		}
		$status = $request->get_param( 'status' );
		return rest_ensure_response(
			array(
				'jobs' => Vendor_Connectors::list_jobs( is_string( $status ) ? $status : '' ),
			)
		);
	}

	/**
	 * POST update vendor suppress queue item status.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_vendor_suppress_queue_item( $request ) {
		$index  = isset( $request['index'] ) ? (int) $request['index'] : -1;
		$body   = $request->get_json_params();
		$status = isset( $body['status'] ) ? sanitize_key( (string) $body['status'] ) : '';
		$ok     = Vendor_Connectors::update_job( $index, $status );
		if ( ! $ok ) {
			return new \WP_Error( 'ucpf_bad_job', __( 'Could not update suppress queue job.', 'universal-consent-privacy-framework' ), array( 'status' => 400 ) );
		}
		return rest_ensure_response(
			array(
				'success' => true,
				'jobs'    => Vendor_Connectors::list_jobs(),
			)
		);
	}

	/**
	 * POST force-refresh remote knowledge hub registry.
	 *
	 * @return \WP_REST_Response
	 */
	public function post_registry_refresh() {
		$result = Script_Registry::refresh_remote_registry();
		$status = Script_Registry::get_remote_registry_status();
		return rest_ensure_response(
			array(
				'success' => ! empty( $result['ok'] ),
				'message' => isset( $result['message'] ) ? $result['message'] : '',
				'status'  => $status,
				'mode'    => Community_Registry::mode(),
				'count'   => isset( $result['services'] ) && is_array( $result['services'] ) ? count( $result['services'] ) : 0,
			)
		);
	}

	/**
	 * GET remote registry sync status.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_registry_status() {
		return rest_ensure_response(
			array(
				'mode'    => Community_Registry::mode(),
				'allowed' => Community_Registry::remote_catalog_allowed(),
				'status'  => Script_Registry::get_remote_registry_status(),
			)
		);
	}

	/**
	 * POST registry import.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_registry_import( $request ) {
		$data = $request->get_json_params();
		if ( empty( $data['services'] ) || ! is_array( $data['services'] ) ) {
			return new \WP_Error( 'ucpf_invalid', __( 'Invalid registry JSON.', 'universal-consent-privacy-framework' ), array( 'status' => 400 ) );
		}
		$count = Script_Registry::instance()->import_services( $data['services'] );
		return rest_ensure_response( array( 'imported' => $count ) );
	}

	/**
	 * GET registry export.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_registry_export() {
		return rest_ensure_response( Script_Registry::instance()->export_services() );
	}

	/**
	 * GET banner theme pack (JSON).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_theme_export( $request ) {
		$name = $request instanceof \WP_REST_Request ? sanitize_text_field( (string) $request->get_param( 'name' ) ) : '';
		return rest_ensure_response( Theme_Manager::instance()->export_pack( $name ) );
	}

	/**
	 * POST import banner theme pack.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_theme_import( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'ucpf_invalid', __( 'Invalid JSON body.', 'universal-consent-privacy-framework' ), array( 'status' => 400 ) );
		}
		// Allow { pack: {...} } or raw pack.
		$pack = isset( $body['pack'] ) && is_array( $body['pack'] ) ? $body['pack'] : $body;
		$result = Theme_Manager::instance()->import_pack( $pack );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * Enable only site-selected / configured tracking services (not the full catalog).
	 * Storing every registry key in the consent cookie blows past browser/header limits
	 * and can destabilize the origin behind Cloudflare (502).
	 *
	 * @return array
	 */
	private function all_services_enabled() {
		$out = array();

		$selected = Settings::get( 'selected_services', array() );
		if ( is_array( $selected ) ) {
			foreach ( $selected as $key ) {
				$key = sanitize_key( $key );
				if ( $key ) {
					$out[ $key ] = true;
				}
			}
		}

		$service_ids = Settings::get( 'service_ids', array() );
		if ( is_array( $service_ids ) ) {
			foreach ( $service_ids as $key => $row ) {
				if ( ! empty( $row['enabled'] ) ) {
					$out[ sanitize_key( $key ) ] = true;
				}
			}
		}

		return $out;
	}

	/**
	 * Validate consent UUID; regenerate when missing or malformed.
	 *
	 * @param mixed $uuid Raw UUID.
	 * @return string
	 */
	private function sanitize_consent_uuid( $uuid ) {
		$uuid = is_string( $uuid ) ? trim( $uuid ) : '';
		if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid ) ) {
			return strtolower( $uuid );
		}
		return wp_generate_uuid4();
	}

	/**
	 * Simple rate limiting via transients.
	 *
	 * @param string $key    Key suffix.
	 * @param int    $limit  Max requests per minute.
	 * @return bool
	 */
	private function rate_limit( $key, $limit ) {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$hash = hash( 'sha256', $ip . wp_salt() );
		$tkey = 'ucpf_rl_' . $key . '_' . $hash;
		$count = (int) get_transient( $tkey );

		if ( $count >= $limit ) {
			return false;
		}

		set_transient( $tkey, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}
}
