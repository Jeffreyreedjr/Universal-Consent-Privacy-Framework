<?php
/**
 * Script and service registry.
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Script registry.
 */
class Script_Registry {

	/**
	 * Instance.
	 *
	 * @var Script_Registry|null
	 */
	private static $instance = null;

	/**
	 * In-memory services.
	 *
	 * @var array
	 */
	private $services = array();

	/**
	 * Get instance.
	 *
	 * @return Script_Registry
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Init registry.
	 */
	public function init() {
		$this->load_json_catalogs();
		$this->load_site_local_catalog();
		$this->maybe_load_remote_registry();
		$this->sync_db_to_memory();
		$this->apply_site_overrides();
		add_action( 'ucpf_loaded', array( $this, 'fire_loaded' ), 20 );
	}

	/**
	 * Load site-local catalog services (from scan suggestions). Never mutates bundled JSON.
	 */
	private function load_site_local_catalog() {
		foreach ( Catalog_Suggestions::get_local_services() as $service ) {
			if ( is_array( $service ) ) {
				$this->register_service( $service, 'site_local' );
			}
		}
	}

	/**
	 * Apply per-site category/treatment overrides from settings.
	 */
	public function apply_site_overrides() {
		$overrides = Settings::get( 'service_overrides', array() );
		if ( ! is_array( $overrides ) ) {
			return;
		}

		foreach ( $overrides as $key => $override ) {
			if ( empty( $this->services[ $key ] ) || ! is_array( $override ) ) {
				continue;
			}
			if ( ! empty( $override['category'] ) ) {
				$this->services[ $key ]['category'] = sanitize_key( $override['category'] );
			}
			if ( ! empty( $override['treatment'] ) ) {
				$this->services[ $key ]['treatment'] = sanitize_key( $override['treatment'] );
			}
			if ( isset( $override['default_blocking'] ) ) {
				$this->services[ $key ]['default_blocking'] = (bool) $override['default_blocking'];
			}
		}
	}

	/**
	 * Optionally fetch remote metadata registry (admin opt-in only).
	 *
	 * @param bool $force Bypass transient cache.
	 */
	private function maybe_load_remote_registry( $force = false ) {
		$result = self::sync_remote_registry( $force );
		if ( empty( $result['services'] ) || ! is_array( $result['services'] ) ) {
			return;
		}
		foreach ( $result['services'] as $service ) {
			$this->register_service( $service, 'remote_metadata' );
		}
	}

