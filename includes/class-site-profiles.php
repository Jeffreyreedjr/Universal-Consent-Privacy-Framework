<?php
/**
 * Site profile presets (basic analytics / WP login / WooCommerce).
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Consent-oriented site profiles that seed scan selection + defaults.
 */
class Site_Profiles {

	const BASIC       = 'basic';
	const WP_LOGIN    = 'wp_login';
	const WOOCOMMERCE = 'woocommerce';

	/**
	 * Profile definitions for UI.
	 *
	 * @return array<string, array{label:string, description:string}>
	 */
	public static function definitions() {
		return array(
			self::BASIC       => array(
				'label'       => __( 'Basic / analytics site', 'universal-consent-privacy-framework' ),
				'description' => __( 'Marketing or content sites with GA4/GTM and embeds. Guest crawl of home + key pages; optional trackers stay consent-gated.', 'universal-consent-privacy-framework' ),
			),
			self::WP_LOGIN    => array(
				'label'       => __( 'WordPress login / membership', 'universal-consent-privacy-framework' ),
				'description' => __( 'Sites where visitors or staff log in. Includes an optional logged-in homepage pass; auth cookies stay necessary and are omitted from public inventory noise.', 'universal-consent-privacy-framework' ),
			),
			self::WOOCOMMERCE => array(
				'label'       => __( 'WooCommerce store', 'universal-consent-privacy-framework' ),
				'description' => __( 'Shop, cart, checkout, and My Account are selected for scans. Cart/session cookies stay necessary; ads pixels and Order Attribution stay consent-gated.', 'universal-consent-privacy-framework' ),
			),
		);
	}

	/**
	 * Allowed profile keys.
	 *
	 * @return string[]
	 */
	public static function keys() {
		return array_keys( self::definitions() );
	}

	/**
	 * Sanitize profile key.
	 *
	 * @param string $profile Raw.
	 * @return string
	 */
	public static function sanitize( $profile ) {
		$profile = sanitize_key( (string) $profile );
		return in_array( $profile, self::keys(), true ) ? $profile : self::BASIC;
	}

	/**
	 * Suggest a profile from the live site (Woo active → store, else basic).
	 *
	 * @return string
	 */
	public static function detect() {
		if ( Cookie_Scanner::instance()->is_woo_active() ) {
			return self::WOOCOMMERCE;
		}
		return self::BASIC;
	}

	/**
	 * Current stored profile (or detected default when empty).
	 *
	 * @return string
	 */
	public static function current() {
		$stored = Settings::get( 'site_profile', '' );
		if ( is_string( $stored ) && '' !== $stored ) {
			return self::sanitize( $stored );
		}
		return self::detect();
	}

	/**
	 * Apply profile side effects: scan URL pack + include_auth.
	 * Does not auto-enable marketing/analytics services (still scan/detect driven).
	 *
	 * @param string $profile Profile key.
	 * @return array{profile:string, urls:int, include_auth:bool}
	 */
	public static function apply( $profile ) {
		$profile = self::sanitize( $profile );
		Settings::update( array( 'site_profile' => $profile ) );

		$scanner   = Cookie_Scanner::instance();
		$selection = $scanner->get_saved_selection();
		$urls      = isset( $selection['urls'] ) && is_array( $selection['urls'] ) ? $selection['urls'] : array();
		$include_auth = ! empty( $selection['include_auth'] );

		$home = home_url( '/' );
		if ( $home && empty( $urls[ $home ] ) ) {
			// Normalize via save_selection later; seed label.
			$urls[ $home ] = __( 'Homepage', 'universal-consent-privacy-framework' );
		}

		if ( self::WOOCOMMERCE === $profile ) {
			foreach ( $scanner->get_woocommerce_url_pack() as $item ) {
				if ( empty( $item['url'] ) ) {
					continue;
				}
				$urls[ $item['url'] ] = isset( $item['label'] ) ? (string) $item['label'] : $item['url'];
			}
			$include_auth = false;
		} elseif ( self::WP_LOGIN === $profile ) {
			$include_auth = true;
		} else {
			$include_auth = false;
		}

		$saved = $scanner->save_selection(
			array(
				'urls'         => $urls,
				'depth'        => isset( $selection['depth'] ) ? $selection['depth'] : 'standard',
				'browser_crawl'=> isset( $selection['browser_crawl'] ) ? (bool) $selection['browser_crawl'] : true,
				'include_auth' => $include_auth,
			)
		);

		/**
		 * Fires after a site profile is applied.
		 *
		 * @param string $profile Profile key.
		 * @param array  $saved   Saved selection.
		 */
		do_action( 'ucpf_site_profile_applied', $profile, $saved );

		return array(
			'profile'      => $profile,
			'urls'         => is_array( $saved['urls'] ) ? count( $saved['urls'] ) : 0,
			'include_auth' => ! empty( $saved['include_auth'] ),
		);
	}
}
