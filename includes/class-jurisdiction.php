<?php
/**
 * Jurisdiction packs — data-driven consent model, copy, and signal rules.
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Resolve and expose jurisdiction packs (local JSON, no phone-home).
 */
class Jurisdiction {

	/**
	 * Instance.
	 *
	 * @var Jurisdiction|null
	 */
	private static $instance = null;

	/**
	 * Loaded packs keyed by id.
	 *
	 * @var array<string, array>
	 */
	private $packs = array();

	/**
	 * Resolved pack for this request.
	 *
	 * @var array|null
	 */
	private $resolved = null;

	/**
	 * Get instance.
	 *
	 * @return Jurisdiction
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Init — load packs; auto-enable geo when Cloudflare is detected.
	 */
	public function init() {
		$this->load_packs();
		$this->maybe_auto_enable_geo_for_cloudflare();
	}

	/**
	 * Load JSON packs from assets/jurisdiction-packs/.
	 */
	private function load_packs() {
		$dir = UCPF_PLUGIN_DIR . 'assets/jurisdiction-packs/';
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$files = glob( $dir . '*.json' );
		if ( ! is_array( $files ) ) {
			return;
		}
		foreach ( $files as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$json = file_get_contents( $file );
			$data = json_decode( (string) $json, true );
			if ( empty( $data['id'] ) || ! is_array( $data ) ) {
				continue;
			}
			$id                 = sanitize_key( $data['id'] );
			$this->packs[ $id ] = $this->normalize_pack( $data );
		}
	}

	/**
	 * Normalize pack fields.
	 *
	 * @param array $data Raw pack.
	 * @return array
	 */
	private function normalize_pack( array $data ) {
		$defaults = $this->fallback_pack();
		$pack     = array_merge( $defaults, $data );
		$pack['id']                    = sanitize_key( (string) $pack['id'] );
		$pack['label']                 = isset( $pack['label'] ) ? sanitize_text_field( (string) $pack['label'] ) : $pack['id'];
		$pack['consent_model']         = in_array( $pack['consent_model'], array( 'optin', 'optout' ), true ) ? $pack['consent_model'] : 'optin';
		$pack['require_reject_parity'] = ! empty( $pack['require_reject_parity'] );
		$pack['esc_behavior']          = 'reject';
		$pack['gpc_enforcement']       = in_array( $pack['gpc_enforcement'] ?? '', array( 'nonessential', 'sale_share' ), true )
			? $pack['gpc_enforcement']
			: 'nonessential';
		$pack['dns_required']          = ! empty( $pack['dns_required'] );
		$pack['privacy_choices_link']  = ! empty( $pack['privacy_choices_link'] );
		$pack['show_limit_sensitive']  = ! empty( $pack['show_limit_sensitive'] );
		$pack['regions']               = isset( $pack['regions'] ) && is_array( $pack['regions'] )
			? array_values( array_map( 'strtoupper', array_map( 'strval', $pack['regions'] ) ) )
			: array();
		$pack['copy']                  = array_merge( $defaults['copy'], isset( $pack['copy'] ) && is_array( $pack['copy'] ) ? $pack['copy'] : array() );
		$pack['category_defaults']     = array_merge(
			$defaults['category_defaults'],
			isset( $pack['category_defaults'] ) && is_array( $pack['category_defaults'] ) ? $pack['category_defaults'] : array()
		);
		$pack['signals']               = array_merge(
			$defaults['signals'],
			isset( $pack['signals'] ) && is_array( $pack['signals'] ) ? $pack['signals'] : array()
		);
		return $pack;
	}

	/**
	 * Fallback pack when JSON missing.
	 *
	 * @return array
	 */
	private function fallback_pack() {
		return array(
			'id'                    => 'strict_gdpr',
			'label'                 => 'EU / UK GDPR & ePrivacy (strict)',
			'regions'               => array(),
			'consent_model'         => 'optin',
			'require_reject_parity' => true,
			'esc_behavior'          => 'reject',
			'gpc_enforcement'       => 'nonessential',
			'dns_required'          => false,
			'privacy_choices_link'  => false,
			'show_limit_sensitive'  => false,
			'category_defaults'     => array(
				'necessary'   => true,
				'preferences' => false,
				'analytics'   => false,
				'marketing'   => false,
				'functional'  => false,
				'security'    => true,
			),
			'signals'               => array(
				'respect_gpc' => true,
				'respect_dnt' => true,
			),
			'copy'                  => array(
				'banner_title'          => 'Cookies',
				'banner_text'           => 'We use essential cookies for security and optional cookies based on your choices. You can withdraw or manage consent later via Cookie Settings.',
				'prefs_title'           => 'Cookie Preferences',
				'prefs_intro'           => 'Choose which optional cookie categories to allow. Essential cookies stay on.',
				'fab_label'             => 'Cookie Settings',
				'privacy_choices_label' => 'Your Privacy Choices',
				'dns_title'             => 'Do Not Sell My Personal Information',
				'dns_intro'             => 'Submit a request to opt out of the sale of personal information where applicable.',
			),
		);
	}

