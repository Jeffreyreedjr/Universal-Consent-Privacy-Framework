<?php
/**
 * Login activity transparency notices (WP / Woo / admin).
 *
 * Complements password-policy / login-security plugins (e.g. miniOrange)
 * by telling people that login-related events may be logged.
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Surfaces a short security notice on login surfaces when enabled.
 */
class Login_Notice {

	const USER_META_DISMISS = 'ucpf_dismiss_login_security_notice';

	/**
	 * @var Login_Notice|null
	 */
	private static $instance = null;

	/**
	 * @return Login_Notice
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hook notices.
	 */
	public function init() {
		if ( ! Settings::get( 'login_security_notice', true ) ) {
			return;
		}

		add_filter( 'login_message', array( $this, 'filter_login_message' ) );
		add_action( 'woocommerce_login_form_start', array( $this, 'render_woo_login_notice' ) );
		add_action( 'woocommerce_before_customer_login_form', array( $this, 'render_woo_login_notice' ), 5 );

		if ( is_admin() ) {
			add_action( 'admin_notices', array( $this, 'maybe_admin_notice' ) );
			add_action( 'wp_ajax_ucpf_dismiss_login_security_notice', array( $this, 'ajax_dismiss' ) );
		}
	}

	/**
	 * Whether the site looks like it has account logins worth disclosing.
	 *
	 * @return bool
	 */
	public static function site_has_logins() {
		$profile = Site_Profiles::current();
		if ( in_array( $profile, array( Site_Profiles::WP_LOGIN, Site_Profiles::WOOCOMMERCE ), true ) ) {
			return true;
		}
		if ( class_exists( '\WooCommerce' ) || function_exists( 'WC' ) ) {
			return true;
		}
		/**
		 * Filter whether login-security notices should treat this site as having logins.
		 *
		 * @param bool $has_logins Detected.
		 */
		return (bool) apply_filters( 'ucpf_site_has_logins', true );
	}

	/**
	 * Shared notice copy (plain text).
	 *
	 * @return string
	 */
	public static function notice_text() {
		$text = __(
			'For security, login attempts and related account-security events (such as password policy checks) may be logged. This helps protect accounts against unauthorized access.',
			'universal-consent-privacy-framework'
		);
		/**
		 * Filter login security notice text.
		 *
		 * @param string $text Notice.
		 */
		return (string) apply_filters( 'ucpf_login_security_notice_text', $text );
	}

	/**
	 * wp-login.php message.
	 *
	 * @param string $message Existing HTML.
	 * @return string
	 */
	public function filter_login_message( $message ) {
		$notice = '<p class="message ucpf-login-security-notice">' . esc_html( self::notice_text() ) . '</p>';
		return $notice . (string) $message;
	}

	/**
	 * WooCommerce My Account / login form notice (once per request).
	 */
	public function render_woo_login_notice() {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		echo '<div class="woocommerce-info ucpf-login-security-notice" role="status">';
		echo esc_html( self::notice_text() );
		echo '</div>';
	}

	/**
	 * Admin transparency for staff / agency operators.
	 */
	public function maybe_admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$user_id = get_current_user_id();
		if ( $user_id && get_user_meta( $user_id, self::USER_META_DISMISS, true ) ) {
			return;
		}

		$dismiss_url = wp_nonce_url(
			admin_url( 'admin-ajax.php?action=ucpf_dismiss_login_security_notice' ),
			'ucpf_dismiss_login_security_notice'
		);

		echo '<div class="notice notice-info is-dismissible ucpf-login-security-admin-notice"><p>';
		echo esc_html(
			__(
				'UCPF: Account logins on this site may be logged by password-policy / login-security plugins (for example miniOrange) for security. Visitors and clients see a short notice on WordPress and WooCommerce login forms when that option is enabled under Advanced settings.',
				'universal-consent-privacy-framework'
			)
		);
		echo ' <a href="' . esc_url( $dismiss_url ) . '">' . esc_html__( 'Dismiss', 'universal-consent-privacy-framework' ) . '</a>';
		echo ' · <a href="' . esc_url( admin_url( 'admin.php?page=ucpf-advanced' ) ) . '">' . esc_html__( 'Advanced settings', 'universal-consent-privacy-framework' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * Persist dismiss for current admin.
	 */
	public function ajax_dismiss() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1, 403 );
		}
		check_admin_referer( 'ucpf_dismiss_login_security_notice' );
		$user_id = get_current_user_id();
		if ( $user_id ) {
			update_user_meta( $user_id, self::USER_META_DISMISS, '1' );
		}
		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$redirect = admin_url();
		}
		wp_safe_redirect( $redirect );
		exit;
	}
}
