<?php
/**
 * WooCommerce compatibility.
 *
 * @package UCPF
 */

namespace UCPF\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce integration.
 */
class WooCommerce {

	/**
	 * Instance.
	 *
	 * @var WooCommerce|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return WooCommerce
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Init.
	 */
	public function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_filter( 'ucpf_consent_categories', array( $this, 'annotate_necessary' ) );
		add_filter( 'ucpf_scanner_urls', array( $this, 'ensure_woo_urls' ) );
	}

	/**
	 * Document WooCommerce cookies as necessary.
	 *
	 * @param array $categories Categories.
	 * @return array
	 */
	public function annotate_necessary( $categories ) {
		if ( isset( $categories['necessary'] ) ) {
			$categories['necessary']['description'] .= ' ' . __( 'Includes WooCommerce cart and session cookies required for checkout.', 'universal-consent-privacy-framework' );
		}
		return $categories;
	}

	/**
	 * Ensure Woo pages are in the scanner URL list.
	 *
	 * @param array $urls URLs.
	 * @return array
	 */
	public function ensure_woo_urls( $urls ) {
		// Default scanner already adds Woo URLs when active; keep hook for extensions.
		return $urls;
	}
}
