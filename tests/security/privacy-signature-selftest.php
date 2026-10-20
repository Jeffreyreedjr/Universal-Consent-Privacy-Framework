<?php
/**
 * Privacy preference signature verification self-test.
 *
 * Run: php tests/security/privacy-signature-selftest.php
 *
 * @package UCPF
 */

define( 'ABSPATH', __DIR__ . '/' );

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
if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * @param mixed $data Data.
	 * @return string|false
	 */
	function wp_json_encode( $data ) {
		return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}

require_once dirname( __DIR__, 2 ) . '/includes/class-privacy-preference-client.php';

use UCPF\Privacy_Preference_Client;

$failed = 0;

/**
 * @param string   $name Name.
 * @param callable $fn   Test.
 */
function ucpf_sig_test( $name, $fn ) {
	global $failed;
	try {
		$fn();
		echo "ok - {$name}\n";
	} catch ( Throwable $e ) {
		$failed++;
		echo "FAIL - {$name}: " . $e->getMessage() . "\n";
	}
}

// Constant must not be defined yet.
ucpf_sig_test(
	'unsigned allowed when no public key',
	static function () {
		if ( defined( 'UCPF_PRIVACY_PUBLIC_KEY' ) ) {
			throw new RuntimeException( 'UCPF_PRIVACY_PUBLIC_KEY already defined; run this self-test in isolation' );
		}
		if ( ! Privacy_Preference_Client::verify_signature( array( 'deny' => array( 'sale' ) ) ) ) {
			throw new RuntimeException( 'expected unsigned ok' );
		}
	}
);

ucpf_sig_test(
	'signed payload rejected without public key',
	static function () {
		$ok = Privacy_Preference_Client::verify_signature(
			array(
				'deny'      => array( 'sale' ),
				'signature' => str_repeat( 'ab', 64 ),
			)
		);
		if ( $ok ) {
			throw new RuntimeException( 'expected reject when no key configured' );
		}
	}
);

if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
	echo "skip - sodium unavailable for positive/negative keypair cases\n";
} else {
	$kp     = sodium_crypto_sign_keypair();
	$secret = sodium_crypto_sign_secretkey( $kp );
	$public = sodium_crypto_sign_publickey( $kp );
	define( 'UCPF_PRIVACY_PUBLIC_KEY', bin2hex( $public ) );

	ucpf_sig_test(
		'unsigned rejected when public key configured',
		static function () {
			if ( Privacy_Preference_Client::verify_signature( array( 'deny' => array() ) ) ) {
				throw new RuntimeException( 'expected reject unsigned with key set' );
			}
		}
	);

	ucpf_sig_test(
		'forged signature rejected',
		static function () {
			$payload = array(
				'deny'      => array( 'sale' ),
				'signature' => bin2hex( random_bytes( SODIUM_CRYPTO_SIGN_BYTES ) ),
			);
			if ( Privacy_Preference_Client::verify_signature( $payload ) ) {
				throw new RuntimeException( 'expected reject forged' );
			}
		}
	);

	ucpf_sig_test(
		'valid signature accepted',
		static function () use ( $secret ) {
			$payload = array(
				'deny'       => array( 'sale' ),
				'expires_at' => time() + 3600,
			);
			ksort( $payload );
			$message           = wp_json_encode( $payload );
			$sig               = sodium_crypto_sign_detached( $message, $secret );
			$payload['signature'] = bin2hex( $sig );
			if ( ! Privacy_Preference_Client::verify_signature( $payload ) ) {
				throw new RuntimeException( 'expected valid signature ok' );
			}
		}
	);
}

if ( $failed ) {
	fwrite( STDERR, "\n{$failed} privacy-signature self-test(s) failed\n" );
	exit( 1 );
}
echo "\nAll privacy-signature self-tests passed\n";
