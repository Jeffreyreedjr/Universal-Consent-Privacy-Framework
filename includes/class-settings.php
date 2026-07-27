<?php
/**
 * Plugin settings.
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Settings storage and defaults.
 */
class Settings {

	const OPTION_KEY = 'ucpf_settings';

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'compliance_mode'           => 'strict_gdpr',
			'geo_jurisdiction_routing'  => false,
			'agency_preset_applied'     => false,
			'policy_version'            => '2026-01',
			'consent_version'           => '1.0.0',
			'cookie_lifetime_days'      => 180,
			'banner_layout'             => 'bar',
			'banner_position'           => 'left',
			'banner_theme'              => 'classic',
			'show_reject_all'           => true,
			'show_accept_all'           => true,
			'show_customize'            => true,
			'floating_prefs_button'     => true,
			'banner_enabled'            => true,
			'blocker_enabled'           => true,
			'business_name'             => '',
			'logo_url'                  => '',
			'show_powered_by'           => false,
			'contact_email'             => '',
			'business_address'          => '',
			'business_country'          => '',
			'business_phone'            => '',
			'wizard_step'               => 1,
			'wizard_section'            => 'general',
			'wizard_completed'          => false,
			'consent_logging'           => true,
			'log_retention_days'        => 360,
			'google_consent_mode'       => 'basic',
			'output_buffer_blocking'    => false,
			'output_buffer_safe_iframes'=> false,
			'remote_registry_enabled'   => false,
			'remote_registry_url'       => '',
			'anonymous_analytics_only'  => false,
			'respect_dnt_gpc'           => true,
			'gpc_enforcement'           => 'nonessential',
			'privacy_api_url'           => '',
			'privacy_api_key'           => '',
			'privacy_controller_id'     => '',
			'privacy_fail_closed'       => true,
			'enable_data_request_forms' => false,
			'auto_refresh_cookie_policy_after_scan' => true,
			'data_request_page_url'     => '',
			'do_not_sell_page_url'      => '',
			'gf_data_request_form_id'   => 0,
			'gf_do_not_sell_form_id'    => 0,
			'gf_data_request_shortcode' => '',
			'gf_do_not_sell_shortcode'  => '',
			'document_sources'          => array(
				'cookie_policy'  => 'generate',
				'privacy_policy' => 'generate',
			),
			'selected_statistics'       => array(),
			'selected_services'         => array(),
			'service_overrides'         => array(),
			'cookie_overrides'          => array(),
			'cookie_display_overrides'  => array(),
			'accent_color'              => '#0b5cad',
			'accent_2_color'            => '#094a8c',
			'surface_color'             => '',
			'custom_css'                => '',
			'service_ids'               => array(),
			'generated_pages'           => array(),
			'delete_data_on_uninstall'  => false,
			'legal_retention_days'      => 365,
			'scanner_api_url'           => '',
			'scanner_api_key'           => '',
			'registry_mode'             => 'local',
			'scheduled_scan_enabled'    => false,
			'scheduled_scan_interval'   => 'monthly',
			'scheduled_scan_paths'      => '/',
			'scheduled_scan_notify_email' => '',
			'scheduled_scan_auto_apply' => true,
			'scheduled_scan_last_status'=> array(),
		);
	}

	/**
	 * Set defaults on activation.
	 */
	public static function set_defaults() {
		if ( false === get_option( self::OPTION_KEY ) ) {
			add_option( self::OPTION_KEY, self::defaults() );
		}
	}

	/**
	 * Get all settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		if ( is_multisite() ) {
			$network = get_site_option( 'ucpf_network_defaults', array() );
			if ( is_array( $network ) && array_key_exists( $key, $network ) && ! is_admin() ) {
				// Per-site settings take precedence; network provides defaults only when site value empty.
			}
		}

		$all = self::all();
		if ( array_key_exists( $key, $all ) ) {
			$value = $all[ $key ];
			// Agency drop-in may supply scanner URL when site setting is empty.
			if ( 'scanner_api_url' === $key && ( '' === $value || null === $value ) ) {
				$brand = Brand::config();
				if ( ! empty( $brand['scanner_api_url'] ) ) {
					return esc_url_raw( (string) $brand['scanner_api_url'] );
				}
			}
			return $value;
		}
		$defaults = self::defaults();
		if ( null === $default && array_key_exists( $key, $defaults ) ) {
			return $defaults[ $key ];
		}
		return $default;
	}

	/**
	 * Update settings.
	 *
	 * @param array $values Values to merge.
	 * @return bool
	 */
	public static function update( array $values ) {
		$current = self::all();
		return update_option( self::OPTION_KEY, array_merge( $current, $values ) );
	}
}
