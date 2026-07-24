<?php
/**
 * Suggest site-local catalog services from unknown scan hosts.
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Catalog suggestions from last scan (never writes bundled vendor-catalog files).
 */
class Catalog_Suggestions {

	const OPTION_KEY = 'ucpf_local_catalog_services';

	/**
	 * Noise hosts / substrings to skip.
	 *
	 * @return string[]
	 */
	public static function noise_hosts() {
		return apply_filters(
			'ucpf_catalog_suggestion_noise',
			array(
				'fonts.googleapis.com',
				'fonts.gstatic.com',
				'ajax.googleapis.com',
				'cdn.jsdelivr.net',
				'cdnjs.cloudflare.com',
				'unpkg.com',
				'wp.com',
				'gravatar.com',
				'gstatic.com',
				'googleusercontent.com',
				'w.org',
				'wordpress.org',
			)
		);
	}

	/**
	 * Heuristic category for a host.
	 *
	 * @param string $host Host.
	 * @return string analytics|marketing|preferences
	 */
	public static function guess_category( $host ) {
		$h = strtolower( (string) $host );
		if ( preg_match( '/facebook|fbcdn|meta\.com|tiktok|linkedin|snapchat|doubleclick|googlesyndication|googleadservices|bing\.com|pinterest|twitter|ads-twitter|taboola|outbrain|criteo|klaviyo|mailchimp|ads\./', $h ) ) {
			return 'marketing';
		}
		if ( preg_match( '/google-analytics|googletagmanager|analytics\.|hotjar|clarity|mixpanel|segment\.|fullstory|heap|matomo|plausible|umami/', $h ) ) {
			return 'analytics';
		}
		return 'analytics';
	}

