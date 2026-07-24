<?php
/**
 * WP Consent API compatibility shim.
 *
 * @package UCPF
 */

namespace UCPF\Integrations;

use UCPF\Consent_Manager;
use UCPF\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Bundled WP Consent API shim.
 */
class Wp_Consent_Api_Shim {

	/**
	 * Instance.
	 *
	 * @var Wp_Consent_Api_Shim|null
	 */
	private static $instance = null;

	/**
	 * Whether official plugin is active.
	 *
	 * @var bool
	 */
	private $official_active = false;

	/**
	 * Category mapping UCPF => WP Consent API.
	 *
	 * @var array
	 */
	private $category_map = array(
		'necessary'   => 'functional',
		'preferences' => 'preferences',
		'analytics'   => 'statistics',
		'marketing'   => 'marketing',
		'functional'  => 'preferences',
		'security'    => 'security',
	);

	/**
	 * Get instance.
	 *
	 * @return Wp_Consent_Api_Shim
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Init shim.
	 */
	public function init() {
		$this->official_active = function_exists( 'wp_has_consent' ) && defined( 'WP_CONSENT_API_VERSION' );

		if ( ! $this->official_active ) {
			$this->register_shim_functions();
		}

		add_filter( 'wp_get_consent_type', array( $this, 'filter_consent_type' ) );
		add_filter( 'wp_consent_categories', array( $this, 'add_security_category' ) );
	}

	/**
	 * Register shim functions when official API not present.
	 */
	private function register_shim_functions() {
		if ( ! function_exists( 'wp_has_consent' ) ) {
			/**
			 * Check consent for WP Consent API category.
			 *
			 * @param string $category Category slug.
			 * @return bool
			 */
			function wp_has_consent( $category ) {
				return \UCPF\Integrations\Wp_Consent_Api_Shim::instance()->shim_has_consent( $category );
			}
		}

		if ( ! function_exists( 'wp_set_consent' ) ) {
			/**
			 * Set consent cookie for category.
			 *
			 * @param string $category Category.
			 * @param string $value    allow|deny.
			 */
			function wp_set_consent( $category, $value ) {
				\UCPF\Integrations\Wp_Consent_Api_Shim::instance()->shim_set_consent( $category, $value );
			}
		}

		if ( ! function_exists( 'wp_get_consent_type' ) ) {
			/**
			 * Get consent type.
			 *
			 * @return string
			 */
			function wp_get_consent_type() {
				return \UCPF\Integrations\Wp_Consent_Api_Shim::instance()->get_consent_type();
			}
		}

		if ( ! function_exists( 'wp_add_cookie_info' ) ) {
			/**
			 * Register cookie info stub.
			 *
			 * @param string $name    Cookie name.
			 * @param string $service Service slug.
			 * @param string $category Category.
			 * @param string $expires Expires.
			 * @param string $function Function description.
			 */
			function wp_add_cookie_info( $name, $service, $category, $expires, $function ) {
				// Stored via script registry in UCPF.
			}
		}
	}

	/**
	 * Filter consent type for CMP producer role.
	 *
	 * @param string $type Current type.
	 * @return string
	 */
	public function filter_consent_type( $type ) {
		if ( ! empty( $type ) ) {
			return $type;
		}
		return $this->get_consent_type();
	}

	/**
	 * Get consent type based on compliance mode.
	 *
	 * @return string
	 */
	public function get_consent_type() {
		return \UCPF\Jurisdiction::instance()->get_consent_type();
	}

	/**
	 * Add security category to WP Consent API.
	 *
	 * @param array $categories Categories.
	 * @return array
	 */
	public function add_security_category( $categories ) {
		if ( ! in_array( 'security', $categories, true ) ) {
			$categories[] = 'security';
		}
		return $categories;
	}

	/**
	 * Sync UCPF consent to WP Consent API cookies.
	 *
	 * @param array $categories UCPF categories.
	 * @param array $services   UCPF services.
	 */
	public function sync_from_ucpf( array $categories, array $services = array() ) {
		foreach ( $this->category_map as $ucpf => $wp ) {
			$allowed = ! empty( $categories[ $ucpf ] );
			if ( 'necessary' === $ucpf ) {
				$allowed = true;
			}

			if ( Settings::get( 'anonymous_analytics_only' ) && 'analytics' === $ucpf ) {
				if ( $allowed ) {
					$this->set_wp_consent( 'statistics-anonymous', 'allow' );
					$this->set_wp_consent( 'statistics', 'deny' );
				} else {
					$this->set_wp_consent( 'statistics-anonymous', 'deny' );
					$this->set_wp_consent( 'statistics', 'deny' );
				}
				continue;
			}

			$this->set_wp_consent( $wp, $allowed ? 'allow' : 'deny' );
		}

		if ( function_exists( 'wp_set_service_consent' ) ) {
			foreach ( $services as $service => $consented ) {
				wp_set_service_consent( $service, (bool) $consented );
			}
		}
	}

	/**
	 * Set WP consent cookie.
	 *
	 * @param string $category Category.
	 * @param string $value    allow|deny.
	 */
	private function set_wp_consent( $category, $value ) {
		if ( $this->official_active && function_exists( 'wp_set_consent' ) ) {
			wp_set_consent( $category, $value );
			return;
		}
		$this->shim_set_consent( $category, $value );
	}

	/**
	 * Shim set consent cookie.
	 *
	 * @param string $category Category.
	 * @param string $value    Value.
	 */
	public function shim_set_consent( $category, $value ) {
		$prefix = apply_filters( 'wp_consent_cookie_prefix', 'wp_consent' );
		$name   = $prefix . '_' . sanitize_key( $category );
		$days   = (int) apply_filters( 'wp_consent_api_cookie_expiration', 30 );

		setcookie(
			$name,
			'allow' === $value ? 'allow' : 'deny',
			time() + ( $days * DAY_IN_SECONDS ),
			'/',
			'',
			is_ssl(),
			false
		);
	}

	/**
	 * Shim has consent check.
	 *
	 * @param string $category WP category.
	 * @return bool
	 */
	public function shim_has_consent( $category ) {
		$ucpf_slug = array_search( $category, $this->category_map, true );
		if ( false === $ucpf_slug && 'statistics-anonymous' === $category ) {
			return Consent_Manager::instance()->has_consent( 'analytics' ) && Settings::get( 'anonymous_analytics_only' );
		}
		if ( false === $ucpf_slug && 'functional' === $category ) {
			$ucpf_slug = 'necessary';
		}
		if ( false === $ucpf_slug ) {
			$ucpf_slug = $category;
		}
		return Consent_Manager::instance()->has_consent( $ucpf_slug );
	}

	/**
	 * JS sync payload for frontend.
	 *
	 * @param array $categories Categories.
	 * @param array $services   Services.
	 * @return array
	 */
	public function get_js_sync_map( array $categories, array $services = array() ) {
		$map = array();
		foreach ( $this->category_map as $ucpf => $wp ) {
			$map[ $wp ] = ! empty( $categories[ $ucpf ] ) ? 'allow' : 'deny';
		}
		$map['functional'] = 'allow';
		return array(
			'categories' => $map,
			'services'   => $services,
			'type'       => $this->get_consent_type(),
		);
	}
}
