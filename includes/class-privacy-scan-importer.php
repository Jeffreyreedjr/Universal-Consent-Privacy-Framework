<?php
/**
 * Import Playwright privacy-scan reports into UCPF inventory.
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Privacy scan importer (Playwright report → UCPF inventory).
 */
class Privacy_Scan_Importer {

	/**
	 * Valid UCPF categories (never "unclassified").
	 *
	 * @return string[]
	 */
	public static function assignable_categories() {
		return array( 'necessary', 'preferences', 'analytics', 'marketing', 'functional', 'security' );
	}

	/**
	 * Map scanner categories → UCPF categories.
	 *
	 * @param string $category Scanner category.
	 * @return string
	 */
	public static function map_category( $category ) {
		$category = sanitize_key( (string) $category );
		if ( 'advertising' === $category ) {
			return 'marketing';
		}
		if ( in_array( $category, self::assignable_categories(), true ) ) {
			return $category;
		}
		return '';
	}

	/**
	 * Import a Playwright report into ucpf_last_scan.
	 *
	 * @param array $report Report JSON (decoded).
	 * @return array|\WP_Error Persisted payload.
	 */
	public static function import_report( array $report ) {
		if ( empty( $report['schema'] ) || 0 !== strpos( (string) $report['schema'], 'ucpf-playwright-scan/' ) ) {
			return new \WP_Error(
				'ucpf_invalid_report',
				__( 'Unrecognized privacy scan report schema.', 'universal-consent-privacy-framework' ),
				array( 'status' => 400 )
			);
		}

		$previous = Cookie_Scanner::instance()->get_last_scan();
		if ( ! is_array( $previous ) ) {
			$previous = array();
		}

		$cookies_known   = array();
		$cookies_unknown = array();
		$results         = array();
		$detected        = array();
		$site_url        = ! empty( $report['site_url'] ) ? esc_url_raw( $report['site_url'] ) : home_url( '/' );

		$cookie_rows = array();
		if ( ! empty( $report['cookies'] ) && is_array( $report['cookies'] ) ) {
			$cookie_rows = $report['cookies'];
		}

		foreach ( $cookie_rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$name = isset( $row['name'] ) ? sanitize_text_field( $row['name'] ) : '';
			if ( '' === $name ) {
				continue;
			}
			// Global noise filter (Defender lockouts, logged-in WP, ephemeral CF challenges).
			if ( Scan_Noise_Filter::should_omit_cookie( $name ) ) {
				continue;
			}

			$raw_category = isset( $row['ucpf_category'] ) ? $row['ucpf_category'] : ( isset( $row['category'] ) ? $row['category'] : '' );
			$category     = self::map_category( $raw_category );
			$status       = isset( $row['status'] ) ? sanitize_key( $row['status'] ) : '';
			$provider     = isset( $row['provider'] ) ? sanitize_text_field( $row['provider'] ) : '';
			$treatment    = isset( $row['treatment'] ) ? sanitize_key( $row['treatment'] ) : '';
			$importance   = isset( $row['importance'] ) ? sanitize_key( $row['importance'] ) : '';
			$contexts     = isset( $row['contexts'] ) && is_array( $row['contexts'] ) ? array_map( 'sanitize_key', $row['contexts'] ) : array();
			$context      = ! empty( $contexts ) ? implode( ',', $contexts ) : 'deep_scan';

			$match    = Script_Registry::instance()->match_cookie_name(
				$name,
				isset( $row['domain'] ) ? (string) $row['domain'] : ''
			);
			$from_ocd = $match && ! empty( $match['source'] ) && 'open_cookie_database' === $match['source'];
			$service  = ( $match && ! empty( $match['service'] ) ) ? Script_Registry::instance()->get_service( $match['service'] ) : null;

			// Prefer Playwright classification; fall back to UCPF catalog (not OCD-only).
			if ( ! $category && $match && ! $from_ocd ) {
				$category = self::map_category( isset( $match['category'] ) ? $match['category'] : ( $service ? $service['category'] : '' ) );
			}
			if ( ! $treatment && $match && ! $from_ocd && ! empty( $match['treatment'] ) ) {
				$treatment = sanitize_key( $match['treatment'] );
			}
			if ( ! $provider && $service && ! empty( $service['name'] ) ) {
				$provider = $service['name'];
			} elseif ( ! $provider && $service && ! empty( $service['provider'] ) ) {
				$provider = $service['provider'];
			} elseif ( ! $provider && $match && ! empty( $match['provider'] ) && ! $from_ocd ) {
				$provider = sanitize_text_field( (string) $match['provider'] );
			}

			// Catalog hits are classified even when Playwright left importance unclassified.
			$catalog_hit  = $match && ! $from_ocd && ( ! empty( $match['service'] ) || ( ! empty( $match['source'] ) && 'ucpf' === $match['source'] ) );
			$needs_review = ( ! $category ) || 'needs_review' === $status || ( 'unclassified' === $importance && ! $catalog_hit );

			if ( $needs_review && ! $category ) {
				// OCD may suggest category/purpose; admin still confirms in Cookie Review.
				$suggested = ( $from_ocd && ! empty( $match['category'] ) ) ? sanitize_key( $match['category'] ) : '';
				$cookies_unknown[] = array(
					'name'               => $name,
					'page_url'           => $site_url,
					'context'            => $context,
					'source'             => 'playwright',
					'status'             => 'needs_review',
					'treatment'          => $treatment ? $treatment : ( ( $from_ocd && ! empty( $match['treatment'] ) ) ? sanitize_key( $match['treatment'] ) : 'consent' ),
					'importance'         => 'unclassified',
					'category'           => $suggested,
					'provider'           => $provider ? $provider : ( $from_ocd && ! empty( $match['provider'] ) ? sanitize_text_field( (string) $match['provider'] ) : '' ),
					'purpose'            => $from_ocd && ! empty( $match['purpose'] ) ? sanitize_text_field( (string) $match['purpose'] ) : '',
					'retention'          => $from_ocd && ! empty( $match['retention'] ) ? sanitize_text_field( (string) $match['retention'] ) : '',
					'service_name'       => $from_ocd && ! empty( $match['service_name'] ) ? sanitize_text_field( (string) $match['service_name'] ) : '',
					'description_source' => $from_ocd ? 'open_cookie_database' : '',
					'httpOnly'           => ! empty( $row['httpOnly'] ),
					'pre_consent'        => ! empty( $row['pre_consent'] ),
					'post_accept'        => ! empty( $row['post_accept'] ),
					'domain'             => isset( $row['domain'] ) ? sanitize_text_field( $row['domain'] ) : '',
				);
				continue;
			}

			// Classified cookies always land in inventory (catalog match optional).
			$service_key  = $match && ! empty( $match['service'] ) ? sanitize_key( $match['service'] ) : sanitize_key( $provider ? $provider : $name );
			$service_name = $service ? $service['name'] : ( $provider ? $provider : ( $match && ! empty( $match['service_name'] ) ? $match['service_name'] : $name ) );
			$ucpf_cat     = $category ? $category : 'analytics';

			$purpose = $match && ! empty( $match['purpose'] ) ? $match['purpose'] : ( isset( $row['note'] ) ? sanitize_text_field( $row['note'] ) : '' );
			$retention = $match && ! empty( $match['retention'] ) ? $match['retention'] : '';
			$desc_src  = ( $match && ! empty( $match['description_source'] ) ) ? sanitize_key( $match['description_source'] ) : '';

			$cookies_known[] = array(
				'name'               => $name,
				'pattern'            => $match && ! empty( $match['pattern'] ) ? $match['pattern'] : $name,
				'purpose'            => $purpose,
				'retention'          => $retention,
				'category'           => $ucpf_cat,
				'treatment'          => $treatment ? $treatment : ( 'necessary' === $ucpf_cat ? 'necessary' : 'consent' ),
				'importance'         => $importance && 'unclassified' !== $importance ? $importance : ( 'necessary' === $ucpf_cat ? 'required' : 'non_essential' ),
				'service'            => $service_key,
				'service_name'       => $service_name,
				'provider'           => $service && ! empty( $service['provider'] ) ? $service['provider'] : $provider,
				'page_url'           => $site_url,
				'context'            => $context,
				'source'             => 'playwright',
				'description_source' => $desc_src,
				'httpOnly'           => ! empty( $row['httpOnly'] ),
				'pre_consent'        => ! empty( $row['pre_consent'] ),
				'post_accept'        => ! empty( $row['post_accept'] ),
				'domain'             => isset( $row['domain'] ) ? sanitize_text_field( $row['domain'] ) : '',
				'path'               => isset( $row['path'] ) ? sanitize_text_field( $row['path'] ) : '',
				'expires'            => isset( $row['expires'] ) ? $row['expires'] : '',
				'selected'           => true,
			);

			if ( $service_key ) {
				$detected[ $service_key ] = true;
			}
		}

		// Dedupe known cookies by name (merge contexts from multi-session scans).
		$cookies_known = self::dedupe_cookie_rows( $cookies_known );

		$signal_groups = array(
			'script'  => isset( $report['scripts'] ) ? $report['scripts'] : array(),
			'request' => isset( $report['requests'] ) ? $report['requests'] : array(),
			'iframe'  => isset( $report['iframes'] ) ? $report['iframes'] : array(),
			'beacon'  => isset( $report['beacons'] ) ? $report['beacons'] : array(),
			'pixel'   => isset( $report['pixels'] ) ? $report['pixels'] : array(),
		);

		foreach ( $signal_groups as $type => $rows ) {
			if ( ! is_array( $rows ) ) {
				continue;
			}
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				if ( empty( $row['provider'] ) && empty( $row['url'] ) && empty( $row['host'] ) ) {
					continue;
				}
				$provider   = isset( $row['provider'] ) ? sanitize_text_field( $row['provider'] ) : '';
				$pattern    = isset( $row['url'] ) ? $row['url'] : ( isset( $row['host'] ) ? $row['host'] : '' );
				$cat        = self::map_category( isset( $row['category'] ) ? $row['category'] : '' );
				$treatment  = isset( $row['treatment'] ) ? sanitize_key( $row['treatment'] ) : ( 'necessary' === $cat ? 'necessary' : 'consent' );
				$importance = isset( $row['importance'] ) ? sanitize_key( $row['importance'] ) : ( 'necessary' === $cat ? 'required' : ( $cat ? 'non_essential' : 'unclassified' ) );

				// Resolve provider string → catalog service key when possible.
				$svc_key = '';
				if ( $provider ) {
					$svc_key = self::resolve_service_key( $provider, is_string( $pattern ) ? $pattern : '' );
				}

				if ( ! $cat && ! $provider && ! $svc_key ) {
					continue;
				}

				$key = $svc_key ? $svc_key : ( $provider ? sanitize_key( $provider ) : sanitize_key( $type . '_' . md5( (string) $pattern ) ) );
				$results[] = array(
					'type'               => 'playwright_' . $type,
					'service'            => $key,
					'service_name'       => $provider ? $provider : $key,
					'pattern'            => sanitize_text_field( is_string( $pattern ) ? substr( $pattern, 0, 200 ) : '' ),
					'suggested_category' => $cat ? $cat : 'analytics',
					'treatment'          => $treatment ? $treatment : 'consent',
					'importance'         => $importance,
					'confidence'         => 'high',
					'blocking_status'    => 'unknown',
					'suggested_action'   => __( 'Observed by deep privacy scan (Playwright).', 'universal-consent-privacy-framework' ),
					'page_url'           => $site_url,
					'context'            => 'deep_scan',
					'selected'           => true,
				);
				$detected[ $key ] = true;
			}
		}