	/**
	 * Fetch / cache remote registry and record sync status for admin UI.
	 *
	 * @param bool $force Bypass cache.
	 * @return array{ok:bool,message:string,services?:array,cached?:bool}
	 */
	public static function sync_remote_registry( $force = false ) {
		$status = array(
			'ok'         => false,
			'at'         => gmdate( 'c' ),
			'message'    => '',
			'service_count' => 0,
			'url'        => '',
			'cached'     => false,
		);

		if ( ! Community_Registry::remote_catalog_allowed() ) {
			$status['message'] = __( 'Remote registry blocked: set Intelligence registry mode to Agency (or Community), enable remote sync, and paste a raw JSON URL.', 'universal-consent-privacy-framework' );
			update_option( 'ucpf_remote_registry_status', $status, false );
			return array( 'ok' => false, 'message' => $status['message'], 'services' => array() );
		}

		$url = (string) Settings::get( 'remote_registry_url' );
		$status['url'] = $url;
		if ( ! $url ) {
			$status['message'] = __( 'No remote registry URL configured.', 'universal-consent-privacy-framework' );
			update_option( 'ucpf_remote_registry_status', $status, false );
			return array( 'ok' => false, 'message' => $status['message'], 'services' => array() );
		}

		if ( ! $force ) {
			$cached = get_transient( 'ucpf_remote_registry' );
			if ( false !== $cached && is_array( $cached ) ) {
				$status['ok']            = true;
				$status['cached']        = true;
				$status['service_count'] = count( $cached );
				$status['message']       = __( 'Using cached remote registry (daily).', 'universal-consent-privacy-framework' );
				// Do not overwrite a prior successful fetch timestamp on cache hits.
				$prev = get_option( 'ucpf_remote_registry_status', array() );
				if ( is_array( $prev ) && ! empty( $prev['ok'] ) && ! empty( $prev['at'] ) ) {
					$status['at'] = $prev['at'];
				}
				update_option( 'ucpf_remote_registry_status', $status, false );
				return array(
					'ok'      => true,
					'message' => $status['message'],
					'services'=> $cached,
					'cached'  => true,
				);
			}
		} else {
			delete_transient( 'ucpf_remote_registry' );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			$status['message'] = sprintf(
				/* translators: %s: error message */
				__( 'Registry fetch failed: %s', 'universal-consent-privacy-framework' ),
				$response->get_error_message()
			);
			update_option( 'ucpf_remote_registry_status', $status, false );
			return array( 'ok' => false, 'message' => $status['message'], 'services' => array() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$body = json_decode( $raw, true );
		if ( $code >= 400 || ! is_array( $body ) ) {
			$status['message'] = sprintf(
				/* translators: %d: HTTP status */
				__( 'Registry URL returned HTTP %d or invalid JSON.', 'universal-consent-privacy-framework' ),
				$code
			);
			update_option( 'ucpf_remote_registry_status', $status, false );
			return array( 'ok' => false, 'message' => $status['message'], 'services' => array() );
		}

		if ( empty( $body['services'] ) || ! is_array( $body['services'] ) ) {
			$status['message'] = __( 'Registry JSON has no services[] array.', 'universal-consent-privacy-framework' );
			update_option( 'ucpf_remote_registry_status', $status, false );
			return array( 'ok' => false, 'message' => $status['message'], 'services' => array() );
		}

		$check = Community_Registry::validate_catalog(
			isset( $body['schema'] ) ? $body : array_merge( array( 'schema' => 'ucpf-registry-catalog/1.0' ), $body )
		);
		if ( is_wp_error( $check ) ) {
			$status['message'] = $check->get_error_message();
			update_option( 'ucpf_remote_registry_status', $status, false );
			return array( 'ok' => false, 'message' => $status['message'], 'services' => array() );
		}

		$services = array();
		foreach ( $body['services'] as $service ) {
			if ( ! is_array( $service ) ) {
				continue;
			}
			// Metadata only — never mark necessary from remote alone.
			if ( isset( $service['category'] ) && 'necessary' === $service['category'] ) {
				$service['category']  = 'preferences';
				$service['treatment'] = 'consent';
			}
			$services[] = $service;
		}

		set_transient( 'ucpf_remote_registry', $services, DAY_IN_SECONDS );
		$status['ok']            = true;
		$status['service_count'] = count( $services );
		$status['message']       = sprintf(
			/* translators: %d: service count */
			__( 'Remote registry synced (%d services).', 'universal-consent-privacy-framework' ),
			count( $services )
		);
		update_option( 'ucpf_remote_registry_status', $status, false );

		return array(
			'ok'       => true,
			'message'  => $status['message'],
			'services' => $services,
			'cached'   => false,
		);
	}

	/**
	 * Last remote registry sync status for admin UI.
	 *
	 * @return array
	 */
	public static function get_remote_registry_status() {
		$raw = get_option( 'ucpf_remote_registry_status', array() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Force refresh remote registry (admin).
	 *
	 * @return array
	 */
	public static function refresh_remote_registry() {
		return self::sync_remote_registry( true );
	}

	/**
	 * Fire after developers register services.
	 */
	public function fire_loaded() {
		$this->services = apply_filters( 'ucpf_service_registry', $this->services );
	}

	/**
	 * Load JSON vendor catalogs.
	 */
	private function load_json_catalogs() {
		$dir = UCPF_PLUGIN_DIR . 'assets/vendor-catalog/';
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$files = glob( $dir . '*.json' );
		foreach ( $files as $file ) {
			// plugin-map.json is detection metadata, not a service catalog.
			if ( 'plugin-map.json' === basename( $file ) ) {
				continue;
			}
			$json = file_get_contents( $file );
			$data = json_decode( $json, true );
			if ( ! empty( $data['services'] ) && is_array( $data['services'] ) ) {
				foreach ( $data['services'] as $service ) {
					$this->register_service( $service, 'core' );
				}
			}
		}
	}

	/**
	 * Sync DB registry entries.
	 */
	private function sync_db_to_memory() {
		global $wpdb;

		$table_name = ucpf_table( 'script_registry' );
		$table      = esc_sql( $table_name );
		if ( '' === $table || '' === $table_name ) {
			return;
		}

		// Skip quietly if schema is not ready yet (before migration/activation).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- existence check; table from whitelist.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) );
		if ( $exists !== $table_name ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from esc_sql( ucpf_table() ) whitelist.
		$rows = $wpdb->get_results( "SELECT * FROM `{$table}`", ARRAY_A );

		if ( ! $rows ) {
			return;
		}

		foreach ( $rows as $row ) {
			$key      = isset( $row['service_key'] ) ? (string) $row['service_key'] : '';
			$normalized = $this->normalize_service( $row );
			// Union JSON catalog path needles so DB rows never drop first-party coverage.
			if ( $key && isset( $this->services[ $key ] ) && is_array( $this->services[ $key ] ) ) {
				$existing = $this->services[ $key ];
				foreach ( array( 'script_patterns', 'iframe_patterns', 'cookie_patterns' ) as $field ) {
					$a = isset( $existing[ $field ] ) && is_array( $existing[ $field ] ) ? $existing[ $field ] : array();
					$b = isset( $normalized[ $field ] ) && is_array( $normalized[ $field ] ) ? $normalized[ $field ] : array();
					$normalized[ $field ] = array_values( array_unique( array_filter( array_merge( $a, $b ) ) ) );
				}
			}
			$this->services[ $key ? $key : $normalized['key'] ] = $normalized;
		}
	}

	/**
	 * Register a service.
	 *
	 * @param array  $args   Service args.
	 * @param string $source Source type.
	 * @return bool|\WP_Error
	 */
	public function register_service( array $args, $source = 'admin' ) {
		$validated = $this->validate_service( $args );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$validated['source'] = $source;
		$key                 = $validated['key'];
		$this->services[ $key ] = $validated;

		/**
		 * Fires when a service is registered.
		 *
		 * @param array $validated Service data.
		 */
		do_action( 'ucpf_service_registered', $validated );

		return true;
	}

	/**
	 * Validate service definition.
	 *
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public function validate_service( array $args ) {
		if ( empty( $args['key'] ) ) {
			return new \WP_Error( 'ucpf_missing_key', __( 'Service key is required.', 'universal-consent-privacy-framework' ) );
		}

		$key = sanitize_key( $args['key'] );

		$categories = array_keys( Consent_Manager::instance()->get_categories() );
		$category   = isset( $args['category'] ) ? sanitize_key( $args['category'] ) : 'analytics';

		if ( ! in_array( $category, $categories, true ) ) {
			return new \WP_Error( 'ucpf_invalid_category', __( 'Invalid service category.', 'universal-consent-privacy-framework' ) );
		}

		$cookies = $this->normalize_cookies( isset( $args['cookies'] ) ? (array) $args['cookies'] : array(), $category );

		$cookie_patterns = isset( $args['cookie_patterns'] ) ? array_map( 'sanitize_text_field', (array) $args['cookie_patterns'] ) : array();
		if ( empty( $cookie_patterns ) && $cookies ) {
			$cookie_patterns = array_values( array_filter( wp_list_pluck( $cookies, 'pattern' ) ) );
		}

		$treatment = isset( $args['treatment'] ) ? sanitize_key( $args['treatment'] ) : '';
		if ( ! in_array( $treatment, array( 'necessary', 'consent', 'ignore' ), true ) ) {
			$treatment = ( 'necessary' === $category ) ? 'necessary' : 'consent';
		}

		return array(
			'key'                         => $key,
			'name'                        => isset( $args['name'] ) ? sanitize_text_field( $args['name'] ) : $key,
			'provider'                    => isset( $args['provider'] ) ? sanitize_text_field( $args['provider'] ) : '',
			'category'                    => $category,
			'treatment'                   => $treatment,
			'description'                 => isset( $args['description'] ) ? sanitize_textarea_field( $args['description'] ) : '',
			'privacy_url'                 => isset( $args['privacy_url'] ) ? esc_url_raw( $args['privacy_url'] ) : '',
			'script_patterns'             => isset( $args['script_patterns'] ) ? array_map( 'sanitize_text_field', (array) $args['script_patterns'] ) : array(),
			'cookie_patterns'             => $cookie_patterns,
			'cookies'                     => $cookies,
			'iframe_patterns'             => isset( $args['iframe_patterns'] ) ? array_map( 'sanitize_text_field', (array) $args['iframe_patterns'] ) : array(),
			'default_blocking'            => ! isset( $args['default_blocking'] ) || $args['default_blocking'],
			'supports_google_consent_mode'=> ! empty( $args['supports_google_consent_mode'] ),
			'google_consent_mapping'      => isset( $args['google_consent_mapping'] ) ? $args['google_consent_mapping'] : array(),
			// Never persist string callables from JSON/import (RCE). Closures from PHP only.
			'loader'                      => ( isset( $args['loader'] ) && $args['loader'] instanceof \Closure ) ? $args['loader'] : null,
			'template'                    => isset( $args['template'] ) ? $args['template'] : '',
		);
	}

	/**
	 * Normalize cookie definitions.
	 *
	 * @param array  $cookies  Cookie rows.
	 * @param string $category Fallback category.
	 * @return array
	 */
	private function normalize_cookies( array $cookies, $category ) {
		$out = array();
		foreach ( $cookies as $cookie ) {
			if ( ! is_array( $cookie ) ) {
				continue;
			}
			$name = isset( $cookie['name'] ) ? sanitize_text_field( $cookie['name'] ) : '';
			if ( ! $name ) {
				continue;
			}
			$cookie_category = isset( $cookie['category'] ) ? sanitize_key( $cookie['category'] ) : $category;
			$treatment       = isset( $cookie['treatment'] ) ? sanitize_key( $cookie['treatment'] ) : '';
			if ( ! in_array( $treatment, array( 'necessary', 'consent', 'ignore' ), true ) ) {
				$treatment = ( 'necessary' === $cookie_category ) ? 'necessary' : 'consent';
			}
			$out[] = array(
				'name'      => $name,
				'pattern'   => isset( $cookie['pattern'] ) ? sanitize_text_field( $cookie['pattern'] ) : $name,
				'purpose'   => isset( $cookie['purpose'] ) ? sanitize_text_field( $cookie['purpose'] ) : '',
				'retention' => isset( $cookie['retention'] ) ? sanitize_text_field( $cookie['retention'] ) : '',
				'category'  => $cookie_category,
				'treatment' => $treatment,
				'contexts'  => isset( $cookie['contexts'] ) ? array_map( 'sanitize_key', (array) $cookie['contexts'] ) : array(),
			);
		}
		return $out;
	}

	/**
	 * Normalize DB row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private function normalize_service( array $row ) {
		$cookie_patterns = json_decode( $row['cookie_patterns'], true );
		if ( ! is_array( $cookie_patterns ) ) {
			$cookie_patterns = array();
		}

		return array(
			'key'              => $row['service_key'],
			'name'             => $row['service_name'],
			'provider'         => $row['provider'],
			'category'         => $row['category'],
			'treatment'        => ( 'necessary' === $row['category'] ) ? 'necessary' : 'consent',
			'description'      => $row['description'],
			'privacy_url'      => $row['privacy_url'],
			'script_patterns'  => json_decode( $row['script_patterns'], true ) ?: array(),
			'cookie_patterns'  => $cookie_patterns,
			'cookies'          => array(),
			'iframe_patterns'  => json_decode( $row['iframe_patterns'], true ) ?: array(),
			'default_blocking' => (bool) $row['default_enabled'],
			'source'           => $row['source'],
		);
	}

	/**
	 * Get all services.
	 *
	 * @return array
	 */
	public function get_services() {
		return $this->services;
	}

	/**
	 * Get single service.
	 *
	 * @param string $key Service key.
	 * @return array|null
	 */
	public function get_service( $key ) {
		return isset( $this->services[ $key ] ) ? $this->services[ $key ] : null;
	}

	/**
	 * Services for JS (no callbacks).
	 *
	 * @return array
	 */
	public function get_services_for_js() {
		$out = array();
		foreach ( $this->services as $key => $service ) {
			$out[ $key ] = array(
				'key'      => $service['key'],
				'name'     => $service['name'],
				'category' => $service['category'],
				'provider' => isset( $service['provider'] ) ? $service['provider'] : '',
			);
		}
		return $out;
	}

	/**
	 * Import services to DB.
	 *
	 * @param array $services Services.
	 * @return int Count imported.
	 */
	public function import_services( array $services ) {
		global $wpdb;

		$table = ucpf_table( 'script_registry' );
		if ( '' === $table ) {
			return 0;
		}
		$count = 0;

		foreach ( $services as $service ) {
			$validated = $this->validate_service( $service );
			if ( is_wp_error( $validated ) ) {
				continue;
			}

			// JSON / DB import must never carry executable loaders.
			$validated['loader'] = null;

			$this->register_service( $validated, 'imported' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- registry import write.
			$wpdb->replace(
				$table,
				array(
					'service_key'     => $validated['key'],
					'service_name'    => $validated['name'],
					'provider'        => $validated['provider'],
					'category'        => $validated['category'],
					'description'     => $validated['description'],
					'privacy_url'     => $validated['privacy_url'],
					'cookie_patterns' => wp_json_encode( $validated['cookie_patterns'] ),
					'script_patterns' => wp_json_encode( $validated['script_patterns'] ),
					'iframe_patterns' => wp_json_encode( $validated['iframe_patterns'] ),
					'default_enabled' => $validated['default_blocking'] ? 1 : 0,
					'source'          => 'imported',
					'created_at'      => current_time( 'mysql', true ),
					'updated_at'      => current_time( 'mysql', true ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
			);
			++$count;
		}

		return $count;
	}

	/**
	 * Export services.
	 *
	 * @return array
	 */
	public function export_services() {
		return array(
			'version'  => UCPF_VERSION,
			'services' => array_values( $this->services ),
		);
	}

	/**
	 * Get all script patterns for scanner/blocker.
	 *
	 * @return array
	 */
	public function get_all_patterns() {
		$patterns = array();
		foreach ( $this->services as $key => $service ) {
			foreach ( (array) $service['script_patterns'] as $pattern ) {
				$patterns[] = array(
					'service'    => $key,
					'pattern'    => $pattern,
					'category'   => $service['category'],
					'treatment'  => isset( $service['treatment'] ) ? $service['treatment'] : 'consent',
					'confidence' => 'high',
				);
			}
		}
		return apply_filters( 'ucpf_detected_script_patterns', $patterns );
	}

	/**
	 * Flat list of cookies across services (for policy table / scanner).
	 *
	 * @return array
	 */
	public function get_all_cookies() {
		$cookies = array();
		foreach ( $this->services as $key => $service ) {
			$list = ! empty( $service['cookies'] ) ? $service['cookies'] : array();
			// Always merge cookie_patterns not already covered by explicit cookies[].
			// Otherwise patterns like sbjs_* / sbjs_migrations are ignored when a few named cookies exist.
			if ( ! empty( $service['cookie_patterns'] ) ) {
				foreach ( (array) $service['cookie_patterns'] as $pattern ) {
					$pattern = (string) $pattern;
					if ( '' === $pattern ) {
						continue;
					}
					$covered = false;
					foreach ( $list as $cookie ) {
						if ( ! is_array( $cookie ) ) {
							continue;
						}
						$cname = isset( $cookie['name'] ) ? (string) $cookie['name'] : '';
						$cpat  = isset( $cookie['pattern'] ) ? (string) $cookie['pattern'] : $cname;
						if ( $cname === $pattern || $cpat === $pattern ) {
							$covered = true;
							break;
						}
					}
					if ( $covered ) {
						continue;
					}
					$list[] = array(
						'name'      => $pattern,
						'pattern'   => $pattern,
						'purpose'   => isset( $service['description'] ) ? $service['description'] : '',
						'retention' => '',
						'category'  => $service['category'],
						'treatment' => isset( $service['treatment'] ) ? $service['treatment'] : 'consent',
						'contexts'  => array(),
					);
				}
			}
			foreach ( $list as $cookie ) {
				$cookie['service']      = $key;
				$cookie['service_name'] = $service['name'];
				$cookie['provider']     = isset( $service['provider'] ) ? $service['provider'] : '';
				$cookies[]              = $cookie;
			}
		}
		return apply_filters( 'ucpf_cookie_inventory', $cookies );
	}

	/**
	 * Match a cookie name against catalog patterns, then Open Cookie Database.
	 *
	 * UCPF service catalog always wins. OCD fills empty purpose/retention/provider
	 * on catalog hits, or returns a synthetic match (source=open_cookie_database)
	 * when the catalog has no hit.
	 *
	 * Short / ambiguous patterns (e.g. Magnite `c`) require a matching cookie domain.
	 *
	 * @param string $cookie_name Cookie name.
	 * @param string $domain      Optional Set-Cookie domain / host for disambiguation.
	 * @return array|null
	 */
	public function match_cookie_name( $cookie_name, $domain = '' ) {
		$cookie_name = (string) $cookie_name;
		$domain      = (string) $domain;
		$ucpf        = null;
		foreach ( $this->get_all_cookies() as $cookie ) {
			$pattern = isset( $cookie['pattern'] ) ? $cookie['pattern'] : $cookie['name'];
			if ( ! $this->cookie_name_matches( $cookie_name, $pattern ) ) {
				continue;
			}
			if ( $this->pattern_needs_host_context( $pattern ) ) {
				$svc = ! empty( $cookie['service'] ) ? $this->get_service( $cookie['service'] ) : null;
				if ( ! $this->cookie_domain_matches_service( $domain, $svc ) ) {
					continue;
				}
			}
			$ucpf = $cookie;
			break;
		}

		$knowledge = Cookie_Knowledge::match_cookie( $cookie_name );
		$ocd       = Cookie_Database::instance()->match( $cookie_name );

		if ( $ucpf ) {
			$ucpf['source'] = 'ucpf';
			$fill           = $knowledge ? $knowledge : $ocd;
			if ( $fill ) {
				if ( empty( $ucpf['purpose'] ) && ! empty( $fill['purpose'] ) ) {
					$ucpf['purpose']            = $fill['purpose'];
					$ucpf['description_source'] = isset( $fill['description_source'] ) ? $fill['description_source'] : 'knowledge';
				}
				if ( empty( $ucpf['retention'] ) && ! empty( $fill['retention'] ) ) {
					$ucpf['retention'] = $fill['retention'];
				}
				if ( empty( $ucpf['provider'] ) && ! empty( $fill['provider'] ) ) {
					$ucpf['provider'] = $fill['provider'];
				}
			}
			return $ucpf;
		}

		if ( $knowledge ) {
			if ( $ocd ) {
				if ( empty( $knowledge['purpose'] ) && ! empty( $ocd['purpose'] ) ) {
					$knowledge['purpose']            = $ocd['purpose'];
					$knowledge['description_source'] = 'open_cookie_database';
				}
				if ( empty( $knowledge['retention'] ) && ! empty( $ocd['retention'] ) ) {
					$knowledge['retention'] = $ocd['retention'];
				}
			}
			return $knowledge;
		}

		return $ocd;
	}

	/**
	 * Whether a cookie pattern is too short to trust without host context.
	 *
	 * @param string $pattern Pattern.
	 * @return bool
	 */
	public function pattern_needs_host_context( $pattern ) {
		$base = str_replace( '*', '', (string) $pattern );
		$min  = (int) apply_filters( 'ucpf_cookie_pattern_host_context_max_len', 2 );
		return strlen( $base ) <= max( 1, $min );
	}

	/**
	 * Whether a cookie domain belongs to a service (script/iframe host patterns).
	 *
	 * @param string     $domain  Cookie domain.
	 * @param array|null $service Service definition.
	 * @return bool
	 */
	public function cookie_domain_matches_service( $domain, $service ) {
		if ( ! is_array( $service ) ) {
			return false;
		}
		$domain = strtolower( ltrim( preg_replace( '/:\d+$/', '', (string) $domain ), '.' ) );
		if ( '' === $domain || false === strpos( $domain, '.' ) ) {
			return false;
		}
		$needles = array_merge(
			isset( $service['script_patterns'] ) ? (array) $service['script_patterns'] : array(),
			isset( $service['iframe_patterns'] ) ? (array) $service['iframe_patterns'] : array()
		);
		foreach ( $needles as $needle ) {
			$needle = strtolower( (string) $needle );
			if ( '' === $needle || false === strpos( $needle, '.' ) ) {
				continue;
			}
			// Host-like pattern (ignore paths / filenames).
			$host = preg_replace( '#^https?://#', '', $needle );
			$host = explode( '/', $host )[0];
			$host = ltrim( $host, '.' );
			if ( strlen( $host ) < 4 || false === strpos( $host, '.' ) ) {
				continue;
			}
			if ( $domain === $host || substr( $domain, -strlen( '.' . $host ) ) === '.' . $host || false !== strpos( $domain, $host ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Admin cookie lookup: catalog → knowledge → OCD (search + exact).
	 *
	 * @param string $query Query string.
	 * @param int    $limit Max hits.
	 * @return array{query:string,results:array[]}
	 */
	public function lookup_cookie( $query, $limit = 25 ) {
		$query = trim( (string) $query );
		$limit = max( 1, min( 50, (int) $limit ) );
		$out   = array();
		$seen  = array();

		$add = static function ( $row, $confidence ) use ( &$out, &$seen, $limit ) {
			if ( ! is_array( $row ) || empty( $row['name'] ) || count( $out ) >= $limit ) {
				return;
			}
			$key = strtolower( (string) $row['name'] ) . '|' . ( isset( $row['source'] ) ? $row['source'] : '' );
			if ( isset( $seen[ $key ] ) ) {
				return;
			}
			$seen[ $key ]     = true;
			$row['confidence'] = $confidence;
			$out[]            = $row;
		};

		if ( strlen( $query ) >= 2 ) {
			foreach ( $this->get_all_cookies() as $cookie ) {
				$name    = isset( $cookie['name'] ) ? (string) $cookie['name'] : '';
				$pattern = isset( $cookie['pattern'] ) ? (string) $cookie['pattern'] : $name;
				if ( '' === $name ) {
					continue;
				}
				if ( false === stripos( $name, $query ) && false === stripos( $pattern, $query ) && ! $this->cookie_name_matches( $query, $pattern ) ) {
					continue;
				}
				$row           = $cookie;
				$row['source'] = 'ucpf';
				$svc           = ! empty( $cookie['service'] ) ? $this->get_service( $cookie['service'] ) : null;
				if ( $svc && empty( $row['provider'] ) ) {
					$row['provider'] = ! empty( $svc['provider'] ) ? $svc['provider'] : $svc['name'];
				}
				if ( $svc && empty( $row['service_name'] ) ) {
					$row['service_name'] = $svc['name'];
				}
				$add( $row, 'high' );
			}

			foreach ( Cookie_Knowledge::search( $query, $limit ) as $row ) {
				$add( $row, 'high' );
			}

			foreach ( Cookie_Database::instance()->search( $query, $limit ) as $row ) {
				$add( $row, 'medium' );
			}
		}

		// Exact match path when query looks like a full cookie name.
		if ( strlen( $query ) >= 2 && preg_match( '/^[A-Za-z0-9_.\-*]+$/', $query ) ) {
			$exact = $this->match_cookie_name( $query );
			if ( $exact ) {
				$add( $exact, 'high' );
			}
		}

		return array(
			'query'   => $query,
			'results' => array_slice( $out, 0, $limit ),
			'note'    => __( 'Local lookup only (vendor catalog, site knowledge, Open Cookie Database). Not a legal determination. Does not call cookiedatabase.org.', 'universal-consent-privacy-framework' ),
		);
	}

	/**
	 * Wildcard cookie name match (* → .* ).
	 *
	 * @param string $name    Cookie name.
	 * @param string $pattern Pattern.
	 * @return bool
	 */
	public function cookie_name_matches( $name, $pattern ) {
		$regex = '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/i';
		return (bool) preg_match( $regex, $name );
	}

	/**
	 * Whether a service should be blocked pending consent.
	 *
	 * @param string|array $service Service key or definition.
	 * @return bool
	 */
	public function should_block_service( $service ) {
		if ( is_string( $service ) ) {
			$service = $this->get_service( $service );
		}
		if ( ! is_array( $service ) ) {
			return true;
		}
		$treatment = isset( $service['treatment'] ) ? $service['treatment'] : 'consent';
		if ( 'necessary' === $treatment || 'ignore' === $treatment || 'necessary' === $service['category'] ) {
			return false;
		}
		if ( empty( $service['default_blocking'] ) ) {
			return false;
		}
		return ! Consent_Manager::instance()->has_consent( $service['category'] );
	}

	/**
	 * Save site override for a service.
	 *
	 * @param string $key      Service key.
	 * @param array  $override Override fields.
	 * @return bool
	 */
	public function save_override( $key, array $override ) {
		$key       = sanitize_key( $key );
		$overrides = Settings::get( 'service_overrides', array() );
		if ( ! is_array( $overrides ) ) {
			$overrides = array();
		}
		$overrides[ $key ] = array(
			'category'         => isset( $override['category'] ) ? sanitize_key( $override['category'] ) : '',
			'treatment'        => isset( $override['treatment'] ) ? sanitize_key( $override['treatment'] ) : '',
			'default_blocking' => ! isset( $override['default_blocking'] ) || (bool) $override['default_blocking'],
		);
		Settings::update( array( 'service_overrides' => $overrides ) );
		$this->apply_site_overrides();
		return true;
	}
}
