<?php
/**
 * UI-safe URL helpers (visitor-facing hrefs / branding).
 *
 * @package UCPF
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sanitize a visitor-facing or branding HTTP(S) URL for UI use.
 *
 * Rejects javascript:, data:, and other non-http schemes after esc_url_raw.
 *
 * @param string $raw Raw URL.
 * @return string Empty string when unsafe or blank.
 */
function ucpf_sanitize_public_http_url( $raw ) {
	$url = esc_url_raw( trim( (string) $raw ) );
	if ( '' === $url ) {
		return '';
	}
	$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
	if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
		return '';
	}
	return $url;
}

/**
 * Whether a URL is safe to use as an admin UI action link (http/https only).
 *
 * @param string $url URL.
 * @return bool
 */
function ucpf_is_safe_ui_href( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return false;
	}
	// Relative admin paths (e.g. admin.php?page=…).
	if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
		return true;
	}
	$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
	return in_array( $scheme, array( 'http', 'https' ), true );
}