		foreach ( isset( $report['detected_services'] ) && is_array( $report['detected_services'] ) ? $report['detected_services'] : array() as $svc ) {
			if ( ! is_array( $svc ) || empty( $svc['provider'] ) ) {
				continue;
			}
			$key = self::resolve_service_key( $svc['provider'], isset( $svc['url'] ) ? (string) $svc['url'] : '' );
			if ( ! $key ) {
				$key = sanitize_key( $svc['provider'] );
			}
			$detected[ $key ] = true;
		}

		$storage = array();
		foreach ( isset( $report['storage'] ) && is_array( $report['storage'] ) ? $report['storage'] : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$key = isset( $row['key'] ) ? sanitize_text_field( $row['key'] ) : '';
			if ( '' === $key || Scan_Noise_Filter::should_omit_storage_key( $key ) ) {
				continue;
			}
			$storage[] = array(
				'key'      => $key,
				'kind'     => isset( $row['kind'] ) ? sanitize_key( $row['kind'] ) : '',
				'contexts' => isset( $row['contexts'] ) && is_array( $row['contexts'] ) ? array_map( 'sanitize_key', $row['contexts'] ) : array(),
			);
		}

		$service_keys = array_values( array_filter( array_keys( $detected ) ) );
		self::select_detected_services( $service_keys );

		$consent_leaks = array();
		foreach ( isset( $report['consent_leaks'] ) && is_array( $report['consent_leaks'] ) ? $report['consent_leaks'] : array() as $leak ) {
			if ( ! is_array( $leak ) ) {
				continue;
			}
			$leak_type = isset( $leak['type'] ) ? sanitize_key( $leak['type'] ) : '';
			$leak_name = isset( $leak['name'] ) ? sanitize_text_field( $leak['name'] ) : '';
			if ( Scan_Noise_Filter::should_ignore_leak( $leak_type, $leak_name ) ) {
				continue;
			}
			$consent_leaks[] = array_merge(
				array(
					'type'       => $leak_type,
					'name'       => $leak_name,
					'provider'   => isset( $leak['provider'] ) ? sanitize_text_field( $leak['provider'] ) : '',
					'category'   => isset( $leak['category'] ) ? sanitize_key( $leak['category'] ) : '',
					'treatment'  => isset( $leak['treatment'] ) ? sanitize_key( $leak['treatment'] ) : '',
					'importance' => isset( $leak['importance'] ) ? sanitize_key( $leak['importance'] ) : '',
					'severity'   => isset( $leak['severity'] ) ? sanitize_key( $leak['severity'] ) : 'high',
					'reason'     => isset( $leak['reason'] ) ? sanitize_text_field( $leak['reason'] ) : '',
					'contexts'   => isset( $leak['contexts'] ) && is_array( $leak['contexts'] ) ? array_map( 'sanitize_key', $leak['contexts'] ) : array(),
				),
				self::remediation_for_signal(
					$leak_type,
					$leak_name,
					isset( $leak['provider'] ) ? (string) $leak['provider'] : ''
				)
			);
		}

		$findings = self::sanitize_findings( isset( $report['findings'] ) ? $report['findings'] : array() );
		$findings_summary = self::sanitize_findings_summary(
			isset( $report['findings_summary'] ) ? $report['findings_summary'] : array(),
			$findings
		);

