<?php
/**
 * Third-party integrations loader.
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Integrations hub.
 */
class Integrations {

	/**
	 * Instance.
	 *
	 * @var Integrations|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Integrations
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Init integrations.
	 */
	public function init() {
		Integrations\Google_Consent_Mode::instance()->init();

		if ( class_exists( 'WooCommerce' ) ) {
			Integrations\WooCommerce::instance()->init();
		}

		add_action( 'elementor/frontend/after_enqueue_styles', array( $this, 'ensure_banner_zindex' ) );
	}

	/**
	 * Ensure banner displays above Elementor layers.
	 */
	public function ensure_banner_zindex() {
		wp_add_inline_style(
			'ucpf-banner',
			'#ucpf-root{position:relative;z-index:2147483000;}#ucpf-banner.ucpf-banner--visible{display:block!important;visibility:visible!important;opacity:1!important;}'
		);
	}
}
