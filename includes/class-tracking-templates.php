<?php
/**
 * Managed tracking tag definitions (IDs / snippets UCPF can inject after consent).
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Tracking templates helper.
 */
class Tracking_Templates {

	/**
	 * Templates available for enable + ID/code configuration.
	 *
	 * @return array
	 */
	public static function all() {
		$id_note = __( 'Used only to load this tag after consent. Not listed as a cookie name on the Cookie Policy (cookie families are documented generically).', 'universal-consent-privacy-framework' );

		return array(
			'google_analytics_4' => array(
				'label'           => __( 'Google Analytics 4', 'universal-consent-privacy-framework' ),
				'id_label'        => __( 'Measurement ID', 'universal-consent-privacy-framework' ),
				'placeholder'     => 'G-XXXXXXXXXX',
				'help'            => __( 'From GA4 Admin → Data streams. Example: G-ABC123XYZ.', 'universal-consent-privacy-framework' ) . ' ' . $id_note,
				'tag_id_label'    => __( 'Google Tag ID', 'universal-consent-privacy-framework' ),
				'tag_placeholder' => 'GT-XXXXXXXX',
				'tag_help'        => __( 'Optional Google Tag ID (GT-…) from Google Tag / Site Kit. Loaded together with the Measurement ID.', 'universal-consent-privacy-framework' ) . ' ' . $id_note,
				'category'        => 'analytics',
			),
			'google_tag_manager' => array(
				'label'       => __( 'Google Tag Manager', 'universal-consent-privacy-framework' ),
				'id_label'    => __( 'Container ID', 'universal-consent-privacy-framework' ),
				'placeholder' => 'GTM-XXXXXXX',
				'help'        => __( 'From GTM container settings. Example: GTM-ABC123.', 'universal-consent-privacy-framework' ) . ' ' . $id_note,
				'category'    => 'analytics',
			),
			'meta_pixel'         => array(
				'label'       => __( 'Meta Pixel', 'universal-consent-privacy-framework' ),
				'id_label'    => __( 'Pixel ID', 'universal-consent-privacy-framework' ),
				'placeholder' => '123456789012345',
				'help'        => __( 'Numeric Pixel ID from Meta Events Manager.', 'universal-consent-privacy-framework' ) . ' ' . $id_note,
				'category'    => 'marketing',
			),
			'microsoft_clarity'  => array(
				'label'       => __( 'Microsoft Clarity', 'universal-consent-privacy-framework' ),
				'id_label'    => __( 'Project ID', 'universal-consent-privacy-framework' ),
				'placeholder' => 'abcdefghij',
				'help'        => __( 'Clarity project ID from clarity.microsoft.com.', 'universal-consent-privacy-framework' ) . ' ' . $id_note,
				'category'    => 'analytics',
			),
			'hotjar'             => array(
				'label'       => __( 'Hotjar', 'universal-consent-privacy-framework' ),
				'id_label'    => __( 'Site ID', 'universal-consent-privacy-framework' ),
				'placeholder' => '1234567',
				'help'        => __( 'Hotjar Site ID (numbers only).', 'universal-consent-privacy-framework' ) . ' ' . $id_note,
				'category'    => 'analytics',
			),
			'tiktok_pixel'       => array(
				'label'       => __( 'TikTok Pixel', 'universal-consent-privacy-framework' ),
				'id_label'    => __( 'Pixel ID', 'universal-consent-privacy-framework' ),
				'placeholder' => 'CXXXXXXXXXXXXXXX',
				'help'        => __( 'TikTok Ads Manager pixel ID.', 'universal-consent-privacy-framework' ) . ' ' . $id_note,
				'category'    => 'marketing',
			),
			'linkedin_insight'   => array(
				'label'       => __( 'LinkedIn Insight Tag', 'universal-consent-privacy-framework' ),
				'id_label'    => __( 'Partner ID', 'universal-consent-privacy-framework' ),
				'placeholder' => '123456',
				'help'        => __( 'LinkedIn Insight Tag partner ID.', 'universal-consent-privacy-framework' ) . ' ' . $id_note,
				'category'    => 'marketing',
			),
		);
	}

	/**
	 * Sanitize a single service_ids row.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	public static function sanitize_row( $row ) {
		if ( ! is_array( $row ) ) {
			$row = array();
		}
		return array(
			'enabled' => ! empty( $row['enabled'] ),
			'id'      => isset( $row['id'] ) ? sanitize_text_field( $row['id'] ) : '',
			'tag_id'  => isset( $row['tag_id'] ) ? sanitize_text_field( $row['tag_id'] ) : '',
			'code'    => isset( $row['code'] ) ? self::sanitize_code( $row['code'] ) : '',
		);
	}

	/**
	 * Sanitize service_ids settings map (Integrations form: unchecked = disabled).
	 *
	 * @param array $input   Posted map.
	 * @param array $current Existing map.
	 * @return array
	 */
	public static function sanitize_service_ids( $input, $current = array() ) {
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		if ( ! is_array( $input ) ) {
			return $current;
		}

		$known = array_keys( self::all() );
		$out   = $current;

		foreach ( $known as $key ) {
			$row         = isset( $input[ $key ] ) && is_array( $input[ $key ] ) ? $input[ $key ] : array();
			$out[ $key ] = self::sanitize_row( $row );
		}

		// Allow extra custom keys with id/code.
		foreach ( $input as $key => $row ) {
			$key = sanitize_key( $key );
			if ( ! $key || isset( $out[ $key ] ) || ! is_array( $row ) ) {
				continue;
			}
			$out[ $key ] = self::sanitize_row( $row );
		}

		return $out;
	}

	/**
	 * Merge partial service_ids updates without disabling omitted keys (wizard / scan).
	 *
	 * @param array $partial Posted subset.
	 * @param array $current Existing map.
	 * @return array
	 */
	public static function merge_service_ids( $partial, $current = array() ) {
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		if ( ! is_array( $partial ) ) {
			return $current;
		}

		$out = $current;
		foreach ( $partial as $key => $row ) {
			$key = sanitize_key( $key );
			if ( ! $key || ! is_array( $row ) ) {
				continue;
			}
			$prev = isset( $out[ $key ] ) && is_array( $out[ $key ] ) ? $out[ $key ] : array();
			$out[ $key ] = array(
				'enabled' => array_key_exists( 'enabled', $row ) ? ! empty( $row['enabled'] ) : ! empty( $prev['enabled'] ),
				'id'      => array_key_exists( 'id', $row ) ? sanitize_text_field( $row['id'] ) : ( isset( $prev['id'] ) ? $prev['id'] : '' ),
				'tag_id'  => array_key_exists( 'tag_id', $row ) ? sanitize_text_field( $row['tag_id'] ) : ( isset( $prev['tag_id'] ) ? $prev['tag_id'] : '' ),
				'code'    => array_key_exists( 'code', $row ) ? self::sanitize_code( $row['code'] ) : ( isset( $prev['code'] ) ? $prev['code'] : '' ),
			);
		}

		return $out;
	}

	/**
	 * Sanitize optional custom JS snippet (no closing script tags / PHP).
	 *
	 * @param string $code Raw.
	 * @return string
	 */
	public static function sanitize_code( $code ) {
		$code = (string) $code;
		$code = wp_unslash( $code );
		$code = str_ireplace( array( '</script', '<?php', '<?=' ), '', $code );
		return trim( $code );
	}
}
