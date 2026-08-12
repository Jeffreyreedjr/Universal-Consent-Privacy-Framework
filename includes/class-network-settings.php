<?php
/**
 * Multisite network-level connection settings (scanner / privacy / registry).
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Shared agency connection defaults for the whole network.
 *
 * Site settings override when non-empty. Banner, consent, and scan inventory stay per-blog.
 */
class Network_Settings {

	/**
	 * Network option key (site meta / sitemeta).
	 */
	const OPTION_KEY = 'ucpf_network_settings';

	/**
	 * Keys that may be stored network-wide and inherited by sites.
	 *
	 * @var string[]
	 */
	const KEYS = array(
		'scanner_api_url',
		'scanner_api_key',
		'privacy_api_url',
		'privacy_api_key',
		'privacy_controller_id',
		'privacy_fail_closed',
		'registry_mode',
		'remote_registry_enabled',
		'remote_registry_url',
	);

	/**
	 * Whether a settings key participates in network inheritance.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function is_network_key( $key ) {
		return in_array( (string) $key, self::KEYS, true );
	}

	/**
	 * Default network bag (empty strings = no network default).
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'scanner_api_url'         => '',
			'scanner_api_key'         => '',
			'privacy_api_url'         => '',
			'privacy_api_key'         => '',
			'privacy_controller_id'   => '',
			'privacy_fail_closed'     => true,
			'registry_mode'           => '',
			'remote_registry_enabled' => false,
			'remote_registry_url'     => '',
		);
	}

	/**
	 * Raw network option (secrets may be sealed).
	 *
	 * @return array
	 */
	public static function raw() {
		if ( ! is_multisite() ) {
			return array();
		}
		$stored = get_network_option( null, self::OPTION_KEY, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * All network settings with secrets revealed.
	 *
	 * @return array
	 */
	public static function all() {
		return Secrets::reveal_in_array( wp_parse_args( self::raw(), self::defaults() ) );
	}

	/**
	 * Whether the network option has a usable non-empty value for a key.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function has( $key ) {
		if ( ! is_multisite() || ! self::is_network_key( $key ) ) {
			return false;
		}
		$raw = self::raw();
		if ( ! array_key_exists( $key, $raw ) ) {
			return false;
		}
		return ! self::is_blank_value( $key, $raw[ $key ] );
	}

	/**
	 * Get one network setting (revealed). Null when unset/blank (caller falls through).
	 *
	 * @param string $key Key.
	 * @return mixed|null
	 */
	public static function get( $key ) {
		if ( ! self::has( $key ) ) {
			return null;
		}
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : null;
	}

	/**
	 * Whether a stored value counts as blank for inheritance purposes.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	public static function is_blank_value( $key, $value ) {
		if ( 'registry_mode' === $key ) {
			return '' === trim( (string) $value );
		}
		if ( 'privacy_fail_closed' === $key || 'remote_registry_enabled' === $key ) {
			// Bools: absent key = blank; present false/true = set. Callers use array_key_exists.
			return null === $value;
		}
		if ( Secrets::is_secret_key( $key ) ) {
			return '' === trim( (string) $value );
		}
		if ( is_bool( $value ) ) {
			return false;
		}
		if ( is_numeric( $value ) ) {
			return false;
		}
		return '' === trim( (string) $value );
	}

	/**
	 * Whether the current site has an explicit override for a network-capable key.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function site_has_override( $key ) {
		if ( ! self::is_network_key( $key ) ) {
			return false;
		}
		$raw = Settings::raw();
		if ( ! array_key_exists( $key, $raw ) ) {
			return false;
		}
		if ( 'privacy_fail_closed' === $key || 'remote_registry_enabled' === $key ) {
			return true;
		}
		return ! self::is_blank_value( $key, $raw[ $key ] );
	}

	/**
	 * Site-stored value for admin forms (not resolved). Empty string when inheriting.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function site_override_value( $key ) {
		$raw = Settings::raw();
		if ( ! array_key_exists( $key, $raw ) ) {
			if ( 'privacy_fail_closed' === $key || 'remote_registry_enabled' === $key ) {
				return null;
			}
			return '';
		}
		if ( Secrets::is_secret_key( $key ) ) {
			return '';
		}
		return $raw[ $key ];
	}

	/**
	 * Update network settings (allowlisted keys only).
	 *
	 * @param array $values Values to merge.
	 * @return bool
	 */
	public static function update( array $values ) {
		if ( ! is_multisite() || ! current_user_can( 'manage_network_options' ) ) {
			return false;
		}
		$allowed  = array_flip( self::KEYS );
		$filtered = array_intersect_key( $values, $allowed );
		if ( ! $filtered ) {
			return true;
		}
		$filtered = Secrets::seal_in_array( $filtered );
		$merged   = array_merge( wp_parse_args( self::raw(), self::defaults() ), $filtered );
		$merged   = array_intersect_key( $merged, $allowed );
		return (bool) update_network_option( null, self::OPTION_KEY, $merged );
	}

	/**
	 * Sanitize network form input.
	 *
	 * @param array $input Raw POST bag.
	 * @return array Clean values to pass to update().
	 */
	public static function sanitize( array $input ) {
		$clean = array();

		if ( isset( $input['scanner_api_url'] ) ) {
			$clean['scanner_api_url'] = Settings::normalize_url( (string) $input['scanner_api_url'] );
		}
		if ( array_key_exists( 'scanner_api_key', $input ) ) {
			$key_in = Settings::sanitize_secret( (string) $input['scanner_api_key'] );
			if ( '' !== $key_in ) {
				$clean['scanner_api_key'] = $key_in;
			}
		}
		if ( isset( $input['privacy_api_url'] ) ) {
			$clean['privacy_api_url'] = Settings::normalize_url( (string) $input['privacy_api_url'] );
		}
		if ( array_key_exists( 'privacy_api_key', $input ) ) {
			$key_in = Settings::sanitize_secret( (string) $input['privacy_api_key'] );
			if ( '' !== $key_in ) {
				$clean['privacy_api_key'] = $key_in;
			}
		}
		if ( isset( $input['privacy_controller_id'] ) ) {
			$clean['privacy_controller_id'] = sanitize_key( (string) $input['privacy_controller_id'] );
		}
		$clean['privacy_fail_closed'] = ! empty( $input['privacy_fail_closed'] );

		if ( isset( $input['registry_mode'] ) ) {
			$rm = sanitize_key( (string) $input['registry_mode'] );
			if ( '' === $rm ) {
				$clean['registry_mode'] = '';
			} else {
				$clean['registry_mode'] = in_array( $rm, array( 'local', 'agency', 'community', 'disabled' ), true ) ? $rm : '';
			}
		}
		$clean['remote_registry_enabled'] = ! empty( $input['remote_registry_enabled'] );
		if ( isset( $input['remote_registry_url'] ) ) {
			$clean['remote_registry_url'] = esc_url_raw( (string) $input['remote_registry_url'] );
		}

		return $clean;
	}

	/**
	 * Copy current site connection settings into the network option.
	 *
	 * @return bool
	 */
	public static function promote_from_current_site() {
		if ( ! is_multisite() || ! current_user_can( 'manage_network_options' ) ) {
			return false;
		}
		$site = Secrets::reveal_in_array( Settings::raw() );
		$bag  = array();
		foreach ( self::KEYS as $key ) {
			if ( ! array_key_exists( $key, $site ) ) {
				continue;
			}
			$bag[ $key ] = $site[ $key ];
		}
		if ( ! $bag ) {
			return false;
		}
		return self::update( $bag );
	}

	/**
	 * Clear site overrides for network keys on every blog (inheritance kicks in).
	 *
	 * @return int Number of blogs updated.
	 */
	public static function clear_all_site_overrides() {
		if ( ! is_multisite() || ! current_user_can( 'manage_network_options' ) ) {
			return 0;
		}
		$ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);
		$n   = 0;
		foreach ( (array) $ids as $blog_id ) {
			switch_to_blog( (int) $blog_id );
			$raw = Settings::raw();
			$changed = false;
			foreach ( self::KEYS as $key ) {
				if ( array_key_exists( $key, $raw ) ) {
					unset( $raw[ $key ] );
					$changed = true;
				}
			}
			if ( $changed ) {
				update_option( Settings::OPTION_KEY, $raw, null );
				$n++;
			}
			restore_current_blog();
		}
		return $n;
	}

	/**
	 * Whether a network secret is configured.
	 *
	 * @param string $key Secret key.
	 * @return bool
	 */
	public static function secret_is_set( $key ) {
		if ( ! Secrets::is_secret_key( $key ) || ! is_multisite() ) {
			return false;
		}
		$raw = self::raw();
		$stored = isset( $raw[ $key ] ) ? (string) $raw[ $key ] : '';
		return '' !== trim( $stored );
	}
}
