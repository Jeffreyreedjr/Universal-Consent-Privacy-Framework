<?php
/**
 * Same-site redirect helper self-test.
 *
 * Run: php tests/security/same-site-http-selftest.php
 *
 * @package UCPF
 */

define( 'ABSPATH', __DIR__ . '/' );

if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url_raw( $url ) {
		return $url;
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * @param string $url URL.
	 * @param int    $component Component.
	 * @return mixed
	 */
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	}
}

require_once dirname( __DIR__, 2 ) . '/includes/class-same-site-http.php';

use UCPF\Same_Site_Http;

$failed = 0;

/**
 * @param string   $name Name.
 * @param callable $fn   Test.
 */
function ucpf_ssh_test( $name, $fn ) {
	global $failed;
	try {
		$fn();
		echo "ok - {$name}\n";
	} catch ( Throwable $e ) {
		$failed++;
		echo "FAIL - {$name}: " . $e->getMessage() . "\n";
	}
}

ucpf_ssh_test(
	'absolute same-host redirect allowed',
	static function () {
		if ( ! Same_Site_Http::redirect_stays_on_host( 'https://example.com/a', 'https://example.com/b', 'example.com' ) ) {
			throw new RuntimeException( 'expected allow' );
		}
	}
);

ucpf_ssh_test(
	'absolute off-site redirect blocked',
	static function () {
		if ( Same_Site_Http::redirect_stays_on_host( 'https://example.com/a', 'https://evil.example/x', 'example.com' ) ) {
			throw new RuntimeException( 'expected block' );
		}
	}
);

ucpf_ssh_test(
	'relative redirect stays on host',
	static function () {
		if ( ! Same_Site_Http::redirect_stays_on_host( 'https://example.com/path/page', '/other', 'example.com' ) ) {
			throw new RuntimeException( 'expected allow relative' );
		}
	}
);

ucpf_ssh_test(
	'protocol-relative off-site blocked',
	static function () {
		if ( Same_Site_Http::redirect_stays_on_host( 'https://example.com/', '//evil.example/meta', 'example.com' ) ) {
			throw new RuntimeException( 'expected block protocol-relative' );
		}
	}
);

ucpf_ssh_test(
	'metadata IP absolute blocked vs site host',
	static function () {
		if ( Same_Site_Http::redirect_stays_on_host( 'https://example.com/', 'http://169.254.169.254/', 'example.com' ) ) {
			throw new RuntimeException( 'expected block metadata' );
		}
	}
);

if ( $failed ) {
	fwrite( STDERR, "\n{$failed} same-site-http self-test(s) failed\n" );
	exit( 1 );
}
echo "\nAll same-site-http self-tests passed\n";
