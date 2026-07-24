<?php
/**
 * Global helper functions.
 *
 * @package UCPF
 */

defined( 'ABSPATH' ) || exit;

/**
 * Check consent for a category or service.
 *
 * @param string $category_or_service Category or service key.
 * @return bool
 */
function ucpf_has_consent( $category_or_service ) {
	return UCPF\Consent_Manager::instance()->has_consent( $category_or_service );
}

/**
 * Register a tracking service.
 *
 * @param array $args Service definition.
 * @return bool|\WP_Error
 */
function ucpf_register_service( array $args ) {
	return UCPF\Script_Registry::instance()->register_service( $args );
}

/**
 * Get current consent state.
 *
 * @return array
 */
function ucpf_get_consent_state() {
	return UCPF\Consent_Manager::instance()->get_consent_state();
}

/**
 * Get consent categories.
 *
 * @return array
 */
function ucpf_get_categories() {
	return UCPF\Consent_Manager::instance()->get_categories();
}

/**
 * Get registered services.
 *
 * @return array
 */
function ucpf_get_registered_services() {
	return UCPF\Script_Registry::instance()->get_services();
}

/**
 * Get plugin option with default.
 *
 * @param string $key     Option key (without prefix).
 * @param mixed  $default Default value.
 * @return mixed
 */
function ucpf_get_option( $key, $default = null ) {
	return UCPF\Settings::get( $key, $default );
}

/**
 * Get authoritative privacy enforcement state (GPC / DNS / central).
 *
 * @return array
 */
function ucpf_get_privacy_state() {
	return UCPF\Privacy_State::instance()->get_state();
}

/**
 * Whether Sec-GPC (or Nginx UCPF_GPC) is present on this request.
 *
 * @return bool
 */
function ucpf_gpc_signal_present() {
	return UCPF\Privacy_State::gpc_signal_present();
}

/**
 * Keyed HMAC for an email (privacy preference lookups).
 *
 * @param string $email Email.
 * @return string
 */
function ucpf_privacy_hmac_email( $email ) {
	return UCPF\Privacy_Identity::hmac_email( $email );
}

/**
 * Plugin table name with prefix.
 *
 * @param string $table Short table name.
 * @return string
 */
function ucpf_table( $table ) {
	global $wpdb;
	return $wpdb->prefix . 'ucpf_' . $table;
}
