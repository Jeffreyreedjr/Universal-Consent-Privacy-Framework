<?php
/**
 * Community intelligence registry foundations (1.4) — opt-in only.
 *
 * Modes: local | agency | community | disabled
 * Never auto-enables community. Never loads remote executable code.
 * Sanitized patterns only — never cookie values, auth headers, bodies, emails.
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Registry mode + contribution sanitization stubs.
 */
class Community_Registry {

	/**
	 * Effective registry mode.
	 *
	 * Constant UCPF_REGISTRY_MODE (wp-config) wins when set.
	 * Otherwise settings; community requires explicit opt-in flag.
	 *
	 * @return string local|agency|community|disabled
	 */
	public static function mode() {
		if ( defined( 'UCPF_REGISTRY_MODE' ) ) {
			$mode = sanitize_key( (string) UCPF_REGISTRY_MODE );
			if ( in_array( $mode, array( 'local', 'agency', 'community', 'disabled' ), true ) ) {
				// Community constant still requires settings opt-in for pulls.
				if ( 'community' === $mode && ! Settings::get( 'remote_registry_enabled' ) ) {
					return 'local';
				}
				return $mode;
			}
		}

		$setting = sanitize_key( (string) Settings::get( 'registry_mode', 'local' ) );
		if ( ! in_array( $setting, array( 'local', 'agency', 'community', 'disabled' ), true ) ) {
			$setting = 'local';
		}

		// Community never activates unless remote_registry_enabled is also true (double gate).
		if ( 'community' === $setting && ! Settings::get( 'remote_registry_enabled' ) ) {
			return 'local';
		}

		return $setting;
	}

	/**
	 * Whether any remote catalog pull is allowed (still never executable code).
	 *
	 * @return bool
	 */
	public static function remote_catalog_allowed() {
		$mode = self::mode();
		if ( 'disabled' === $mode || 'local' === $mode ) {
			return false;
		}
		if ( 'community' === $mode ) {
			return (bool) Settings::get( 'remote_registry_enabled' );
		}
		// agency: org-private URL only when configured.
		return (bool) Settings::get( 'remote_registry_enabled' ) && (string) Settings::get( 'remote_registry_url', '' );
	}

