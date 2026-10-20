<?php
/**
 * Standalone test for Theme_Manager::sanitize_custom_css dangerous patterns.
 *
 * Run: php tests/security/css-sanitize-selftest.php
 *
 * @package UCPF
 */

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * @param string $string String.
	 * @return string
	 */
	function wp_strip_all_tags( $string ) {
		return strip_tags( $string ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
	}
}

define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__, 2 ) . '/includes/class-theme-manager.php';

use UCPF\Theme_Manager;

$ref = new ReflectionClass( Theme_Manager::class );
$obj = $ref->newInstanceWithoutConstructor();

$failed = 0;
$cases  = array(
	'@import url("https://evil.example/x.css"); color: red'       => 'import',
	'background: url(https://evil.example/pixel); color: blue'   => 'url',
	'width: expression(alert(1)); color: green'                  => 'expression',
	'color: red; -moz-binding: url(https://evil.example/x.xml#x)' => 'moz-binding',
);

foreach ( $cases as $input => $label ) {
	$out = $obj->sanitize_custom_css( $input );
	$bad = ( false !== stripos( $out, '@import' ) )
		|| ( false !== stripos( $out, 'url(' ) )
		|| ( false !== stripos( $out, 'expression(' ) )
		|| ( false !== stripos( $out, '-moz-binding' ) );
	if ( $bad ) {
		echo "FAIL - css sanitize still contains dangerous token ({$label}): {$out}\n";
		$failed++;
	} else {
		echo "ok - css sanitize strips {$label}\n";
	}
}

if ( $failed ) {
	exit( 1 );
}
echo "\nCSS sanitize self-tests passed\n";
