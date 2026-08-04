<?php
/**
 * Agency scanner federation helpers (1.3 foundations).
 *
 * Domain verification token, scan baseline storage for drift, node metadata.
 * Does not phone home — agencies configure their own scanner URL.
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Agency-facing scanner federation utilities.
 */
class Agency_Scanner {

	/**
	 * Instance.
	 *
	 * @var Agency_Scanner|null
	 */
	private static $instance = null;

	const OPTION_BASELINE = 'ucpf_scan_baseline';
	const OPTION_TOKEN    = 'ucpf_scan_verify_token';

	/**
	 * @return Agency_Scanner
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hook well-known verification + REST helpers.
	 */
	public function init() {
		add_action( 'init', array( $this, 'register_well_known' ) );
		add_action( 'template_redirect', array( $this, 'serve_well_known' ), 0 );
	}

	/**
	 * Rewrite for /.well-known/ucpf-scan-token
	 */
	public function register_well_known() {
		add_rewrite_rule( '^\.well-known/ucpf-scan-token/?$', 'index.php?ucpf_scan_token=1', 'top' );
		add_rewrite_tag( '%ucpf_scan_token%', '1' );
	}

	/**
	 * Serve domain-control token (plain text).
	 */
	public function serve_well_known() {
		if ( ! get_query_var( 'ucpf_scan_token' ) ) {
			// Fallback without rewrite flush: match REQUEST_URI.
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			if ( ! preg_match( '#/\.well-known/ucpf-scan-token/?(\?|$)#', $uri ) ) {
				return;
			}
		}

		$token = $this->get_or_create_token();
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		status_header( 200 );
		echo esc_html( $token );
		exit;
	}

	/**
	 * @return string
	 */
	public function get_or_create_token() {
		$existing = get_option( self::OPTION_TOKEN, '' );
		if ( is_string( $existing ) && strlen( $existing ) >= 24 ) {
			return $existing;
		}
		$token = wp_generate_password( 32, false, false );
		update_option( self::OPTION_TOKEN, $token, false );
		return $token;
	}

	/**
	 * Persist last successful scan as drift baseline (agency).
	 *
	 * @param array $payload Sanitized last_scan-like payload (no cookie values).
	 * @return bool
	 */
	public static function store_baseline( array $payload ) {
		$slim = array(
			'date'             => isset( $payload['date'] ) ? sanitize_text_field( $payload['date'] ) : current_time( 'mysql' ),
			'findings_summary' => isset( $payload['findings_summary'] ) && is_array( $payload['findings_summary'] ) ? $payload['findings_summary'] : array(),
			'consent_leaks'    => isset( $payload['consent_leaks'] ) && is_array( $payload['consent_leaks'] ) ? array_slice( $payload['consent_leaks'], 0, 200 ) : array(),
			'cookies'          => array(),
			'requests'         => array(),
		);
		foreach ( isset( $payload['cookies'] ) && is_array( $payload['cookies'] ) ? $payload['cookies'] : array() as $c ) {
			if ( ! is_array( $c ) || empty( $c['name'] ) ) {
				continue;
			}
			$slim['cookies'][] = array(
				'name'     => sanitize_text_field( $c['name'] ),
				'category' => isset( $c['category'] ) ? sanitize_key( $c['category'] ) : '',
			);
			if ( count( $slim['cookies'] ) >= 300 ) {
				break;
			}
		}
		foreach ( isset( $payload['privacy_signals']['requests'] ) && is_array( $payload['privacy_signals']['requests'] ) ? $payload['privacy_signals']['requests'] : array() as $r ) {
			if ( is_array( $r ) && ! empty( $r['host'] ) ) {
				$slim['requests'][] = sanitize_text_field( $r['host'] );
			} elseif ( is_string( $r ) ) {
				$slim['requests'][] = sanitize_text_field( $r );
			}
			if ( count( $slim['requests'] ) >= 200 ) {
				break;
			}
		}
		return update_option( self::OPTION_BASELINE, $slim, false );
	}

	/**
	 * @return array|null
	 */
	public static function get_baseline() {
		$b = get_option( self::OPTION_BASELINE, null );
		return is_array( $b ) ? $b : null;
	}

	/**
	 * Scanner mode for admin docs: local | agency.
	 *
	 * @return string
	 */
	public static function scanner_mode() {
		$url = (string) Settings::get( 'scanner_api_url', '' );
		if ( $url ) {
			return 'agency';
		}
		return 'local';
	}
}