	/**
	 * Sanitize a contribution candidate for community review.
	 * Strips values, headers, bodies, emails, admin URLs, screenshots.
	 *
	 * @param array $raw Raw contribution.
	 * @return array|\WP_Error
	 */
	public static function sanitize_contribution( array $raw ) {
		$out = array(
			'cookie_patterns'   => array(),
			'hosts'             => array(),
			'script_paths'      => array(),
			'suggested_category'=> '',
			'consent_behavior'  => array(),
			'confidence'        => 'unknown',
			'provenance'        => 'contributor',
			'note'              => '',
		);

		$allowed_conf = array( 'verified', 'high', 'medium', 'low', 'unknown' );
		if ( ! empty( $raw['confidence'] ) && in_array( sanitize_key( $raw['confidence'] ), $allowed_conf, true ) ) {
			$out['confidence'] = sanitize_key( $raw['confidence'] );
		}

		$cat = Privacy_Scan_Importer::map_category( isset( $raw['suggested_category'] ) ? $raw['suggested_category'] : '' );
		// Never auto-mark necessary from community suggestions.
		if ( $cat && 'necessary' !== $cat ) {
			$out['suggested_category'] = $cat;
		}

		foreach ( isset( $raw['cookie_patterns'] ) && is_array( $raw['cookie_patterns'] ) ? $raw['cookie_patterns'] : array() as $pat ) {
			$p = sanitize_text_field( (string) $pat );
			if ( $p && ! preg_match( '/[=;@]|password|token|auth|session/i', $p ) ) {
				$out['cookie_patterns'][] = substr( $p, 0, 120 );
			}
			if ( count( $out['cookie_patterns'] ) >= 50 ) {
				break;
			}
		}

		foreach ( isset( $raw['hosts'] ) && is_array( $raw['hosts'] ) ? $raw['hosts'] : array() as $host ) {
			$h = strtolower( sanitize_text_field( (string) $host ) );
			$h = preg_replace( '/[^a-z0-9.\-]/', '', $h );
			if ( $h && false === strpos( $h, 'admin' ) ) {
				$out['hosts'][] = substr( $h, 0, 120 );
			}
			if ( count( $out['hosts'] ) >= 50 ) {
				break;
			}
		}

		foreach ( isset( $raw['script_paths'] ) && is_array( $raw['script_paths'] ) ? $raw['script_paths'] : array() as $path ) {
			$p = sanitize_text_field( (string) $path );
			if ( $p && 0 === strpos( $p, '/' ) && false === stripos( $p, 'wp-admin' ) ) {
				$out['script_paths'][] = substr( preg_replace( '/\?.*/', '', $p ), 0, 200 );
			}
			if ( count( $out['script_paths'] ) >= 50 ) {
				break;
			}
		}

		if ( ! empty( $raw['consent_behavior'] ) && is_array( $raw['consent_behavior'] ) ) {
			foreach ( $raw['consent_behavior'] as $k => $v ) {
				$out['consent_behavior'][ sanitize_key( (string) $k ) ] = sanitize_key( (string) $v );
			}
		}

		$out['note'] = isset( $raw['note'] ) ? sanitize_text_field( substr( (string) $raw['note'], 0, 280 ) ) : '';

		// Reject if empty after sanitization.
		if ( empty( $out['cookie_patterns'] ) && empty( $out['hosts'] ) && empty( $out['script_paths'] ) ) {
			return new \WP_Error(
				'ucpf_empty_contribution',
				__( 'Contribution contained no shareable patterns after sanitization.', 'universal-consent-privacy-framework' )
			);
		}

		/**
		 * Filter sanitized community contribution (still never executable).
		 *
		 * @param array $out Sanitized payload.
		 * @param array $raw Original.
		 */
		return apply_filters( 'ucpf_sanitize_registry_contribution', $out, $raw );
	}

	/**
	 * Validate signed catalog JSON shape (data/rules only).
	 * Does not execute code. Signature verification is stubbed for agency keys.
	 *
	 * @param array  $catalog Catalog.
	 * @param string $signature Detached signature (optional).
	 * @return true|\WP_Error
	 */
	public static function validate_catalog( array $catalog, $signature = '' ) {
		if ( empty( $catalog['schema'] ) || 0 !== strpos( (string) $catalog['schema'], 'ucpf-registry-catalog/' ) ) {
			return new \WP_Error( 'ucpf_bad_catalog', __( 'Unrecognized registry catalog schema.', 'universal-consent-privacy-framework' ) );
		}
		if ( ! empty( $catalog['executable'] ) || ! empty( $catalog['scripts'] ) ) {
			return new \WP_Error( 'ucpf_catalog_code', __( 'Catalog must not include executable code.', 'universal-consent-privacy-framework' ) );
		}
		if ( $signature && defined( 'UCPF_REGISTRY_PUBLIC_KEY' ) && UCPF_REGISTRY_PUBLIC_KEY ) {
			// Placeholder: agencies may wire sodium_crypto_sign_verify_detached.
			/**
			 * Filter catalog signature verification result.
			 *
			 * @param bool   $ok Default true when key defined but verifier not hooked.
			 * @param string $signature Signature.
			 * @param array  $catalog Catalog.
			 */
			$ok = apply_filters( 'ucpf_verify_registry_catalog_signature', true, $signature, $catalog );
			if ( ! $ok ) {
				return new \WP_Error( 'ucpf_catalog_sig', __( 'Catalog signature verification failed.', 'universal-consent-privacy-framework' ) );
			}
		}
		return true;
	}
}
