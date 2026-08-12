<?php
/**
 * Encrypt API secrets at rest (options table).
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Seals plugin API credentials so DB dumps / option exporters do not expose
 * plaintext tokens. Decryption uses WordPress salts (AUTH_KEY family).
 *
 * Optional wp-config overrides (never stored):
 * - UCPF_SCANNER_API_KEY
 * - UCPF_PRIVACY_API_KEY
 * - UCPF_CLOUDFLARE_API_TOKEN
 */
class Secrets {

	/**
	 * Setting keys treated as secrets.
	 *
	 * @var string[]
	 */
	const KEYS = array(
		'scanner_api_key',
		'privacy_api_key',
		'cloudflare_api_token',
	);

	/**
	 * Sealed payload prefix (sodium secretbox).
	 */
	const PREFIX_SODIUM = 'ucpf1:';

	/**
	 * Sealed payload prefix (OpenSSL AES-256-GCM fallback).
	 */
	const PREFIX_OPENSSL = 'ucpf1o:';

	/**
	 * Whether a settings key is a secret.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function is_secret_key( $key ) {
		return in_array( (string) $key, self::KEYS, true );
	}

	/**
	 * wp-config constant name for a secret key, or empty.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	public static function constant_name( $key ) {
		$map = array(
			'scanner_api_key'       => 'UCPF_SCANNER_API_KEY',
			'privacy_api_key'       => 'UCPF_PRIVACY_API_KEY',
			'cloudflare_api_token'  => 'UCPF_CLOUDFLARE_API_TOKEN',
		);
		return isset( $map[ $key ] ) ? $map[ $key ] : '';
	}

	/**
	 * Value from wp-config when defined and non-empty; otherwise null.
	 *
	 * @param string $key Setting key.
	 * @return string|null
	 */
	public static function constant_value( $key ) {
		$name = self::constant_name( $key );
		if ( '' === $name || ! defined( $name ) ) {
			return null;
		}
		$val = constant( $name );
		if ( ! is_string( $val ) && ! is_numeric( $val ) ) {
			return null;
		}
		$val = trim( (string) $val );
		return '' !== $val ? $val : null;
	}

	/**
	 * Whether a sealed or plaintext secret is present (or a constant overrides).
	 *
	 * @param string $key   Setting key.
	 * @param string $stored Raw stored value (may be sealed).
	 * @return bool
	 */
	public static function is_configured( $key, $stored = '' ) {
		if ( null !== self::constant_value( $key ) ) {
			return true;
		}
		return '' !== trim( (string) $stored );
	}

	/**
	 * Whether a string looks like a UCPF-sealed blob.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function is_sealed( $value ) {
		$value = (string) $value;
		return 0 === strpos( $value, self::PREFIX_SODIUM ) || 0 === strpos( $value, self::PREFIX_OPENSSL );
	}

	/**
	 * Encrypt plaintext for storage. Empty stays empty. Already sealed left as-is.
	 *
	 * @param string $plaintext Secret.
	 * @return string
	 */
	public static function seal( $plaintext ) {
		$plaintext = (string) $plaintext;
		if ( '' === $plaintext ) {
			return '';
		}
		if ( self::is_sealed( $plaintext ) ) {
			return $plaintext;
		}

		$key = self::key_bytes();

		if ( function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'random_bytes' ) ) {
			try {
				$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
				$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
				return self::PREFIX_SODIUM . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- storage encoding, not obfuscation.
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Fall through to OpenSSL.
			}
		}

		if ( function_exists( 'openssl_encrypt' ) && function_exists( 'random_bytes' ) ) {
			try {
				$iv     = random_bytes( 12 );
				$tag    = '';
				$cipher = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
				if ( false !== $cipher && '' !== $tag ) {
					return self::PREFIX_OPENSSL . base64_encode( $iv . $tag . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Last resort: store plaintext (same as pre-hardening).
			}
		}

		return $plaintext;
	}

	/**
	 * Decrypt a sealed value, or return plaintext legacy as-is.
	 *
	 * @param string $stored Stored value.
	 * @return string
	 */
	public static function reveal( $stored ) {
		$stored = (string) $stored;
		if ( '' === $stored || ! self::is_sealed( $stored ) ) {
			return $stored;
		}

		$key = self::key_bytes();

		if ( 0 === strpos( $stored, self::PREFIX_SODIUM ) ) {
			$raw = base64_decode( substr( $stored, strlen( self::PREFIX_SODIUM ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $raw || ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
				return '';
			}
			$nonce_len = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
			if ( strlen( $raw ) < $nonce_len + 16 ) {
				return '';
			}
			$nonce  = substr( $raw, 0, $nonce_len );
			$cipher = substr( $raw, $nonce_len );
			$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
			return false === $plain ? '' : $plain;
		}

		if ( 0 === strpos( $stored, self::PREFIX_OPENSSL ) ) {
			$raw = base64_decode( substr( $stored, strlen( self::PREFIX_OPENSSL ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $raw || ! function_exists( 'openssl_decrypt' ) || strlen( $raw ) < 28 ) {
				return '';
			}
			$iv     = substr( $raw, 0, 12 );
			$tag    = substr( $raw, 12, 16 );
			$cipher = substr( $raw, 28 );
			$plain  = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			return false === $plain ? '' : $plain;
		}

		return $stored;
	}

	/**
	 * Seal secret keys present in an array (in place copy).
	 *
	 * @param array $settings Settings.
	 * @return array
	 */
	public static function seal_in_array( array $settings ) {
		foreach ( self::KEYS as $key ) {
			if ( ! array_key_exists( $key, $settings ) ) {
				continue;
			}
			$settings[ $key ] = self::seal( (string) $settings[ $key ] );
		}
		return $settings;
	}

	/**
	 * Reveal secret keys in an array for runtime use.
	 *
	 * Does not inject wp-config constants (those apply only via Settings::get /
	 * constant_value) so saving other settings cannot copy a constant into the DB.
	 *
	 * @param array $settings Settings.
	 * @return array
	 */
	public static function reveal_in_array( array $settings ) {
		foreach ( self::KEYS as $key ) {
			if ( ! array_key_exists( $key, $settings ) ) {
				continue;
			}
			$settings[ $key ] = self::reveal( (string) $settings[ $key ] );
		}
		return $settings;
	}

	/**
	 * Encrypt any plaintext secrets currently stored in ucpf_settings.
	 *
	 * @return bool True if option was rewritten.
	 */
	public static function migrate_plaintext_at_rest() {
		$raw = get_option( Settings::OPTION_KEY, array() );
		if ( ! is_array( $raw ) ) {
			return false;
		}
		$changed = false;
		foreach ( self::KEYS as $key ) {
			if ( empty( $raw[ $key ] ) || ! is_string( $raw[ $key ] ) ) {
				continue;
			}
			if ( self::is_sealed( $raw[ $key ] ) ) {
				continue;
			}
			$raw[ $key ] = self::seal( $raw[ $key ] );
			$changed     = true;
		}
		if ( ! $changed ) {
			return false;
		}
		return (bool) update_option( Settings::OPTION_KEY, $raw, false );
	}

	/**
	 * 32-byte key derived from WordPress salts.
	 *
	 * @return string
	 */
	private static function key_bytes() {
		$material = wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ) . '|ucpf-secrets-v1';
		/**
		 * Filter secret encryption key material (advanced). Returning a non-32-byte
		 * string is hashed again — prefer leaving the default.
		 *
		 * @param string $material Key material.
		 */
		$material = (string) apply_filters( 'ucpf_secrets_key_material', $material );
		$hash     = hash( 'sha256', $material, true );
		return $hash;
	}
}
