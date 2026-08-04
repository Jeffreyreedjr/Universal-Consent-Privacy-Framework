<?php
/**
 * Authoritative privacy enforcement state (GPC + DNS + optional central policy).
 *
 * Local-first: never phones home unless privacy_api_url is configured.
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Privacy state singleton.
 */
class Privacy_State {

	const DNS_COOKIE = 'ucpf_dns';

	/**
	 * @var Privacy_State|null
	 */
	private static $instance = null;

	/**
	 * @var array|null
	 */
	private $resolved = null;

	/**
	 * @return Privacy_State
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Init: early GPC observation hook.
	 */
	public function init() {
		add_action( 'init', array( $this, 'maybe_observe_gpc' ), 1 );
	}

	/**
	 * Detect Sec-GPC / Nginx UCPF_GPC.
	 *
	 * @return bool
	 */
	public static function gpc_signal_present() {
		if ( ! empty( $_SERVER['UCPF_GPC'] ) && '1' === (string) $_SERVER['UCPF_GPC'] ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return true;
		}
		if ( ! empty( $_SERVER['HTTP_SEC_GPC'] ) && '1' === (string) $_SERVER['HTTP_SEC_GPC'] ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return true;
		}
		/**
		 * Filter GPC detection (e.g. Cloudflare header maps).
		 *
		 * @param bool $present Detected.
		 */
		return (bool) apply_filters( 'ucpf_gpc_signal_present', false );
	}

	/**
	 * Whether DNT/GPC respect setting is enabled.
	 *
	 * @return bool
	 */
	public static function respect_signals() {
		return (bool) Settings::get( 'respect_dnt_gpc', true );
	}

	/**
	 * Record GPC observation (no PII).
	 */
	public function maybe_observe_gpc() {
		if ( is_admin() || wp_doing_cron() || ! self::respect_signals() || ! self::gpc_signal_present() ) {
			return;
		}
		// Light-weight rate-limited log marker (no IP stored here).
		$flag = get_transient( 'ucpf_gpc_seen_today' );
		if ( ! $flag ) {
			set_transient( 'ucpf_gpc_seen_today', 1, DAY_IN_SECONDS );
			/**
			 * Fires once per day when GPC is observed on this site.
			 */
			do_action( 'ucpf_gpc_observed' );
		}
	}

