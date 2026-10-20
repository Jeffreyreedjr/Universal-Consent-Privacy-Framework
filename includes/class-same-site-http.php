<?php
/**
 * Same-site HTTP redirect helpers (SSRF hardening for WP helper scanner).
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Pure URL helpers for same-site redirect following.
 */
class Same_Site_Http {

	/**
	 * Resolve a redirect Location against the current request URL.
	 *
	 * @param string $current  Current absolute URL.
	 * @param string $location Location header value.
	 * @return string Empty if unresolvable.
	 */
	public static function resolve_redirect_url( $current, $location ) {
		$location = trim( (string) $location );
		if ( '' === $location ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $location ) ) {
			return function_exists( 'esc_url_raw' ) ? esc_url_raw( $location ) : $location;
		}
		$base = function_exists( 'wp_parse_url' ) ? wp_parse_url( $current ) : parse_url( $current ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		if ( ! is_array( $base ) || empty( $base['scheme'] ) || empty( $base['host'] ) ) {
			return '';
		}
		$origin = $base['scheme'] . '://' . $base['host'];
		if ( ! empty( $base['port'] ) ) {
			$origin .= ':' . (int) $base['port'];
		}
		if ( 0 === strpos( $location, '//' ) ) {
			$abs = $base['scheme'] . ':' . $location;
			return function_exists( 'esc_url_raw' ) ? esc_url_raw( $abs ) : $abs;
		}
		if ( 0 === strpos( $location, '/' ) ) {
			$abs = $origin . $location;
			return function_exists( 'esc_url_raw' ) ? esc_url_raw( $abs ) : $abs;
		}
		$path = isset( $base['path'] ) ? (string) $base['path'] : '/';
		$dir  = rtrim( str_replace( '\\', '/', dirname( $path ) ), '/' );
		if ( '' === $dir ) {
			$dir = '';
		}
		$abs = $origin . $dir . '/' . ltrim( $location, '/' );
		return function_exists( 'esc_url_raw' ) ? esc_url_raw( $abs ) : $abs;
	}

	/**
	 * Whether a redirect Location stays on an allowed host (case-insensitive).
	 *
	 * @param string $current       Current absolute URL.
	 * @param string $location      Location header.
	 * @param string $allowed_host  Allowed hostname.
	 * @return bool
	 */
	public static function redirect_stays_on_host( $current, $location, $allowed_host ) {
		$next = self::resolve_redirect_url( $current, $location );
		if ( ! $next || ! $allowed_host ) {
			return false;
		}
		$host = function_exists( 'wp_parse_url' ) ? wp_parse_url( $next, PHP_URL_HOST ) : parse_url( $next, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		if ( ! $host ) {
			return false;
		}
		return strtolower( (string) $host ) === strtolower( (string) $allowed_host );
	}
}
