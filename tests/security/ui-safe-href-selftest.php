<?php
/**
 * Standalone self-test for UI-safe URL helpers.
 *
 * Run: php tests/security/ui-safe-href-selftest.php
 *
 * @package UCPF
 */

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * @param string   $url       URL.
	 * @param int|null $component Component.
	 * @return mixed
	 */
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * Minimal esc_url_raw stub: strip dangerous schemes; keep http(s).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url_raw( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( preg_match( '#^(javascript|data|vbscript):#i', $url ) ) {
			return '';
		}
		return $url;
	}
}

define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__, 2 ) . '/includes/ui-safe.php';

$failed = 0;

$public_cases = array(
	'https://example.com/logo.png' => true,
	'http://example.com/x'         => true,
	'javascript:alert(1)'          => false,
	'data:text/html,hi'            => false,
	'//evil.example/path'          => false,
	''                             => false,
);

foreach ( $public_cases as $input => $expect_ok ) {
	$out = ucpf_sanitize_public_http_url( $input );
	$ok  = ( '' !== $out );
	if ( $ok !== $expect_ok ) {
		echo "FAIL - sanitize_public_http_url(" . var_export( $input, true ) . ") => " . var_export( $out, true ) . "\n";
		$failed++;
	} else {
		echo 'ok - sanitize_public_http_url ' . ( $expect_ok ? 'allows' : 'rejects' ) . "\n";
	}
}

$href_cases = array(
	'/wp-admin/admin.php?page=ucpf' => true,
	'https://example.com/a'         => true,
	'http://example.com/a'          => true,
	'//evil.example'                => false,
	'javascript:alert(1)'           => false,
	'data:text/html,x'              => false,
	''                              => false,
);

foreach ( $href_cases as $input => $expect_ok ) {
	$ok = ucpf_is_safe_ui_href( $input );
	if ( $ok !== $expect_ok ) {
		echo "FAIL - is_safe_ui_href(" . var_export( $input, true ) . ") => " . var_export( $ok, true ) . "\n";
		$failed++;
	} else {
		echo 'ok - is_safe_ui_href ' . ( $expect_ok ? 'allows' : 'rejects' ) . "\n";
	}
}

if ( $failed ) {
	exit( 1 );
}
echo "\nUI-safe href self-tests passed\n";