	/**
	 * All loaded packs.
	 *
	 * @return array<string, array>
	 */
	public function get_packs() {
		return $this->packs;
	}

	/**
	 * Pack ids for admin selects.
	 *
	 * @return string[]
	 */
	public function get_pack_ids() {
		if ( empty( $this->packs ) ) {
			$this->load_packs();
		}
		$ids = array_keys( $this->packs );
		if ( empty( $ids ) ) {
			return array( 'strict_gdpr', 'us_baseline', 'global_balanced', 'custom' );
		}
		return $ids;
	}

	/**
	 * Detect visitor region (Cloudflare CF-IPCountry + filter). No GeoIP SaaS.
	 *
	 * @return string Uppercase country / region code or empty.
	 */
	public function detect_visitor_region() {
		$region = '';
		if ( ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			$region = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) );
		}
		/**
		 * Filter detected visitor region for jurisdiction packs.
		 *
		 * Agencies may inject US-CA / CA-QC from their own edge. Bare "CA" from
		 * Cloudflare means Canada — never California.
		 *
		 * @param string $region Country/region code.
		 */
		return strtoupper( (string) apply_filters( 'ucpf_visitor_region', $region ) );
	}

	/**
	 * Whether geo-based pack switching is enabled.
	 *
	 * When Cloudflare edge signals are present, geo routing is treated as on
	 * (and persisted) so US/EEA packs apply without a manual Advanced toggle.
	 *
	 * @return bool
	 */
	public function geo_routing_enabled() {
		if ( (bool) Settings::get( 'geo_jurisdiction_routing', false ) ) {
			return true;
		}
		if ( $this->maybe_auto_enable_geo_for_cloudflare() ) {
			return true;
		}
		return false;
	}

	/**
	 * Lightweight Cloudflare edge signal (no NS lookup).
	 *
	 * @return bool
	 */
	public function cloudflare_edge_signal_present() {
		$headers = array(
			'HTTP_CF_IPCOUNTRY',
			'HTTP_CF_RAY',
			'HTTP_CF_CONNECTING_IP',
			'HTTP_CF_VISITOR',
		);
		foreach ( $headers as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				return true;
			}
		}
		/**
		 * Filter whether Cloudflare edge is considered present for auto geo routing.
		 *
		 * @param bool $present Present.
		 */
		return (bool) apply_filters( 'ucpf_cloudflare_edge_signal_present', false );
	}

	/**
	 * Turn on geo_jurisdiction_routing when Cloudflare is detected.
	 *
	 * @return bool True when geo should be treated as enabled for this request.
	 */
	public function maybe_auto_enable_geo_for_cloudflare() {
		if ( (bool) Settings::get( 'geo_jurisdiction_routing', false ) ) {
			return true;
		}

		/**
		 * Filter whether to auto-enable geo pack routing when Cloudflare is detected.
		 *
		 * @param bool $auto Auto-enable (default true).
		 */
		if ( ! (bool) apply_filters( 'ucpf_auto_enable_geo_on_cloudflare', true ) ) {
			return false;
		}

		if ( ! $this->cloudflare_detected_for_geo() ) {
			return false;
		}

		// Persist setting; throttle option writes on busy front-ends.
		if ( ! get_transient( 'ucpf_geo_cf_write' ) ) {
			Settings::update( array( 'geo_jurisdiction_routing' => true ) );
			set_transient( 'ucpf_geo_cf_write', 1, HOUR_IN_SECONDS );
		}

		return true;
	}

	/**
	 * Whether Cloudflare is known for this site (edge headers, live detect, or last scan).
	 *
	 * @return bool
	 */
	public function cloudflare_detected_for_geo() {
		if ( $this->cloudflare_edge_signal_present() ) {
			return true;
		}

		if ( class_exists( __NAMESPACE__ . '\\Cookie_Scanner' ) ) {
			if ( is_admin() ) {
				$cf = Cookie_Scanner::instance()->detect_cloudflare_proxy();
				if ( ! empty( $cf['proxied'] ) ) {
					return true;
				}
			}
			$scan = Cookie_Scanner::instance()->get_last_scan();
			if ( ! empty( $scan['cloudflare_proxied'] ) ) {
				return true;
			}
			if ( ! empty( $scan['detected_services'] ) && is_array( $scan['detected_services'] )
				&& in_array( 'cloudflare', $scan['detected_services'], true ) ) {
				return true;
			}
		}

		/**
		 * Filter Cloudflare detection for geo auto-enable.
		 *
		 * @param bool $detected Detected.
		 */
		return (bool) apply_filters( 'ucpf_cloudflare_detected_for_geo', false );
	}

	/**
	 * Whether a region code is treated as unknown / untrusted for geo routing.
	 *
	 * @param string $region Region.
	 * @return bool
	 */
	public function is_unknown_region( $region ) {
		$region = strtoupper( trim( (string) $region ) );
		return ( '' === $region || 'XX' === $region || 'T1' === $region );
	}

	/**
	 * Resolve active pack for this request.
	 *
	 * Geo matrix (when geo_jurisdiction_routing is on):
	 * - US → us_baseline (all US states; no IP-state switching)
	 * - EEA / UK / CH (and pack region list) → strict_gdpr
	 * - BR → br_lgpd
	 * - Explicit subregions from filter (US-CA, CA-QC) → matching pack when present
	 * - Unknown / Tor / missing CF header → strict_gdpr (fail closed)
	 * - Other known countries → keep admin default pack
	 *
	 * @return array
	 */
	public function resolve() {
		if ( null !== $this->resolved ) {
			return $this->resolved;
		}

		$mode    = sanitize_key( (string) Settings::get( 'compliance_mode', 'strict_gdpr' ) );
		$pack_id = $mode;

		if ( $this->geo_routing_enabled() ) {
			$region = $this->detect_visitor_region();
			if ( $this->is_unknown_region( $region ) ) {
				$pack_id = 'strict_gdpr';
			} else {
				$geo = $this->pack_id_for_region( $region );
				if ( $geo ) {
					$pack_id = $geo;
				}
			}
		}

		/**
		 * Filter resolved jurisdiction pack id.
		 *
		 * @param string $pack_id Pack id.
		 * @param string $mode    Admin compliance_mode.
		 */
		$pack_id = sanitize_key( (string) apply_filters( 'ucpf_jurisdiction_pack_id', $pack_id, $mode ) );

		if ( isset( $this->packs[ $pack_id ] ) ) {
			$this->resolved = $this->packs[ $pack_id ];
		} elseif ( isset( $this->packs['strict_gdpr'] ) ) {
			$this->resolved = $this->packs['strict_gdpr'];
		} else {
			$this->resolved = $this->fallback_pack();
		}

		// Admin gpc_enforcement setting overrides pack when explicitly set and mode is custom.
		if ( 'custom' === $mode ) {
			$gpc = Settings::get( 'gpc_enforcement' );
			if ( in_array( $gpc, array( 'nonessential', 'sale_share' ), true ) ) {
				$this->resolved['gpc_enforcement'] = $gpc;
			}
		}

		return $this->resolved;
	}

	/**
	 * Find pack id for a visitor region (country or subregion code).
	 *
	 * @param string $region Region code.
	 * @return string|null Pack id or null to keep admin default.
	 */
	private function pack_id_for_region( $region ) {
		$region = strtoupper( trim( (string) $region ) );
		if ( $this->is_unknown_region( $region ) ) {
			return null;
		}

		/*
		 * Country-level Cloudflare codes (primary path).
		 * Entire US uses us_baseline — covers comprehensive state privacy laws
		 * without unreliable IP-state detection.
		 */
		if ( 'US' === $region ) {
			return isset( $this->packs['us_baseline'] ) ? 'us_baseline' : null;
		}
		if ( 'BR' === $region ) {
			return isset( $this->packs['br_lgpd'] ) ? 'br_lgpd' : null;
		}

		// Subregion from agency filter (US-CA, CA-QC, …) — never bare ISO "CA" as California.
		if ( false !== strpos( $region, '-' ) ) {
			$sub = $this->exact_region_pack( $region );
			if ( $sub ) {
				return $sub;
			}
			if ( 0 === strpos( $region, 'US-' ) && isset( $this->packs['us_baseline'] ) ) {
				return 'us_baseline';
			}
		}

		// EEA / UK / CH etc. from strict_gdpr regions list.
		if ( isset( $this->packs['strict_gdpr'] ) ) {
			$eu_regions = isset( $this->packs['strict_gdpr']['regions'] ) ? $this->packs['strict_gdpr']['regions'] : array();
			foreach ( $eu_regions as $code ) {
				if ( strtoupper( (string) $code ) === $region ) {
					return 'strict_gdpr';
				}
			}
		}

		// Other exact region matches (exclude ambiguous 2-letter codes on US state packs).
		$exact = $this->exact_region_pack( $region, true );
		if ( $exact ) {
			return $exact;
		}

		return null;
	}

	/**
	 * Exact region → pack match.
	 *
	 * @param string $region            Region code.
	 * @param bool   $skip_us_state_iso When true, ignore US_* packs that only list US-XX (already handled) and skip mapping bare codes onto us_* packs except us_baseline for US.
	 * @return string|null
	 */
	private function exact_region_pack( $region, $skip_us_state_iso = false ) {
		$region     = strtoupper( trim( (string) $region ) );
		$candidates = array();

		foreach ( $this->packs as $id => $pack ) {
			if ( $skip_us_state_iso && 0 === strpos( $id, 'us_' ) && 'us_baseline' !== $id ) {
				// State packs must use US-XX only; never match bare country codes here.
				$regions = isset( $pack['regions'] ) ? $pack['regions'] : array();
				$ok      = false;
				foreach ( $regions as $code ) {
					if ( strtoupper( (string) $code ) === $region && false !== strpos( (string) $code, '-' ) ) {
						$ok = true;
						break;
					}
				}
				if ( ! $ok ) {
					continue;
				}
			}

			$regions = isset( $pack['regions'] ) ? $pack['regions'] : array();
			foreach ( $regions as $code ) {
				$code = strtoupper( (string) $code );
				if ( $code === $region ) {
					// Cloudflare "CA" is Canada — never treat as California.
					if ( 'CA' === $region && 0 === strpos( $id, 'us_' ) ) {
						continue;
					}
					$candidates[ $id ] = strlen( $code );
				}
			}
		}

		if ( empty( $candidates ) ) {
			return null;
		}
		arsort( $candidates );
		return (string) array_key_first( $candidates );
	}

	/**
	 * Consent type for WP Consent API.
	 *
	 * @return string optin|optout
	 */
	public function get_consent_type() {
		$pack = $this->resolve();
		return ! empty( $pack['consent_model'] ) && 'optout' === $pack['consent_model'] ? 'optout' : 'optin';
	}

	/**
	 * Copy string from active pack.
	 *
	 * @param string $key Copy key.
	 * @return string
	 */
	public function get_copy( $key ) {
		$pack = $this->resolve();
		$copy = isset( $pack['copy'] ) && is_array( $pack['copy'] ) ? $pack['copy'] : array();
		return isset( $copy[ $key ] ) ? (string) $copy[ $key ] : '';
	}

	/**
	 * Payload for frontend ucpfConfig.
	 *
	 * @return array
	 */
	public function get_config_for_js() {
		$pack = $this->resolve();
		$dns  = Page_Generator::instance()->get_rights_url( 'do_not_sell' );
		$data = Page_Generator::instance()->get_rights_url( 'data_request' );
		return array(
			'packId'              => $pack['id'],
			'consentType'         => $this->get_consent_type(),
			'requireRejectParity' => ! empty( $pack['require_reject_parity'] ),
			'escBehavior'         => $pack['esc_behavior'],
			'gpcEnforcement'      => $pack['gpc_enforcement'],
			'dnsRequired'         => ! empty( $pack['dns_required'] ),
			'privacyChoicesLink'  => ! empty( $pack['privacy_choices_link'] ),
			'showLimitSensitive'  => ! empty( $pack['show_limit_sensitive'] ),
			'doNotSellUrl'        => $dns ? $dns : '',
			'dataRequestUrl'      => $data ? $data : '',
			'copy'                => isset( $pack['copy'] ) ? $pack['copy'] : array(),
			'visitorRegion'       => $this->detect_visitor_region(),
			'geoRouting'          => $this->geo_routing_enabled(),
		);
	}

	/**
	 * Apply recommended defaults (strict GDPR + GPC nonessential + local-first).
	 *
	 * @return array Updated settings subset.
	 */
	public static function apply_agency_preset() {
		return self::apply_recommended_defaults();
	}

	/**
	 * Apply recommended defaults (strict GDPR + GPC nonessential + local-first).
	 *
	 * @return array Updated settings subset.
	 */
	public static function apply_recommended_defaults() {
		$preset = array(
			'compliance_mode'                       => 'strict_gdpr',
			'geo_jurisdiction_routing'              => true,
			'show_reject_all'                       => true,
			'show_accept_all'                       => true,
			'show_customize'                        => true,
			'banner_enabled'                        => true,
			'blocker_enabled'                       => true,
			'respect_dnt_gpc'                       => true,
			'gpc_enforcement'                       => 'nonessential',
			'google_consent_mode'                   => 'basic',
			'remote_registry_enabled'               => false,
			'registry_mode'                         => 'local',
			'privacy_api_url'                       => '',
			'enable_data_request_forms'             => false,
			'auto_refresh_cookie_policy_after_scan' => true,
			'consent_logging'                       => true,
			'output_buffer_blocking'                => false,
			'show_powered_by'                       => false,
			'agency_preset_applied'                 => true,
		);
		Settings::update( $preset );
		return $preset;
	}
}
