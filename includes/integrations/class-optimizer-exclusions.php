<?php
/**
 * Exclude UCPF assets from minify / combine / delay optimizers.
 *
 * @package UCPF
 */

namespace UCPF\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Registers exclusion needles for Hummingbird, Autoptimize, WP Rocket, LiteSpeed.
 */
class Optimizer_Exclusions {

	/**
	 * Instance.
	 *
	 * @var Optimizer_Exclusions|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Optimizer_Exclusions
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Path / handle needles to keep out of optimizer pipelines.
	 *
	 * @return string[]
	 */
	public static function needles() {
		/**
		 * Filter UCPF optimizer exclusion needles.
		 *
		 * @param string[] $needles Substrings matched against script/style URLs and handles.
		 */
		return apply_filters(
			'ucpf_optimizer_exclusion_needles',
			array(
				'universal-consent-privacy-framework',
				'ucpf-network-gate',
				'ucpf-consent',
				'ucpf-consent-motion',
				'ucpf-loader',
				'ucpf-form-captcha-guard',
				'ucpf-legal',
				'ucpf-banner',
				'legal.css',
			)
		);
	}

	/**
	 * Hook optimizer exclusion filters.
	 */
	public function init() {
		$needles = self::needles();
		if ( ! $needles ) {
			return;
		}

		// Hummingbird (delay + minify exclusions).
		add_filter( 'wphb_delay_js_exclusions', array( $this, 'merge_list' ) );
		add_filter( 'wphb_minify_resource', array( $this, 'hummingbird_maybe_skip_minify' ), 10, 3 );

		// Autoptimize.
		add_filter( 'autoptimize_filter_js_exclude', array( $this, 'autoptimize_exclude_csv' ) );
		add_filter( 'autoptimize_filter_css_exclude', array( $this, 'autoptimize_exclude_csv' ) );

		// WP Rocket.
		add_filter( 'rocket_exclude_js', array( $this, 'merge_list' ) );
		add_filter( 'rocket_exclude_css', array( $this, 'merge_list' ) );
		add_filter( 'rocket_exclude_defer_js', array( $this, 'merge_list' ) );
		add_filter( 'rocket_delay_js_exclusions', array( $this, 'merge_list' ) );

		// LiteSpeed Cache.
		add_filter( 'litespeed_optimize_js_excludes', array( $this, 'merge_list' ) );
		add_filter( 'litespeed_optimize_css_excludes', array( $this, 'merge_list' ) );
		add_filter( 'litespeed_optm_js_defer_exc', array( $this, 'merge_list' ) );
		add_filter( 'litespeed_optm_js_delay_exc', array( $this, 'merge_list' ) );
	}

	/**
	 * Merge UCPF needles into an exclusion list.
	 *
	 * @param mixed $list Existing exclusions (array or CSV string).
	 * @return mixed
	 */
	public function merge_list( $list ) {
		$needles = self::needles();
		if ( is_string( $list ) ) {
			$parts = array_filter( array_map( 'trim', explode( ',', $list ) ) );
			foreach ( $needles as $needle ) {
				if ( ! in_array( $needle, $parts, true ) ) {
					$parts[] = $needle;
				}
			}
			return implode( ',', $parts );
		}
		if ( ! is_array( $list ) ) {
			$list = array();
		}
		foreach ( $needles as $needle ) {
			if ( ! in_array( $needle, $list, true ) ) {
				$list[] = $needle;
			}
		}
		return $list;
	}

	/**
	 * Autoptimize CSV exclude string.
	 *
	 * @param string $exclude Existing CSV.
	 * @return string
	 */
	public function autoptimize_exclude_csv( $exclude ) {
		$exclude = is_string( $exclude ) ? $exclude : '';
		return (string) $this->merge_list( $exclude );
	}

	/**
	 * Skip Hummingbird minify for UCPF resources.
	 *
	 * @param bool   $minify Whether to minify.
	 * @param string $type   Resource type.
	 * @param string $handle Resource handle or URL.
	 * @return bool
	 */
	public function hummingbird_maybe_skip_minify( $minify, $type, $handle ) {
		unset( $type );
		$blob = (string) $handle;
		foreach ( self::needles() as $needle ) {
			if ( $needle && false !== stripos( $blob, $needle ) ) {
				return false;
			}
		}
		return $minify;
	}
}
