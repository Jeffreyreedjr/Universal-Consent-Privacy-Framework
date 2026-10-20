<?php
/**
 * Standalone security self-test for Http_Url_Guard (no WordPress bootstrap).
 *
 * Run: php tests/security/http-url-guard-selftest.php
 *
 * @package UCPF
 */

// Minimal stubs used by Http_Url_Guard.
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
if ( ! function_exists( '__' ) ) {
	/**
	 * @param string $text Text.
	 * @return string
	 */
	function __( $text ) {
		return $text;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * @param string $tag Tag.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	function apply_filters( $tag, $value ) {
		unset( $tag );
		return $value;
	}
}
if ( ! class_exists( 'WP_Error', false ) ) {
	/**
	 * Minimal WP_Error stub.
	 */
	class WP_Error {
		/**
		 * @var string
		 */
		public $code;
		/**
		 * @var string
		 */
		public $message;
		/**
		 * @param string $code Code.
		 * @param string $message Message.
		 */
		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		/**
		 * @return string
		 */
		public function get_error_message() {
			return $this->message;
		}
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * @param mixed $thing Thing.
	 * @return bool
	 */
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__, 2 ) . '/includes/class-http-url-guard.php';

use UCPF\Http_Url_Guard;

$failed = 0;

/**
 * @param string   $name Name.
 * @param callable $fn   Test.
 */
function ucpf_sec_test( $name, $fn ) {
	global $failed;
	try {
		$fn();
		echo "ok - {$name}\n";
	} catch ( Throwable $e ) {
		$failed++;
		echo "FAIL - {$name}\n";
		echo $e->getMessage() . "\n";
	}
}

ucpf_sec_test(
	'blocks private IPv4',
	static function () {
		if ( ! Http_Url_Guard::is_blocked_ip( '10.0.0.1' ) ) {
			throw new RuntimeException( 'expected 10.0.0.1 blocked' );
		}
		if ( ! Http_Url_Guard::is_blocked_ip( '169.254.169.254' ) ) {
			throw new RuntimeException( 'expected metadata IP blocked' );
		}
		if ( Http_Url_Guard::is_blocked_ip( '8.8.8.8' ) ) {
			throw new RuntimeException( '8.8.8.8 should be allowed' );
		}
	}
);

ucpf_sec_test(
	'blocks localhost hostname',
	static function () {
		if ( ! Http_Url_Guard::is_blocked_hostname( 'localhost' ) ) {
			throw new RuntimeException( 'localhost should be blocked' );
		}
		$r = Http_Url_Guard::assert_safe_outbound( 'http://127.0.0.1:3847/health' );
		if ( ! is_wp_error( $r ) ) {
			throw new RuntimeException( 'loopback URL should be rejected without allow flag' );
		}
	}
);

ucpf_sec_test(
	'allows public https host without DNS check failure',
	static function () {
		$r = Http_Url_Guard::assert_safe_outbound( 'https://example.com/path' );
		if ( is_wp_error( $r ) ) {
			throw new RuntimeException( $r->get_error_message() );
		}
	}
);

if ( $failed ) {
	fwrite( STDERR, "\n{$failed} self-test(s) failed\n" );
	exit( 1 );
}
echo "\nAll Http_Url_Guard self-tests passed\n";