	/**
	 * Get site-local service definitions.
	 *
	 * @return array
	 */
	public static function get_local_services() {
		$raw = get_option( self::OPTION_KEY, array() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Save site-local services.
	 *
	 * @param array $services Services.
	 */
	public static function save_local_services( array $services ) {
		$clean = array();
		foreach ( $services as $svc ) {
			if ( ! is_array( $svc ) || empty( $svc['key'] ) ) {
				continue;
			}
			$validated = Script_Registry::instance()->validate_service( $svc );
			if ( is_wp_error( $validated ) ) {
				continue;
			}
			$clean[ $validated['key'] ] = $validated;
		}
		update_option( self::OPTION_KEY, array_values( $clean ), false );
	}

	/**
	 * Apply a suggested host as a site-local service.
	 *
	 * @param string $host     Host.
	 * @param string $category Category slug.
	 * @return array|\WP_Error Service or error.
	 */
	public static function apply_host( $host, $category = '' ) {
		$host = self::normalize_host( $host );
		if ( '' === $host ) {
			return new \WP_Error( 'ucpf_bad_host', __( 'Invalid host.', 'universal-consent-privacy-framework' ) );
		}
		$categories = array_keys( Consent_Manager::instance()->get_categories() );
		$category   = sanitize_key( $category );
		if ( ! in_array( $category, $categories, true ) ) {
			$category = self::guess_category( $host );
		}
		if ( ! in_array( $category, $categories, true ) ) {
			$category = 'analytics';
		}

		$key = 'local_' . sanitize_key( str_replace( '.', '_', $host ) );
		if ( strlen( $key ) > 60 ) {
			$key = 'local_' . substr( md5( $host ), 0, 12 );
		}

		$service = array(
			'key'              => $key,
			'name'             => sprintf(
				/* translators: %s: hostname */
				__( 'Site local: %s', 'universal-consent-privacy-framework' ),
				$host
			),
			'provider'         => $host,
			'category'         => $category,
			'treatment'        => 'consent',
			'description'      => __( 'Added from scan unknown-host suggestion. Review category before relying on it.', 'universal-consent-privacy-framework' ),
			'script_patterns'  => array( $host ),
			'iframe_patterns'  => array(),
			'cookie_patterns'  => array(),
			'default_blocking' => true,
		);

		$validated = Script_Registry::instance()->validate_service( $service );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$local = self::get_local_services();
		$found = false;
		foreach ( $local as $i => $row ) {
			if ( isset( $row['key'] ) && $row['key'] === $validated['key'] ) {
				$local[ $i ] = $validated;
				$found       = true;
				break;
			}
		}
		if ( ! $found ) {
			$local[] = $validated;
		}
		self::save_local_services( $local );

		// Refresh in-memory registry.
		Script_Registry::instance()->register_service( $validated, 'site_local' );

		return $validated;
	}

	/**
	 * Remove a site-local service by key.
	 *
	 * @param string $key Service key.
	 * @return bool
	 */
	public static function remove_local( $key ) {
		$key   = sanitize_key( $key );
		$local = self::get_local_services();
		$next  = array();
		foreach ( $local as $row ) {
			if ( ! is_array( $row ) || empty( $row['key'] ) || $row['key'] === $key ) {
				continue;
			}
			$next[] = $row;
		}
		self::save_local_services( $next );
		return true;
	}

	/**
	 * Compute suggestions from last scan.
	 *
	 * @return array
	 */
	public static function compute() {
		$scan     = Cookie_Scanner::instance()->get_last_scan();
		$hosts    = self::collect_hosts_from_scan( $scan );
		$patterns = Script_Registry::instance()->get_all_patterns();
		$matched  = array();
		foreach ( $patterns as $row ) {
			if ( ! empty( $row['pattern'] ) ) {
				$matched[] = strtolower( (string) $row['pattern'] );
			}
		}

		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$site_host = $site_host ? strtolower( $site_host ) : '';
		$noise     = self::noise_hosts();
		$local     = self::get_local_services();
		$local_keys = array();
		foreach ( $local as $svc ) {
			if ( ! empty( $svc['key'] ) ) {
				$local_keys[ $svc['key'] ] = true;
			}
			foreach ( (array) ( $svc['script_patterns'] ?? array() ) as $p ) {
				$matched[] = strtolower( (string) $p );
			}
		}

		$out = array();
		foreach ( $hosts as $host => $meta ) {
			$host = strtolower( (string) $host );
			if ( '' === $host ) {
				continue;
			}
			if ( $site_host && ( $host === $site_host || self::ends_with( $host, '.' . $site_host ) ) ) {
				continue;
			}
			$skip = false;
			foreach ( $noise as $n ) {
				if ( false !== strpos( $host, strtolower( (string) $n ) ) ) {
					$skip = true;
					break;
				}
			}
			if ( $skip ) {
				continue;
			}
			$already = false;
			foreach ( $matched as $pat ) {
				if ( '' !== $pat && false !== strpos( $host, $pat ) ) {
					$already = true;
					break;
				}
				if ( '' !== $pat && false !== strpos( $pat, $host ) ) {
					$already = true;
					break;
				}
			}
			if ( $already ) {
				continue;
			}

			$category = self::guess_category( $host );
			$key      = 'local_' . sanitize_key( str_replace( '.', '_', $host ) );
			$stub     = array(
				'key'              => $key,
				'name'             => $host,
				'provider'         => $host,
				'category'         => $category,
				'treatment'        => 'consent',
				'script_patterns'  => array( $host ),
				'default_blocking' => true,
			);
			$out[] = array(
				'host'       => $host,
				'category'   => $category,
				'sessions'   => isset( $meta['sessions'] ) ? $meta['sessions'] : array(),
				'sources'    => isset( $meta['sources'] ) ? $meta['sources'] : array(),
				'applied'    => ! empty( $local_keys[ $key ] ),
				'stub'       => $stub,
				'json'       => wp_json_encode( array( 'services' => array( $stub ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $a['host'], $b['host'] );
			}
		);

		return array_slice( $out, 0, 100 );
	}

	/**
	 * Gate extra patterns — site-local suggestions (bundled majors already hardcoded in network-gate.js).
	 *
	 * @return array{analytics: string[], marketing: string[]}
	 */
	public static function gate_extra_patterns() {
		$analytics = array();
		$marketing = array();
		foreach ( Script_Registry::instance()->get_services() as $svc ) {
			$source = isset( $svc['source'] ) ? $svc['source'] : '';
			if ( 'site_local' !== $source ) {
				continue;
			}
			if ( isset( $svc['default_blocking'] ) && ! $svc['default_blocking'] ) {
				continue;
			}
			$cat  = isset( $svc['category'] ) ? $svc['category'] : '';
			$pats = array_merge(
				(array) ( $svc['script_patterns'] ?? array() ),
				(array) ( $svc['iframe_patterns'] ?? array() )
			);
			foreach ( $pats as $p ) {
				$p = strtolower( trim( (string) $p ) );
				if ( '' === $p || strlen( $p ) < 4 ) {
					continue;
				}
				if ( 'marketing' === $cat ) {
					$marketing[] = $p;
				} elseif ( in_array( $cat, array( 'analytics', 'statistics' ), true ) ) {
					$analytics[] = $p;
				} else {
					// Prefer preferences/functional as analytics-gated (fail closed for unknown).
					$analytics[] = $p;
				}
			}
		}
		return array(
			'analytics' => array_values( array_unique( $analytics ) ),
			'marketing' => array_values( array_unique( $marketing ) ),
		);
	}

	/**
	 * Normalize host string.
	 *
	 * @param string $raw Raw host or URL.
	 * @return string
	 */
	public static function normalize_host( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $raw ) ) {
			$host = wp_parse_url( $raw, PHP_URL_HOST );
			return $host ? strtolower( $host ) : '';
		}
		$raw = preg_replace( '#^//#', '', $raw );
		$raw = explode( '/', $raw )[0];
		$raw = explode( '?', $raw )[0];
		$raw = strtolower( $raw );
		$raw = preg_replace( '/:\d+$/', '', $raw );
		return preg_match( '/^[a-z0-9.-]+$/', $raw ) ? $raw : '';
	}

	/**
	 * Collect hosts from last scan payload.
	 *
	 * @param array $scan Scan.
	 * @return array host => meta
	 */
	private static function collect_hosts_from_scan( array $scan ) {
		$hosts = array();
		$add   = static function ( $host, $source, $session = '' ) use ( &$hosts ) {
			$host = Catalog_Suggestions::normalize_host( $host );
			if ( '' === $host ) {
				return;
			}
			if ( ! isset( $hosts[ $host ] ) ) {
				$hosts[ $host ] = array(
					'sources'  => array(),
					'sessions' => array(),
				);
			}
			if ( $source && ! in_array( $source, $hosts[ $host ]['sources'], true ) ) {
				$hosts[ $host ]['sources'][] = $source;
			}
			if ( $session && ! in_array( $session, $hosts[ $host ]['sessions'], true ) ) {
				$hosts[ $host ]['sessions'][] = $session;
			}
		};

		if ( ! empty( $scan['privacy_signals'] ) && is_array( $scan['privacy_signals'] ) ) {
			foreach ( array( 'requests', 'scripts', 'beacons', 'pixels', 'iframes' ) as $bucket ) {
				if ( empty( $scan['privacy_signals'][ $bucket ] ) || ! is_array( $scan['privacy_signals'][ $bucket ] ) ) {
					continue;
				}
				foreach ( $scan['privacy_signals'][ $bucket ] as $row ) {
					if ( is_string( $row ) ) {
						$add( $row, $bucket );
						continue;
					}
					if ( ! is_array( $row ) ) {
						continue;
					}
					$host = isset( $row['host'] ) ? $row['host'] : ( isset( $row['url'] ) ? $row['url'] : '' );
					$add( $host, $bucket );
				}
			}
		}

		if ( ! empty( $scan['consent_leaks'] ) && is_array( $scan['consent_leaks'] ) ) {
			foreach ( $scan['consent_leaks'] as $leak ) {
				if ( ! is_array( $leak ) || empty( $leak['name'] ) ) {
					continue;
				}
				if ( isset( $leak['type'] ) && 'cookie' === $leak['type'] ) {
					continue;
				}
				$add( $leak['name'], 'consent_leak' );
			}
		}

		if ( ! empty( $scan['findings'] ) && is_array( $scan['findings'] ) ) {
			foreach ( $scan['findings'] as $f ) {
				if ( ! is_array( $f ) || empty( $f['name'] ) ) {
					continue;
				}
				if ( isset( $f['type'] ) && 'cookie' === $f['type'] ) {
					continue;
				}
				$sessions = isset( $f['sessions'] ) && is_array( $f['sessions'] ) ? $f['sessions'] : array();
				foreach ( $sessions as $sess ) {
					$add( $f['name'], 'finding', sanitize_key( (string) $sess ) );
				}
				if ( empty( $sessions ) ) {
					$add( $f['name'], 'finding' );
				}
			}
		}

		if ( ! empty( $scan['results'] ) && is_array( $scan['results'] ) ) {
			foreach ( $scan['results'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				if ( ! empty( $row['pattern'] ) ) {
					$add( $row['pattern'], 'result' );
				}
				if ( ! empty( $row['url'] ) ) {
					$add( $row['url'], 'result' );
				}
			}
		}

		return $hosts;
	}

	/**
	 * PHP 7.4-safe ends-with.
	 *
	 * @param string $haystack Haystack.
	 * @param string $needle   Needle.
	 * @return bool
	 */
	private static function ends_with( $haystack, $needle ) {
		$needle = (string) $needle;
		if ( '' === $needle ) {
			return true;
		}
		$len = strlen( $needle );
		return substr( (string) $haystack, -$len ) === $needle;
	}
}
