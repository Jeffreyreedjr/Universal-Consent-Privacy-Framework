<?php
/**
 * Managed tracking tag definitions (IDs / snippets UCPF can inject after consent).
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Tracking templates helper.
 */
class Tracking_Templates {

	/** @var string Option key for scan-suggested GTM container IDs. */
	const GTM_SUGGESTIONS_OPTION = 'ucpf_gtm_scan_suggestions';

	/** @var int Max GTM containers per site. */
	const GTM_MAX_CONTAINERS = 20;

	/**
	 * Templates available for enable + ID/code configuration.
	 *
	 * @return array
	 */
	public static function all() {
		$id_note = __( 'Used only to load this tag after consent. Not listed as a cookie name on the Cookie Policy (cookie families are documented generically).', 'universal-consent-privacy-framework' );

		return array(
			'google_analytics_4' => array(
				'label'           => __( 'Google Analytics 4', 'universal-consent-privacy-framework' ),
				'id_label'        => __( 'Measurement ID', 'universal-consent-privacy-framework' ),
				'placeholder'     => 'G-XXXXXXXXXX',
				'help'            => __( 'From GA4 Admin → Data streams. Example: G-ABC123XYZ.', 'universal-consent-privacy-framework' ) . ' ' . $id_note,
				'tag_id_label'    => __( 'Google Tag ID', 'universal-consent-privacy-framework' ),
				'tag_placeholder' => 'GT-XXXXXXXX',
				'tag_help'        => __( 'Optional Google Tag ID (GT-…) from Google Tag / Site Kit. Loaded together with the Measurement ID.', 'universal-consent-privacy-framework' ) . ' ' . $id_note,
				'category'        => 'analytics',
			),
			'google_tag_manager' => array(
				'label'            => __( 'Google Tag Manager', 'universal-consent-privacy-framework' ),
				'id_label'         => __( 'Container / Tag ID', 'universal-consent-privacy-framework' ),
				'placeholder'      => 'GTM-XXXXXXX or GT-XXXXXXXX',
				'help'             => __( 'Add each Google container or tag ID to load after consent: GTM-… (Tag Manager container) and/or GT-… (Google Tag). Duplicate IDs are saved once. Put Google Ads conversion IDs (AW-…) under Google Ads (Marketing), not here.', 'universal-consent-privacy-framework' ) . ' ' . $id_note,
				'category'         => 'analytics',
				'multi_containers' => true,
			),
			'google_ads'         => array(
				'label'       => __( 'Google Ads', 'universal-consent-privacy-framework' ),
				'id_label'    => __( 'Conversion ID', 'universal-consent-privacy-framework' ),
				'placeholder' => 'AW-XXXXXXXXXX',
				'help'        => __( 'Google Ads conversion / remarketing ID (AW-…). Loads only after Marketing consent — not Analytics. Use for PPC, YouTube, and Performance Max tags managed here.', 'universal-consent-privacy-framework' ) . ' ' . $id_note,
				'category'    => 'marketing',
			),
			'meta_pixel'         => array(
				'label'       => __( 'Meta Pixel', 'universal-consent-privacy-framework' ),
				'id_label'    => __( 'Pixel ID', 'universal-consent-privacy-framework' ),
				'placeholder' => '123456789012345',
				'help'        => __( 'Numeric Pixel ID from Meta Events Manager.', 'universal-consent-privacy-framework' ) . ' ' . $id_note,
				'category'    => 'marketing',
			),
			'microsoft_clarity'  => array(
				'label'       => __( 'Microsoft Clarity', 'universal-consent-privacy-framework' ),
				'id_label'    => __( 'Project ID', 'universal-consent-privacy-framework' ),
				'placeholder' => 'abcdefghij',
				'help'        => __( 'Clarity project ID from clarity.microsoft.com.', 'universal-consent-privacy-framework' ) . ' ' . $id_note,
				'category'    => 'analytics',
			),
			'hotjar'             => array(
				'label'       => __( 'Hotjar', 'universal-consent-privacy-framework' ),
				'id_label'    => __( 'Site ID', 'universal-consent-privacy-framework' ),
				'placeholder' => '1234567',
				'help'        => __( 'Hotjar Site ID (numbers only).', 'universal-consent-privacy-framework' ) . ' ' . $id_note,
				'category'    => 'analytics',
			),
			'tiktok_pixel'       => array(
				'label'       => __( 'TikTok Pixel', 'universal-consent-privacy-framework' ),
				'id_label'    => __( 'Pixel ID', 'universal-consent-privacy-framework' ),
				'placeholder' => 'CXXXXXXXXXXXXXXX',
				'help'        => __( 'TikTok Ads Manager pixel ID.', 'universal-consent-privacy-framework' ) . ' ' . $id_note,
				'category'    => 'marketing',
			),
			'linkedin_insight'   => array(
				'label'       => __( 'LinkedIn Insight Tag', 'universal-consent-privacy-framework' ),
				'id_label'    => __( 'Partner ID', 'universal-consent-privacy-framework' ),
				'placeholder' => '123456',
				'help'        => __( 'LinkedIn Insight Tag partner ID.', 'universal-consent-privacy-framework' ) . ' ' . $id_note,
				'category'    => 'marketing',
			),
		);
	}

