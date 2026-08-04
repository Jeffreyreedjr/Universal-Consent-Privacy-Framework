<?php
/**
 * White-label / agency brand configuration.
 *
 * Optional drop-in: wp-content/ucpf-brand.php returning an array.
 * Filters: ucpf_brand_config, ucpf_product_name.
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Brand helpers.
 */
class Brand {

	/**
	 * Cached config.
	 *
	 * @var array|null
	 */
	private static $config = null;

	/**
	 * Merged brand configuration.
	 *
	 * @return array{
	 *   product_name:string,
	 *   menu_title:string,
	 *   support_url:string,
	 *   scanner_api_url:string,
	 *   default_theme:string
	 * }
	 */
	public static function config() {
		if ( null !== self::$config ) {
			return self::$config;
		}

		$from_file = array();
		$file      = trailingslashit( WP_CONTENT_DIR ) . 'ucpf-brand.php';
		if ( is_readable( $file ) ) {
			$loaded = include $file;
			if ( is_array( $loaded ) ) {
				$from_file = $loaded;
			}
		}

		$defaults = array(
			'product_name'    => __( 'Universal Consent & Privacy Framework', 'universal-consent-privacy-framework' ),
			'menu_title'      => __( 'Privacy Consent', 'universal-consent-privacy-framework' ),
			'support_url'     => 'https://github.com/Jeffreyreedjr/Universal-Consent-Privacy-Framework',
			'scanner_api_url' => '',
			'default_theme'   => 'classic',
		);

		/**
		 * Filter agency / white-label brand configuration.
		 *
		 * @param array $config Brand keys.
		 */
		self::$config = apply_filters( 'ucpf_brand_config', array_merge( $defaults, $from_file ) );
		return self::$config;
	}

	/**
	 * Public product name (admin titles, powered-by).
	 *
	 * @return string
	 */
	public static function product_name() {
		$cfg  = self::config();
		$name = isset( $cfg['product_name'] ) ? (string) $cfg['product_name'] : 'UCPF';
		/**
		 * Filter display product name.
		 *
		 * @param string $name Product name.
		 */
		return (string) apply_filters( 'ucpf_product_name', $name );
	}

	/**
	 * Admin menu title.
	 *
	 * @return string
	 */
	public static function menu_title() {
		$cfg = self::config();
		return isset( $cfg['menu_title'] ) && $cfg['menu_title']
			? (string) $cfg['menu_title']
			: __( 'Privacy Consent', 'universal-consent-privacy-framework' );
	}

	/**
	 * Support / docs URL.
	 *
	 * @return string
	 */
	public static function support_url() {
		$cfg = self::config();
		$url = isset( $cfg['support_url'] ) ? (string) $cfg['support_url'] : '';
		return $url ? esc_url_raw( $url ) : '';
	}

	/**
	 * Reset cache (tests).
	 */
	public static function reset() {
		self::$config = null;
	}
}
