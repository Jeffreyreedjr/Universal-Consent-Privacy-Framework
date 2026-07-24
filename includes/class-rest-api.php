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

		if ( 'accept_all' === $action ) {
			$result = $manager->save_consent(
				array(
					'state'      => 'accepted_all',
					'categories' => $manager->default_categories_accepted(),
					'services'   => array(),
					'uuid'       => isset( $body['uuid'] ) ? $body['uuid'] : '',
				),
				'accept_all'
			);
		} elseif ( 'reject_all' === $action ) {
			$result = $manager->save_consent(
				array(
					'state'      => 'rejected_all',
					'categories' => $manager->default_categories_rejected(),
					'services'   => array(),
					'uuid'       => isset( $body['uuid'] ) ? $body['uuid'] : '',
				),
				'reject_all'
			);
		} else {
			$result = $manager->save_consent( $body, $action );
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
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_withdraw() {
		$result = Consent_Manager::instance()->withdraw_consent();
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
		$scanner = Cookie_Scanner::instance();
		$depth   = 'standard';
		if ( $request instanceof \WP_REST_Request ) {
			$depth = sanitize_key( (string) $request->get_param( 'depth' ) );
		}
		if ( ! in_array( $depth, array( 'quick', 'standard', 'deep' ), true ) ) {
			$depth = 'standard';
		}
		$limit = $scanner->depth_limit( $depth );
		$urls  = $scanner->discover_site_paths( $depth );
		return rest_ensure_response(
			array(
				'urls'       => $urls,
				'available'  => $urls,
				'chips'      => $scanner->get_scan_chips(),
				'woo_active' => $scanner->is_woo_active(),
				'home_url'   => untrailingslashit( home_url( '/' ) ),
				'max_crawl'  => min( Cookie_Scanner::MAX_BROWSER_URLS, $limit ),
				'max_server' => min( Cookie_Scanner::MAX_SERVER_URLS, $limit ),
				'depth'      => $depth,
				'depth_limit'=> $limit,
				'presets'    => array(
					'quick'    => Cookie_Scanner::DEPTH_QUICK,
					'standard' => Cookie_Scanner::DEPTH_STANDARD,
					'deep'     => min( Cookie_Scanner::DEPTH_DEEP, Cookie_Scanner::MAX_PICKER_URLS ),
				),
				'count'      => count( $urls ),
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
	public function post_scan_cancel() {
		return rest_ensure_response( Cookie_Scanner::instance()->cancel_scan() );
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
		$body  = $request->get_json_params();
		$body  = is_array( $body ) ? $body : array();
		$url   = ! empty( $body['url'] ) ? esc_url_raw( $body['url'] ) : home_url( '/' );
		$paths = array();
		if ( ! empty( $body['urls'] ) && is_array( $body['urls'] ) ) {
			foreach ( $body['urls'] as $item ) {
				$u = is_array( $item ) && ! empty( $item['url'] ) ? $item['url'] : (string) $item;
				$parsed = wp_parse_url( $u );
				if ( ! empty( $parsed['path'] ) ) {
					$paths[] = $parsed['path'];
				} elseif ( is_string( $u ) && 0 === strpos( $u, '/' ) ) {
					$paths[] = $u;
				}
			}
		} elseif ( ! empty( $body['paths'] ) && is_array( $body['paths'] ) ) {
			$paths = $body['paths'];
		}
		if ( ! $paths ) {
			$paths = array( '/' );
		}

		$result = Privacy_Scan_Importer::start_remote_scan( $url, $paths );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
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

		$auto_import = $request->get_param( 'import' );
		if ( ! empty( $job['report'] ) && ( null === $auto_import || $auto_import || '1' === $auto_import ) && 'completed' === ( $job['status'] ?? '' ) ) {
			$previous = Cookie_Scanner::instance()->get_last_scan();
			$imported = Privacy_Scan_Importer::import_report( $job['report'] );
			if ( ! is_wp_error( $imported ) ) {
				$job['imported'] = true;
				$job['inventory'] = array(
					'cookies'         => isset( $imported['cookies'] ) ? count( $imported['cookies'] ) : 0,
					'unknown_cookies' => isset( $imported['unknown_cookies'] ) ? count( $imported['unknown_cookies'] ) : 0,
					'results'         => isset( $imported['results'] ) ? count( $imported['results'] ) : 0,
				);
				if ( Settings::get( 'scheduled_scan_auto_apply', true ) ) {
					$job['safe_apply'] = Privacy_Scan_Importer::apply_safe_updates( $imported, is_array( $previous ) ? $previous : array() );
				}
			} else {
				$job['import_error'] = $imported->get_error_message();
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
		$page = max( 1, (int) $request->get_param( 'page' ) );
		return rest_ensure_response( Audit_Log::instance()->get_logs( $page ) );
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
		$host     = isset( $body['host'] ) ? (string) $body['host'] : '';
		$category = isset( $body['category'] ) ? (string) $body['category'] : '';
		$result   = Catalog_Suggestions::apply_host( $host, $category );
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
