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
	 * When true, sanitize_settings must pass through Settings::update() merges
	 * (do not rebuild from Settings::all() — that stripped programmatic keys like zone_id
	 * and re-sealed secrets forever → memory death loop).
	 *
	 * @var bool
	 */
	private static $internal_update = false;

	/**
	 * Request-level cache of raw option row, keyed by blog ID.
	 *
	 * @var array<int, array>
	 */
	private static $raw_cache = array();

	/**
	 * Request-level cache of defaults().
	 *
	 * @var array|null
	 */
	private static $defaults_cache = null;

	/**
	 * Request-level cache of revealed merged settings, keyed by blog ID.
	 *
	 * @var array<int, array>
	 */
	private static $merged_cache = array();

	/**
	 * Per-key get() memo for this request, keyed by blog ID then setting key.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static $get_cache = array();

	/**
	 * Whether option-write flush hooks are registered.
	 *
	 * @var bool
	 */
	private static $hooks_registered = false;

	/**
	 * Whether an internal Settings::update is in progress.
	 *
	 * @return bool
	 */
	public static function is_internal_update() {
		return (bool) self::$internal_update;
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		if ( null !== self::$defaults_cache ) {
			return self::$defaults_cache;
		}
		self::$defaults_cache = array(
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
			'site_profile'              => '',
			'consent_logging'           => true,
			'log_retention_days'        => 360,
			'login_security_notice'     => true,
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
			'accent_color'              => '',
			'accent_2_color'            => '',
			'surface_color'             => '',
			'custom_css'                => '',
			'service_ids'               => array(),
			'generated_pages'           => array(),
			'delete_data_on_uninstall'  => false,
			'legal_retention_days'      => 365,
			'ai_image_disclosure'       => 'off',
			'scanner_api_url'           => '',
			'scanner_api_key'           => '',
			'registry_mode'             => 'local',
			'scheduled_scan_enabled'    => false,
			'scheduled_scan_interval'   => 'monthly',
			'scheduled_scan_paths'      => '/',
			'scheduled_scan_notify_email' => '',
			'scheduled_scan_auto_apply' => true,
			'scheduled_scan_last_status'=> array(),
			'cloudflare_purge_enabled'       => false,
			'cloudflare_domain'              => '',
			'cloudflare_zone_id'             => '',
			'cloudflare_api_token'           => '',
			'cloudflare_purge_on_updates'    => true,
			'cloudflare_purge_on_ucpf_update'=> true,
			'elementor_clear_css_on_updates' => true,
		);
		return self::$defaults_cache;
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
	 * Current blog id for cache keys (1 on single-site).
	 *
	 * @return int
	 */
	private static function cache_blog_id() {
		return function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
	}

	/**
	 * Register flush hooks once (switch_blog + direct option writes).
	 *
	 * @return void
	 */
	public static function register_cache_hooks() {
		if ( self::$hooks_registered ) {
			return;
		}
		self::$hooks_registered = true;
		add_action( 'switch_blog', array( __CLASS__, 'flush_runtime_cache' ), 0 );
		add_action( 'updated_option', array( __CLASS__, 'on_option_changed' ), 0, 1 );
		add_action( 'added_option', array( __CLASS__, 'on_option_changed' ), 0, 1 );
		add_action( 'deleted_option', array( __CLASS__, 'on_option_changed' ), 0, 1 );
	}

	/**
	 * Flush memo when ucpf_settings is written outside Settings::update().
	 *
	 * @param string $option Option name.
	 * @return void
	 */
	public static function on_option_changed( $option ) {
		if ( self::OPTION_KEY === $option ) {
			self::flush_runtime_cache();
		}
	}

	/**
	 * Clear request-level settings memo (call after writes / blog switch).
	 *
	 * @return void
	 */
	public static function flush_runtime_cache() {
		self::$raw_cache    = array();
		self::$merged_cache = array();
		self::$get_cache    = array();
		// Keep defaults_cache — immutable for the process.
	}

	/**
	 * Raw option row as stored (secrets may be sealed). Prefer all() / get().
	 *
	 * @return array
	 */
	public static function raw() {
		self::register_cache_hooks();
		$blog = self::cache_blog_id();
		if ( isset( self::$raw_cache[ $blog ] ) ) {
			return self::$raw_cache[ $blog ];
		}
		$stored                      = get_option( self::OPTION_KEY, array() );
		self::$raw_cache[ $blog ]    = is_array( $stored ) ? $stored : array();
		return self::$raw_cache[ $blog ];
	}

	/**
	 * Revealed settings merged with defaults (blog option only; no network inheritance).
	 *
	 * @return array
	 */
	private static function merged_revealed() {
		$blog = self::cache_blog_id();
		if ( isset( self::$merged_cache[ $blog ] ) ) {
			return self::$merged_cache[ $blog ];
		}
		self::$merged_cache[ $blog ] = Secrets::reveal_in_array( wp_parse_args( self::raw(), self::defaults() ) );
		return self::$merged_cache[ $blog ];
	}

	/**
	 * Get all settings merged with defaults (API secrets decrypted for runtime).
	 * Multisite: network-capable keys inherit from Network_Settings when the site value is blank.
	 *
	 * @return array
	 */
	public static function all() {
		$merged = self::merged_revealed();
		if ( is_multisite() ) {
			foreach ( Network_Settings::KEYS as $key ) {
				$merged[ $key ] = self::get( $key, isset( $merged[ $key ] ) ? $merged[ $key ] : null );
			}
		}
		return $merged;
	}

	/**
	 * Whether an API secret is configured (wp-config, site, and/or network). Never needed in HTML value attrs.
	 *
	 * @param string $key Secret setting key.
	 * @return bool
	 */
	public static function secret_is_set( $key ) {
		if ( ! Secrets::is_secret_key( $key ) ) {
			return false;
		}
		if ( null !== Secrets::constant_value( $key ) ) {
			return true;
		}
		$raw    = self::raw();
		$stored = isset( $raw[ $key ] ) ? (string) $raw[ $key ] : '';
		if ( '' !== trim( $stored ) ) {
			return true;
		}
		return Network_Settings::secret_is_set( $key );
	}

	/**
	 * Seal secrets in a settings array before writing to the options table.
	 *
	 * @param array $settings Settings (secrets as plaintext or already sealed).
	 * @return array
	 */
	public static function prepare_for_storage( array $settings ) {
		return Secrets::seal_in_array( $settings );
	}

	/**
	 * Get a single setting.
	 *
	 * Resolve order for connection keys on multisite:
	 * wp-config constant → non-empty site setting → network setting → brand/default.
	 * Banner, consent, inventory, and other keys stay blog-scoped only.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		self::register_cache_hooks();
		$blog = self::cache_blog_id();
		if ( ! isset( self::$get_cache[ $blog ] ) ) {
			self::$get_cache[ $blog ] = array();
		}
		if ( array_key_exists( $key, self::$get_cache[ $blog ] ) ) {
			return self::$get_cache[ $blog ][ $key ];
		}

		if ( Secrets::is_secret_key( $key ) ) {
			$from_const = Secrets::constant_value( $key );
			if ( null !== $from_const ) {
				self::$get_cache[ $blog ][ $key ] = $from_const;
				return $from_const;
			}
		}

		$raw      = self::raw();
		$defaults = self::defaults();
		$network  = Network_Settings::is_network_key( $key );

		if ( $network ) {
			if ( array_key_exists( $key, $raw ) && ! Network_Settings::is_blank_value( $key, $raw[ $key ] ) ) {
				if ( 'privacy_fail_closed' === $key || 'remote_registry_enabled' === $key ) {
					$value = (bool) $raw[ $key ];
					self::$get_cache[ $blog ][ $key ] = $value;
					return $value;
				}
				if ( Secrets::is_secret_key( $key ) ) {
					$value = Secrets::reveal( (string) $raw[ $key ] );
					self::$get_cache[ $blog ][ $key ] = $value;
					return $value;
				}
				$value = is_string( $raw[ $key ] ) ? $raw[ $key ] : $raw[ $key ];
				self::$get_cache[ $blog ][ $key ] = $value;
				return $value;
			}
			$from_net = Network_Settings::get( $key );
			if ( null !== $from_net ) {
				self::$get_cache[ $blog ][ $key ] = $from_net;
				return $from_net;
			}
			if ( 'scanner_api_url' === $key ) {
				$brand = Brand::config();
				if ( ! empty( $brand['scanner_api_url'] ) ) {
					$value = esc_url_raw( (string) $brand['scanner_api_url'] );
					self::$get_cache[ $blog ][ $key ] = $value;
					return $value;
				}
			}
			if ( array_key_exists( $key, $defaults ) ) {
				self::$get_cache[ $blog ][ $key ] = $defaults[ $key ];
				return $defaults[ $key ];
			}
			self::$get_cache[ $blog ][ $key ] = $default;
			return $default;
		}

		$merged = self::merged_revealed();
		if ( array_key_exists( $key, $merged ) ) {
			$value = $merged[ $key ];
			if ( 'scanner_api_url' === $key && ( '' === $value || null === $value ) ) {
				$brand = Brand::config();
				if ( ! empty( $brand['scanner_api_url'] ) ) {
					$value = esc_url_raw( (string) $brand['scanner_api_url'] );
					self::$get_cache[ $blog ][ $key ] = $value;
					return $value;
				}
			}
			self::$get_cache[ $blog ][ $key ] = $value;
			return $value;
		}
		if ( null === $default && array_key_exists( $key, $defaults ) ) {
			self::$get_cache[ $blog ][ $key ] = $defaults[ $key ];
			return $defaults[ $key ];
		}
		self::$get_cache[ $blog ][ $key ] = $default;
		return $default;
	}

	/**
	 * Update settings (allowlisted keys from defaults only).
	 *
	 * Merges onto the raw (possibly sealed) option so re-saving non-secret fields
	 * does not rewrite API tokens as plaintext.
	 *
	 * @param array $values Values to merge; unknown keys are ignored.
	 * @return bool
	 */
	public static function update( array $values ) {
		$allowed  = array_flip( array_keys( self::defaults() ) );
		$filtered = array_intersect_key( $values, $allowed );
		if ( ! $filtered ) {
			return true;
		}
		$filtered = Secrets::seal_in_array( $filtered );
		$merged   = array_merge( wp_parse_args( self::raw(), self::defaults() ), $filtered );
		$merged   = array_intersect_key( $merged, $allowed );

		// Bypass form-oriented sanitize so programmatic keys (e.g. cloudflare_zone_id) stick.
		self::$internal_update = true;
		try {
			// Preserve existing autoload; null = do not change autoload flag.
			update_option( self::OPTION_KEY, $merged, null );

			// update_option returns false when WordPress thinks the value is unchanged.
			// Verify critical keys actually stuck; force-write if not.
			self::flush_runtime_cache();
			$raw_after = self::raw();
			foreach ( $filtered as $key => $want ) {
				$have = array_key_exists( $key, $raw_after ) ? $raw_after[ $key ] : null;
				if ( $have === $want || (string) $have === (string) $want ) {
					continue;
				}
				// Booleans / ints may loose-compare; skip if equal under PHP == for scalars.
				if ( is_scalar( $want ) && is_scalar( $have ) && $have == $want ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
					continue;
				}
				update_option( self::OPTION_KEY, $merged );
				self::flush_runtime_cache();
				break;
			}
		} finally {
			self::$internal_update = false;
			self::flush_runtime_cache();
		}

		return true;
	}

	/**
	 * Normalize a settings URL (adds https:// when scheme missing).
	 *
	 * @param string $raw Raw URL.
	 * @return string
	 */
	public static function normalize_url( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}
		if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $raw ) ) {
			$raw = 'https://' . ltrim( $raw, '/' );
		}
		return esc_url_raw( $raw );
	}

	/**
	 * Sanitize a secret API key / token from form input (do not use sanitize_text_field).
	 *
	 * @param string $raw Raw secret.
	 * @return string
	 */
	public static function sanitize_secret( $raw ) {
		$raw = trim( (string) $raw );
		// Strip control chars only — keep tokens with +, /, =, _, -, etc.
		$raw = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $raw );
		return is_string( $raw ) ? $raw : '';
	}
}
