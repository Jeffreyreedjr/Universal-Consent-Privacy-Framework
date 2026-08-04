<?php
/**
 * Identity helpers for privacy preferences (HMAC, never fingerprinting).
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Privacy identity utilities.
 */
class Privacy_Identity {

	/**
	 * HMAC key (never use unsalted SHA alone for identifiers).
	 *
	 * @return string
	 */
	public static function hmac_key() {
		if ( defined( 'UCPF_PRIVACY_HMAC_KEY' ) && UCPF_PRIVACY_HMAC_KEY ) {
			return (string) UCPF_PRIVACY_HMAC_KEY;
		}
		// Site-specific secret; not exported.
		return wp_salt( 'auth' ) . '|ucpf-privacy';
	}

	/**
	 * Normalize email for matching.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	public static function normalize_email( $email ) {
		$email = strtolower( trim( (string) $email ) );
		$email = sanitize_email( $email );
		return $email;
	}

	/**
	 * Keyed HMAC of email (lookup key for controller-scoped preferences).
	 *
	 * @param string $email Email.
	 * @return string Hex HMAC-SHA256 or empty.
	 */
	public static function hmac_email( $email ) {
		$norm = self::normalize_email( $email );
		if ( ! $norm || ! is_email( $norm ) ) {
			return '';
		}
		return hash_hmac( 'sha256', $norm, self::hmac_key() );
	}

	/**
	 * Keyed HMAC of phone (digits only).
	 *
	 * @param string $phone Phone.
	 * @return string
	 */
	public static function hmac_phone( $phone ) {
		$digits = preg_replace( '/\D+/', '', (string) $phone );
		if ( ! $digits || strlen( $digits ) < 7 ) {
			return '';
		}
		return hash_hmac( 'sha256', $digits, self::hmac_key() );
	}

	/**
	 * Account subject for logged-in users (stable site user id, not email).
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function account_subject( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return '';
		}
		$controller = sanitize_key( (string) Settings::get( 'privacy_controller_id', '' ) );
		$payload    = ( $controller ? $controller . ':' : '' ) . 'user:' . $user_id;
		return hash_hmac( 'sha256', $payload, self::hmac_key() );
	}
}