	/**
	 * Extract GTM IDs from a persisted scan payload (scripts, HTML, JSON blob).
	 *
	 * @param array $payload Scan payload.
	 * @return string[]
	 */
	public static function extract_gtm_ids_from_scan_payload( array $payload ) {
		$chunks = array();
		foreach ( array( 'html', 'body_html', 'head_html' ) as $k ) {
			if ( ! empty( $payload[ $k ] ) && is_string( $payload[ $k ] ) ) {
				$chunks[] = $payload[ $k ];
			}
		}
		foreach ( array( 'scripts', 'suspicious_scripts', 'technologies', 'destinations' ) as $k ) {
			if ( empty( $payload[ $k ] ) || ! is_array( $payload[ $k ] ) ) {
				continue;
			}
			$chunks[] = wp_json_encode( $payload[ $k ] );
		}
		if ( isset( $payload['pages'] ) && is_array( $payload['pages'] ) ) {
			$chunks[] = wp_json_encode( $payload['pages'] );
		}
		if ( isset( $payload['results'] ) && is_array( $payload['results'] ) ) {
			$chunks[] = wp_json_encode( $payload['results'] );
		}

		$found = array();
		foreach ( $chunks as $chunk ) {
			foreach ( self::extract_gtm_container_ids( $chunk ) as $id ) {
				if ( ! in_array( $id, $found, true ) ) {
					$found[] = $id;
				}
			}
		}
		return $found;
	}

	/**
	 * Sanitize raw posted service_ids (wizard — preserves GTM containers[]).
	 *
	 * Does not force enabled=false when the checkbox was not posted (wizard uses
	 * selected_statistics / selected_services for enable state).
	 *
	 * @param mixed $posted $_POST service_ids.
	 * @return array
	 */
	public static function sanitize_posted_service_ids( $posted ) {
		if ( ! is_array( $posted ) ) {
			return array();
		}
		$posted = wp_unslash( $posted );
		$out    = array();
		foreach ( $posted as $key => $row ) {
			$key = sanitize_key( $key );
			if ( ! $key || ! is_array( $row ) ) {
				continue;
			}
			$clean = self::sanitize_row( $key, $row );
			// Wizard ID fields omit [enabled] — leave merge_service_ids to keep prior/selected enable.
			if ( ! array_key_exists( 'enabled', $row ) ) {
				unset( $clean['enabled'] );
			}
			$out[ $key ] = $clean;
		}
		return $out;
	}

	/**
	 * Match a bare Google container/tag token (GTM- / GT- / G-).
	 *
	 * @param string $upper Already-uppercased trimmed token.
	 * @return string Empty when invalid.
	 */
	private static function match_google_tag_id_token( $upper ) {
		$upper = (string) $upper;
		// Longest prefixes first (GTM- before GT- before G-).
		if ( preg_match( '/^GTM-[A-Z0-9]+$/', $upper ) ) {
			return $upper;
		}
		if ( preg_match( '/^GT-[A-Z0-9]+$/', $upper ) ) {
			return $upper;
		}
		if ( preg_match( '/^G-[A-Z0-9]+$/', $upper ) ) {
			return $upper;
		}
		return '';
	}

	/**
	 * Extract Google container/tag IDs (GTM- / GT- / G-) from HTML, JSON, or script URLs.
	 *
	 * @param string $haystack Raw content.
	 * @return string[] Uppercase IDs, deduped in order found.
	 */
	public static function extract_gtm_container_ids( $haystack ) {
		$haystack = (string) $haystack;
		if ( '' === $haystack ) {
			return array();
		}

		$found = array();
		// GTM- then GT- then G- so longer tokens win when overlapping in text.
		foreach ( array( '/GTM-[A-Z0-9]+/i', '/GT-[A-Z0-9]+/i', '/G-[A-Z0-9]+/i' ) as $pattern ) {
			if ( ! preg_match_all( $pattern, $haystack, $matches ) ) {
				continue;
			}
			foreach ( $matches[0] as $raw ) {
				$id = self::match_google_tag_id_token( strtoupper( $raw ) );
				if ( $id && ! in_array( $id, $found, true ) ) {
					$found[] = $id;
				}
			}
		}

		return $found;
	}

	/**
	 * Normalize a Google container/tag ID for the GTM multi-ID list.
	 *
	 * Accepts GTM-… (Tag Manager), GT-… (Google Tag), G-… (GA4), or extracts
	 * the first match from a pasted URL/snippet.
	 *
	 * @param string $id Raw ID.
	 * @return string Empty when invalid.
	 */
	public static function normalize_gtm_container_id( $id ) {
		$raw = trim( (string) $id );
		if ( '' === $raw ) {
			return '';
		}
		$upper = strtoupper( sanitize_text_field( $raw ) );
		$bare  = self::match_google_tag_id_token( $upper );
		if ( $bare ) {
			return $bare;
		}
		$extracted = self::extract_gtm_container_ids( $raw );
		return $extracted ? $extracted[0] : '';
	}

	/**
	 * Whether an ID should load via gtm.js (Tag Manager container).
	 *
	 * @param string $id Normalized ID.
	 * @return bool
	 */
	public static function is_gtm_container_id( $id ) {
		return 0 === strpos( strtoupper( (string) $id ), 'GTM-' );
	}

