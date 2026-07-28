<?php
/**
 * Site cookie knowledge log (offline learning; never stores cookie values).
 *
 * Feeds admin lookup and agency knowledge-pack export for a GitHub / remote registry hub.
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Per-site knowledge store.
 */
class Cookie_Knowledge {

	const OPTION_KEY = 'ucpf_knowledge_entries';
	const MAX_ENTRIES = 500;

	/**
	 * Allowed capability / behavior / provenance tags (controlled vocabulary).
	 *
	 * @return string[]
	 */
	public static function allowed_tags() {
		return apply_filters(
			'ucpf_knowledge_allowed_tags',
			array(
				'analytics',
				'advertising',
				'marketing_pixel',
				'social_embed',
				'video_embed',
				'maps',
				'chat',
				'a11y_widget',
				'payment',
				'payment_fraud',
				'cdn_library',
				'security_bot',
				'consent_record',
				'fonts',
				'booking',
				'idx',
				'email_capture',
				'storefront_ui',
				'sets_first_party_cookie',
				'sets_third_party_cookie',
				'loads_script',
				'loads_iframe',
				'loads_beacon',
				'uses_localstorage',
				'uses_sessionstorage',
				'seen_no_consent',
				'seen_after_reject',
				'seen_after_accept_only',
				'persists_after_revoke',
				'respects_gpc',
				'respects_dns',
				'source_scan',
				'source_plugin_map',
				'source_ocd',
				'source_manual_review',
				'promoted_fleet',
			)
		);
	}

	/**
	 * Map consent session labels → behavior tags.
	 *
	 * @param string[] $sessions Sessions.
	 * @return string[]
	 */
	public static function tags_from_sessions( array $sessions ) {
		$tags = array( 'source_scan' );
		$set  = array_map( 'sanitize_key', $sessions );
		if ( in_array( 'no_consent', $set, true ) ) {
			$tags[] = 'seen_no_consent';
		}
		if ( in_array( 'reject_all', $set, true ) || in_array( 'revoke', $set, true ) ) {
			$tags[] = 'seen_after_reject';
		}
		if ( in_array( 'accept_all', $set, true ) && ! in_array( 'no_consent', $set, true ) ) {
			$tags[] = 'seen_after_accept_only';
		}
		if ( in_array( 'revoke', $set, true ) ) {
			$tags[] = 'persists_after_revoke';
		}
		if ( in_array( 'gpc_on', $set, true ) ) {
			$tags[] = 'respects_gpc';
		}
		if ( in_array( 'dns_opt_out', $set, true ) ) {
			$tags[] = 'respects_dns';
		}
		return array_values( array_unique( $tags ) );
	}

