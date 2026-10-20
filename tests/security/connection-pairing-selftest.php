<?php
/**
 * Pure-logic self-test for multisite connection URL/secret pairing.
 *
 * Run: php tests/security/connection-pairing-selftest.php
 *
 * @package UCPF
 */

define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__, 2 ) . '/includes/class-settings.php';

use UCPF\Settings;

$failed = 0;

/**
 * @param string   $name Name.
 * @param callable $fn   Test.
 */
function ucpf_pair_test( $name, $fn ) {
	global $failed;
	try {
		$fn();
		echo "ok - {$name}\n";
	} catch ( Throwable $e ) {
		$failed++;
		echo "FAIL - {$name}: " . $e->getMessage() . "\n";
	}
}

ucpf_pair_test(
	'forces network URL when site URL + network secret + no site secret',
	static function () {
		$force = Settings::evaluate_force_network_connection_url(
			'scanner_api_url',
			array(
				'is_multisite'       => true,
				'has_constant'       => false,
				'site_has_secret'    => false,
				'site_has_url'       => true,
				'network_has_secret' => true,
			)
		);
		if ( ! $force ) {
			throw new RuntimeException( 'expected force=true' );
		}
	}
);

ucpf_pair_test(
	'allows site URL when site has its own secret',
	static function () {
		$force = Settings::evaluate_force_network_connection_url(
			'scanner_api_url',
			array(
				'is_multisite'       => true,
				'has_constant'       => false,
				'site_has_secret'    => true,
				'site_has_url'       => true,
				'network_has_secret' => true,
			)
		);
		if ( $force ) {
			throw new RuntimeException( 'expected force=false when site has secret' );
		}
	}
);

ucpf_pair_test(
	'no force on single-site',
	static function () {
		$force = Settings::evaluate_force_network_connection_url(
			'scanner_api_url',
			array(
				'is_multisite'       => false,
				'has_constant'       => false,
				'site_has_secret'    => false,
				'site_has_url'       => true,
				'network_has_secret' => true,
			)
		);
		if ( $force ) {
			throw new RuntimeException( 'expected force=false on single-site' );
		}
	}
);

ucpf_pair_test(
	'no force when constant provides secret',
	static function () {
		$force = Settings::evaluate_force_network_connection_url(
			'scanner_api_url',
			array(
				'is_multisite'       => true,
				'has_constant'       => true,
				'site_has_secret'    => false,
				'site_has_url'       => true,
				'network_has_secret' => true,
			)
		);
		if ( $force ) {
			throw new RuntimeException( 'expected force=false when constant set' );
		}
	}
);

ucpf_pair_test(
	'privacy_api_url pair also forced',
	static function () {
		$force = Settings::evaluate_force_network_connection_url(
			'privacy_api_url',
			array(
				'is_multisite'       => true,
				'has_constant'       => false,
				'site_has_secret'    => false,
				'site_has_url'       => true,
				'network_has_secret' => true,
			)
		);
		if ( ! $force ) {
			throw new RuntimeException( 'expected force=true for privacy_api_url' );
		}
	}
);

if ( $failed ) {
	fwrite( STDERR, "\n{$failed} connection-pairing self-test(s) failed\n" );
	exit( 1 );
}
echo "\nAll connection-pairing self-tests passed\n";
