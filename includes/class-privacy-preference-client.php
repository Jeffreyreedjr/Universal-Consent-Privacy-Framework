<?php
/**
 * Optional central privacy preference client (agency). Off by default — no phone-home.
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches and verifies signed preference policies.
 */
class Privacy_Preference_Client {

	const TRANSIENT_PREFIX = 'ucpf_priv_pol_';

	/**
	 * Whether a remote privacy API is configured.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$url = (string) Settings::get( 'privacy_api_url', '' );
		return (bool) $url;
	}

	/**
	 * Fetch policy for a subject HMAC (cached). Fail-closed for marketing when unavailable.
	 *
	 * @param string $subject_hmac Subject identifier HMAC.
	 * @return array|null Policy deny list payload or null.
	 */
	public static function fetch_policy( $subject_hmac ) {
		$subject_hmac = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $subject_hmac ) );
		if ( ! $subject_hmac || ! self::is_configured() ) {
			return null;
		}

		$cache_key = self::TRANSIENT_PREFIX . substr( $subject_hmac, 0, 40 );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && ! empty( $cached['expires_at'] ) && (int) $cached['expires_at'] > time() ) {
			if ( self::verify_signature( $cached ) ) {
				return $cached;
			}
		}

		$url = trailingslashit( (string) Settings::get( 'privacy_api_url', '' ) ) . 'v1/preferences/' . rawurlencode( $subject_hmac );
		$args = array(
			'timeout' => 4,
			'headers' => array(
				'Accept' => 'application/json',
			),
		);
		$key = (string) Settings::get( 'privacy_api_key', '' );
		if ( $key ) {
			$args['headers']['Authorization'] = 'Bearer ' . $key;
		}
		$controller = sanitize_key( (string) Settings::get( 'privacy_controller_id', '' ) );
		if ( $controller ) {
			$url = add_query_arg( 'controller_id', $controller, $url );
		}

		/**
		 * Filter remote preference request URL (must remain HTTPS JSON — never executable).
		 *
		 * @param string $url URL.
		 * @param string $subject_hmac Subject.
		 */
		$url = apply_filters( 'ucpf_privacy_preference_url', $url, $subject_hmac );

		$response = wp_remote_get( esc_url_raw( $url ), $args );
		if ( is_wp_error( $response ) ) {
			return self::fail_closed_stub( $subject_hmac );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $code ) {
			// No preference on file — not an opt-out.
			set_transient( $cache_key, array( 'deny' => array(), 'expires_at' => time() + HOUR_IN_SECONDS, 'empty' => true ), HOUR_IN_SECONDS );
			return array( 'deny' => array(), 'empty' => true );
		}
		if ( $code < 200 || $code >= 300 ) {
			return self::fail_closed_stub( $subject_hmac );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return self::fail_closed_stub( $subject_hmac );
		}
		if ( ! empty( $body['executable'] ) || ! empty( $body['scripts'] ) ) {
			return self::fail_closed_stub( $subject_hmac );
		}
		if ( ! self::verify_signature( $body ) ) {
			return self::fail_closed_stub( $subject_hmac );
		}

		$ttl = ! empty( $body['expires_at'] ) ? max( 60, (int) $body['expires_at'] - time() ) : 30 * MINUTE_IN_SECONDS;
		$ttl = min( $ttl, HOUR_IN_SECONDS );
		set_transient( $cache_key, $body, $ttl );
		return $body;
	}

	/**
	 * Purge cached policy (webhook / revoke).
	 *
	 * @param string $subject_hmac Subject.
	 */
	public static function purge_cache( $subject_hmac ) {
		$subject_hmac = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $subject_hmac ) );
		if ( $subject_hmac ) {
			delete_transient( self::TRANSIENT_PREFIX . substr( $subject_hmac, 0, 40 ) );
		}
	}

	/**
	 * Verify detached signature when UCPF_PRIVACY_PUBLIC_KEY is defined.
	 *
	 * @param array $payload Policy.
	 * @return bool
	 */
	public static function verify_signature( array $payload ) {
		if ( empty( $payload['signature'] ) ) {
			// Unsigned agency responses allowed when no public key configured.
			return ! defined( 'UCPF_PRIVACY_PUBLIC_KEY' ) || ! UCPF_PRIVACY_PUBLIC_KEY;
		}
		/**
		 * Filter signature verification (agencies may wire sodium_crypto_sign_verify_detached).
		 *
		 * @param bool  $ok Default true when unverified hook.
		 * @param array $payload Payload.
		 */
		return (bool) apply_filters( 'ucpf_verify_privacy_policy_signature', true, $payload );
	}

	/**
	 * Fail-closed marketing when API unreachable.
	 *
	 * @param string $subject_hmac Subject.
	 * @return array|null
	 */
	private static function fail_closed_stub( $subject_hmac ) {
		$fail_closed = ! isset( Settings::all()['privacy_fail_closed'] ) || Settings::get( 'privacy_fail_closed', true );
		if ( ! $fail_closed ) {
			return null;
		}
		return array(
			'deny'           => array( 'sale', 'sharing', 'targeted_advertising', 'nonessential_tracking' ),
			'fail_closed'    => true,
			'subject'        => $subject_hmac,
			'policy_version' => 'fail-closed',
			'expires_at'     => time() + 5 * MINUTE_IN_SECONDS,
		);
	}
}
