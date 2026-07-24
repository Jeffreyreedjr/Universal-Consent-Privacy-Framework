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
	 * Init — load packs.
	 */
	public function init() {
		$this->load_packs();
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
	 * Normalize pack shape.
	 *
	 * @param array $data Raw pack.
	 * @return array
	 */
	private function normalize_pack( array $data ) {
		$defaults = $this->fallback_pack();
		$pack     = array_merge( $defaults, $data );
		$pack['id']                   = sanitize_key( (string) $pack['id'] );
		$pack['consent_model']        = in_array( $pack['consent_model'], array( 'optin', 'optout' ), true ) ? $pack['consent_model'] : 'optin';
		$pack['require_reject_parity'] = ! empty( $pack['require_reject_parity'] );
		$pack['esc_behavior']         = 'reject' === ( $pack['esc_behavior'] ?? 'reject' ) ? 'reject' : 'reject';
		$pack['gpc_enforcement']      = in_array( $pack['gpc_enforcement'] ?? '', array( 'nonessential', 'sale_share' ), true )
			? $pack['gpc_enforcement']
			: 'nonessential';
		$pack['dns_required']         = ! empty( $pack['dns_required'] );
		$pack['privacy_choices_link'] = ! empty( $pack['privacy_choices_link'] );
		$pack['show_limit_sensitive'] = ! empty( $pack['show_limit_sensitive'] );
		$pack['regions']              = isset( $pack['regions'] ) && is_array( $pack['regions'] ) ? $pack['regions'] : array();
		$pack['copy']                 = array_merge( $defaults['copy'], isset( $pack['copy'] ) && is_array( $pack['copy'] ) ? $pack['copy'] : array() );
		$pack['category_defaults']    = array_merge(
			$defaults['category_defaults'],
			isset( $pack['category_defaults'] ) && is_array( $pack['category_defaults'] ) ? $pack['category_defaults'] : array()
		);
		$pack['signals'] = array_merge(
			$defaults['signals'],
			isset( $pack['signals'] ) && is_array( $pack['signals'] ) ? $pack['signals'] : array()
		);
		return $pack;
	}

	/**
	 * Fallback when packs missing.
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
				'banner_title'           => 'Cookies',
				'banner_text'            => 'We use essential cookies for security and optional cookies based on your choices. This plugin helps support privacy compliance; review with legal counsel.',
				'prefs_title'            => 'Cookie Preferences',
				'prefs_intro'            => 'Choose which optional cookie categories to allow. Essential cookies stay on.',
				'fab_label'              => 'Cookie Settings',
				'privacy_choices_label'  => 'Your Privacy Choices',
				'dns_title'              => 'Do Not Sell My Personal Information',
				'dns_intro'              => 'Submit a request to opt out of the sale of personal information where applicable.',
			),
		);
	}

	/**
	 * All packs.
	 *
	 * @return array<string, array>
	 */
	public function get_packs() {
		return $this->packs;
	}

	/**
	 * Pack ids valid for settings.
	 *
	 * @return string[]
	 */
	public function get_pack_ids() {
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
		 * @param string $region Country/region code.
		 */
		return strtoupper( (string) apply_filters( 'ucpf_visitor_region', $region ) );
	}

	/**
	 * Whether geo-based pack switching is enabled.
	 *
	 * @return bool
	 */
	public function geo_routing_enabled() {
		return (bool) Settings::get( 'geo_jurisdiction_routing', false );
	}

	/**
	 * Resolve active pack for this request.
	 *
	 * @return array
	 */
	public function resolve() {
		if ( null !== $this->resolved ) {
			return $this->resolved;
		}

		$mode = sanitize_key( (string) Settings::get( 'compliance_mode', 'strict_gdpr' ) );
		// Map legacy/admin modes that are also pack ids.
		$pack_id = $mode;
		if ( 'us_baseline' === $mode && $this->geo_routing_enabled() ) {
			$region = $this->detect_visitor_region();
			$geo    = $this->pack_id_for_region( $region );
			if ( $geo ) {
				$pack_id = $geo;
			}
		} elseif ( $this->geo_routing_enabled() && in_array( $mode, array( 'global_balanced', 'custom' ), true ) ) {
			$region = $this->detect_visitor_region();
			$geo    = $this->pack_id_for_region( $region );
			if ( $geo ) {
				$pack_id = $geo;
			}
		} elseif ( $this->geo_routing_enabled() && 'strict_gdpr' === $mode ) {
			// Keep GDPR default unless visitor clearly maps to another pack and admin opted into geo.
			$region = $this->detect_visitor_region();
			$geo    = $this->pack_id_for_region( $region );
			// Only switch away from GDPR when geo finds US/BR/etc. pack.
			if ( $geo && 'strict_gdpr' !== $geo ) {
				$pack_id = $geo;
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
	 * Find pack id whose regions list contains the visitor region.
	 *
	 * @param string $region Region code.
	 * @return string|null
	 */
	private function pack_id_for_region( $region ) {
		$region = strtoupper( trim( (string) $region ) );
		if ( '' === $region || 'XX' === $region || 'T1' === $region ) {
			return null;
		}

		// Exact region match (US-CA, CA-QC, BR, GB, …). Prefer longest code.
		$candidates = array();
		foreach ( $this->packs as $id => $pack ) {
			$regions = isset( $pack['regions'] ) ? $pack['regions'] : array();
			foreach ( $regions as $code ) {
				$code = strtoupper( (string) $code );
				if ( $code === $region ) {
					$candidates[ $id ] = strlen( $code );
				}
			}
		}
		if ( ! empty( $candidates ) ) {
			arsort( $candidates );
			return (string) array_key_first( $candidates );
		}

		// Country-level fallbacks (Cloudflare typically sends ISO country only).
		if ( 'US' === $region && isset( $this->packs['us_baseline'] ) ) {
			return 'us_baseline';
		}
		if ( 'BR' === $region && isset( $this->packs['br_lgpd'] ) ) {
			return 'br_lgpd';
		}
		// "CA" from Cloudflare is Canada, not California — use Quebec only when CA-QC is provided.
		$eu_packs = array( 'strict_gdpr' );
		foreach ( $eu_packs as $eid ) {
			if ( ! isset( $this->packs[ $eid ] ) ) {
				continue;
			}
			$regions = isset( $this->packs[ $eid ]['regions'] ) ? $this->packs[ $eid ]['regions'] : array();
			foreach ( $regions as $code ) {
				if ( strtoupper( (string) $code ) === $region ) {
					return $eid;
				}
			}
		}

		return null;
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
			'packId'               => $pack['id'],
			'consentType'          => $this->get_consent_type(),
			'requireRejectParity'  => ! empty( $pack['require_reject_parity'] ),
			'escBehavior'          => $pack['esc_behavior'],
			'gpcEnforcement'       => $pack['gpc_enforcement'],
			'dnsRequired'          => ! empty( $pack['dns_required'] ),
			'privacyChoicesLink'   => ! empty( $pack['privacy_choices_link'] ),
			'showLimitSensitive'   => ! empty( $pack['show_limit_sensitive'] ),
			'doNotSellUrl'         => $dns ? $dns : '',
			'dataRequestUrl'       => $data ? $data : '',
			'copy'                 => isset( $pack['copy'] ) ? $pack['copy'] : array(),
			'visitorRegion'        => $this->detect_visitor_region(),
			'geoRouting'           => $this->geo_routing_enabled(),
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
			'compliance_mode'         => 'strict_gdpr',
			'geo_jurisdiction_routing'=> false,
			'show_reject_all'         => true,
			'show_accept_all'         => true,
			'show_customize'          => true,
			'banner_enabled'          => true,
			'blocker_enabled'         => true,
			'respect_dnt_gpc'         => true,
			'gpc_enforcement'         => 'nonessential',
			'google_consent_mode'     => 'basic',
			'remote_registry_enabled' => false,
			'registry_mode'           => 'local',
			'privacy_api_url'         => '',
			'enable_data_request_forms' => true,
			'auto_refresh_cookie_policy_after_scan' => true,
			'consent_logging'         => true,
			'output_buffer_blocking'  => false,
			'show_powered_by'         => false,
			'agency_preset_applied'   => true,
		);
		Settings::update( $preset );
		return $preset;
	}
}