		$request_diffs = array();
		if ( isset( $report['request_diffs'] ) && is_array( $report['request_diffs'] ) ) {
			foreach ( $report['request_diffs'] as $diff_key => $diff_rows ) {
				if ( ! is_array( $diff_rows ) ) {
					continue;
				}
				$request_diffs[ sanitize_key( $diff_key ) ] = array_values(
					array_map(
						'sanitize_text_field',
						array_slice( $diff_rows, 0, 100 )
					)
				);
			}
		}

		$payload = array(
			'date'               => current_time( 'mysql' ),
			'results'            => $results,
			'cookies'            => $cookies_known,
			'unknown_cookies'    => $cookies_unknown,
			'detected_services'  => $service_keys,
			'scanned_urls'       => isset( $report['pages'] ) && is_array( $report['pages'] ) ? count( $report['pages'] ) : 0,
			'storage'            => $storage,
			'privacy_signals'    => array(
				'scripts'  => self::summarize_signal_list( isset( $report['scripts'] ) ? $report['scripts'] : array() ),
				'requests' => self::summarize_signal_list( isset( $report['requests'] ) ? $report['requests'] : array() ),
				'iframes'  => self::summarize_signal_list( isset( $report['iframes'] ) ? $report['iframes'] : array() ),
				'beacons'  => self::summarize_signal_list( isset( $report['beacons'] ) ? $report['beacons'] : array() ),
				'pixels'   => self::summarize_signal_list( isset( $report['pixels'] ) ? $report['pixels'] : array() ),
				'gpc'      => self::sanitize_privacy_signal_block( isset( $report['privacy_signals']['gpc'] ) ? $report['privacy_signals']['gpc'] : array() ),
				'gpp'      => self::sanitize_privacy_signal_block( isset( $report['privacy_signals']['gpp'] ) ? $report['privacy_signals']['gpp'] : array() ),
				'consent_mode' => self::sanitize_privacy_signal_block( isset( $report['privacy_signals']['consent_mode'] ) ? $report['privacy_signals']['consent_mode'] : array() ),
			),
			'consent_leaks'      => $consent_leaks,
			'findings'           => $findings,
			'findings_summary'   => $findings_summary,
			'request_diffs'      => $request_diffs,
			'cookie_phases'      => self::summarize_cookie_phases( isset( $report['cookie_phases'] ) ? $report['cookie_phases'] : array() ),
			'sessions'           => isset( $report['sessions'] ) ? $report['sessions'] : array(),
			'source'             => 'playwright',
			'schema'             => isset( $report['schema'] ) ? sanitize_text_field( (string) $report['schema'] ) : '',
			'scan_profile'       => isset( $report['options']['profile'] ) ? sanitize_key( (string) $report['options']['profile'] ) : '',
			'notice'             => isset( $report['notice'] ) ? sanitize_text_field( $report['notice'] ) : __( 'Technical privacy scan only — not a guarantee of full detection or legal compliance.', 'universal-consent-privacy-framework' ),
			'cmp'                => self::sanitize_cmp( isset( $report['cmp'] ) ? $report['cmp'] : null ),
			'consent_modal'      => self::sanitize_consent_modal( isset( $report['consent_modal'] ) ? $report['consent_modal'] : array() ),
			'tcf'                => self::sanitize_tcf( isset( $report['tcf'] ) ? $report['tcf'] : array() ),
			'dark_patterns'      => self::sanitize_dark_patterns( isset( $report['dark_patterns'] ) ? $report['dark_patterns'] : array() ),
			'compliance_score'   => self::sanitize_compliance_score( isset( $report['compliance_score'] ) ? $report['compliance_score'] : array() ),
			'verify_delta'       => self::build_verify_delta(
				array(
					'consent_leaks'    => $consent_leaks,
					'findings_summary' => $findings_summary,
					'compliance_score' => self::sanitize_compliance_score( isset( $report['compliance_score'] ) ? $report['compliance_score'] : array() ),
				),
				$previous
			),
		);

		if ( empty( $cookies_known ) && empty( $cookies_unknown ) && empty( $cookie_rows ) ) {
			return new \WP_Error(
				'ucpf_empty_cookies',
				__( 'Report contained no cookies array. Re-export from the Playwright scanner and try again.', 'universal-consent-privacy-framework' ),
				array( 'status' => 400 )
			);
		}