	/**
	 * Read local Do Not Sell / share cookie (first-party only).
	 *
	 * @return array|null
	 */
	public function read_dns_cookie() {
		if ( empty( $_COOKIE[ self::DNS_COOKIE ] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return null;
		}
		$raw = wp_unslash( $_COOKIE[ self::DNS_COOKIE ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Persist local DNS / global privacy mode cookie.
	 *
	 * @param array $prefs Preferences (sale/sharing/etc false = not permitted).
	 */
	public function set_dns_cookie( array $prefs ) {
		$flag = static function ( $prefs, $key, $default = false ) {
			return array_key_exists( $key, $prefs ) ? (bool) $prefs[ $key ] : $default;
		};
		$payload = array(
			'v'                     => 1,
			'sale'                  => $flag( $prefs, 'sale', false ),
			'sharing'               => $flag( $prefs, 'sharing', false ),
			'targeted_advertising'  => $flag( $prefs, 'targeted_advertising', false ),
			'profiling'             => $flag( $prefs, 'profiling', false ),
			'nonessential_tracking' => $flag( $prefs, 'nonessential_tracking', false ),
			'limit_sensitive'       => $flag( $prefs, 'limit_sensitive', false ),
			'scope'                 => isset( $prefs['scope'] ) ? sanitize_key( $prefs['scope'] ) : 'site',
			'policy_version'        => Settings::get( 'policy_version' ),
			'effective_at'          => time(),
		);
		$lifetime = (int) apply_filters( 'ucpf_dns_cookie_lifetime', YEAR_IN_SECONDS );
		$secure   = is_ssl();
		$path     = ucpf_cookie_path();
		$domain   = ucpf_cookie_domain();

		if ( PHP_VERSION_ID >= 70300 ) {
			setcookie(
				self::DNS_COOKIE,
				wp_json_encode( $payload ),
				array(
					'expires'  => time() + $lifetime,
					'path'     => $path ? $path : '/',
					'domain'   => $domain ? $domain : '',
					'secure'   => $secure,
					'httponly' => false,
					'samesite' => 'Lax',
				)
			);
		} else {
			setcookie( self::DNS_COOKIE, wp_json_encode( $payload ), time() + $lifetime, $path ? $path : '/', $domain, $secure, false );
		}
		$_COOKIE[ self::DNS_COOKIE ] = wp_json_encode( $payload ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$this->resolved              = null;
	}

	/**
	 * Resolve authoritative privacy allowances.
	 *
	 * @return array
	 */
	public function get_state() {
		if ( null !== $this->resolved ) {
			return $this->resolved;
		}

		$sources = array(
			'gpc'           => false,
			'dns_local'     => false,
			'account'       => false,
			'central'       => false,
			'fail_closed'   => false,
		);

		$deny = array(); // sale, sharing, targeted_advertising, profiling, nonessential_tracking
		$dns_denies_nonessential = false;

		if ( self::respect_signals() && self::gpc_signal_present() ) {
			$sources['gpc'] = true;
			$pack           = Jurisdiction::instance()->resolve();
			$mode           = isset( $pack['gpc_enforcement'] ) ? sanitize_key( (string) $pack['gpc_enforcement'] ) : 'nonessential';
			// Custom mode still honors explicit setting.
			if ( 'custom' === Settings::get( 'compliance_mode' ) ) {
				$mode = sanitize_key( (string) Settings::get( 'gpc_enforcement', $mode ) );
			}
			if ( 'sale_share' === $mode ) {
				$deny = array_merge( $deny, array( 'sale', 'sharing', 'targeted_advertising' ) );
			} else {
				$deny = array_merge( $deny, array( 'sale', 'sharing', 'targeted_advertising', 'profiling', 'nonessential_tracking' ) );
			}
		}

		$dns = $this->read_dns_cookie();
		if ( $dns ) {
			$sources['dns_local'] = true;
			// Cookie stores allow flags: false = activity not permitted.
			foreach ( array( 'sale', 'sharing', 'targeted_advertising', 'profiling', 'nonessential_tracking' ) as $flag ) {
				if ( array_key_exists( $flag, $dns ) && false === $dns[ $flag ] ) {
					$deny[] = $flag;
					if ( 'nonessential_tracking' === $flag ) {
						$dns_denies_nonessential = true;
					}
				}
			}
		}

		// Logged-in + optional central policy.
		$central_denies_nonessential = false;
		if ( is_user_logged_in() ) {
			$subject = Privacy_Identity::account_subject( get_current_user_id() );
			$policy  = Privacy_Preference_Client::fetch_policy( $subject );
			if ( is_array( $policy ) ) {
				$sources['account'] = true;
				if ( ! empty( $policy['fail_closed'] ) ) {
					$sources['fail_closed'] = true;
				}
				if ( ! empty( $policy['deny'] ) && is_array( $policy['deny'] ) ) {
					$sources['central'] = empty( $policy['empty'] );
					foreach ( $policy['deny'] as $d ) {
						$key = sanitize_key( $d );
						$deny[] = $key;
						if ( 'nonessential_tracking' === $key ) {
							$central_denies_nonessential = true;
						}
					}
				}
			}
		}

		$deny = array_values( array_unique( array_filter( $deny ) ) );

		$nonessential_blocked = in_array( 'nonessential_tracking', $deny, true );
		$ads_blocked          = $nonessential_blocked
			|| in_array( 'sale', $deny, true )
			|| in_array( 'sharing', $deny, true )
			|| in_array( 'targeted_advertising', $deny, true );

		/*
		 * GPC opts out of sale / share / targeted ads — not payment, shipping, or map
		 * embeds (functional). Brave sends Sec-GPC by default; treating functional as
		 * blocked made checkout overlays impossible to clear. Explicit Do Not Sell
		 * (DNS cookie) or central policy may still deny functional.
		 */
		$functional_allowed = ! ( $dns_denies_nonessential || $central_denies_nonessential );

		$state = array(
			'necessary'             => true,
			'security'              => true,
			'preferences'           => ! $nonessential_blocked,
			'analytics'             => ! $nonessential_blocked,
			'marketing'             => ! $ads_blocked,
			'functional'            => $functional_allowed,
			'sale_sharing'          => ! ( in_array( 'sale', $deny, true ) || in_array( 'sharing', $deny, true ) ),
			'targeted_advertising'  => ! in_array( 'targeted_advertising', $deny, true ),
			'profiling'             => ! ( $nonessential_blocked || in_array( 'profiling', $deny, true ) ),
			'nonessential_tracking' => ! $nonessential_blocked,
			'deny'                  => $deny,
			'sources'               => $sources,
			'policy_version'        => Settings::get( 'policy_version' ),
			'gpc'                   => $sources['gpc'],
		);

		/**
		 * Filter authoritative privacy state.
		 *
		 * @param array $state State.
		 */
		$this->resolved = apply_filters( 'ucpf_privacy_state', $state );
		return $this->resolved;
	}

	/**
	 * Whether a consent category is allowed under privacy enforcement.
	 *
	 * @param string $category Category slug.
	 * @return bool
	 */
	public function allows_category( $category ) {
		$category = sanitize_key( $category );
		$state    = $this->get_state();

		$map = array(
			'necessary'   => 'necessary',
			'security'    => 'security',
			'preferences' => 'preferences',
			'analytics'   => 'analytics',
			'marketing'   => 'marketing',
			'functional'  => 'functional',
			'advertising' => 'marketing',
		);

		$key = isset( $map[ $category ] ) ? $map[ $category ] : $category;
		if ( isset( $state[ $key ] ) ) {
			return (bool) $state[ $key ];
		}
		// Unknown categories: deny if nonessential blocked.
		return ! empty( $state['nonessential_tracking'] );
	}

	/**
	 * Public subset for JS bootstrap.
	 *
	 * @return array
	 */
	public function get_state_for_js() {
		$s = $this->get_state();
		return array(
			'necessary'            => ! empty( $s['necessary'] ),
			'security'             => ! empty( $s['security'] ),
			'preferences'          => ! empty( $s['preferences'] ),
			'analytics'            => ! empty( $s['analytics'] ),
			'marketing'            => ! empty( $s['marketing'] ),
			'functional'           => ! empty( $s['functional'] ),
			'sale_sharing'         => ! empty( $s['sale_sharing'] ),
			'targeted_advertising' => ! empty( $s['targeted_advertising'] ),
			'gpc'                  => ! empty( $s['gpc'] ),
			'deny'                 => isset( $s['deny'] ) ? $s['deny'] : array(),
		);
	}
}
