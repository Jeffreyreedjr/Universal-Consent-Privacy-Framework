<?php
/**
 * Outbound URL safety checks (SSRF hardening).
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Blocks private / link-local / metadata targets for admin-configured outbound URLs.
 */
class Http_Url_Guard {

	/**
	 * Whether private / loopback outbound URLs are explicitly allowed.
	 *
	 * @return bool
	 */
	public static function allow_private() {
		if ( defined( 'UCPF_ALLOW_PRIVATE_SCANNER_URL' ) && UCPF_ALLOW_PRIVATE_SCANNER_URL ) {
			return true;
		}
		/**
		 * Filter whether private/loopback hosts are allowed for scanner/privacy/registry URLs.
		 *
		 * @param bool $allow Default false.
		 */
		return (bool) apply_filters( 'ucpf_allow_private_outbound_url', false );
	}

	/**
	 * Validate a URL for outbound WordPress HTTP requests.
	 *
	 * @param string $raw Raw URL.
	 * @return true|\WP_Error
	 */
	public static function assert_safe_outbound( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return true;
		}

		$parts = wp_parse_url( $raw );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return new \WP_Error( 'ucpf_bad_url', __( 'Invalid outbound URL.', 'universal-consent-privacy-framework' ) );
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new \WP_Error( 'ucpf_bad_scheme', __( 'Only http/https outbound URLs are allowed.', 'universal-consent-privacy-framework' ) );
		}

		$host = strtolower( (string) $parts['host'] );
		$host = trim( $host, '[]' );

		if ( self::allow_private() ) {
			return true;
		}

		if ( self::is_blocked_hostname( $host ) ) {
			return new \WP_Error(
				'ucpf_blocked_host',
				__( 'Private, loopback, or metadata hosts are not allowed. Set UCPF_ALLOW_PRIVATE_SCANNER_URL in wp-config for intentional loopback scanners.', 'universal-consent-privacy-framework' )
			);
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			if ( self::is_blocked_ip( $host ) ) {
				return new \WP_Error(
					'ucpf_blocked_ip',
					__( 'Private or link-local IP addresses are not allowed for outbound URLs.', 'universal-consent-privacy-framework' )
				);
			}
			return true;
		}

		// Best-effort DNS resolve when available (not always present on Windows PHP).
		if ( function_exists( 'dns_get_record' ) ) {
			$records = @dns_get_record( $host, DNS_A + DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_array( $records ) ) {
				foreach ( $records as $rec ) {
					$ip = '';
					if ( ! empty( $rec['ip'] ) ) {
						$ip = (string) $rec['ip'];
					} elseif ( ! empty( $rec['ipv6'] ) ) {
						$ip = (string) $rec['ipv6'];
					}
					if ( $ip && self::is_blocked_ip( $ip ) ) {
						return new \WP_Error(
							'ucpf_blocked_resolved_ip',
							__( 'URL hostname resolves to a private or blocked address.', 'universal-consent-privacy-framework' )
						);
					}
				}
			}
		}

		return true;
	}

	/**
	 * Sanitize / normalize then validate; returns empty string on failure.
	 *
	 * @param string $raw Raw URL.
	 * @return string
	 */
	public static function sanitize_outbound_url( $raw ) {
		$url = Settings::normalize_url( $raw );
		if ( '' === $url ) {
			return '';
		}
		$ok = self::assert_safe_outbound( $url );
		if ( is_wp_error( $ok ) ) {
			return '';
		}
		return $url;
	}

	/**
	 * @param string $host Hostname.
	 * @return bool
	 */
	public static function is_blocked_hostname( $host ) {
		$host = strtolower( (string) $host );
		if ( '' === $host ) {
			return true;
		}
		$blocked = array(
			'localhost',
			'metadata.google.internal',
			'metadata.google',
			'instance-data',
		);
		if ( in_array( $host, $blocked, true ) ) {
			return true;
		}
		if ( preg_match( '/\.(local|internal|localhost)$/', $host ) ) {
			return true;
		}
		if ( false !== strpos( $host, 'metadata' ) && false === strpos( $host, '.' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * @param string $ip IP address.
	 * @return bool
	 */
	public static function is_blocked_ip( $ip ) {
		$ip = strtolower( (string) $ip );
		$ip = preg_replace( '/^::ffff:/', '', $ip );

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$parts = array_map( 'intval', explode( '.', $ip ) );
			if ( 4 !== count( $parts ) ) {
				return true;
			}
			list( $a, $b ) = $parts;
			if ( 10 === $a ) {
				return true;
			}
			if ( 127 === $a ) {
				return true;
			}
			if ( 0 === $a ) {
				return true;
			}
			if ( 169 === $a && 254 === $b ) {
				return true;
			}
			if ( 172 === $a && $b >= 16 && $b <= 31 ) {
				return true;
			}
			if ( 192 === $a && 168 === $b ) {
				return true;
			}
			if ( 100 === $a && $b >= 64 && $b <= 127 ) {
				return true;
			}
			return false;
		}

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			if ( '::1' === $ip ) {
				return true;
			}
			if ( 0 === strpos( $ip, 'fc' ) || 0 === strpos( $ip, 'fd' ) ) {
				return true;
			}
			if ( 0 === strpos( $ip, 'fe80' ) ) {
				return true;
			}
			if ( 0 === strpos( $ip, 'ff' ) ) {
				return true;
			}
			return false;
		}

		return true;
	}
}