	/**
	 * Whether an ID should load via gtag.js (Google Tag / GA4).
	 *
	 * @param string $id Normalized ID.
	 * @return bool
	 */
	public static function is_gtag_id( $id ) {
		$id = strtoupper( (string) $id );
		// Analytics / Google Tag only — AW- Ads IDs are marketing (see is_google_ads_id).
		return 0 === strpos( $id, 'GT-' ) || 0 === strpos( $id, 'G-' );
	}

	/**
	 * Whether an ID is a Google Ads conversion / remarketing ID (AW-…).
	 *
	 * @param string $id Normalized ID.
	 * @return bool
	 */
	public static function is_google_ads_id( $id ) {
		$id = strtoupper( (string) $id );
		return 0 === strpos( $id, 'AW-' );
	}

	/**
	 * Whether a gtag/script URL is a Google Ads (AW-) load.
	 *
	 * @param string $src Script URL.
	 * @return bool
	 */
	public static function is_google_ads_src( $src ) {
		$src = (string) $src;
		if ( '' === $src ) {
			return false;
		}
		if ( preg_match( '/[?&]id=AW-/i', $src ) ) {
			return true;
		}
		$src_l = strtolower( $src );
		return (
			false !== strpos( $src_l, 'googleadservices.com' ) ||
			false !== strpos( $src_l, 'googlesyndication.com' ) ||
			false !== strpos( $src_l, 'googleads.g.doubleclick.net' ) ||
			false !== strpos( $src_l, 'adservice.google.com' )
		);
	}

