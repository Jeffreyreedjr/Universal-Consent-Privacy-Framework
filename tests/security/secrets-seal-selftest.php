<?php
/**
 * Secrets::seal fail-closed self-test.
 *
 * Run: php tests/security/secrets-seal-selftest.php
 *
 * @package UCPF
 */

define( 'ABSPATH', __DIR__ . '/' );

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
		global $ucpf_test_filters;
		if ( isset( $ucpf_test_filters[ $tag ] ) ) {
			return call_user_func( $ucpf_test_filters[ $tag ], $value );
		}
		return $value;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * @param string   $tag Tag.
	 * @param callable $cb  Callback.
	 */
	function add_filter( $tag, $cb ) {
		global $ucpf_test_filters;
		$ucpf_test_filters[ $tag ] = $cb;
	}
}
if ( ! function_exists( 'wp_salt' ) ) {
	/**
	 * @param string $scheme Scheme.
	 * @return string
	 */
	function wp_salt( $scheme = 'auth' ) {
		return 'ucpf-test-salt-' . $scheme;
	}
}
if ( ! class_exists( 'WP_Error', false ) ) {
	/**
	 * Minimal WP_Error.
	 */
	class WP_Error {
		/** @var string */
		public $message;
		/**
		 * @param string $code Code.
		 * @param string $message Message.
		 */
		public function __construct( $code = '', $message = '' ) {
			unset( $code );
			$this->message = $message;
		}
		/** @return string */
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

$ucpf_test_filters = array();

require_once dirname( __DIR__, 2 ) . '/includes/class-secrets.php';

use UCPF\Secrets;

$failed = 0;

/**
 * @param string   $name Name.
 * @param callable $fn   Test.
 */
function ucpf_seal_test( $name, $fn ) {
	global $failed;
	try {
		$fn();
		echo "ok - {$name}\n";
	} catch ( Throwable $e ) {
		$failed++;
		echo "FAIL - {$name}: " . $e->getMessage() . "\n";
	}
}

ucpf_seal_test(
	'empty seal stays empty',
	static function () {
		if ( '' !== Secrets::seal( '' ) ) {
			throw new RuntimeException( 'expected empty' );
		}
	}
);

ucpf_seal_test(
	'deny filter returns WP_Error (fail closed)',
	static function () {
		global $ucpf_test_filters;
		$ucpf_test_filters['ucpf_secrets_deny_seal'] = static function () {
			return true;
		};
		$out = Secrets::seal( 'super-secret-token' );
		unset( $ucpf_test_filters['ucpf_secrets_deny_seal'] );
		if ( ! is_wp_error( $out ) ) {
			throw new RuntimeException( 'expected WP_Error when seal denied' );
		}
	}
);

ucpf_seal_test(
	'seal_in_array omits key on failure and sets last_seal_error',
	static function () {
		global $ucpf_test_filters;
		$ucpf_test_filters['ucpf_secrets_deny_seal'] = static function () {
			return true;
		};
		$out = Secrets::seal_in_array(
			array(
				'scanner_api_key' => 'plaintext-key',
				'banner_layout'   => 'bar',
			)
		);
		unset( $ucpf_test_filters['ucpf_secrets_deny_seal'] );
		if ( array_key_exists( 'scanner_api_key', $out ) ) {
			throw new RuntimeException( 'secret key should be omitted on seal failure' );
		}
		if ( ! Secrets::last_seal_error() ) {
			throw new RuntimeException( 'last_seal_error should be set' );
		}
	}
);

if ( function_exists( 'sodium_crypto_secretbox' ) || function_exists( 'openssl_encrypt' ) ) {
	ucpf_seal_test(
		'normal seal produces sealed blob not plaintext',
		static function () {
			$plain = 'token-' . bin2hex( random_bytes( 8 ) );
			$out   = Secrets::seal( $plain );
			if ( is_wp_error( $out ) ) {
				throw new RuntimeException( $out->get_error_message() );
			}
			if ( $out === $plain ) {
				throw new RuntimeException( 'seal must not return plaintext' );
			}
			if ( ! Secrets::is_sealed( $out ) ) {
				throw new RuntimeException( 'expected sealed prefix' );
			}
			if ( Secrets::reveal( $out ) !== $plain ) {
				throw new RuntimeException( 'reveal mismatch' );
			}
		}
	);
} else {
	echo "skip - no sodium/OpenSSL for positive seal case\n";
}

if ( $failed ) {
	fwrite( STDERR, "\n{$failed} secrets-seal self-test(s) failed\n" );
	exit( 1 );
}
echo "\nAll secrets-seal self-tests passed\n";
