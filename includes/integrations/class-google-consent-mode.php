<?php
/**
 * Google Consent Mode v2.
 *
 * @package UCPF
 */

namespace UCPF\Integrations;

use UCPF\Consent_Manager;
use UCPF\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Google Consent Mode integration.
 */
class Google_Consent_Mode {

	/**
	 * Instance.
	 *
	 * @var Google_Consent_Mode|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Google_Consent_Mode
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
		// Enqueued from maybe_enqueue.
	}

	/**
	 * Maybe enqueue consent mode defaults.
	 */
	public function maybe_enqueue() {
		$mode = Settings::get( 'google_consent_mode' );
		if ( 'off' === $mode || ! $mode ) {
			return;
		}

		$defaults = apply_filters(
			'ucpf_google_consent_mode_defaults',
			array(
				'ad_storage'         => 'denied',
				'analytics_storage'  => 'denied',
				'ad_user_data'       => 'denied',
				'ad_personalization' => 'denied',
				'wait_for_update'    => 500,
			)
		);

		if ( Consent_Manager::instance()->is_discover_mode() ) {
			$defaults = array(
				'ad_storage'         => 'granted',
				'analytics_storage'  => 'granted',
				'ad_user_data'       => 'granted',
				'ad_personalization' => 'granted',
			);
		}

		$script = "window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('consent','default'," . wp_json_encode( $defaults ) . ');';

		// Returning visitors: re-grant from cookie before tags fire (default alone stays denied).
		if ( ! Consent_Manager::instance()->is_discover_mode() ) {
			$cookie = Consent_Manager::instance()->read_cookie();
			if ( is_array( $cookie ) && ! empty( $cookie['categories'] ) && is_array( $cookie['categories'] ) ) {
				$script .= $this->build_update_script( $cookie['categories'] );
			}
		}

		wp_add_inline_script( 'ucpf-consent', $script, 'before' );
	}

	/**
	 * Build update script from categories.
	 *
	 * @param array $categories Categories.
	 * @return string
	 */
	public function build_update_script( array $categories ) {
		$privacy = \UCPF\Privacy_State::instance();
		$mkt     = ! empty( $categories['marketing'] ) && $privacy->allows_category( 'marketing' );
		$an      = ! empty( $categories['analytics'] ) && $privacy->allows_category( 'analytics' );
		$update  = array(
			'ad_storage'         => $mkt ? 'granted' : 'denied',
			'analytics_storage'  => $an ? 'granted' : 'denied',
			'ad_user_data'       => $mkt ? 'granted' : 'denied',
			'ad_personalization' => $mkt ? 'granted' : 'denied',
		);

		return "if(typeof gtag==='function'){gtag('consent','update'," . wp_json_encode( $update ) . ');}';
	}
}