		$persisted = Cookie_Scanner::instance()->persist_scan_payload( $payload );
		if ( ! is_wp_error( $persisted ) && is_array( $persisted ) ) {
			Agency_Scanner::store_baseline( $payload );
			Cookie_Knowledge::ingest_scan_cookies( $cookies_known, $cookies_unknown );
		}
		return $persisted;
	}

	/**
	 * Dedupe cookie inventory rows by name (case-insensitive), merging contexts.
	 *
	 * @param array $rows Rows.
	 * @return array
	 */
	private static function dedupe_cookie_rows( array $rows ) {
		$by_name = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['name'] ) ) {
				continue;
			}
			$key = strtolower( (string) $row['name'] );
			if ( ! isset( $by_name[ $key ] ) ) {
				$by_name[ $key ] = $row;
				continue;
			}
			$prev = $by_name[ $key ];
			$ctx_a = isset( $prev['context'] ) ? preg_split( '/\s*,\s*/', (string) $prev['context'] ) : array();
			$ctx_b = isset( $row['context'] ) ? preg_split( '/\s*,\s*/', (string) $row['context'] ) : array();
			$merged = array_values( array_filter( array_unique( array_merge( (array) $ctx_a, (array) $ctx_b ) ) ) );
			$by_name[ $key ]['context'] = implode( ',', $merged );
			foreach ( array( 'purpose', 'provider', 'service_name', 'retention' ) as $field ) {
				if ( empty( $by_name[ $key ][ $field ] ) && ! empty( $row[ $field ] ) ) {
					$by_name[ $key ][ $field ] = $row[ $field ];
				}
			}
		}
		return array_values( $by_name );
	}

	/**
	 * Diff new vs previous scan for review triggers (no mutations).
	 *
	 * @param array $imported New persisted payload.
	 * @param array $previous Previous last scan.
	 * @return array
	 */
	public static function diff_scan_for_review( array $imported, array $previous = array() ) {
		$prev_unknown = array();
		if ( ! empty( $previous['unknown_cookies'] ) && is_array( $previous['unknown_cookies'] ) ) {
			foreach ( $previous['unknown_cookies'] as $row ) {
				if ( is_array( $row ) && ! empty( $row['name'] ) ) {
					$prev_unknown[ $row['name'] ] = true;
				}
			}
		}

		$new_unknown_names = array();
		$unknown_count     = 0;
		if ( ! empty( $imported['unknown_cookies'] ) && is_array( $imported['unknown_cookies'] ) ) {
			$unknown_count = count( $imported['unknown_cookies'] );
			foreach ( $imported['unknown_cookies'] as $row ) {
				if ( ! is_array( $row ) || empty( $row['name'] ) ) {
					continue;
				}
				$name = $row['name'];
				if ( empty( $prev_unknown[ $name ] ) ) {
					$new_unknown_names[] = $name;
				}
			}
		}

		$prev_leaks = array();
		if ( ! empty( $previous['consent_leaks'] ) && is_array( $previous['consent_leaks'] ) ) {
			foreach ( $previous['consent_leaks'] as $leak ) {
				if ( is_array( $leak ) && ! empty( $leak['name'] ) ) {
					$prev_leaks[ $leak['name'] ] = true;
				}
			}
		}

		$new_leak_names = array();
		if ( ! empty( $imported['consent_leaks'] ) && is_array( $imported['consent_leaks'] ) ) {
			foreach ( $imported['consent_leaks'] as $leak ) {
				if ( ! is_array( $leak ) || empty( $leak['name'] ) ) {
					continue;
				}
				$name = $leak['name'];
				if ( empty( $prev_leaks[ $name ] ) ) {
					$new_leak_names[] = $name;
				}
			}
		}

		$needs = ( $unknown_count > 0 ) || ( count( $new_leak_names ) > 0 );

		return array(
			'needs_review'      => $needs,
			'unknown_count'     => $unknown_count,
			'new_unknown_names' => $new_unknown_names,
			'new_leak_count'    => count( $new_leak_names ),
			'new_leak_names'    => $new_leak_names,
			'services_enabled'  => 0,
		);
	}

	/**
	 * Safe auto-apply after import: select known services; enable Integrations only when IDs exist.
	 * Never auto-classifies unknown cookies.
	 *
	 * @param array $imported New payload.
	 * @param array $previous Previous payload.
	 * @return array Review summary.
	 */
	public static function apply_safe_updates( array $imported, array $previous = array() ) {
		$review = self::diff_scan_for_review( $imported, $previous );

		$keys = array();
		if ( ! empty( $imported['detected_services'] ) && is_array( $imported['detected_services'] ) ) {
			$keys = $imported['detected_services'];
		} elseif ( ! empty( $imported['results'] ) && is_array( $imported['results'] ) ) {
			foreach ( $imported['results'] as $row ) {
				if ( is_array( $row ) && ! empty( $row['service'] ) ) {
					$keys[] = $row['service'];
				}
			}
		}
		$keys = array_values( array_unique( array_filter( array_map( 'sanitize_key', $keys ) ) ) );

		// Live infrastructure signals on this WordPress host (proxy headers / NS / SMTP plugins).
		if ( class_exists( __NAMESPACE__ . '\\Cookie_Scanner' ) ) {
			$scanner = Cookie_Scanner::instance();
			$cf      = $scanner->detect_cloudflare_proxy();
			if ( ! empty( $cf['proxied'] ) ) {
				$keys[]                         = 'cloudflare';
				$imported['cloudflare_proxied'] = true;
				$imported['cloudflare_signals'] = isset( $cf['signals'] ) ? $cf['signals'] : array();
			}
			foreach ( $scanner->scan_active_plugins_public() as $finding ) {
				if ( ! empty( $finding['service'] ) ) {
					$keys[] = sanitize_key( $finding['service'] );
				}
			}
			$tx     = array_merge( array( 'transactional_email', 'gravity_smtp' ), Cookie_Scanner::transactional_provider_keys() );
			$has_tx = false;
			foreach ( $keys as $k ) {
				if ( in_array( $k, $tx, true ) ) {
					$has_tx = true;
					break;
				}
			}
			if ( $has_tx ) {
				$keys[] = 'transactional_email';
			}
		}

		$keys = array_values( array_unique( array_filter( array_map( 'sanitize_key', $keys ) ) ) );
		if ( $keys ) {
			self::select_detected_services( $keys );
		}

		// Only flip Integrations "enabled" when Measurement/Tag ID or custom code already saved.
		$enabled_n = 0;
		if ( $keys && class_exists( __NAMESPACE__ . '\\Tracking_Templates' ) ) {
			$templates = Tracking_Templates::all();
			$ids       = Settings::get( 'service_ids', array() );
			if ( ! is_array( $ids ) ) {
				$ids = array();
			}
			$partial = array();
			foreach ( $keys as $key ) {
				if ( ! isset( $templates[ $key ] ) ) {
					continue;
				}
				$row = isset( $ids[ $key ] ) && is_array( $ids[ $key ] ) ? $ids[ $key ] : array();
				$has = ! empty( $row['id'] ) || ! empty( $row['tag_id'] ) || ! empty( $row['code'] );
				if ( ! $has ) {
					continue;
				}
				$partial[ $key ] = array( 'enabled' => true );
				++$enabled_n;
			}
			if ( $partial ) {
				Settings::update(
					array(
						'service_ids' => Tracking_Templates::merge_service_ids( $partial, $ids ),
					)
				);
			}
		}

		$review['services_enabled'] = $enabled_n;
		return $review;
	}

	/**
	 * Mark detected catalog services as selected (wizard + blocking).
	 *
	 * @param string[] $keys Service keys.
	 */
	public static function select_detected_services( array $keys ) {
		$keys = array_values( array_unique( array_filter( array_map( 'sanitize_key', $keys ) ) ) );
		if ( ! $keys ) {
			return;
		}

		// Legacy Gravity SMTP map key → umbrella transactional service.
		$keys = array_map(
			static function ( $key ) {
				return ( 'gravity_smtp' === $key ) ? 'transactional_email' : $key;
			},
			$keys
		);
		$keys = array_values( array_unique( $keys ) );

		$selected = Settings::get( 'selected_services', array() );
		if ( ! is_array( $selected ) ) {
			$selected = array();
		}
		$selected = array_values( array_unique( array_merge( array_map( 'sanitize_key', $selected ), $keys ) ) );
		Settings::update( array( 'selected_services' => $selected ) );
	}

	/**
	 * Map a leak/finding signal to a catalog service + suggested admin action.
	 *
	 * @param string $type     cookie|script|request|iframe|beacon|….
	 * @param string $name     Cookie name, host, or URL.
	 * @param string $provider Optional provider label.
	 * @return array{service_key: string, service_name: string, action: string, blocking_on: bool}
	 */
	public static function remediation_for_signal( $type, $name, $provider = '' ) {
		$type     = sanitize_key( (string) $type );
		$name     = sanitize_text_field( (string) $name );
		$provider = sanitize_text_field( (string) $provider );
		$registry = Script_Registry::instance();
		$key      = '';
		$svc      = null;

		if ( 'cookie' === $type && $name ) {
			$match = $registry->match_cookie_name( $name );
			if ( is_array( $match ) && ! empty( $match['service'] ) ) {
				$key = sanitize_key( (string) $match['service'] );
			}
		}

		if ( ! $key ) {
			$key = self::resolve_service_key( $provider, $name );
		}

		if ( $key ) {
			$svc = $registry->get_service( $key );
			if ( ! is_array( $svc ) ) {
				// resolve_service_key may return a sanitized provider slug that is not a catalog key.
				$key = '';
			}
		}

		$blocking_on = false;
		$action      = 'review';
		$service_name = '';

		if ( $key && is_array( $svc ) ) {
			$service_name = isset( $svc['name'] ) ? (string) $svc['name'] : $key;
			$treatment    = isset( $svc['treatment'] ) ? (string) $svc['treatment'] : 'consent';
			$blocking_on  = ! empty( $svc['default_blocking'] ) && 'ignore' !== $treatment && 'necessary' !== $treatment;
			if ( ! $blocking_on ) {
				$action = 'enable_blocking';
			} elseif ( empty( $svc['default_blocking'] ) ) {
				$action = 'enable_blocking';
			} else {
				$action = 'verify';
			}
		} elseif ( $name && in_array( $type, array( 'request', 'script', 'iframe', 'beacon', 'pixel' ), true ) ) {
			$action = 'catalog_suggestion';
		}

		return array(
			'service_key'  => $key,
			'service_name' => $service_name,
			'action'       => $action,
			'blocking_on'  => $blocking_on,
		);
	}

	/**
	 * Compare new Playwright verify metrics vs the previous stored scan.
	 *
	 * @param array $current  New payload fields.
	 * @param array $previous Prior last scan.
	 * @return array
	 */
	public static function build_verify_delta( array $current, array $previous ) {
		$prev_leaks = ! empty( $previous['consent_leaks'] ) && is_array( $previous['consent_leaks'] )
			? count( $previous['consent_leaks'] )
			: 0;
		$cur_leaks  = ! empty( $current['consent_leaks'] ) && is_array( $current['consent_leaks'] )
			? count( $current['consent_leaks'] )
			: 0;

		$prev_fail = 0;
		if ( ! empty( $previous['findings_summary']['fail'] ) ) {
			$prev_fail = (int) $previous['findings_summary']['fail'];
		}
		$cur_fail = 0;
		if ( ! empty( $current['findings_summary']['fail'] ) ) {
			$cur_fail = (int) $current['findings_summary']['fail'];
		}

		$prev_score = isset( $previous['compliance_score']['total'] ) ? (int) $previous['compliance_score']['total'] : null;
		$cur_score  = isset( $current['compliance_score']['total'] ) ? (int) $current['compliance_score']['total'] : null;

		$has_previous = $prev_leaks > 0
			|| $prev_fail > 0
			|| null !== $prev_score
			|| ( ! empty( $previous['source'] ) && 'playwright' === $previous['source'] )
			|| ! empty( $previous['findings_summary'] );

		return array(
			'has_previous'    => (bool) $has_previous,
			'previous_leaks'  => $prev_leaks,
			'current_leaks'   => $cur_leaks,
			'leaks_delta'     => $cur_leaks - $prev_leaks,
			'previous_fail'   => $prev_fail,
			'current_fail'    => $cur_fail,
			'fail_delta'      => $cur_fail - $prev_fail,
			'previous_score'  => $prev_score,
			'current_score'   => $cur_score,
			'score_delta'     => ( null !== $prev_score && null !== $cur_score ) ? ( $cur_score - $prev_score ) : null,
		);
	}

	/**
	 * Resolve a provider label or URL to a catalog service key.
	 *
	 * @param string $provider Provider name.
	 * @param string $url      Optional URL/host.
	 * @return string
	 */
	public static function resolve_service_key( $provider, $url = '' ) {
		$provider = trim( (string) $provider );
		$url      = (string) $url;
		$hay      = strtolower( $provider . ' ' . $url );

		$aliases = array(
			'google_analytics_4'       => array( 'google analytics', 'ga4', 'google tag', 'gt-', 'googletagmanager.com/gtag', 'google-analytics.com', 'analytics.google.com', 'g/collect' ),
			'google_tag_manager'       => array( 'google tag manager', 'gtm-', 'googletagmanager.com/gtm', 'gtm.js', 'googletagmanager.com' ),
			'google_ads'               => array( 'google ads', 'google advertising', 'doubleclick', 'googleads.g.doubleclick.net', 'static.doubleclick.net' ),
			'youtube'                  => array( 'youtube', 'youtu.be', 'youtube.com', 'ytimg.com', 'i.ytimg.com', 'ysc', 'visitor_info1_live' ),
			'mailchimp'                => array( 'mailchimp forms', 'chimpstatic.com', 'list-manage.com', 'mailchimp-for-woocommerce', 'mailchimp-for-wp' ),
			'mailchimp_transactional'  => array( 'mailchimp transactional', 'mandrill', 'mandrillapp.com', 'smtp.mandrillapp.com' ),
			'transactional_email'      => array( 'transactional email', 'gravity smtp', 'gravitysmtp', 'wp mail smtp', 'fluentsmtp', 'fluent smtp', 'post smtp', 'easy wp smtp', 'smtp mailer' ),
			'mailgun'                  => array( 'mailgun', 'api.mailgun.net', 'smtp.mailgun.org' ),
			'sendgrid'                 => array( 'sendgrid', 'api.sendgrid.com', 'smtp.sendgrid.net' ),
			'postmark'                 => array( 'postmark', 'postmarkapp.com' ),
			'amazon_ses'               => array( 'amazon ses', 'amazonaws.com/ses', 'email-smtp.', 'wp-offload-ses', 'wp ses' ),
			'brevo_smtp'               => array( 'brevo smtp', 'smtp-relay.brevo.com', 'api.brevo.com', 'sendinblue smtp' ),
			'sparkpost'                => array( 'sparkpost' ),
			'smtp2go'                  => array( 'smtp2go' ),
			'mailjet'                  => array( 'mailjet', 'in-v3.mailjet.com' ),
			'elastic_email'            => array( 'elastic email', 'elasticemail' ),
			'sendlayer'                => array( 'sendlayer' ),
			'smtp_com'                 => array( 'smtp.com', 'api.smtp.com' ),
			'resend'                   => array( 'resend.com', 'api.resend.com' ),
			'mailersend'               => array( 'mailersend' ),
			'emailit'                  => array( 'emailit' ),
			'zoho_mail'                => array( 'zoho mail', 'smtp.zoho.com' ),
			'microsoft_365_smtp'       => array( 'microsoft 365 smtp', 'smtp.office365.com', 'outlook.office365.com' ),
			'gmail_smtp'               => array( 'gmail smtp', 'smtp.gmail.com', 'googleapis.com/gmail' ),
			'generic_smtp'             => array( 'generic smtp', 'custom smtp', 'other smtp' ),
			'gravity_smtp'             => array( 'gravity_smtp' ),
			'paypal'                   => array( 'paypal', 'paypalobjects.com', 'c.paypal.com', 'b.stats.paypal.com' ),
			'calendly'                 => array( 'calendly', 'assets.calendly.com' ),
			'constant_contact'         => array( 'constant contact', 'ctctcdn.com', 'listgrowth.ctctcdn.com' ),
			'woocommerce_order_attribution' => array( 'sourcebuster', 'sbjs_', 'order attribution', 'order-attribution' ),
			'cloudflare_web_analytics' => array( 'cloudflareinsights', 'static.cloudflareinsights.com', 'cloudflare web analytics' ),
			'cloudflare'               => array( 'cloudflare', 'cf_clearance', '__cf_bm', 'cloudflareinsights', 'static.cloudflareinsights.com' ),
			'cloudflare_turnstile'     => array( 'cloudflare turnstile', 'challenges.cloudflare.com', 'turnstile', 'simple-cloudflare-turnstile' ),
			'hcaptcha'                 => array( 'hcaptcha', 'hcaptcha.com' ),
			'google_fonts'             => array( 'google fonts', 'fonts.googleapis.com', 'fonts.gstatic.com' ),
			'adobe_fonts'              => array( 'typekit', 'adobe fonts', 'use.typekit.net', 'p.typekit.net' ),
			'font_awesome'             => array( 'font awesome', 'fontawesome', 'kit.fontawesome.com' ),
			'activecampaign'           => array( 'activecampaign', 'trackcmp.net', 'activehosted.com' ),
			'convertkit'               => array( 'convertkit', 'ck.page' ),
			'drip'                     => array( 'drip', 'getdrip.com' ),
			'getresponse'              => array( 'getresponse' ),
			'mailerlite'               => array( 'mailerlite', 'mlcdn.com' ),
			'userway'                  => array( 'userway', 'cdn.userway.org', 'api.userway.org' ),
			'jotform'                  => array( 'jotform', 'jotfor.ms', 'cdn.jotfor.ms' ),
			'elementor'                => array( 'elementor' ),
			'google_maps'              => array( 'wp go maps', 'wp google maps', 'wpgmza', 'wp-google-maps', 'google maps', 'mapster' ),
			'wp_consent_api'           => array( 'wp consent api', 'wp_consent_' ),
			'magnite'                  => array( 'magnite', 'rubicon' ),
		);

		foreach ( Script_Registry::instance()->get_services() as $key => $service ) {
			$name = isset( $service['name'] ) ? strtolower( $service['name'] ) : '';
			$prov = isset( $service['provider'] ) ? strtolower( $service['provider'] ) : '';
			if ( $provider && ( strtolower( $provider ) === $name || sanitize_key( $provider ) === $key ) ) {
				return $key;
			}
			if ( $provider && $prov && false !== stripos( $provider, $service['provider'] ) ) {
				// Keep scanning aliases for a tighter match.
			}
			foreach ( (array) ( isset( $service['script_patterns'] ) ? $service['script_patterns'] : array() ) as $pat ) {
				if ( $pat && false !== stripos( $hay, (string) $pat ) ) {
					return $key;
				}
			}
		}

		foreach ( $aliases as $key => $needles ) {
			foreach ( $needles as $needle ) {
				if ( $needle && false !== strpos( $hay, $needle ) ) {
					return $key;
				}
			}
		}

		return $provider ? sanitize_key( $provider ) : '';
	}

	/**
	 * Compact timed cookie phase rows for storage.
	 *
	 * @param array $rows Rows.
	 * @return array
	 */
	private static function summarize_cookie_phases( $rows ) {
		$out = array();
		if ( ! is_array( $rows ) ) {
			return $out;
		}
		foreach ( array_slice( $rows, 0, 200 ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$names = array();
			if ( ! empty( $row['cookie_names'] ) && is_array( $row['cookie_names'] ) ) {
				$names = array_map( 'sanitize_text_field', array_slice( $row['cookie_names'], 0, 80 ) );
			}
			$out[] = array(
				'session'      => isset( $row['session'] ) ? sanitize_key( $row['session'] ) : '',
				'page'         => isset( $row['page'] ) ? esc_url_raw( $row['page'] ) : '',
				'phase'        => isset( $row['phase'] ) ? sanitize_key( $row['phase'] ) : '',
				'cookie_count' => isset( $row['cookie_count'] ) ? (int) $row['cookie_count'] : count( $names ),
				'cookie_names' => $names,
			);
		}
		return $out;
	}

	/**
	 * Compact signal rows for storage.
	 *
	 * @param array $rows Rows.
	 * @return array
	 */
	private static function summarize_signal_list( $rows ) {
		$out  = array();
		$seen = array();
		if ( ! is_array( $rows ) ) {
			return $out;
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$url  = isset( $row['url'] ) ? (string) $row['url'] : '';
			$host = isset( $row['host'] ) ? (string) $row['host'] : '';
			if ( '' === $host && '' !== $url ) {
				$host = $url;
			}
			if ( Scan_Noise_Filter::should_omit_signal( $host ) || Scan_Noise_Filter::should_omit_signal( $url ) ) {
				continue;
			}
			$host = Scan_Noise_Filter::collapse_signal_host( $host ? $host : $url );
			if ( '' === $host || Scan_Noise_Filter::should_omit_signal( $host ) ) {
				continue;
			}

			$provider = isset( $row['provider'] ) ? sanitize_text_field( $row['provider'] ) : '';
			$category = isset( $row['category'] ) ? sanitize_key( $row['category'] ) : '';
			if ( '' === $provider || '' === $category || 'unclassified' === $category ) {
				$svc_key = self::resolve_service_key( $provider, $host );
				if ( $svc_key ) {
					$svc = Script_Registry::instance()->get_service( $svc_key );
					if ( is_array( $svc ) ) {
						if ( '' === $provider && ! empty( $svc['name'] ) ) {
							$provider = (string) $svc['name'];
						}
						if ( ( '' === $category || 'unclassified' === $category ) && ! empty( $svc['category'] ) ) {
							$category = sanitize_key( (string) $svc['category'] );
						}
					}
				}
			}

			$dedupe = strtolower( ( $provider ? $provider : $host ) . '|' . $category );
			if ( isset( $seen[ $dedupe ] ) ) {
				continue;
			}
			$seen[ $dedupe ] = true;

			$out[] = array(
				'url'        => $url ? esc_url_raw( $url ) : '',
				'host'       => sanitize_text_field( $host ),
				'provider'   => $provider,
				'category'   => $category,
				'treatment'  => isset( $row['treatment'] ) ? sanitize_key( $row['treatment'] ) : '',
				'importance' => isset( $row['importance'] ) ? sanitize_key( $row['importance'] ) : '',
			);
			if ( count( $out ) >= 100 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Map WP scan depth to Playwright scanner profile + page cap.
	 *
	 * @param string $depth quick|standard|deep.
	 * @return array{profile:string,maxPages:int}
	 */
	public static function depth_to_scanner_options( $depth ) {
		$depth = sanitize_key( (string) $depth );
		if ( 'quick' === $depth ) {
			return array(
				'profile'  => 'quick',
				'maxPages' => 8,
			);
		}
		if ( 'deep' === $depth ) {
			return array(
				'profile'  => 'compliance',
				'maxPages' => 80,
			);
		}
		return array(
			'profile'  => 'standard',
			'maxPages' => 40,
		);
	}

	/**
	 * Create a remote deep scan job.
	 *
	 * @param string $url     Target URL.
	 * @param array  $paths   Paths.
	 * @param array  $options Client options (depth, profile, maxPages, interact).
	 * @return array|\WP_Error
	 */
	public static function start_remote_scan( $url, array $paths = array(), array $options = array() ) {
		$api = self::api_base();
		$key = self::api_key();
		if ( ! $api ) {
			return new \WP_Error( 'ucpf_scanner_unconfigured', __( 'Scanner API URL is not configured.', 'universal-consent-privacy-framework' ), array( 'status' => 400 ) );
		}

		$depth = ! empty( $options['depth'] ) ? sanitize_key( (string) $options['depth'] ) : 'standard';
		$mapped = self::depth_to_scanner_options( $depth );
		$profile = ! empty( $options['profile'] ) ? sanitize_key( (string) $options['profile'] ) : $mapped['profile'];
		if ( ! in_array( $profile, array( 'quick', 'standard', 'compliance' ), true ) ) {
			$profile = $mapped['profile'];
		}
		$max_pages = isset( $options['maxPages'] ) ? absint( $options['maxPages'] ) : $mapped['maxPages'];
		if ( $max_pages < 1 ) {
			$max_pages = $mapped['maxPages'];
		}
		$max_pages = min( 100, $max_pages );

		$paths = array_values( array_filter( array_map( 'strval', $paths ) ) );
		$paths = array_slice( $paths, 0, $max_pages );

		$scan_options = array(
			'profile'  => $profile,
			'maxPages' => $max_pages,
		);
		if ( ! empty( $options['interact'] ) ) {
			$scan_options['interact'] = true;
		}

		$body = array(
			'url'     => esc_url_raw( $url ),
			'paths'   => $paths,
			'options' => $scan_options,
		);

		$response = wp_remote_post(
			trailingslashit( $api ) . 'v1/scans',
			array(
				'timeout' => 20,
				'headers' => self::api_headers( $key ),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 || ! is_array( $data ) ) {
			$msg = is_array( $data ) && ! empty( $data['error'] ) ? $data['error'] : __( 'Scanner API error.', 'universal-consent-privacy-framework' );
			if ( is_array( $data ) && ! empty( $data['hint'] ) ) {
				$msg .= ' ' . sanitize_text_field( (string) $data['hint'] );
			}
			if ( 503 === $code || ( is_array( $data ) && ! empty( $data['error'] ) && false !== stripos( (string) $data['error'], 'queue is full' ) ) ) {
				$msg = __( 'Scanner queue is full. Wait and retry — do not cancel other sites’ jobs on a shared scanner.', 'universal-consent-privacy-framework' );
				if ( is_array( $data ) && ! empty( $data['retry_after'] ) ) {
					$msg .= ' ' . sprintf(
						/* translators: %d: seconds */
						__( 'Retry after about %d seconds.', 'universal-consent-privacy-framework' ),
						(int) $data['retry_after']
					);
				}
			} elseif ( 429 === $code ) {
				$msg = __( 'Scanner is busy or this API key already has a job running/queued. Wait for your job to finish, then try again.', 'universal-consent-privacy-framework' );
			}
			return new \WP_Error(
				'ucpf_scanner_http',
				$msg,
				array(
					'status'      => $code ? $code : 502,
					'retry_after' => is_array( $data ) && isset( $data['retry_after'] ) ? (int) $data['retry_after'] : 0,
					'position'    => is_array( $data ) && isset( $data['position'] ) ? (int) $data['position'] : 0,
				)
			);
		}

		return $data;
	}

	/**
	 * Poll remote scan job.
	 *
	 * @param string $job_id Job id.
	 * @return array|\WP_Error
	 */
	public static function get_remote_scan( $job_id ) {
		$api = self::api_base();
		$key = self::api_key();
		if ( ! $api ) {
			return new \WP_Error( 'ucpf_scanner_unconfigured', __( 'Scanner API URL is not configured.', 'universal-consent-privacy-framework' ), array( 'status' => 400 ) );
		}

		$job_id   = sanitize_text_field( $job_id );
		$response = wp_remote_get(
			trailingslashit( $api ) . 'v1/scans/' . rawurlencode( $job_id ),
			array(
				// Keep short so WP/nginx fail fast instead of hanging the admin UI.
				'timeout' => 12,
				'headers' => self::api_headers( $key ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$err = $response;
			$code = $err->get_error_code();
			if ( 'http_request_failed' === $code ) {
				return new \WP_Error(
					'ucpf_scanner_unreachable',
					__( 'Scanner API did not respond in time. Check that the scanner service is running and reachable.', 'universal-consent-privacy-framework' ),
					array( 'status' => 504 )
				);
			}
			return $err;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 || ! is_array( $data ) ) {
			$msg = is_array( $data ) && ! empty( $data['error'] ) ? $data['error'] : __( 'Scanner API error.', 'universal-consent-privacy-framework' );
			if ( 404 === $code ) {
				$msg = __( 'Scan job not found (scanner may have restarted). Start a new scan.', 'universal-consent-privacy-framework' );
			} elseif ( 502 === $code || 504 === $code ) {
				$msg = __( 'Scanner gateway error. The scan service may be overloaded or down.', 'universal-consent-privacy-framework' );
			}
			return new \WP_Error( 'ucpf_scanner_http', $msg, array( 'status' => $code ? $code : 502 ) );
		}

		return $data;
	}

	/**
	 * Cancel a remote Playwright scan job (closes Chromium; may return partial report).
	 *
	 * @param string $job_id Job id.
	 * @return array|\WP_Error
	 */
	public static function cancel_remote_scan( $job_id ) {
		$api = self::api_base();
		$key = self::api_key();
		if ( ! $api ) {
			return new \WP_Error( 'ucpf_scanner_unconfigured', __( 'Scanner API URL is not configured.', 'universal-consent-privacy-framework' ), array( 'status' => 400 ) );
		}

		$job_id = sanitize_text_field( $job_id );
		if ( '' === $job_id ) {
			return new \WP_Error( 'ucpf_scanner_job', __( 'Missing scan job id.', 'universal-consent-privacy-framework' ), array( 'status' => 400 ) );
		}

		$response = wp_remote_post(
			trailingslashit( $api ) . 'v1/scans/' . rawurlencode( $job_id ) . '/cancel',
			array(
				'timeout' => 30,
				'headers' => self::api_headers( $key ),
				'body'    => '{}',
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 || ! is_array( $data ) ) {
			$msg = is_array( $data ) && ! empty( $data['error'] ) ? $data['error'] : __( 'Could not cancel remote scan.', 'universal-consent-privacy-framework' );
			return new \WP_Error( 'ucpf_scanner_http', $msg, array( 'status' => $code ? $code : 502 ) );
		}

		return $data;
	}

	/**
	 * Cancel all remote scans and optionally reset concurrency slots.
	 *
	 * @param bool $reset_slots Reset stuck active-slot counter on scanner.
	 * @return array|\WP_Error
	 */
	public static function cancel_all_remote_scans( $reset_slots = true ) {
		$api = self::api_base();
		$key = self::api_key();
		if ( ! $api ) {
			return new \WP_Error( 'ucpf_scanner_unconfigured', __( 'Scanner API URL is not configured.', 'universal-consent-privacy-framework' ), array( 'status' => 400 ) );
		}

		$response = wp_remote_post(
			trailingslashit( $api ) . 'v1/scans/cancel-all',
			array(
				'timeout' => 20,
				'headers' => self::api_headers( $key ),
				'body'    => wp_json_encode( array( 'reset_slots' => (bool) $reset_slots ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 || ! is_array( $data ) ) {
			$msg = is_array( $data ) && ! empty( $data['error'] ) ? $data['error'] : __( 'Could not cancel remote scans.', 'universal-consent-privacy-framework' );
			return new \WP_Error( 'ucpf_scanner_http', $msg, array( 'status' => $code ? $code : 502 ) );
		}

		return $data;
	}

	/**
	 * Sanitize findings[] from schema 2.0 reports (backward compatible if empty).
	 *
	 * @param mixed $rows Findings.
	 * @return array
	 */
	private static function sanitize_findings( $rows ) {
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$allowed = array(
			'blocked_before_consent',
			'incorrectly_loaded_before_consent',
			'correctly_loaded_after_accept',
			'still_loaded_after_reject',
			'still_loaded_after_dns',
			'still_loaded_after_gpc',
			'removed_after_revocation',
			'category_mismatch',
			'indeterminate',
		);
		$out = array();
		foreach ( array_slice( $rows, 0, 500 ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$finding = isset( $row['finding'] ) ? sanitize_key( $row['finding'] ) : '';
			if ( ! in_array( $finding, $allowed, true ) ) {
				continue;
			}
			$name = isset( $row['name'] ) ? sanitize_text_field( $row['name'] ) : '';
			if ( '' === $name ) {
				continue;
			}
			$type = isset( $row['type'] ) ? sanitize_key( $row['type'] ) : '';
			if ( Scan_Noise_Filter::should_ignore_leak( $type, $name ) ) {
				continue;
			}
			$out[] = array_merge(
				array(
					'type'     => $type,
					'name'     => $name,
					'provider' => isset( $row['provider'] ) ? sanitize_text_field( $row['provider'] ) : '',
					'category' => isset( $row['category'] ) ? sanitize_key( $row['category'] ) : '',
					'finding'  => $finding,
					'severity'=> isset( $row['severity'] ) ? sanitize_key( $row['severity'] ) : 'info',
					'sessions' => isset( $row['sessions'] ) && is_array( $row['sessions'] ) ? array_map( 'sanitize_key', $row['sessions'] ) : array(),
					'reason'   => isset( $row['reason'] ) ? sanitize_text_field( $row['reason'] ) : '',
				),
				self::remediation_for_signal(
					$type,
					$name,
					isset( $row['provider'] ) ? (string) $row['provider'] : ''
				)
			);
		}
		return $out;
	}

	/**
	 * @param mixed $summary Summary blob.
	 * @param array $findings Sanitized findings.
	 * @return array
	 */
	private static function sanitize_findings_summary( $summary, array $findings ) {
		$fail_findings = array(
			'incorrectly_loaded_before_consent',
			'still_loaded_after_reject',
			'still_loaded_after_dns',
			'still_loaded_after_gpc',
			'category_mismatch',
		);
		$fail          = 0;
		foreach ( $findings as $f ) {
			if ( ! empty( $f['finding'] ) && in_array( $f['finding'], $fail_findings, true ) ) {
				++$fail;
			}
		}
		$total = count( $findings );
		if ( is_array( $summary ) ) {
			$total = isset( $summary['total'] ) ? absint( $summary['total'] ) : $total;
			$fail  = isset( $summary['fail'] ) ? absint( $summary['fail'] ) : $fail;
		}
		$info = max( 0, $total - $fail );
		return array(
			'total' => $total,
			'fail'  => $fail,
			'info'  => $info,
			'pass'  => 0 === $fail,
		);
	}

	/**
	 * @param mixed $block Signal block.
	 * @return array
	 */
	private static function sanitize_privacy_signal_block( $block ) {
		if ( ! is_array( $block ) ) {
			return array();
		}
		$out = array();
		foreach ( $block as $key => $value ) {
			$k = sanitize_key( (string) $key );
			if ( is_bool( $value ) ) {
				$out[ $k ] = $value;
			} elseif ( is_numeric( $value ) ) {
				$out[ $k ] = 0 + $value;
			} elseif ( is_string( $value ) ) {
				$out[ $k ] = sanitize_text_field( $value );
			} elseif ( is_array( $value ) ) {
				$out[ $k ] = self::sanitize_privacy_signal_block( $value );
			}
		}
		return $out;
	}

	/**
	 * @param mixed $cmp CMP blob.
	 * @return array|null
	 */
	private static function sanitize_cmp( $cmp ) {
		if ( ! is_array( $cmp ) ) {
			return null;
		}
		return array(
			'id'       => isset( $cmp['id'] ) ? sanitize_key( $cmp['id'] ) : '',
			'name'     => isset( $cmp['name'] ) ? sanitize_text_field( $cmp['name'] ) : '',
			'selector' => isset( $cmp['selector'] ) ? sanitize_text_field( $cmp['selector'] ) : '',
		);
	}

	/**
	 * @param mixed $modal Modal summary.
	 * @return array
	 */
	private static function sanitize_consent_modal( $modal ) {
		if ( ! is_array( $modal ) ) {
			return array();
		}
		return array(
			'detected'       => ! empty( $modal['detected'] ),
			'has_accept'     => ! empty( $modal['has_accept'] ),
			'has_reject'     => ! empty( $modal['has_reject'] ),
			'has_customize'  => ! empty( $modal['has_customize'] ),
			'button_count'   => isset( $modal['button_count'] ) ? absint( $modal['button_count'] ) : 0,
			'checkbox_count' => isset( $modal['checkbox_count'] ) ? absint( $modal['checkbox_count'] ) : 0,
		);
	}

	/**
	 * @param mixed $tcf TCF info.
	 * @return array
	 */
	private static function sanitize_tcf( $tcf ) {
		if ( ! is_array( $tcf ) ) {
			return array();
		}
		return array(
			'detected'             => ! empty( $tcf['detected'] ),
			'has_tcfapi'           => ! empty( $tcf['has_tcfapi'] ),
			'has_cmp_v1'           => ! empty( $tcf['has_cmp_v1'] ),
			'has_locator'          => ! empty( $tcf['has_locator'] ),
			'has_euconsent_cookie' => ! empty( $tcf['has_euconsent_cookie'] ),
			'note'                 => isset( $tcf['note'] ) ? sanitize_text_field( $tcf['note'] ) : '',
			'ping'                 => self::sanitize_privacy_signal_block( isset( $tcf['ping'] ) ? $tcf['ping'] : array() ),
			'tc_data'              => self::sanitize_privacy_signal_block( isset( $tcf['tc_data'] ) ? $tcf['tc_data'] : array() ),
		);
	}

	/**
	 * @param mixed $issues Dark pattern list.
	 * @return array
	 */
	private static function sanitize_dark_patterns( $issues ) {
		if ( ! is_array( $issues ) ) {
			return array();
		}
		$out = array();
		foreach ( $issues as $issue ) {
			if ( ! is_array( $issue ) ) {
				continue;
			}
			$out[] = array(
				'type'        => isset( $issue['type'] ) ? sanitize_key( $issue['type'] ) : '',
				'severity'    => isset( $issue['severity'] ) ? sanitize_key( $issue['severity'] ) : 'warning',
				'description' => isset( $issue['description'] ) ? sanitize_text_field( $issue['description'] ) : '',
			);
		}
		return $out;
	}

	/**
	 * @param mixed $score Score blob.
	 * @return array
	 */
	private static function sanitize_compliance_score( $score ) {
		if ( ! is_array( $score ) ) {
			return array();
		}
		$breakdown = isset( $score['breakdown'] ) && is_array( $score['breakdown'] ) ? $score['breakdown'] : array();
		return array(
			'total'      => isset( $score['total'] ) ? max( 0, min( 100, (int) $score['total'] ) ) : 0,
			'grade'      => isset( $score['grade'] ) ? sanitize_text_field( $score['grade'] ) : '',
			'breakdown'  => array(
				'consent_validity' => isset( $breakdown['consent_validity'] ) ? (int) $breakdown['consent_validity'] : 0,
				'easy_refusal'     => isset( $breakdown['easy_refusal'] ) ? (int) $breakdown['easy_refusal'] : 0,
				'transparency'     => isset( $breakdown['transparency'] ) ? (int) $breakdown['transparency'] : 0,
				'cookie_behavior'  => isset( $breakdown['cookie_behavior'] ) ? (int) $breakdown['cookie_behavior'] : 0,
			),
			'disclaimer' => isset( $score['disclaimer'] )
				? sanitize_text_field( $score['disclaimer'] )
				: __( 'Technical automated checks only — not a GDPR compliance determination or legal audit.', 'universal-consent-privacy-framework' ),
		);
	}

	/**
	 * @return string
	 */
	public static function api_base() {
		if ( defined( 'UCPF_SCANNER_API_URL' ) && UCPF_SCANNER_API_URL ) {
			return untrailingslashit( (string) UCPF_SCANNER_API_URL );
		}
		$url = Settings::get( 'scanner_api_url', '' );
		return $url ? untrailingslashit( (string) $url ) : '';
	}

	/**
	 * @return string
	 */
	public static function api_key() {
		if ( defined( 'UCPF_SCANNER_API_KEY' ) && UCPF_SCANNER_API_KEY ) {
			return (string) UCPF_SCANNER_API_KEY;
		}
		return (string) Settings::get( 'scanner_api_key', '' );
	}

	/**
	 * @param string $key API key.
	 * @return array
	 */
	private static function api_headers( $key ) {
		$headers = array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		);
		if ( $key ) {
			$headers['Authorization']      = 'Bearer ' . $key;
			$headers['X-UCPF-Scanner-Key'] = $key;
		}
		return $headers;
	}
}
