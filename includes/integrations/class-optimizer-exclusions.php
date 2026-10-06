<?php
/**
 * Exclude UCPF + layout-critical assets from minify / combine / delay optimizers.
 *
 * Fleet hardening: Hummingbird (and peers) combining jQuery / The Plus / Mailchimp
 * Woo pixel into one file causes `jQuery(...).ready is not a function` and broken
 * builder layout. Keep those handles out of optimizer pipelines automatically.
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
				// UCPF consent assets (must load early / uncombined).
				'universal-consent-privacy-framework',
				'ucpf-network-gate',
				'ucpf-consent',
				'ucpf-consent-motion',
				'ucpf-loader',
				'ucpf-form-captcha-guard',
				'ucpf-legal',
				'ucpf-banner',
				'legal.css',
				// Core jQuery (Hummingbird combine + Migrate → ready() crashes).
				'jquery-core',
				'jquery-migrate',
				'jquery.min.js',
				'jquery-migrate.min.js',
				// Elementor frontend (fleet builders).
				'elementor-frontend',
				'elementor-pro-frontend',
				'elementor-pro-webpack-runtime',
				'webpack-pro.runtime',
				'elementor/assets/js',
				// Pro webpack runtime must stay ahead of elementor-pro-frontend.
				// Protecting only frontend (data-no-optimize) while optimizers delay/strip
				// webpack-pro.runtime leaves chunks on a plain array → no elementorProFrontend
				// → Elementor popups / mobile nav never open.
				'elementor-pro/assets/js',
				// The Plus Addons for Elementor.
				'the-plus-addons',
				'theplus',
				'pt-plus',
				'plus-addon',
				// Mailchimp for WooCommerce pixel / SMS (often combined with The Plus).
				'mailchimp-woocommerce-pixel',
				'mailchimp-woocommerce_sms',
				'mailchimp-woocommerce',
				// PayPal / GF PayPal Checkout / Braintree (must not delay past consent unlock).
				'paypal.com/sdk',
				'gform_paypal_sdk',
				'gravityformsppcp',
				'gravityformspaypal',
				'braintreegateway',
			)
		);
	}

	/**
	 * Whether a handle or URL matches an exclusion needle.
	 *
	 * @param string $blob Handle, src, or tag fragment.
	 * @return bool
	 */
	public static function matches( $blob ) {
		$blob = (string) $blob;
		if ( '' === $blob ) {
			return false;
		}
		foreach ( self::needles() as $needle ) {
			if ( $needle && false !== stripos( $blob, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Hook optimizer exclusion filters + fragile script tag protection.
	 */
	public function init() {
		$needles = self::needles();
		if ( ! $needles ) {
			return;
		}

		// Re-enqueue Pro webpack runtime if an optimizer dropped the dependency.
		// Also run late (99) so a missing-on-disk file gets dequeued after Pro registers it.
		add_action( 'wp_print_scripts', array( $this, 'ensure_elementor_pro_webpack_runtime' ), 1 );
		add_action( 'wp_print_scripts', array( $this, 'ensure_elementor_pro_webpack_runtime' ), 99 );
		add_action( 'elementor/frontend/after_enqueue_scripts', array( $this, 'ensure_elementor_pro_webpack_runtime' ), 20 );

		// Hummingbird (delay + minify exclusions).
		add_filter( 'wphb_delay_js_exclusions', array( $this, 'merge_list' ) );
		add_filter( 'wphb_minify_resource', array( $this, 'hummingbird_maybe_skip_minify' ), 10, 3 );
		// Some HB builds use combine-specific skips (pixel must stay uncombined for handle park).
		add_filter( 'wphb_combine_resource', array( $this, 'hummingbird_maybe_skip_minify' ), 10, 3 );

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

		// SiteGround Speed Optimizer (handle-based; dropping ucpf-loader leaves all parked scripts dead).
		add_filter( 'sgo_js_minify_exclude', array( $this, 'merge_handle_list' ) );
		add_filter( 'sgo_javascript_combine_exclude', array( $this, 'merge_handle_list' ) );
		add_filter( 'sgo_js_async_exclude', array( $this, 'merge_handle_list' ) );
		add_filter( 'sgo_javascript_combine_excluded_inline_content', array( $this, 'merge_list' ) );

		// WP Smush Pro — do not lazy-load consent-gated YouTube/Vimeo iframes.
		add_filter( 'smush_skip_iframe_from_lazy_load', array( $this, 'smush_skip_video_iframe' ), 10, 2 );
		add_filter( 'wp_smush_should_skip_iframe', array( $this, 'smush_skip_video_iframe' ), 10, 2 );

		// Stamp fragile scripts so Autoptimize / similar skip them.
		add_filter( 'script_loader_tag', array( $this, 'protect_fragile_scripts' ), 15, 3 );
	}

	/**
	 * WP script handles SiteGround / peers must never minify, combine, or defer.
	 *
	 * @return string[]
	 */
	public static function handles() {
		/**
		 * Filter UCPF script handles excluded from optimizer pipelines.
		 *
		 * @param string[] $handles Script handles.
		 */
		return apply_filters(
			'ucpf_optimizer_exclusion_handles',
			array(
				'ucpf-network-gate',
				'ucpf-consent',
				'ucpf-consent-motion',
				'ucpf-loader',
				'ucpf-form-captcha-guard',
				'elementor-pro-webpack-runtime',
				'elementor-pro-frontend',
				'pro-elements-handlers',
			)
		);
	}

	/**
	 * Merge UCPF handles into a SiteGround-style exclusion list (array of handles).
	 *
	 * @param mixed $list Existing exclusions.
	 * @return array
	 */
	public function merge_handle_list( $list ) {
		if ( ! is_array( $list ) ) {
			$list = array();
		}
		foreach ( self::handles() as $handle ) {
			if ( $handle && ! in_array( $handle, $list, true ) ) {
				$list[] = $handle;
			}
		}
		return $list;
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
	 * Skip Hummingbird minify for excluded resources.
	 *
	 * @param bool   $minify Whether to minify.
	 * @param string $type   Resource type.
	 * @param string $handle Resource handle or URL.
	 * @return bool
	 */
	public function hummingbird_maybe_skip_minify( $minify, $type, $handle ) {
		unset( $type );
		if ( self::matches( (string) $handle ) ) {
			return false;
		}
		return $minify;
	}

	/**
	 * Ensure Elementor Pro's webpack runtime prints whenever Pro frontend is enqueued.
	 *
	 * Optimizers that exclude only `elementor-pro-frontend` can strip/delay
	 * `elementor-pro-webpack-runtime`, so frontend.min.js pushes chunks onto a
	 * plain array and `window.elementorProFrontend` never appears (mobile nav /
	 * popups die). Re-enqueue late so the dependency is back in the print queue.
	 *
	 * Elementor Pro 4.3+ may omit webpack-pro.runtime entirely (self-contained
	 * bundles). Never enqueue a missing file — origin 404 HTML becomes
	 * `SyntaxError: Unexpected token '<'` at webpack-pro.runtime.min.js:1.
	 *
	 * @return void
	 */
	public function ensure_elementor_pro_webpack_runtime() {
		if ( ! wp_script_is( 'elementor-pro-frontend', 'enqueued' ) ) {
			return;
		}

		$suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
		$rel    = 'assets/js/webpack-pro.runtime' . $suffix . '.js';
		$path   = '';
		if ( defined( 'ELEMENTOR_PRO_PATH' ) ) {
			$path = ELEMENTOR_PRO_PATH . $rel;
		} elseif ( defined( 'WP_PLUGIN_DIR' ) ) {
			$path = WP_PLUGIN_DIR . '/elementor-pro/' . $rel;
		}

		if ( ! $path || ! is_readable( $path ) ) {
			// Ghost handle (ours or Pro's) → 404 HTML as JS. Drop it.
			if ( wp_script_is( 'elementor-pro-webpack-runtime', 'enqueued' ) ) {
				wp_dequeue_script( 'elementor-pro-webpack-runtime' );
			}
			if ( wp_script_is( 'elementor-pro-webpack-runtime', 'registered' ) ) {
				wp_deregister_script( 'elementor-pro-webpack-runtime' );
			}
			return;
		}

		if ( ! wp_script_is( 'elementor-pro-webpack-runtime', 'registered' ) ) {
			if ( ! defined( 'ELEMENTOR_PRO_URL' ) || ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
				return;
			}
			wp_register_script(
				'elementor-pro-webpack-runtime',
				ELEMENTOR_PRO_URL . $rel,
				array(),
				ELEMENTOR_PRO_VERSION,
				true
			);
		}

		if ( ! wp_script_is( 'elementor-pro-webpack-runtime', 'enqueued' ) ) {
			wp_enqueue_script( 'elementor-pro-webpack-runtime' );
		}
	}

	/**
	 * Keep layout-critical scripts out of CF Rocket Loader / Autoptimize-style optimizers.
	 *
	 * @param string $tag    Script tag.
	 * @param string $handle Handle.
	 * @param string $src    Source URL.
	 * @return string
	 */
	public function protect_fragile_scripts( $tag, $handle, $src ) {
		$blob = (string) $handle . ' ' . (string) $src;
		if ( ! self::matches( $blob ) ) {
			return $tag;
		}
		if ( false === strpos( $tag, 'data-cfasync' ) ) {
			$tag = str_replace( '<script ', '<script data-cfasync="false" ', $tag );
		}
		if ( false === strpos( $tag, 'data-no-optimize' ) ) {
			$tag = str_replace( '<script ', '<script data-no-optimize="1" data-no-defer="1" ', $tag );
		}
		return $tag;
	}

	/**
	 * Clear Hummingbird Asset Optimization (minify) cache when the API is available.
	 *
	 * Safe no-op if Hummingbird is inactive. Prefer minify clear over a full HB flush.
	 *
	 * @return bool True when a clear was attempted successfully.
	 */
	public static function clear_hummingbird_ao() {
		/**
		 * Whether to clear Hummingbird AO on UCPF asset bust.
		 *
		 * @param bool $allow Default true.
		 */
		if ( ! (bool) apply_filters( 'ucpf_clear_hummingbird_ao_on_bust', true ) ) {
			return false;
		}

		$cleared = false;

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party HB APIs.

		// Namespaced Hummingbird Pro / current.
		if ( class_exists( '\Hummingbird\WP_Hummingbird' ) && method_exists( '\Hummingbird\WP_Hummingbird', 'get_instance' ) ) {
			try {
				$hb = \Hummingbird\WP_Hummingbird::get_instance();
				if ( $hb && isset( $hb->core ) && isset( $hb->core->modules['minify'] ) ) {
					$mod = $hb->core->modules['minify'];
					if ( is_object( $mod ) && method_exists( $mod, 'clear_cache' ) ) {
						$mod->clear_cache();
						$cleared = true;
					}
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Ignore HB API differences across versions.
			}
		}

		// Legacy global class.
		if ( ! $cleared && class_exists( 'WP_Hummingbird' ) && method_exists( 'WP_Hummingbird', 'get_instance' ) ) {
			try {
				$hb = \WP_Hummingbird::get_instance();
				if ( $hb && isset( $hb->core ) && isset( $hb->core->modules['minify'] ) ) {
					$mod = $hb->core->modules['minify'];
					if ( is_object( $mod ) && method_exists( $mod, 'clear_cache' ) ) {
						$mod->clear_cache();
						$cleared = true;
					}
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Ignore.
			}
		}

		// Helpers used by some HB builds.
		if ( ! $cleared && function_exists( 'wphb_clear_minification_cache' ) ) {
			wphb_clear_minification_cache();
			$cleared = true;
		} elseif ( ! $cleared && has_action( 'wphb_clear_minification_cache' ) ) {
			do_action( 'wphb_clear_minification_cache' );
			$cleared = true;
		}

		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		/**
		 * After attempting Hummingbird AO clear.
		 *
		 * @param bool $cleared Whether a clear path ran.
		 */
		do_action( 'ucpf_cleared_hummingbird_ao', $cleared );

		return $cleared;
	}

	/**
	 * Skip Smush iframe lazy-load for YouTube / Vimeo embeds (consent-gated).
	 *
	 * @param bool   $skip Whether Smush should skip.
	 * @param string $src  Iframe src or markup snippet.
	 * @return bool
	 */
	public function smush_skip_video_iframe( $skip, $src = '' ) {
		if ( $skip ) {
			return true;
		}
		$blob = strtolower( (string) $src );
		if (
			false !== strpos( $blob, 'player.vimeo.com' ) ||
			false !== strpos( $blob, 'youtube.com/embed' ) ||
			false !== strpos( $blob, 'youtube-nocookie.com' ) ||
			false !== strpos( $blob, 'youtu.be/' ) ||
			false !== strpos( $blob, 'data-ucpf-gated' ) ||
			false !== strpos( $blob, 'elementor-video-iframe' )
		) {
			return true;
		}
		return (bool) $skip;
	}
}
