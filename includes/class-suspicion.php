<?php
/**
 * Tracking-path suspicion needles (mirrors tools/ucpf-scanner/rules/suspicion.json).
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Shared URL/path suspicion helpers for Script_Blocker + network gate.
 */
class Suspicion {

	const IGNORED_OPTION = 'ucpf_suspicion_ignored';

	/**
	 * Allowlist path fragments (do not gate on suspicion alone).
	 *
	 * @return string[]
	 */
	public static function allowlist() {
		return apply_filters(
			'ucpf_suspicion_allowlist',
			array(
				'jquery.min.js',
				'jquery.js',
				'jquery-migrate',
				'swiper.min.js',
				'swiper-bundle',
				'slick.min.js',
				'wp-includes/js/',
				'wp-emoji',
				'devicepx',
			)
		);
	}

	/**
	 * Path needles gated as marketing until consent / Ignore.
	 *
	 * @return string[]
	 */
	public static function gate_needles() {
		return apply_filters(
			'ucpf_suspicion_gate_needles',
			array(
				'pixel-tracking.js',
				'pixel-tracking',
				'mailchimp-woocommerce-pixel',
				'-pixel.js',
				'/pixel.',
				'pixel.js',
				'-tracking.js',
				'tracking.js',
				'/tracking.',
				'/track.',
				'tracker.js',
				'/analytics.',
				'analytics.js',
				'/ads.',
				'adsense',
				'remarketing',
				'mailchimp-for-woocommerce',
				'mailchimp-woocommerce',
				'mcjs-connected',
			)
		);
	}

	/**
	 * Whether URL matches an allowlist fragment.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_allowlisted( $url ) {
		$u = strtolower( (string) $url );
		foreach ( self::allowlist() as $a ) {
			$a = strtolower( (string) $a );
			if ( $a && false !== strpos( $u, $a ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Matching suspicion needle for a URL, or empty.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function match_needle( $url ) {
		$u = strtolower( (string) $url );
		if ( '' === $u || self::is_allowlisted( $u ) ) {
			return '';
		}
		$ignored = self::get_ignored_patterns();
		foreach ( self::gate_needles() as $needle ) {
			$needle = strtolower( trim( (string) $needle ) );
			if ( '' === $needle || strlen( $needle ) < 4 ) {
				continue;
			}
			if ( false === strpos( $u, $needle ) ) {
				continue;
			}
			if ( in_array( $needle, $ignored, true ) ) {
				continue;
			}
			return $needle;
		}
		return '';
	}

	/**
	 * Operator-ignored suspicion patterns (treated as necessary / skip gate).
	 *
	 * @return string[]
	 */
	public static function get_ignored_patterns() {
		$raw = get_option( self::IGNORED_OPTION, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $p ) {
			$p = strtolower( trim( (string) $p ) );
			if ( '' !== $p ) {
				$out[] = $p;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Persist ignored pattern.
	 *
	 * @param string $pattern Needle.
	 * @return void
	 */
	public static function ignore_pattern( $pattern ) {
		$pattern = strtolower( trim( (string) $pattern ) );
		if ( '' === $pattern ) {
			return;
		}
		$list   = self::get_ignored_patterns();
		$list[] = $pattern;
		update_option( self::IGNORED_OPTION, array_values( array_unique( $list ) ), false );
	}

	/**
	 * Suggest a stable path needle from a full URL for site-local override.
	 *
	 * @param string $url Full URL or path.
	 * @return string
	 */
	public static function suggest_pattern_from_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		$path = $url;
		if ( preg_match( '#^https?://#i', $url ) ) {
			$parts = wp_parse_url( $url );
			$path  = isset( $parts['path'] ) ? (string) $parts['path'] : $url;
		}
		$path = strtolower( $path );
		$hit  = self::match_needle( $path );
		if ( $hit ) {
			return $hit;
		}
		// Prefer filename.
		$base = basename( strtok( $path, '?' ) );
		if ( $base && strlen( $base ) >= 6 && false !== strpos( $base, '.' ) ) {
			return $base;
		}
		// Plugin slug segment.
		if ( preg_match( '#/wp-content/plugins/([^/]+)/#', $path, $m ) ) {
			return $m[1];
		}
		return $path;
	}
}