	/**
	 * Sanitize a GTM dataLayer variable name (preserve camelCase).
	 *
	 * @param mixed $name Raw name.
	 * @return string
	 */
	public static function sanitize_gtm_data_layer( $name ) {
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return 'dataLayer';
		}
		if ( ! preg_match( '/^[A-Za-z_$][A-Za-z0-9_$]*$/', $name ) ) {
			return 'dataLayer';
		}
		return $name;
	}

	/**
	 * Purpose checkbox options for GTM partner disclosures.
	 *
	 * @return array<string,string>
	 */
	public static function gtm_disclosure_purpose_options() {
		return array(
			'conversion_tracking'  => __( 'Conversion tracking', 'universal-consent-privacy-framework' ),
			'remarketing'          => __( 'Remarketing', 'universal-consent-privacy-framework' ),
			'analytics'            => __( 'Analytics', 'universal-consent-privacy-framework' ),
			'audience_measurement' => __( 'Audience measurement', 'universal-consent-privacy-framework' ),
		);
	}

	/**
	 * Use-of-data checkbox options for GTM partner disclosures.
	 *
	 * @return array<string,string>
	 */
	public static function gtm_disclosure_use_options() {
		return array(
			'advertising'          => __( 'Advertising', 'universal-consent-privacy-framework' ),
			'remarketing'          => __( 'Remarketing', 'universal-consent-privacy-framework' ),
			'audience_building'    => __( 'Audience building', 'universal-consent-privacy-framework' ),
			'profiling'            => __( 'Profiling', 'universal-consent-privacy-framework' ),
			'cross_site_tracking'  => __( 'Cross-site tracking', 'universal-consent-privacy-framework' ),
		);
	}

	/**
	 * Empty disclosure structure.
	 *
	 * @return array
	 */
	public static function empty_gtm_disclosure() {
		return array(
			'platforms'       => '',
			'cookie_duration' => '',
			'purposes'        => array(),
			'recipients'      => '',
			'uses'            => array(),
			'visitor_info'    => '',
			'notes'           => '',
		);
	}

	/**
	 * Whether a disclosure has any agency-entered content.
	 *
	 * @param array $disc Disclosure.
	 * @return bool
	 */
	public static function gtm_disclosure_has_content( $disc ) {
		if ( ! is_array( $disc ) ) {
			return false;
		}
		foreach ( array( 'platforms', 'cookie_duration', 'recipients', 'visitor_info', 'notes' ) as $k ) {
			if ( ! empty( $disc[ $k ] ) && '' !== trim( (string) $disc[ $k ] ) ) {
				return true;
			}
		}
		if ( ! empty( $disc['purposes'] ) && is_array( $disc['purposes'] ) ) {
			return true;
		}
		if ( ! empty( $disc['uses'] ) && is_array( $disc['uses'] ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Sanitize GTM partner disclosure (free-text; site-specific).
	 *
	 * @param mixed $raw Raw disclosure.
	 * @return array
	 */
	public static function sanitize_gtm_disclosure( $raw ) {
		$out      = self::empty_gtm_disclosure();
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		$out['platforms']       = isset( $raw['platforms'] ) ? sanitize_textarea_field( (string) $raw['platforms'] ) : '';
		$out['cookie_duration'] = isset( $raw['cookie_duration'] ) ? sanitize_text_field( (string) $raw['cookie_duration'] ) : '';
		$out['recipients']      = isset( $raw['recipients'] ) ? sanitize_text_field( (string) $raw['recipients'] ) : '';
		$out['visitor_info']    = isset( $raw['visitor_info'] ) ? sanitize_textarea_field( (string) $raw['visitor_info'] ) : '';
		$out['notes']           = isset( $raw['notes'] ) ? sanitize_textarea_field( (string) $raw['notes'] ) : '';

		$purpose_keys = array_keys( self::gtm_disclosure_purpose_options() );
		$use_keys     = array_keys( self::gtm_disclosure_use_options() );
		$out['purposes'] = array();
		if ( ! empty( $raw['purposes'] ) && is_array( $raw['purposes'] ) ) {
			foreach ( $raw['purposes'] as $p ) {
				$p = sanitize_key( (string) $p );
				if ( $p && in_array( $p, $purpose_keys, true ) && ! in_array( $p, $out['purposes'], true ) ) {
					$out['purposes'][] = $p;
				}
			}
		}
		$out['uses'] = array();
		if ( ! empty( $raw['uses'] ) && is_array( $raw['uses'] ) ) {
			foreach ( $raw['uses'] as $u ) {
				$u = sanitize_key( (string) $u );
				if ( $u && in_array( $u, $use_keys, true ) && ! in_array( $u, $out['uses'], true ) ) {
					$out['uses'][] = $u;
				}
			}
		}
		return $out;
	}

	/**
	 * Sanitize one GTM container row.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	public static function sanitize_gtm_container( $row ) {
		if ( ! is_array( $row ) ) {
			$row = array();
		}
		$id = self::normalize_gtm_container_id( isset( $row['id'] ) ? $row['id'] : '' );
		$dl = self::sanitize_gtm_data_layer( isset( $row['data_layer'] ) ? $row['data_layer'] : 'dataLayer' );
		return array(
			'id'         => $id,
			'label'      => isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '',
			'data_layer' => $dl,
			'disclosure' => self::sanitize_gtm_disclosure( isset( $row['disclosure'] ) ? $row['disclosure'] : array() ),
		);
	}

	/**
	 * Sanitize and dedupe GTM containers list; cap at GTM_MAX_CONTAINERS.
	 *
	 * @param mixed $containers Raw list.
	 * @return array<int, array>
	 */
	public static function sanitize_gtm_containers( $containers ) {
		if ( ! is_array( $containers ) ) {
			return array();
		}
		$out  = array();
		$seen = array();
		foreach ( $containers as $row ) {
			if ( count( $out ) >= self::GTM_MAX_CONTAINERS ) {
				break;
			}
			$clean = self::sanitize_gtm_container( $row );
			if ( '' === $clean['id'] || isset( $seen[ $clean['id'] ] ) ) {
				continue;
			}
			$seen[ $clean['id'] ] = true;
			$out[]                = $clean;
		}
		return $out;
	}

	/**
	 * Normalize a GTM service_ids row (legacy id → containers[], sync id mirror).
	 *
	 * If legacy `id` is valid and not already in containers[], it is prepended so
	 * pre-multi-container installs never lose their single container on save/read.
	 *
	 * @param array $row Raw or stored row.
	 * @return array
	 */
	public static function normalize_gtm_row( $row ) {
		if ( ! is_array( $row ) ) {
			$row = array();
		}

		$containers = self::sanitize_gtm_containers( isset( $row['containers'] ) ? $row['containers'] : array() );
		$legacy_id  = self::normalize_gtm_container_id( isset( $row['id'] ) ? $row['id'] : '' );

		if ( $legacy_id ) {
			$has_legacy = false;
			foreach ( $containers as $c ) {
				if ( isset( $c['id'] ) && $c['id'] === $legacy_id ) {
					$has_legacy = true;
					break;
				}
			}
			if ( ! $has_legacy ) {
				array_unshift(
					$containers,
					self::sanitize_gtm_container(
						array(
							'id'         => $legacy_id,
							'label'      => '',
							'data_layer' => 'dataLayer',
						)
					)
				);
				if ( count( $containers ) > self::GTM_MAX_CONTAINERS ) {
					$containers = array_slice( $containers, 0, self::GTM_MAX_CONTAINERS );
				}
			}
		}

		$row['containers'] = $containers;
		$row['id']         = $containers ? $containers[0]['id'] : $legacy_id;

		return $row;
	}

	/**
	 * GTM containers from a service_ids row (normalized).
	 *
	 * @param array $row Service row.
	 * @return array<int, array>
	 */
	public static function gtm_containers_from_row( $row ) {
		$row = self::normalize_gtm_row( is_array( $row ) ? $row : array() );
		return isset( $row['containers'] ) && is_array( $row['containers'] ) ? $row['containers'] : array();
	}

	/**
	 * Containers that have partner disclosure content (for policies).
	 *
	 * @param array|null $service_ids Optional service_ids map; defaults to settings.
	 * @return array<int, array>
	 */
	public static function gtm_disclosures_for_policy( $service_ids = null ) {
		if ( null === $service_ids ) {
			$service_ids = Settings::get( 'service_ids', array() );
		}
		if ( ! is_array( $service_ids ) || empty( $service_ids['google_tag_manager'] ) ) {
			return array();
		}
		$row = $service_ids['google_tag_manager'];
		if ( ! is_array( $row ) || empty( $row['enabled'] ) ) {
			return array();
		}
		$out = array();
		foreach ( self::gtm_containers_from_row( $row ) as $c ) {
			$disc = isset( $c['disclosure'] ) ? $c['disclosure'] : array();
			if ( ! self::gtm_disclosure_has_content( $disc ) ) {
				continue;
			}
			$out[] = array(
				'id'         => isset( $c['id'] ) ? (string) $c['id'] : '',
				'label'      => isset( $c['label'] ) ? (string) $c['label'] : '',
				'disclosure' => self::sanitize_gtm_disclosure( $disc ),
			);
		}
		return $out;
	}

	/**
	 * All enabled GTM/GT/G- containers for policy listing (not disclosure-gated).
	 *
	 * @param array|null $service_ids Optional service_ids map; defaults to settings.
	 * @return array<int, array{id:string,label:string}>
	 */
	public static function gtm_configured_tags_for_policy( $service_ids = null ) {
		if ( null === $service_ids ) {
			$service_ids = Settings::get( 'service_ids', array() );
		}
		if ( ! is_array( $service_ids ) || empty( $service_ids['google_tag_manager'] ) ) {
			return array();
		}
		$row = $service_ids['google_tag_manager'];
		if ( ! is_array( $row ) || empty( $row['enabled'] ) || ! self::row_has_ids( 'google_tag_manager', $row ) ) {
			return array();
		}
		$out = array();
		foreach ( self::gtm_containers_from_row( $row ) as $c ) {
			$id = isset( $c['id'] ) ? (string) $c['id'] : '';
			if ( '' === $id ) {
				continue;
			}
			$out[] = array(
				'id'    => $id,
				'label' => isset( $c['label'] ) ? (string) $c['label'] : '',
			);
		}
		return $out;
	}

	/**
	 * Display label for a GTM policy row (label + ID when both present).
	 *
	 * @param string $label Optional agency label.
	 * @param string $id    Container/tag ID.
	 * @param string $fallback Fallback when both empty.
	 * @return string
	 */
	public static function gtm_policy_display_label( $label, $id, $fallback = '' ) {
		$label = trim( (string) $label );
		$id    = trim( (string) $id );
		if ( $label && $id ) {
			return $label . ' (' . $id . ')';
		}
		if ( $id ) {
			return $id;
		}
		if ( $label ) {
			return $label;
		}
		return $fallback ? (string) $fallback : __( 'GTM container', 'universal-consent-privacy-framework' );
	}

	/**
	 * Whether any GTM multi-list ID should load via gtag (GT- / G-).
	 *
	 * @param array $row GTM service_ids row.
	 * @return bool
	 */
	public static function gtm_row_has_gtag_ids( $row ) {
		foreach ( self::gtm_containers_from_row( is_array( $row ) ? $row : array() ) as $c ) {
			if ( ! empty( $c['id'] ) && self::is_gtag_id( $c['id'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Human labels for disclosure purpose/use keys.
	 *
	 * @param array  $keys    Selected keys.
	 * @param string $which   purposes|uses.
	 * @return string Comma-separated labels.
	 */
	public static function gtm_disclosure_labels( array $keys, $which = 'purposes' ) {
		$map = ( 'uses' === $which ) ? self::gtm_disclosure_use_options() : self::gtm_disclosure_purpose_options();
		$labels = array();
		foreach ( $keys as $k ) {
			if ( isset( $map[ $k ] ) ) {
				$labels[] = $map[ $k ];
			}
		}
		return implode( ', ', $labels );
	}

	/**
	 * Fingerprint of Google tags + GTM disclosures that affect legal pages.
	 *
	 * @param array $service_ids Service IDs map.
	 * @return string
	 */
	public static function policy_relevant_fingerprint( $service_ids ) {
		if ( ! is_array( $service_ids ) ) {
			$service_ids = array();
		}
		$slice = array();
		foreach ( array( 'google_analytics_4', 'google_tag_manager', 'google_ads' ) as $key ) {
			if ( empty( $service_ids[ $key ] ) || ! is_array( $service_ids[ $key ] ) ) {
				continue;
			}
			$row = $service_ids[ $key ];
			$entry = array(
				'enabled' => ! empty( $row['enabled'] ),
				'id'      => isset( $row['id'] ) ? (string) $row['id'] : '',
				'tag_id'  => isset( $row['tag_id'] ) ? (string) $row['tag_id'] : '',
			);
			if ( 'google_tag_manager' === $key ) {
				$entry['containers'] = array();
				foreach ( self::gtm_containers_from_row( $row ) as $c ) {
					$entry['containers'][] = array(
						'id'         => isset( $c['id'] ) ? $c['id'] : '',
						'label'      => isset( $c['label'] ) ? $c['label'] : '',
						'disclosure' => isset( $c['disclosure'] ) ? $c['disclosure'] : self::empty_gtm_disclosure(),
					);
				}
			}
			$slice[ $key ] = $entry;
		}
		return md5( (string) wp_json_encode( $slice ) );
	}

	/**
	 * Whether a service row has injectable IDs.
	 *
	 * @param string $key Service key.
	 * @param array  $row Row.
	 * @return bool
	 */
	public static function row_has_ids( $key, $row ) {
		if ( ! is_array( $row ) ) {
			return false;
		}
		if ( 'google_tag_manager' === $key ) {
			return (bool) self::gtm_containers_from_row( $row );
		}
		return ! empty( $row['id'] ) || ! empty( $row['tag_id'] ) || ! empty( $row['code'] );
	}

	/**
	 * Persist scan-suggested GTM container IDs.
	 *
	 * @param string[] $ids     Container IDs.
	 * @param string   $stamp   Scan stamp (optional).
	 * @return void
	 */
	public static function store_gtm_scan_suggestions( array $ids, $stamp = '' ) {
		$ids = array_values(
			array_filter(
				array_map( array( __CLASS__, 'normalize_gtm_container_id' ), $ids )
			)
		);
		if ( ! $ids ) {
			return;
		}
		update_option(
			self::GTM_SUGGESTIONS_OPTION,
			array(
				'ids'   => $ids,
				'stamp' => sanitize_text_field( (string) $stamp ),
				'time'  => time(),
			),
			false
		);
	}

	/**
	 * Read stored GTM scan suggestions not yet saved in service_ids.
	 *
	 * @param array $service_ids Current service_ids map.
	 * @return array{ids:string[],stamp:string,time:int}
	 */
	public static function get_pending_gtm_scan_suggestions( $service_ids = array() ) {
		$stored = get_option( self::GTM_SUGGESTIONS_OPTION, array() );
		if ( ! is_array( $stored ) || empty( $stored['ids'] ) || ! is_array( $stored['ids'] ) ) {
			return array(
				'ids'   => array(),
				'stamp' => '',
				'time'  => 0,
			);
		}

		$saved = array();
		if ( is_array( $service_ids ) && isset( $service_ids['google_tag_manager'] ) ) {
			foreach ( self::gtm_containers_from_row( $service_ids['google_tag_manager'] ) as $c ) {
				if ( ! empty( $c['id'] ) ) {
					$saved[ $c['id'] ] = true;
				}
			}
		}

		$pending = array();
		foreach ( $stored['ids'] as $id ) {
			$id = self::normalize_gtm_container_id( $id );
			if ( $id && ! isset( $saved[ $id ] ) ) {
				$pending[] = $id;
			}
		}

		return array(
			'ids'   => $pending,
			'stamp' => isset( $stored['stamp'] ) ? (string) $stored['stamp'] : '',
			'time'  => isset( $stored['time'] ) ? (int) $stored['time'] : 0,
		);
	}

	/**
	 * Dismiss GTM scan suggestions (all or specific IDs merged into settings).
	 *
	 * @param string[] $remove_ids IDs to remove from suggestion list (empty = clear all).
	 * @return void
	 */
	public static function dismiss_gtm_scan_suggestions( array $remove_ids = array() ) {
		if ( ! $remove_ids ) {
			delete_option( self::GTM_SUGGESTIONS_OPTION );
			return;
		}
		$stored = get_option( self::GTM_SUGGESTIONS_OPTION, array() );
		if ( ! is_array( $stored ) || empty( $stored['ids'] ) || ! is_array( $stored['ids'] ) ) {
			return;
		}
		$remove = array();
		foreach ( $remove_ids as $id ) {
			$id = self::normalize_gtm_container_id( $id );
			if ( $id ) {
				$remove[ $id ] = true;
			}
		}
		$remaining = array();
		foreach ( $stored['ids'] as $id ) {
			$id = self::normalize_gtm_container_id( $id );
			if ( $id && ! isset( $remove[ $id ] ) ) {
				$remaining[] = $id;
			}
		}
		if ( $remaining ) {
			$stored['ids'] = $remaining;
			update_option( self::GTM_SUGGESTIONS_OPTION, $stored, false );
		} else {
			delete_option( self::GTM_SUGGESTIONS_OPTION );
		}
	}

	/**
	 * Merge suggested GTM IDs into a service_ids partial (does not force enabled).
	 *
	 * @param string[] $ids Suggested container IDs.
	 * @param array    $current Current service_ids.
	 * @return array Partial update for merge_service_ids.
	 */
	public static function merge_gtm_suggestions_into_partial( array $ids, $current = array() ) {
		$row     = isset( $current['google_tag_manager'] ) && is_array( $current['google_tag_manager'] )
			? self::normalize_gtm_row( $current['google_tag_manager'] )
			: array( 'enabled' => false, 'containers' => array() );
		$existing = array();
		foreach ( $row['containers'] as $c ) {
			if ( ! empty( $c['id'] ) ) {
				$existing[ $c['id'] ] = $c;
			}
		}
		foreach ( $ids as $id ) {
			$id = self::normalize_gtm_container_id( $id );
			if ( $id && ! isset( $existing[ $id ] ) ) {
				$existing[ $id ] = array(
					'id'         => $id,
					'label'      => '',
					'data_layer' => 'dataLayer',
					'disclosure' => self::empty_gtm_disclosure(),
				);
			}
		}
		$containers = array_values( $existing );
		return array(
			'google_tag_manager' => array(
				'containers' => $containers,
				'id'         => $containers ? $containers[0]['id'] : '',
			),
		);
	}

	/**
	 * Sanitize a single service_ids row.
	 *
	 * @param string $key Service key.
	 * @param array  $row Raw row.
	 * @return array
	 */
	public static function sanitize_row( $key, $row ) {
		if ( ! is_array( $row ) ) {
			$row = array();
		}

		$out = array(
			'enabled' => ! empty( $row['enabled'] ),
			'id'      => isset( $row['id'] ) ? sanitize_text_field( $row['id'] ) : '',
			'tag_id'  => isset( $row['tag_id'] ) ? sanitize_text_field( $row['tag_id'] ) : '',
			'code'    => isset( $row['code'] ) ? self::sanitize_code( $row['code'] ) : '',
		);

		if ( 'google_tag_manager' === $key ) {
			$out['containers'] = self::sanitize_gtm_containers( isset( $row['containers'] ) ? $row['containers'] : array() );
			$out               = self::normalize_gtm_row( $out );
		}

		return $out;
	}

	/**
	 * Sanitize service_ids settings map (Integrations form: unchecked = disabled).
	 *
	 * GTM: blank/empty posted containers must not wipe a still-valid legacy id or
	 * previously saved containers (common after multi-container migration).
	 *
	 * @param array $input   Posted map.
	 * @param array $current Existing map.
	 * @return array
	 */
	public static function sanitize_service_ids( $input, $current = array() ) {
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		if ( ! is_array( $input ) ) {
			return $current;
		}

		$known = array_keys( self::all() );
		$out   = $current;

		foreach ( $known as $key ) {
			$row  = isset( $input[ $key ] ) && is_array( $input[ $key ] ) ? $input[ $key ] : array();
			$prev = isset( $current[ $key ] ) && is_array( $current[ $key ] ) ? $current[ $key ] : array();

			if ( 'google_tag_manager' === $key ) {
				$out[ $key ] = self::sanitize_gtm_service_row_for_integrations( $row, $prev );
				continue;
			}

			$out[ $key ] = self::sanitize_row( $key, $row );
		}

		// Allow extra custom keys with id/code.
		foreach ( $input as $key => $row ) {
			$key = sanitize_key( $key );
			if ( ! $key || isset( $out[ $key ] ) || ! is_array( $row ) ) {
				continue;
			}
			$out[ $key ] = self::sanitize_row( $key, $row );
		}

		return $out;
	}

	/**
	 * Sanitize GTM row from Integrations without wiping legacy / prior containers.
	 *
	 * Prefers containers_json (single POST field from admin JS) over nested
	 * containers[] — avoids max_input_vars / broken name-index saves.
	 *
	 * @param array $row  Posted row.
	 * @param array $prev Previously stored row.
	 * @return array
	 */
	public static function sanitize_gtm_service_row_for_integrations( $row, $prev = array() ) {
		if ( ! is_array( $row ) ) {
			$row = array();
		}
		if ( ! is_array( $prev ) ) {
			$prev = array();
		}

		$prev_norm = self::normalize_gtm_row( $prev );
		$enabled   = ! empty( $row['enabled'] );
		$code      = array_key_exists( 'code', $row ) ? self::sanitize_code( $row['code'] ) : ( isset( $prev['code'] ) ? self::sanitize_code( $prev['code'] ) : '' );

		// Preferred path: non-empty containers_json from Integrations submit JS.
		$from_json = self::containers_from_posted_json( isset( $row['containers_json'] ) ? $row['containers_json'] : null );
		if ( null !== $from_json && $from_json ) {
			$legacy = self::normalize_gtm_container_id( isset( $row['id'] ) ? $row['id'] : '' );
			$out    = array(
				'enabled'    => $enabled,
				'id'         => $legacy ? $legacy : $from_json[0]['id'],
				'tag_id'     => '',
				'code'       => $code,
				'containers' => $from_json,
			);
			return self::normalize_gtm_row( $out );
		}

		// Empty JSON [] must not wipe IDs when nested container fields still posted
		// (older admin.js disabled fields after serializing an empty list for GT- IDs).
		// Fall through to nested / preserve logic below.

		// Truncated POST: containers key absent — keep prior containers, update enable/code only.
		if ( ! array_key_exists( 'containers', $row ) ) {
			$kept             = $prev_norm;
			$kept['enabled'] = $enabled;
			$kept['code']    = $code;
			return self::normalize_gtm_row( $kept );
		}

		$incoming = self::sanitize_gtm_containers( $row['containers'] );
		$legacy   = self::normalize_gtm_container_id( isset( $row['id'] ) ? $row['id'] : '' );

		// Posted blank UI (empty container IDs + empty legacy) but DB still has GTM — preserve.
		if ( ! $incoming && ! $legacy && self::row_has_ids( 'google_tag_manager', $prev_norm ) ) {
			$kept             = $prev_norm;
			$kept['enabled'] = $enabled;
			$kept['code']    = $code;
			return self::normalize_gtm_row( $kept );
		}

		$out = array(
			'enabled'    => $enabled,
			'id'         => $legacy,
			'tag_id'     => '',
			'code'       => $code,
			'containers' => $incoming,
		);

		if ( ! $incoming && $legacy ) {
			$out['containers'] = array();
			$out['id']         = $legacy;
		}

		return self::normalize_gtm_row( $out );
	}

	/**
	 * Decode posted containers_json into sanitized containers, or null if absent/invalid payload.
	 *
	 * Empty JSON array `[]` is a valid intentional clear signal (returns empty list).
	 * Missing / non-string / undecodable returns null so callers can fall back.
	 *
	 * @param mixed $raw Posted containers_json.
	 * @return array<int, array>|null
	 */
	public static function containers_from_posted_json( $raw ) {
		if ( null === $raw || false === $raw ) {
			return null;
		}
		if ( is_array( $raw ) ) {
			return self::sanitize_gtm_containers( $raw );
		}
		if ( ! is_string( $raw ) ) {
			return null;
		}
		$raw = trim( wp_unslash( $raw ) );
		if ( '' === $raw ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}
		return self::sanitize_gtm_containers( $decoded );
	}

	/**
	 * Merge partial service_ids updates without disabling omitted keys (wizard / scan).
	 *
	 * @param array $partial Posted subset.
	 * @param array $current Existing map.
	 * @return array
	 */
	public static function merge_service_ids( $partial, $current = array() ) {
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		if ( ! is_array( $partial ) ) {
			return $current;
		}

		$out = $current;
		foreach ( $partial as $key => $row ) {
			$key = sanitize_key( $key );
			if ( ! $key || ! is_array( $row ) ) {
				continue;
			}
			$prev = isset( $out[ $key ] ) && is_array( $out[ $key ] ) ? $out[ $key ] : array();

			$merged = array(
				'enabled' => array_key_exists( 'enabled', $row ) ? ! empty( $row['enabled'] ) : ! empty( $prev['enabled'] ),
				'id'      => array_key_exists( 'id', $row ) ? sanitize_text_field( $row['id'] ) : ( isset( $prev['id'] ) ? $prev['id'] : '' ),
				'tag_id'  => array_key_exists( 'tag_id', $row ) ? sanitize_text_field( $row['tag_id'] ) : ( isset( $prev['tag_id'] ) ? $prev['tag_id'] : '' ),
				'code'    => array_key_exists( 'code', $row ) ? self::sanitize_code( $row['code'] ) : ( isset( $prev['code'] ) ? $prev['code'] : '' ),
			);

			if ( 'google_tag_manager' === $key ) {
				if ( array_key_exists( 'containers', $row ) ) {
					$incoming = self::sanitize_gtm_containers( $row['containers'] );
					// Empty posted list + legacy id still in partial → normalize synthesizes.
					// Truly empty post with no id: keep previous containers (wizard unchecked / partial save).
					if ( $incoming ) {
						$merged['containers'] = $incoming;
					} elseif ( array_key_exists( 'id', $row ) && self::normalize_gtm_container_id( $row['id'] ) ) {
						$merged['containers'] = array();
						$merged['id']         = self::normalize_gtm_container_id( $row['id'] );
					} elseif ( isset( $prev['containers'] ) ) {
						$merged['containers'] = self::sanitize_gtm_containers( $prev['containers'] );
					} else {
						$merged['containers'] = array();
					}
				} elseif ( isset( $prev['containers'] ) ) {
					$merged['containers'] = self::sanitize_gtm_containers( $prev['containers'] );
				}
				$merged = self::normalize_gtm_row( $merged );
			}

			$out[ $key ] = $merged;
		}

		return $out;
	}

	/**
	 * Persist legacy GTM `id` into `containers[]` when still missing (idempotent).
	 *
	 * @return bool True when settings were written.
	 */
	public static function maybe_persist_gtm_legacy_migration() {
		$settings = get_option( Settings::OPTION_KEY, array() );
		if ( ! is_array( $settings ) ) {
			return false;
		}
		$ids = isset( $settings['service_ids'] ) && is_array( $settings['service_ids'] ) ? $settings['service_ids'] : array();
		if ( empty( $ids['google_tag_manager'] ) || ! is_array( $ids['google_tag_manager'] ) ) {
			return false;
		}

		$row        = $ids['google_tag_manager'];
		$normalized = self::normalize_gtm_row( $row );
		$before     = array(
			'id'         => isset( $row['id'] ) ? (string) $row['id'] : '',
			'containers' => isset( $row['containers'] ) && is_array( $row['containers'] ) ? $row['containers'] : array(),
		);
		$after      = array(
			'id'         => isset( $normalized['id'] ) ? (string) $normalized['id'] : '',
			'containers' => isset( $normalized['containers'] ) && is_array( $normalized['containers'] ) ? $normalized['containers'] : array(),
		);

		if ( wp_json_encode( $before ) === wp_json_encode( $after ) ) {
			return false;
		}

		$ids['google_tag_manager'] = array_merge(
			$row,
			array(
				'containers' => $after['containers'],
				'id'         => $after['id'],
			)
		);
		$settings['service_ids']   = $ids;
		update_option( Settings::OPTION_KEY, $settings, false );
		return true;
	}

	/**
	 * Sanitize optional custom JS snippet (no closing script tags / PHP).
	 *
	 * @param string $code Raw.
	 * @return string
	 */
	public static function sanitize_code( $code ) {
		$code = (string) $code;
		$code = wp_unslash( $code );
		$code = str_ireplace( array( '</script', '<?php', '<?=' ), '', $code );
		return trim( $code );
	}
}
