<?php
/**
 * Shared scan noise filters — omit lockouts, ephemeral WAF, logged-in-only cookies.
 *
 * Rules live in data/noise-filters.json (mirrored in tools/ucpf-scanner/rules/).
 * Filterable for agencies: ucpf_scan_noise_filters, ucpf_is_cookie_scan_noise.
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Scan noise filter.
 */
class Scan_Noise_Filter {

	/**
	 * Cached rules.
	 *
	 * @var array|null
	 */
	private static $rules = null;

	/**
	 * Load and filter rules.
	 *
	 * @return array
	 */
	public static function get_rules() {
		if ( null !== self::$rules ) {
			return self::$rules;
		}

		$path = UCPF_PLUGIN_DIR . 'data/noise-filters.json';
		$data = array();
		if ( is_readable( $path ) ) {
			$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false !== $raw ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) ) {
					$data = $decoded;
				}
			}
		}

		/**
		 * Filter global scan noise rules (cookie omit patterns, leak ignores, etc.).
		 *
		 * @param array $data Rules from data/noise-filters.json.
		 */
		self::$rules = apply_filters( 'ucpf_scan_noise_filters', $data );
		return self::$rules;
	}

	/**
	 * Glob match: * → any chars (case-insensitive full string).
	 *
	 * @param string $name    Cookie name.
	 * @param string $pattern Pattern with optional *.
	 * @return bool
	 */
	public static function name_matches_pattern( $name, $pattern ) {
		$name    = (string) $name;
		$pattern = (string) $pattern;
		if ( '' === $name || '' === $pattern ) {
			return false;
		}
		if ( false !== strpos( $pattern, '*' ) ) {
			$regex = '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/i';
			return (bool) preg_match( $regex, $name );
		}
		return 0 === strcasecmp( $name, $pattern );
	}

	/**
	 * Whether a cookie should be omitted from inventory / policy.
	 *
	 * @param string $name Cookie name.
	 * @return bool
	 */
	public static function should_omit_cookie( $name ) {
		$name  = (string) $name;
		$rules = self::get_rules();

		$omit = false;
		$reason = '';

		foreach ( isset( $rules['cookie_omit'] ) && is_array( $rules['cookie_omit'] ) ? $rules['cookie_omit'] : array() as $row ) {
			if ( ! is_array( $row ) || empty( $row['pattern'] ) ) {
				continue;
			}
			if ( self::name_matches_pattern( $name, $row['pattern'] ) ) {
				$omit   = true;
				$reason = isset( $row['reason'] ) ? (string) $row['reason'] : 'noise';
				break;
			}
		}

		if ( ! $omit ) {
			foreach ( isset( $rules['cookie_omit_regex'] ) && is_array( $rules['cookie_omit_regex'] ) ? $rules['cookie_omit_regex'] : array() as $row ) {
				if ( ! is_array( $row ) || empty( $row['regex'] ) ) {
					continue;
				}
				$regex = '/' . str_replace( '/', '\/', (string) $row['regex'] ) . '/i';
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid agency regex should not fatal.
				if ( @preg_match( $regex, $name ) ) {
					$omit   = true;
					$reason = isset( $row['reason'] ) ? (string) $row['reason'] : 'noise';
					break;
				}
			}
		}

		/**
		 * Filter whether a cookie name is scan noise (omit from clean inventories).
		 *
		 * @param bool   $omit   Whether to omit.
		 * @param string $name   Cookie name.
		 * @param string $reason Match reason.
		 */
		return (bool) apply_filters( 'ucpf_is_cookie_scan_noise', $omit, $name, $reason );
	}

	/**
	 * Whether a consent-leak row should be ignored.
	 *
	 * @param string $type Type (cookie|request|script|…).
	 * @param string $name Cookie name or URL/host.
	 * @return bool
	 */
	public static function should_ignore_leak( $type, $name ) {
		$type = sanitize_key( (string) $type );
		$name = (string) $name;
		$rules = self::get_rules();

		if ( 'cookie' === $type ) {
			if ( self::should_omit_cookie( $name ) ) {
				return true;
			}
			foreach ( isset( $rules['leak_ignore_cookies'] ) && is_array( $rules['leak_ignore_cookies'] ) ? $rules['leak_ignore_cookies'] : array() as $row ) {
				if ( is_array( $row ) && ! empty( $row['pattern'] ) && self::name_matches_pattern( $name, $row['pattern'] ) ) {
					return true;
				}
			}
			return false;
		}

		$lower = strtolower( $name );
		foreach ( isset( $rules['leak_ignore_hosts'] ) && is_array( $rules['leak_ignore_hosts'] ) ? $rules['leak_ignore_hosts'] : array() as $row ) {
			$host = isset( $row['host'] ) ? strtolower( (string) $row['host'] ) : '';
			if ( $host && false !== strpos( $lower, $host ) ) {
				return true;
			}
		}
		foreach ( isset( $rules['leak_ignore_url_substrings'] ) && is_array( $rules['leak_ignore_url_substrings'] ) ? $rules['leak_ignore_url_substrings'] : array() as $sub ) {
			$sub = strtolower( (string) $sub );
			if ( $sub && false !== strpos( $lower, $sub ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a storage key should be omitted.
	 *
	 * @param string $key Storage key.
	 * @return bool
	 */
	public static function should_omit_storage_key( $key ) {
		$key   = (string) $key;
		$rules = self::get_rules();
		foreach ( isset( $rules['storage_omit'] ) && is_array( $rules['storage_omit'] ) ? $rules['storage_omit'] : array() as $row ) {
			if ( is_array( $row ) && isset( $row['key'] ) && 0 === strcasecmp( $key, (string) $row['key'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a network/iframe/script inventory signal should be omitted.
	 *
	 * @param string $url_or_host URL or host.
	 * @return bool
	 */
	public static function should_omit_signal( $url_or_host ) {
		$v = trim( (string) $url_or_host );
		if ( '' === $v ) {
			return true;
		}
		$lower = strtolower( $v );
		$rules = self::get_rules();

		foreach ( isset( $rules['signal_omit_schemes'] ) && is_array( $rules['signal_omit_schemes'] ) ? $rules['signal_omit_schemes'] : array() as $row ) {
			$scheme = isset( $row['scheme'] ) ? strtolower( (string) $row['scheme'] ) : '';
			if ( $scheme && 0 === strpos( $lower, $scheme ) ) {
				return true;
			}
		}
		foreach ( isset( $rules['signal_omit_hosts'] ) && is_array( $rules['signal_omit_hosts'] ) ? $rules['signal_omit_hosts'] : array() as $row ) {
			$host = isset( $row['host'] ) ? strtolower( (string) $row['host'] ) : '';
			if ( $host && ( $lower === $host || false !== strpos( $lower, $host ) ) ) {
				return true;
			}
		}

		/**
		 * Filter whether a request/iframe/script host is inventory noise.
		 *
		 * @param bool   $omit Whether to omit.
		 * @param string $v    URL or host.
		 */
		return (bool) apply_filters( 'ucpf_is_signal_scan_noise', false, $v );
	}

	/**
	 * Collapse ephemeral CDN worker hosts onto a stable parent for inventory dedupe.
	 *
	 * @param string $host_or_url Host or URL.
	 * @return string
	 */
	public static function collapse_signal_host( $host_or_url ) {
		$host = trim( (string) $host_or_url );
		if ( '' === $host ) {
			return '';
		}
		if ( false !== strpos( $host, '://' ) ) {
			$parts = wp_parse_url( $host );
			$host  = isset( $parts['host'] ) ? (string) $parts['host'] : $host;
		} else {
			$parts = explode( '/', $host );
			$host  = (string) $parts[0];
		}
		$host  = strtolower( rtrim( (string) $host, '.' ) );
		$rules = self::get_rules();
		foreach ( isset( $rules['signal_host_collapse'] ) && is_array( $rules['signal_host_collapse'] ) ? $rules['signal_host_collapse'] : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$suffix = isset( $row['suffix'] ) ? strtolower( (string) $row['suffix'] ) : '';
			$to     = isset( $row['to'] ) ? strtolower( (string) $row['to'] ) : '';
			if ( '' === $suffix || '' === $to ) {
				continue;
			}
			$bare = ltrim( $suffix, '.' );
			if ( $host === $bare || substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return $to;
			}
		}
		return $host;
	}

	/**
	 * Filter cookie row arrays (known or unknown) by name.
	 *
	 * @param array $rows Cookie rows.
	 * @return array
	 */
	public static function filter_cookie_rows( array $rows ) {
		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['name'] ) ) {
				continue;
			}
			if ( self::should_omit_cookie( $row['name'] ) ) {
				continue;
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Filter consent leak rows.
	 *
	 * @param array $leaks Leak rows.
	 * @return array
	 */
	public static function filter_consent_leaks( array $leaks ) {
		$out = array();
		foreach ( $leaks as $leak ) {
			if ( ! is_array( $leak ) ) {
				continue;
			}
			$type = isset( $leak['type'] ) ? (string) $leak['type'] : '';
			$name = isset( $leak['name'] ) ? (string) $leak['name'] : '';
			if ( self::should_ignore_leak( $type, $name ) ) {
				continue;
			}
			$out[] = $leak;
		}
		return $out;
	}

	/**
	 * Filter storage rows.
	 *
	 * @param array $storage Storage rows.
	 * @return array
	 */
	public static function filter_storage_rows( array $storage ) {
		$out = array();
		foreach ( $storage as $row ) {
			if ( ! is_array( $row ) || empty( $row['key'] ) ) {
				continue;
			}
			if ( self::should_omit_storage_key( $row['key'] ) ) {
				continue;
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Whether a detected "service" key is noise (CMP rivals, first-party placeholders, sanitize_key garbage).
	 *
	 * @param string $key Service key or sanitize_key(provider).
	 * @return bool
	 */
	public static function should_omit_detected_service( $key ) {
		$key = sanitize_key( (string) $key );
		if ( '' === $key ) {
			return true;
		}

		$exact = array(
			'ucpf',
			'firstpartysite',
			'first_party_site',
			'first-partysite',
			'wordpress',
			'wordpresscore',
			'wordpress_core',
			'complianzgdpr',
			'complianz',
			'cookiebot',
			'cookieyes',
			'cookielawinfo',
			'customfacebookfeedsmashballoon',
			'gravityformsrecaptcha',
		);
		$omit = in_array( $key, $exact, true );

		if ( ! $omit ) {
			foreach ( array( 'complianz', 'cookiebot', 'cookieyes', 'cookie-law', 'cookienotice' ) as $needle ) {
				if ( false !== strpos( $key, str_replace( '-', '', $needle ) ) || false !== strpos( $key, $needle ) ) {
					$omit = true;
					break;
				}
			}
		}

		/**
		 * Filter whether a detected service key should be omitted from inventory chips.
		 *
		 * @param bool   $omit Whether to omit.
		 * @param string $key  Service key.
		 */
		return (bool) apply_filters( 'ucpf_should_omit_detected_service', $omit, $key );
	}
}