	/**
	 * All entries keyed by sanitized name (lowercase cookie or host:…).
	 *
	 * @return array<string,array>
	 */
	public static function all() {
		$raw = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $key => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$k = is_string( $key ) ? strtolower( $key ) : '';
			if ( '' === $k && ! empty( $row['name'] ) ) {
				$k = strtolower( (string) $row['name'] );
			}
			if ( '' === $k ) {
				continue;
			}
			$out[ $k ] = $row;
		}
		return $out;
	}

	/**
	 * Get one entry by cookie name or host key.
	 *
	 * @param string $name Name.
	 * @return array|null
	 */
	public static function get( $name ) {
		$key = self::entry_key( $name );
		if ( '' === $key ) {
			return null;
		}
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Normalize storage key.
	 *
	 * @param string $name Cookie or host.
	 * @return string
	 */
	public static function entry_key( $name ) {
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return '';
		}
		if ( 0 === stripos( $name, 'host:' ) ) {
			return 'host:' . strtolower( substr( $name, 5 ) );
		}
		return strtolower( $name );
	}

	/**
	 * First-party hostnames for this install (never share in packs).
	 *
	 * @return string[]
	 */
	public static function first_party_hosts() {
		$hosts = array();
		$home  = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		if ( is_string( $home ) && $home ) {
			$hosts[] = strtolower( $home );
			$hosts[] = strtolower( ltrim( $home, '.' ) );
			if ( 0 === strpos( $home, 'www.' ) ) {
				$hosts[] = strtolower( substr( $home, 4 ) );
			} else {
				$hosts[] = 'www.' . strtolower( $home );
			}
		}
		if ( defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ) {
			$hosts[] = strtolower( ltrim( (string) COOKIE_DOMAIN, '.' ) );
		}
		$hosts = array_values( array_unique( array_filter( $hosts ) ) );
		/**
		 * Filter first-party hosts stripped from shareable knowledge packs.
		 *
		 * @param string[] $hosts Hosts.
		 */
		return apply_filters( 'ucpf_knowledge_first_party_hosts', $hosts );
	}

	/**
	 * Whether a host is first-party (site / COOKIE_DOMAIN).
	 *
	 * @param string $host Host.
	 * @return bool
	 */
	public static function is_first_party_host( $host ) {
		$host = strtolower( ltrim( (string) $host, '.' ) );
		if ( '' === $host ) {
			return false;
		}
		foreach ( self::first_party_hosts() as $fp ) {
			if ( $host === $fp || substr( $host, -strlen( '.' . $fp ) ) === '.' . $fp ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Generalize site-/person-specific cookie names into safe patterns for sharing.
	 *
	 * Returns empty string to omit the cookie from shareable packs.
	 *
	 * @param string $name Cookie name.
	 * @return string Pattern or ''.
	 */
	public static function anonymize_cookie_name( $name ) {
		$raw  = trim( (string) $name );
		$name = $raw;
		if ( '' === $name || preg_match( '/[=;@\s]/', $name ) ) {
			return '';
		}

		// Never share names that look like secrets / auth material.
		if ( preg_match( '/(password|passwd|secret|apikey|api_key|access_token|refresh_token|bearer)/i', $name ) ) {
			return '';
		}

		// UUID-shaped names (session / visitor ids used as cookie names).
		if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $name ) ) {
			return '';
		}

		// Tracker families with property-/site-specific suffixes → catalog patterns.
		if ( preg_match( '/^_ga_[A-Z0-9]+$/i', $name ) ) {
			$name = '_ga_*';
		} elseif ( preg_match( '/^_gat(_|$)/i', $name ) && '_gat' !== strtolower( $name ) ) {
			// _gat_gtag_XXXX / _gat_* → generic family (keep bare _gat as-is).
			$name = '_gat_*';
		} elseif ( preg_match( '/^_gcl_[A-Za-z0-9_-]+$/i', $name ) ) {
			$name = '_gcl_*';
		} elseif ( preg_match( '/^_hjSessionUser_\d+$/i', $name ) ) {
			$name = '_hjSessionUser_*';
		} elseif ( preg_match( '/^_hjSession_\d+$/i', $name ) ) {
			$name = '_hjSession_*';
		} elseif ( preg_match( '/^_hj[A-Za-z]+_\d+$/i', $name ) ) {
			// Other Hotjar cookies that embed the numeric Site ID.
			$name = preg_replace( '/_\d+$/', '_*', $name );
		} elseif ( preg_match( '/^(wc_cart_hash|wc_fragments|woocommerce_cart_hash|woocommerce_items_in_cart)[_-]?[a-f0-9]{6,}$/i', $name ) ) {
			// Woo / storefront hashes tied to this install.
			$name = preg_replace( '/[_-]?[a-f0-9]{6,}$/i', '_*', $name );
		} elseif ( preg_match( '/^[a-f0-9]{20,}$/i', $name ) ) {
			// Long opaque hex ids.
			return '';
		}

		// Names that embed this site's hostname.
		foreach ( self::first_party_hosts() as $fp ) {
			if ( $fp && false !== stripos( $raw, $fp ) ) {
				return '';
			}
		}

		/**
		 * Filter anonymized cookie pattern for shareable packs.
		 *
		 * @param string $name Generalized pattern (or original).
		 * @param string $raw  Original name.
		 */
		$out = apply_filters( 'ucpf_anonymize_cookie_name', $name, $raw );
		return is_string( $out ) ? $out : $name;
	}

	/**
	 * Public Cookie Policy display name for an observed cookie.
	 *
	 * Collapses property-/site-specific names (e.g. _ga_XXXX) to catalog patterns.
	 * Integration IDs (GTM-XXXX, Pixel ID) are unrelated and never returned here.
	 *
	 * @param string     $observed_name Observed Set-Cookie name.
	 * @param array|null $catalog_match Optional match_cookie_name() row.
	 * @return string Display name for policy tables.
	 */
	public static function policy_cookie_display_name( $observed_name, $catalog_match = null ) {
		$observed = trim( (string) $observed_name );
		if ( '' === $observed ) {
			return '';
		}

		if ( is_array( $catalog_match ) ) {
			$pattern = '';
			if ( ! empty( $catalog_match['pattern'] ) ) {
				$pattern = (string) $catalog_match['pattern'];
			} elseif ( ! empty( $catalog_match['name'] ) && false !== strpos( (string) $catalog_match['name'], '*' ) ) {
				$pattern = (string) $catalog_match['name'];
			}
			if ( $pattern && false !== strpos( $pattern, '*' ) ) {
				/**
				 * Filter public Cookie Policy cookie display name.
				 *
				 * @param string     $pattern  Catalog pattern.
				 * @param string     $observed Observed name.
				 * @param array|null $catalog_match Match row.
				 */
				$filtered = apply_filters( 'ucpf_policy_cookie_display_name', $pattern, $observed, $catalog_match );
				return is_string( $filtered ) && '' !== $filtered ? $filtered : $pattern;
			}
		}

		$anon = self::anonymize_cookie_name( $observed );
		if ( is_string( $anon ) && '' !== $anon && $anon !== $observed ) {
			$filtered = apply_filters( 'ucpf_policy_cookie_display_name', $anon, $observed, $catalog_match );
			return is_string( $filtered ) && '' !== $filtered ? $filtered : $anon;
		}

		$filtered = apply_filters( 'ucpf_policy_cookie_display_name', $observed, $observed, $catalog_match );
		return is_string( $filtered ) && '' !== $filtered ? $filtered : $observed;
	}

	/**
	 * Scrub purpose / provider text: strip URLs, emails, first-party hosts, site title.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function anonymize_text( $text ) {
		$text = (string) $text;
		if ( '' === $text ) {
			return '';
		}
		// URLs and emails.
		$text = preg_replace( '#https?://[^\s<>"\']+#i', '[url]', $text );
		$text = preg_replace( '/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', '[email]', $text );

		foreach ( self::first_party_hosts() as $fp ) {
			if ( strlen( $fp ) >= 4 ) {
				$text = str_ireplace( $fp, '[site]', $text );
			}
		}
		$blog = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		if ( is_string( $blog ) && strlen( $blog ) >= 3 ) {
			$text = str_ireplace( $blog, '[site]', $text );
		}

		$text = sanitize_text_field( $text );
		if ( strlen( $text ) > 280 ) {
			$text = substr( $text, 0, 280 );
		}
		return $text;
	}

	/**
	 * Anonymize one cookie row for export / contribution.
	 *
	 * @param array $row Row with name/purpose/provider/….
	 * @return array|null Null to omit.
	 */
	public static function anonymize_cookie_row( array $row ) {
		$raw_name = isset( $row['name'] ) ? (string) $row['name'] : '';
		$pattern  = self::anonymize_cookie_name( $raw_name );
		if ( '' === $pattern ) {
			return null;
		}
		$row['name']     = $pattern;
		$row['pattern']  = $pattern;
		$row['purpose']  = self::anonymize_text( isset( $row['purpose'] ) ? (string) $row['purpose'] : '' );
		$row['provider'] = self::anonymize_text( isset( $row['provider'] ) ? (string) $row['provider'] : '' );
		// Drop fields that can fingerprint a scan machine / site.
		unset( $row['page_url'], $row['domain'], $row['path'], $row['site_url'], $row['expires'], $row['value'] );
		return $row;
	}

	/**
	 * Upsert a knowledge row (never stores cookie values).
	 *
	 * @param string $name Name.
	 * @param array  $data Fields.
	 * @return array|\WP_Error Saved row.
	 */
	public static function upsert( $name, array $data ) {
		$key = self::entry_key( $name );
		if ( '' === $key ) {
			return new \WP_Error( 'ucpf_knowledge_name', __( 'Knowledge entry name required.', 'universal-consent-privacy-framework' ) );
		}

		$all  = self::all();
		$prev = isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : array();

		$categories = array_keys( Consent_Manager::instance()->get_categories() );
		$category   = isset( $data['category'] ) ? sanitize_key( (string) $data['category'] ) : ( isset( $prev['category'] ) ? $prev['category'] : '' );
		if ( $category && ! in_array( $category, $categories, true ) ) {
			$category = '';
		}

		$treatment = isset( $data['treatment'] ) ? sanitize_key( (string) $data['treatment'] ) : ( isset( $prev['treatment'] ) ? $prev['treatment'] : 'consent' );
		if ( ! in_array( $treatment, array( 'necessary', 'consent', 'ignore' ), true ) ) {
			$treatment = 'consent';
		}

		$source = isset( $data['source'] ) ? sanitize_key( (string) $data['source'] ) : ( isset( $prev['source'] ) ? $prev['source'] : 'manual' );
		if ( ! in_array( $source, array( 'scan', 'manual', 'ocd', 'catalog', 'import' ), true ) ) {
			$source = 'manual';
		}

		$tags = array();
		$raw_tags = isset( $data['tags'] ) && is_array( $data['tags'] ) ? $data['tags'] : ( isset( $prev['tags'] ) && is_array( $prev['tags'] ) ? $prev['tags'] : array() );
		$allowed  = self::allowed_tags();
		foreach ( $raw_tags as $t ) {
			$t = sanitize_key( (string) $t );
			if ( $t && in_array( $t, $allowed, true ) ) {
				$tags[] = $t;
			}
		}
		$tags = array_values( array_unique( $tags ) );

		$sessions = array();
		$raw_sess = isset( $data['observed_sessions'] ) && is_array( $data['observed_sessions'] )
			? $data['observed_sessions']
			: ( isset( $prev['observed_sessions'] ) && is_array( $prev['observed_sessions'] ) ? $prev['observed_sessions'] : array() );
		foreach ( $raw_sess as $s ) {
			$s = sanitize_key( (string) $s );
			if ( $s ) {
				$sessions[] = $s;
			}
		}
		$sessions = array_values( array_unique( $sessions ) );
		if ( $sessions ) {
			$tags = array_values( array_unique( array_merge( $tags, self::tags_from_sessions( $sessions ) ) ) );
		}

		$plugins = array();
		$raw_pl  = isset( $data['plugin_slugs'] ) && is_array( $data['plugin_slugs'] )
			? $data['plugin_slugs']
			: ( isset( $prev['plugin_slugs'] ) && is_array( $prev['plugin_slugs'] ) ? $prev['plugin_slugs'] : array() );
		foreach ( $raw_pl as $p ) {
			$p = sanitize_key( (string) $p );
			if ( $p ) {
				$plugins[] = $p;
			}
		}

		$kind = ( 0 === strpos( $key, 'host:' ) ) ? 'host' : 'cookie';
		$display_name = isset( $data['name'] ) ? sanitize_text_field( (string) $data['name'] ) : ( isset( $prev['name'] ) ? $prev['name'] : $name );
		if ( 'host' === $kind && 0 === stripos( $display_name, 'host:' ) ) {
			$display_name = substr( $display_name, 5 );
		}

		$row = array(
			'name'               => $display_name,
			'pattern'            => isset( $data['pattern'] ) ? sanitize_text_field( (string) $data['pattern'] ) : ( isset( $prev['pattern'] ) ? $prev['pattern'] : $display_name ),
			'kind'               => $kind,
			'category'           => $category,
			'treatment'          => $treatment,
			'purpose'            => isset( $data['purpose'] ) ? sanitize_textarea_field( (string) $data['purpose'] ) : ( isset( $prev['purpose'] ) ? $prev['purpose'] : '' ),
			'provider'           => isset( $data['provider'] ) ? sanitize_text_field( (string) $data['provider'] ) : ( isset( $prev['provider'] ) ? $prev['provider'] : '' ),
			'retention'          => isset( $data['retention'] ) ? sanitize_text_field( (string) $data['retention'] ) : ( isset( $prev['retention'] ) ? $prev['retention'] : '' ),
			'tags'               => $tags,
			'observed_sessions'  => $sessions,
			'plugin_slugs'       => array_values( array_unique( $plugins ) ),
			'source'             => $source,
			'note'               => isset( $data['note'] ) ? sanitize_text_field( substr( (string) $data['note'], 0, 280 ) ) : ( isset( $prev['note'] ) ? $prev['note'] : '' ),
			'updated_at'         => gmdate( 'c' ),
		);

		// Cap purpose length.
		if ( strlen( $row['purpose'] ) > 2000 ) {
			$row['purpose'] = substr( $row['purpose'], 0, 2000 );
		}

		$all[ $key ] = $row;

		if ( count( $all ) > self::MAX_ENTRIES ) {
			uasort(
				$all,
				static function ( $a, $b ) {
					$ta = isset( $a['updated_at'] ) ? (string) $a['updated_at'] : '';
					$tb = isset( $b['updated_at'] ) ? (string) $b['updated_at'] : '';
					return strcmp( $tb, $ta );
				}
			);
			$all = array_slice( $all, 0, self::MAX_ENTRIES, true );
		}

		update_option( self::OPTION_KEY, $all, false );
		return $row;
	}

	/**
	 * Search knowledge by name substring.
	 *
	 * @param string $query Query.
	 * @param int    $limit Limit.
	 * @return array[]
	 */
	public static function search( $query, $limit = 25 ) {
		$query = strtolower( trim( (string) $query ) );
		$limit = max( 1, min( 50, (int) $limit ) );
		if ( strlen( $query ) < 2 ) {
			return array();
		}
		$hits = array();
		foreach ( self::all() as $key => $row ) {
			$name = isset( $row['name'] ) ? strtolower( (string) $row['name'] ) : $key;
			if ( false === strpos( $name, $query ) && false === strpos( $key, $query ) ) {
				continue;
			}
			$hits[] = self::to_lookup_row( $row, $key );
			if ( count( $hits ) >= $limit ) {
				break;
			}
		}
		return $hits;
	}

	/**
	 * Exact or wildcard lookup row for Script_Registry.
	 *
	 * @param string $cookie_name Cookie name.
	 * @return array|null
	 */
	public static function match_cookie( $cookie_name ) {
		$cookie_name = (string) $cookie_name;
		$row         = self::get( $cookie_name );
		if ( $row && ( ! isset( $row['kind'] ) || 'host' !== $row['kind'] ) ) {
			return self::to_lookup_row( $row, self::entry_key( $cookie_name ) );
		}

		// Pattern match (e.g. imported _ga_* from a hub pack).
		foreach ( self::all() as $key => $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}
			if ( isset( $candidate['kind'] ) && 'host' === $candidate['kind'] ) {
				continue;
			}
			$pattern = '';
			if ( ! empty( $candidate['pattern'] ) ) {
				$pattern = (string) $candidate['pattern'];
			} elseif ( ! empty( $candidate['name'] ) ) {
				$pattern = (string) $candidate['name'];
			} else {
				$pattern = (string) $key;
			}
			if ( '' === $pattern || false === strpos( $pattern, '*' ) ) {
				continue;
			}
			if ( Script_Registry::instance()->cookie_name_matches( $cookie_name, $pattern ) ) {
				$out            = self::to_lookup_row( $candidate, $key );
				$out['name']    = $cookie_name;
				$out['pattern'] = $pattern;
				return $out;
			}
		}
		return null;
	}

	/**
	 * Shape for lookup UI / match_cookie_name consumers.
	 *
	 * @param array  $row Row.
	 * @param string $key Key.
	 * @return array
	 */
	public static function to_lookup_row( array $row, $key = '' ) {
		$name = isset( $row['name'] ) ? (string) $row['name'] : $key;
		return array(
			'name'               => $name,
			'pattern'            => ! empty( $row['pattern'] ) ? (string) $row['pattern'] : $name,
			'purpose'            => isset( $row['purpose'] ) ? (string) $row['purpose'] : '',
			'retention'          => isset( $row['retention'] ) ? (string) $row['retention'] : '',
			'category'           => isset( $row['category'] ) ? (string) $row['category'] : '',
			'treatment'          => isset( $row['treatment'] ) ? (string) $row['treatment'] : 'consent',
			'provider'           => isset( $row['provider'] ) ? (string) $row['provider'] : '',
			'service'            => '',
			'service_name'       => isset( $row['provider'] ) && $row['provider'] ? (string) $row['provider'] : __( 'Site knowledge', 'universal-consent-privacy-framework' ),
			'source'             => 'knowledge',
			'description_source' => 'knowledge',
			'tags'               => isset( $row['tags'] ) && is_array( $row['tags'] ) ? $row['tags'] : array(),
			'observed_sessions'  => isset( $row['observed_sessions'] ) && is_array( $row['observed_sessions'] ) ? $row['observed_sessions'] : array(),
			'plugin_slugs'       => isset( $row['plugin_slugs'] ) && is_array( $row['plugin_slugs'] ) ? $row['plugin_slugs'] : array(),
			'note'               => isset( $row['note'] ) ? (string) $row['note'] : '',
			'updated_at'         => isset( $row['updated_at'] ) ? (string) $row['updated_at'] : '',
			'kind'               => isset( $row['kind'] ) ? (string) $row['kind'] : 'cookie',
		);
	}

	/**
	 * Upsert cookie observations from a deep-scan import payload.
	 *
	 * @param array $known   Known cookie rows.
	 * @param array $unknown Unknown cookie rows.
	 */
	public static function ingest_scan_cookies( array $known, array $unknown ) {
		foreach ( array_merge( $known, $unknown ) as $row ) {
			if ( ! is_array( $row ) || empty( $row['name'] ) ) {
				continue;
			}
			$name = (string) $row['name'];
			$sessions = array();
			if ( ! empty( $row['context'] ) && is_string( $row['context'] ) ) {
				$sessions = array_filter( array_map( 'trim', explode( ',', $row['context'] ) ) );
			}
			if ( ! empty( $row['contexts'] ) && is_array( $row['contexts'] ) ) {
				$sessions = array_merge( $sessions, $row['contexts'] );
			} elseif ( ! empty( $row['contexts'] ) && is_string( $row['contexts'] ) ) {
				$sessions = array_merge( $sessions, array_filter( array_map( 'trim', explode( ',', $row['contexts'] ) ) ) );
			}
			$data = array(
				'source'            => 'scan',
				'observed_sessions' => $sessions,
				'provider'          => isset( $row['provider'] ) ? $row['provider'] : ( isset( $row['service_name'] ) ? $row['service_name'] : '' ),
				'purpose'           => isset( $row['purpose'] ) ? $row['purpose'] : '',
				'retention'         => isset( $row['retention'] ) ? $row['retention'] : '',
				'treatment'         => isset( $row['treatment'] ) ? $row['treatment'] : 'consent',
			);
			if ( ! empty( $row['category'] ) ) {
				$data['category'] = $row['category'];
			}
			if ( ! empty( $row['description_source'] ) && 'open_cookie_database' === $row['description_source'] ) {
				$data['source'] = 'ocd';
				$data['tags']   = array( 'source_ocd', 'source_scan' );
			} else {
				$data['tags'] = array( 'source_scan' );
			}
			self::upsert( $name, $data );
		}
	}

	/**
	 * Backfill knowledge from last scan + display overrides (no cookie values).
	 *
	 * Safe to call before export; does not use get_last_scan() (avoids recursion).
	 */
	public static function sync_from_last_scan() {
		$scan = get_option( 'ucpf_last_scan', array() );
		if ( ! is_array( $scan ) ) {
			$scan = array();
		}
		$known   = ( ! empty( $scan['cookies'] ) && is_array( $scan['cookies'] ) ) ? $scan['cookies'] : array();
		$unknown = ( ! empty( $scan['unknown_cookies'] ) && is_array( $scan['unknown_cookies'] ) ) ? $scan['unknown_cookies'] : array();
		if ( $known || $unknown ) {
			self::ingest_scan_cookies( $known, $unknown );
		}

		$overrides = Cookie_Scanner::get_display_overrides();
		foreach ( $overrides as $name => $ov ) {
			if ( ! is_array( $ov ) ) {
				continue;
			}
			$name = is_string( $name ) ? $name : ( isset( $ov['name'] ) ? (string) $ov['name'] : '' );
			if ( '' === $name ) {
				continue;
			}
			$payload = array(
				'source' => 'manual',
				'tags'   => array( 'source_manual_review' ),
			);
			if ( ! empty( $ov['category'] ) ) {
				$payload['category'] = $ov['category'];
			}
			if ( ! empty( $ov['treatment'] ) ) {
				$payload['treatment'] = $ov['treatment'];
			}
			if ( ! empty( $ov['purpose'] ) ) {
				$payload['purpose'] = $ov['purpose'];
			}
			if ( ! empty( $ov['label'] ) ) {
				$payload['provider'] = $ov['label'];
			}
			// Skip empty override rows (show-only defaults).
			if ( empty( $payload['category'] ) && empty( $payload['purpose'] ) && empty( $ov['treatment'] ) ) {
				continue;
			}
			self::upsert( $name, $payload );
		}
	}

	/**
	 * Export sanitized knowledge pack (for GitHub hub / remote registry merge).
	 *
	 * Syncs last scan inventory into knowledge first so the pack matches Cookie Review.
	 *
	 * @return array
	 */
	public static function export_pack() {
		self::sync_from_last_scan();

		$cookies  = array();
		$services = array();
		$seen     = array();
		/** @var array<string, array> */
		$by_provider = array();

		foreach ( self::all() as $key => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$kind = isset( $row['kind'] ) ? $row['kind'] : 'cookie';
			if ( 'host' === $kind || 0 === strpos( $key, 'host:' ) ) {
				$host = isset( $row['name'] ) ? (string) $row['name'] : substr( $key, 5 );
				$host = strtolower( preg_replace( '/[^a-z0-9.\-]/', '', $host ) );
				if ( strlen( $host ) < 4 || self::is_first_party_host( $host ) ) {
					continue;
				}
				$cat = ! empty( $row['category'] ) ? $row['category'] : 'analytics';
				if ( 'necessary' === $cat ) {
					$cat = 'preferences';
				}
				$svc_key = 'knowledge_' . sanitize_key( str_replace( '.', '_', $host ) );
				$services[] = array(
					'key'              => $svc_key,
					'name'             => $host,
					'provider'         => self::anonymize_text( ! empty( $row['provider'] ) ? $row['provider'] : $host ),
					'category'         => $cat,
					'treatment'        => 'consent',
					'description'      => self::anonymize_text( ! empty( $row['purpose'] ) ? $row['purpose'] : '' ),
					'script_patterns'  => array( $host ),
					'cookie_patterns'  => array(),
					'cookies'          => array(),
					'iframe_patterns'  => array(),
					'default_blocking' => true,
					'tags'             => isset( $row['tags'] ) ? $row['tags'] : array(),
				);
				continue;
			}

			$name = isset( $row['name'] ) ? (string) $row['name'] : $key;
			$anon = self::anonymize_cookie_row(
				array(
					'name'      => $name,
					'purpose'   => isset( $row['purpose'] ) ? (string) $row['purpose'] : '',
					'category'  => isset( $row['category'] ) ? (string) $row['category'] : '',
					'treatment' => isset( $row['treatment'] ) ? (string) $row['treatment'] : 'consent',
					'provider'  => isset( $row['provider'] ) ? (string) $row['provider'] : '',
					'retention' => isset( $row['retention'] ) ? (string) $row['retention'] : '',
					'tags'      => isset( $row['tags'] ) && is_array( $row['tags'] ) ? $row['tags'] : array(),
					'source'    => 'knowledge',
				)
			);
			if ( ! $anon ) {
				continue;
			}
			$nkey = strtolower( $anon['name'] );
			if ( isset( $seen[ $nkey ] ) ) {
				continue;
			}
			$seen[ $nkey ] = true;
			$cookies[]     = $anon;

			$prov = ! empty( $anon['provider'] ) ? (string) $anon['provider'] : '';
			$prov = trim( $prov );
			if ( '' === $prov || 0 === strcasecmp( $prov, 'Pending review' ) ) {
				$bucket = 'ucpf_site_knowledge_cookies';
				$label  = 'Site knowledge cookies';
			} else {
				$bucket = 'knowledge_' . sanitize_key( $prov );
				$label  = $prov;
			}
			if ( ! isset( $by_provider[ $bucket ] ) ) {
				$cat = ! empty( $anon['category'] ) ? $anon['category'] : 'analytics';
				if ( 'necessary' === $cat ) {
					$cat = 'preferences';
				}
				$by_provider[ $bucket ] = array(
					'key'              => $bucket,
					'name'             => $label,
					'provider'         => $label,
					'category'         => $cat,
					'treatment'        => 'consent',
					'description'      => 'Anonymized cookies from last scan + Cookie Review (metadata only; no site URL or first-party hosts).',
					'script_patterns'  => array(),
					'cookie_patterns'  => array(),
					'cookies'          => array(),
					'iframe_patterns'  => array(),
					'default_blocking' => true,
				);
			}
			$by_provider[ $bucket ]['cookies'][]          = $anon;
			$by_provider[ $bucket ]['cookie_patterns'][]  = isset( $anon['pattern'] ) ? $anon['pattern'] : $anon['name'];
		}

		foreach ( $by_provider as $svc ) {
			$svc['cookie_patterns'] = array_values( array_unique( array_filter( $svc['cookie_patterns'] ) ) );
			$services[]             = $svc;
		}

		return array(
			'schema'           => 'ucpf-registry-catalog/1.0',
			'exported_at'      => gmdate( 'c' ),
			'plugin_version'   => defined( 'UCPF_VERSION' ) ? UCPF_VERSION : '',
			'knowledge_count'  => count( $cookies ),
			'services'         => $services,
			'cookies'          => $cookies,
			'anonymized'       => true,
			'note'             => 'Anonymized metadata only — no cookie values, no site URL, no first-party hosts. Site-specific ids (e.g. _ga_XXXX) are generalized. Merge with tools/merge-knowledge-hub.ps1 into your agency GitHub hub. Not a legal determination.',
		);
	}

	/**
	 * Public GitHub contribution pack — scrubbed, no site URL, never cookie values.
	 *
	 * Manual download only; WordPress does not POST this anywhere.
	 *
	 * @return array
	 */
	public static function contribution_pack() {
		self::sync_from_last_scan();

		$max      = (int) apply_filters( 'ucpf_contribution_pack_max_cookies', 100 );
		$max      = max( 1, min( 200, $max ) );
		$cookies  = array();
		$hosts    = array();
		$patterns = array();
		$seen     = array();

		foreach ( self::all() as $key => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$kind = isset( $row['kind'] ) ? $row['kind'] : 'cookie';
			if ( 'host' === $kind || 0 === strpos( $key, 'host:' ) ) {
				$host = isset( $row['name'] ) ? (string) $row['name'] : substr( $key, 5 );
				$host = strtolower( preg_replace( '/[^a-z0-9.\-]/', '', $host ) );
				if ( strlen( $host ) < 4 || false !== strpos( $host, 'admin' ) || self::is_first_party_host( $host ) ) {
					continue;
				}
				$hosts[] = substr( $host, 0, 120 );
				continue;
			}

			$cat = Privacy_Scan_Importer::map_category( isset( $row['category'] ) ? $row['category'] : '' );
			if ( 'necessary' === $cat ) {
				$cat = 'preferences';
			}
			$treatment = isset( $row['treatment'] ) ? sanitize_key( (string) $row['treatment'] ) : 'consent';
			if ( ! in_array( $treatment, array( 'consent', 'ignore', 'necessary' ), true ) ) {
				$treatment = 'consent';
			}
			if ( 'necessary' === $treatment ) {
				$treatment = 'consent';
			}
			$tags = array();
			if ( ! empty( $row['tags'] ) && is_array( $row['tags'] ) ) {
				$allowed = array_flip( self::allowed_tags() );
				foreach ( $row['tags'] as $tag ) {
					$t = sanitize_key( (string) $tag );
					if ( isset( $allowed[ $t ] ) ) {
						$tags[] = $t;
					}
					if ( count( $tags ) >= 20 ) {
						break;
					}
				}
			}

			$anon = self::anonymize_cookie_row(
				array(
					'name'      => isset( $row['name'] ) ? (string) $row['name'] : '',
					'purpose'   => isset( $row['purpose'] ) ? (string) $row['purpose'] : '',
					'category'  => $cat ? $cat : '',
					'treatment' => $treatment,
					'provider'  => isset( $row['provider'] ) ? (string) $row['provider'] : '',
					'tags'      => $tags,
				)
			);
			if ( ! $anon ) {
				continue;
			}
			$nkey = strtolower( $anon['name'] );
			if ( isset( $seen[ $nkey ] ) ) {
				continue;
			}
			$seen[ $nkey ] = true;
			$cookies[]     = $anon;
			$patterns[]    = $anon['name'];
			if ( count( $cookies ) >= $max ) {
				break;
			}
		}

		$hosts = array_values(
			array_unique(
				array_filter(
					array_slice( $hosts, 0, 50 ),
					static function ( $h ) {
						return ! Cookie_Knowledge::is_first_party_host( $h );
					}
				)
			)
		);

		// Run host/pattern lists through community sanitizer (strips junk).
		$scrub = Community_Registry::sanitize_contribution(
			array(
				'cookie_patterns'    => $patterns,
				'hosts'              => $hosts,
				'suggested_category' => '',
				'confidence'         => 'medium',
				'note'               => 'Anonymized site knowledge contribution',
			)
		);
		if ( ! is_wp_error( $scrub ) ) {
			if ( ! empty( $scrub['cookie_patterns'] ) ) {
				$allowed_names = array_flip( $scrub['cookie_patterns'] );
				$cookies       = array_values(
					array_filter(
						$cookies,
						static function ( $c ) use ( $allowed_names ) {
							return isset( $allowed_names[ $c['name'] ] );
						}
					)
				);
			}
			if ( ! empty( $scrub['hosts'] ) ) {
				$hosts = $scrub['hosts'];
			}
		}

		return array(
			'schema'         => 'ucpf-cookie-knowledge-contribution/1.0',
			'exported_at'    => gmdate( 'c' ),
			'plugin_version' => defined( 'UCPF_VERSION' ) ? UCPF_VERSION : '',
			'cookie_count'   => count( $cookies ),
			'cookies'        => $cookies,
			'hosts'          => $hosts,
			'anonymized'     => true,
			'license_note'   => 'By attaching this file to a UCPF GitHub issue you offer it under GPL-2.0-or-later for inclusion in the vendor catalog / community hub.',
			'note'           => 'Anonymized metadata only — no cookie values, no site URL, no first-party hosts, no property-specific ids. WordPress does not upload this pack. Not a legal determination.',
		);
	}

	/**
	 * GitHub new-issue URL for cookie knowledge contributions (filterable).
	 *
	 * @return string
	 */
	public static function contribute_issue_url() {
		$base = 'https://github.com/Jeffreyreedjr/Universal-Consent-Privacy-Framework/issues/new';
		$url  = $base . '?template=cookie-knowledge.yml&title=' . rawurlencode( 'Cookie knowledge contribution' );
		/**
		 * Filter the GitHub issue URL used by Contribute cookie knowledge.
		 *
		 * @param string $url Issue URL.
		 */
		return (string) apply_filters( 'ucpf_contribute_issue_url', $url );
	}

	/**
	 * Import knowledge cookies from a pack (admin).
	 *
	 * @param array $pack Pack.
	 * @return int Count imported.
	 */
	public static function import_pack( array $pack ) {
		$count = 0;
		$rows  = array();
		if ( ! empty( $pack['cookies'] ) && is_array( $pack['cookies'] ) ) {
			$rows = $pack['cookies'];
		} elseif ( ! empty( $pack['services'] ) && is_array( $pack['services'] ) ) {
			foreach ( $pack['services'] as $svc ) {
				if ( empty( $svc['cookies'] ) || ! is_array( $svc['cookies'] ) ) {
					continue;
				}
				foreach ( $svc['cookies'] as $c ) {
					$rows[] = $c;
				}
			}
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['name'] ) ) {
				continue;
			}
			$name = (string) $row['name'];
			$pattern = ! empty( $row['pattern'] ) ? (string) $row['pattern'] : $name;
			self::upsert(
				$name,
				array(
					'source'    => 'import',
					'category'  => isset( $row['category'] ) ? $row['category'] : '',
					'treatment' => isset( $row['treatment'] ) ? $row['treatment'] : 'consent',
					'purpose'   => isset( $row['purpose'] ) ? $row['purpose'] : '',
					'provider'  => isset( $row['provider'] ) ? $row['provider'] : '',
					'retention' => isset( $row['retention'] ) ? $row['retention'] : '',
					'pattern'   => $pattern,
					'tags'      => isset( $row['tags'] ) && is_array( $row['tags'] ) ? $row['tags'] : array( 'promoted_fleet' ),
				)
			);
			++$count;
		}
		return $count;
	}
}
