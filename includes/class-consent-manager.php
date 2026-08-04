<?php
/**
 * Consent state management.
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Consent manager singleton.
 */
class Consent_Manager {

	const COOKIE_NAME = 'ucpf_consent';

	/**
	 * Instance.
	 *
	 * @var Consent_Manager|null
	 */
	private static $instance = null;

	/**
	 * Cached consent payload.
	 *
	 * @var array|null
	 */
	private $cached = null;

	/**
	 * Get instance.
	 *
	 * @return Consent_Manager
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Init hooks.
	 */
	public function init() {
		// Reserved for future server-side hooks.
	}

	/**
	 * Whether this front-end request is an admin guest-discover crawl.
	 *
	 * @return bool
	 */
	public function is_discover_mode() {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}
		$cached = false;
		if ( empty( $_GET['ucpf_discover'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $cached;
		}
		$token  = sanitize_text_field( wp_unslash( $_GET['ucpf_discover'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$cached = Cookie_Scanner::instance()->validate_discover_token( $token );
		return $cached;
	}

	/**
	 * Default category definitions.
	 *
	 * @return array
	 */
	public function get_categories() {
		$categories = array(
			'necessary'   => array(
				'label'       => __( 'Essential / Necessary', 'universal-consent-privacy-framework' ),
				'description' => __( 'Required for security, login, cart, and storing your consent.', 'universal-consent-privacy-framework' ),
				'required'    => true,
				'default'     => true,
			),
			'preferences' => array(
				'label'       => __( 'Preferences', 'universal-consent-privacy-framework' ),
				'description' => __( 'Remembers language, region, and layout choices.', 'universal-consent-privacy-framework' ),
				'required'    => false,
				'default'     => false,
			),
			'analytics'   => array(
				'label'       => __( 'Analytics', 'universal-consent-privacy-framework' ),
				'description' => __( 'Helps measure traffic and site performance.', 'universal-consent-privacy-framework' ),
				'required'    => false,
				'default'     => false,
			),
			'marketing'   => array(
				'label'       => __( 'Marketing', 'universal-consent-privacy-framework' ),
				'description' => __( 'Advertising, remarketing, and personalization.', 'universal-consent-privacy-framework' ),
				'required'    => false,
				'default'     => false,
			),
			'functional'  => array(
				'label'       => __( 'Embeds & Widgets', 'universal-consent-privacy-framework' ),
				'description' => __( 'Maps, video embeds, chat widgets, social feeds, checkout payment widgets (PayPal, Stripe, Square), and shipping / address-validation widgets (Shippo, UPS, USPS, FedEx, DHL).', 'universal-consent-privacy-framework' ),
				'required'    => false,
				'default'     => false,
			),
			'security'    => array(
				'label'       => __( 'Security', 'universal-consent-privacy-framework' ),
				'description' => __( 'Anti-spam, fraud prevention, and CAPTCHA tools.', 'universal-consent-privacy-framework' ),
				'required'    => false,
				'default'     => false,
			),
		);

		return apply_filters( 'ucpf_consent_categories', $categories );
	}

	/**
	 * Categories formatted for JS.
	 *
	 * @return array
	 */
	public function get_categories_for_js() {
		$out = array();
		foreach ( $this->get_categories() as $slug => $cat ) {
			$out[ $slug ] = array(
				'label'       => $cat['label'],
				'description' => $cat['description'],
				'required'    => ! empty( $cat['required'] ),
				'default'     => ! empty( $cat['default'] ),
			);
		}
		return $out;
	}

	/**
	 * Default category booleans (reject all state).
	 *
	 * @return array
	 */
	public function default_categories_rejected() {
		$categories = $this->get_categories();
		$out        = array();
		foreach ( $categories as $slug => $cat ) {
			$out[ $slug ] = ! empty( $cat['required'] ) || ! empty( $cat['default'] ) && ! empty( $cat['required'] );
			if ( 'necessary' === $slug ) {
				$out[ $slug ] = true;
			} else {
				$out[ $slug ] = false;
			}
		}
		$out['necessary'] = true;
		return $out;
	}

	/**
	 * Default category booleans (accept all state).
	 *
	 * @return array
	 */
	public function default_categories_accepted() {
		$out = array();
		foreach ( array_keys( $this->get_categories() ) as $slug ) {
			$out[ $slug ] = true;
		}
		return $out;
	}

	/**
	 * Read consent from cookie.
	 *
	 * @return array|null
	 */
	public function read_cookie() {
		if ( $this->is_discover_mode() ) {
			return null;
		}

		if ( null !== $this->cached ) {
			return $this->cached;
		}

		if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return null;
		}

		$raw  = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
		$data = json_decode( rawurldecode( $raw ), true );

		if ( ! is_array( $data ) ) {
			$data = json_decode( $raw, true );
		}

		if ( ! is_array( $data ) ) {
			return null;
		}

		// Drop bloated service maps from older Accept All payloads.
		if ( isset( $data['services'] ) && is_array( $data['services'] ) && count( $data['services'] ) > 40 ) {
			$data['services'] = array();
		}

		$this->cached = $data;
		return $data;
	}

	/**
	 * Get consent state for API.
	 *
	 * @return array
	 */
	public function get_consent_state() {
		if ( $this->is_discover_mode() ) {
			return array(
				'state'      => 'discover',
				'categories' => $this->default_categories_accepted(),
				'services'   => array(),
				'discover'   => true,
			);
		}

		$cookie = $this->read_cookie();

		if ( ! $cookie ) {
			return array(
				'state'      => 'unknown',
				'categories' => $this->default_categories_rejected(),
				'services'   => array(),
			);
		}

		if ( $this->should_reprompt( $cookie ) ) {
			return array(
				'state'      => 'unknown',
				'categories' => $this->default_categories_rejected(),
				'services'   => array(),
				'reprompt'   => true,
			);
		}

		return array(
			'state'          => isset( $cookie['state'] ) ? $cookie['state'] : 'custom',
			'uuid'           => isset( $cookie['uuid'] ) ? $cookie['uuid'] : '',
			'version'        => isset( $cookie['version'] ) ? $cookie['version'] : '',
			'policy_version' => isset( $cookie['policy_version'] ) ? $cookie['policy_version'] : '',
			'categories'     => isset( $cookie['categories'] ) ? $cookie['categories'] : array(),
			'services'       => isset( $cookie['services'] ) ? $cookie['services'] : array(),
			'timestamp'      => isset( $cookie['timestamp'] ) ? $cookie['timestamp'] : 0,
			'expires'        => isset( $cookie['expires'] ) ? $cookie['expires'] : 0,
		);
	}

	/**
	 * Whether visitor should be re-prompted.
	 *
	 * @param array $cookie Cookie data.
	 * @return bool
	 */
	public function should_reprompt( array $cookie ) {
		$policy  = Settings::get( 'policy_version' );
		$version = Settings::get( 'consent_version' );

		if ( ! empty( $cookie['expires'] ) && (int) $cookie['expires'] < time() ) {
			return true;
		}

		if ( ! empty( $cookie['policy_version'] ) && $cookie['policy_version'] !== $policy ) {
			return true;
		}

		if ( ! empty( $cookie['version'] ) && $cookie['version'] !== $version ) {
			return true;
		}

		return false;
	}

	/**
	 * Check consent for category or service.
	 *
	 * @param string $category_or_service Slug.
	 * @return bool
	 */
	public function has_consent( $category_or_service ) {
		if ( $this->is_discover_mode() ) {
			return true;
		}

		// Resolve service → category for privacy gate.
		$category = $category_or_service;
		$cats     = $this->get_categories();
		if ( ! isset( $cats[ $category ] ) ) {
			$service = Script_Registry::instance()->get_service( $category_or_service );
			if ( $service && ! empty( $service['category'] ) ) {
				$category = $service['category'];
			}
		}
		if ( isset( $cats[ $category ] ) && empty( $cats[ $category ]['required'] ) ) {
			if ( ! Privacy_State::instance()->allows_category( $category ) ) {
				return false;
			}
		}

		$state = $this->get_consent_state();

		if ( 'unknown' === $state['state'] ) {
			$categories = $this->get_categories();
			if ( isset( $categories[ $category_or_service ] ) ) {
				return ! empty( $categories[ $category_or_service ]['required'] );
			}
			return false;
		}

		if ( isset( $state['services'][ $category_or_service ] ) ) {
			return (bool) $state['services'][ $category_or_service ];
		}

		if ( isset( $state['categories'][ $category_or_service ] ) ) {
			return (bool) $state['categories'][ $category_or_service ];
		}

		$service = Script_Registry::instance()->get_service( $category_or_service );
		if ( $service && ! empty( $service['category'] ) ) {
			return $this->has_consent( $service['category'] );
		}

		return false;
	}

	/**
	 * Validate and save consent from REST/request.
	 *
	 * @param array  $payload Request payload.
	 * @param string $action  Action name.
	 * @return array|\WP_Error
	 */
	public function save_consent( array $payload, $action = 'save_preferences' ) {
		$categories = $this->sanitize_categories( isset( $payload['categories'] ) ? $payload['categories'] : array() );
		$services   = $this->sanitize_services( isset( $payload['services'] ) ? $payload['services'] : array() );

		$state = isset( $payload['state'] ) ? sanitize_key( $payload['state'] ) : 'custom';

		$lifetime = (int) apply_filters( 'ucpf_consent_cookie_lifetime', Settings::get( 'cookie_lifetime_days' ) );
		$expires  = time() + ( $lifetime * DAY_IN_SECONDS );

		$uuid = ! empty( $payload['uuid'] ) ? sanitize_text_field( $payload['uuid'] ) : wp_generate_uuid4();

		$cookie_data = array(
			'uuid'           => $uuid,
			'version'        => Settings::get( 'consent_version' ),
			'policy_version' => Settings::get( 'policy_version' ),
			'state'          => $state,
			'categories'     => $categories,
			'services'       => $services,
			'timestamp'      => time(),
			'expires'        => $expires,
		);

		$this->set_cookie( $cookie_data );
		$this->cached = $cookie_data;

		Integrations\Wp_Consent_Api_Shim::instance()->sync_from_ucpf( $categories, $services );

		Audit_Log::instance()->log( $action, $cookie_data );

		/**
		 * Fires after consent is saved.
		 *
		 * @param array  $cookie_data Saved cookie payload.
		 * @param string $action      Action name.
		 */
		do_action( 'ucpf_consent_saved', $cookie_data, $action );

		return $cookie_data;
	}

	/**
	 * Withdraw consent.
	 *
	 * @param array $payload Optional payload (uuid to keep visitor trail).
	 * @return array|\WP_Error
	 */
	public function withdraw_consent( array $payload = array() ) {
		$existing = $this->read_cookie();
		$uuid     = '';
		if ( ! empty( $payload['uuid'] ) ) {
			$uuid = sanitize_text_field( (string) $payload['uuid'] );
		} elseif ( is_array( $existing ) && ! empty( $existing['uuid'] ) ) {
			$uuid = sanitize_text_field( (string) $existing['uuid'] );
		}

		$result = $this->save_consent(
			array(
				'state'      => 'withdrawn',
				'categories' => $this->default_categories_rejected(),
				'services'   => array(),
				'uuid'       => $uuid,
			),
			'withdraw'
		);

		/**
		 * Fires after consent withdrawn.
		 */
		do_action( 'ucpf_consent_withdrawn' );

		return $result;
	}

	/**
	 * Sanitize category map.
	 *
	 * @param array $input Raw categories.
	 * @return array
	 */
	public function sanitize_categories( array $input ) {
		$valid  = array_keys( $this->get_categories() );
		$output = $this->default_categories_rejected();

		foreach ( $valid as $slug ) {
			if ( 'necessary' === $slug ) {
				$output[ $slug ] = true;
				continue;
			}
			if ( array_key_exists( $slug, $input ) ) {
				$output[ $slug ] = (bool) $input[ $slug ];
			}
		}

		return $output;
	}

	/**
	 * Sanitize services map.
	 *
	 * @param array $input Raw services.
	 * @return array
	 */
	public function sanitize_services( array $input ) {
		$output   = array();
		$registry = Script_Registry::instance()->get_services();
		$count    = 0;

		foreach ( $input as $key => $value ) {
			$key = sanitize_key( $key );
			if ( ! $key || ! isset( $registry[ $key ] ) ) {
				continue;
			}
			$output[ $key ] = (bool) $value;
			$count++;
			// Hard cap — oversized consent cookies break browsers and reverse proxies.
			if ( $count >= 40 ) {
				break;
			}
		}

		return $output;
	}

	/**
	 * Set consent cookie.
	 *
	 * @param array $data Cookie payload.
	 */
	public function set_cookie( array $data ) {
		if ( isset( $data['services'] ) && is_array( $data['services'] ) && count( $data['services'] ) > 40 ) {
			$data['services'] = array_slice( $data['services'], 0, 40, true );
		}

		$secure   = is_ssl();
		$lifetime = (int) apply_filters( 'ucpf_consent_cookie_lifetime', Settings::get( 'cookie_lifetime_days' ) );
		$value    = rawurlencode( wp_json_encode( $data ) );

		// Keep under typical proxy/browser cookie limits (~4KB).
		if ( strlen( $value ) > 3500 ) {
			$data['services'] = array();
			$value            = rawurlencode( wp_json_encode( $data ) );
		}

		$domain = ucpf_cookie_domain();
		$path   = ucpf_cookie_path();

		$options = array(
			'expires'  => time() + ( $lifetime * DAY_IN_SECONDS ),
			'path'     => $path,
			'domain'   => $domain,
			'secure'   => $secure,
			'httponly' => false,
			'samesite' => 'Lax',
		);

		if ( PHP_VERSION_ID >= 70300 ) {
			setcookie( self::COOKIE_NAME, $value, $options );
		} else {
			setcookie(
				self::COOKIE_NAME,
				$value,
				$options['expires'],
				$options['path'],
				$options['domain'],
				$options['secure'],
				$options['httponly']
			);
		}

		// Drop legacy Path=/ cookie so subdirectory multisite blogs do not share consent.
		if ( '/' !== $path ) {
			$legacy = $options;
			$legacy['expires'] = time() - YEAR_IN_SECONDS;
			$legacy['path']    = '/';
			if ( PHP_VERSION_ID >= 70300 ) {
				setcookie( self::COOKIE_NAME, '', $legacy );
			} else {
				setcookie( self::COOKIE_NAME, '', $legacy['expires'], '/', $domain, $secure, false );
			}
		}

		$_COOKIE[ self::COOKIE_NAME ] = $value;
	}
}
