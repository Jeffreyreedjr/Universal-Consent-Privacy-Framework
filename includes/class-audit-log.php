<?php
/**
 * Consent audit log.
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Audit log handler.
 */
class Audit_Log {

	/**
	 * Instance.
	 *
	 * @var Audit_Log|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Audit_Log
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Init.
	 */
	public function init() {
		// Cron hooked from Plugin.
	}

	/**
	 * Log consent event.
	 *
	 * @param string $action      Action name.
	 * @param array  $cookie_data Cookie payload.
	 */
	public function log( $action, array $cookie_data ) {
		if ( ! Settings::get( 'consent_logging' ) ) {
			return;
		}

		global $wpdb;

		$table = ucpf_table( 'consent_logs' );

		$ip_hash = null;
		if ( Settings::get( 'log_ip_hash' ) && apply_filters( 'ucpf_log_ip_hash', true ) ) {
			$ip      = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
			$ip_hash = $ip ? hash( 'sha256', $ip . wp_salt() ) : null;
		}

		$ua_hash = null;
		if ( Settings::get( 'log_user_agent_hash' ) ) {
			$ua      = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
			$ua_hash = $ua ? hash( 'sha256', $ua . wp_salt() ) : null;
		}

		$retention = (int) Settings::get( 'log_retention_days' );
		$expires   = $retention > 0 ? gmdate( 'Y-m-d H:i:s', time() + ( $retention * DAY_IN_SECONDS ) ) : null;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'consent_uuid'     => isset( $cookie_data['uuid'] ) ? $cookie_data['uuid'] : wp_generate_uuid4(),
				'user_id'          => get_current_user_id() ?: null,
				'session_hash'     => $this->session_hash(),
				'ip_hash'          => $ip_hash,
				'user_agent_hash'  => $ua_hash,
				'region'           => $this->detect_region(),
				'policy_version'   => isset( $cookie_data['policy_version'] ) ? $cookie_data['policy_version'] : '',
				'consent_version'  => isset( $cookie_data['version'] ) ? $cookie_data['version'] : '',
				'action'           => sanitize_key( $action ),
				'categories'       => wp_json_encode( isset( $cookie_data['categories'] ) ? $cookie_data['categories'] : array() ),
				'services'         => wp_json_encode( isset( $cookie_data['services'] ) ? $cookie_data['services'] : array() ),
				'created_at'       => current_time( 'mysql', true ),
				'expires_at'       => $expires,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Get paginated logs.
	 *
	 * @param int $page Page number.
	 * @return array
	 */
	public function get_logs( $page = 1 ) {
		global $wpdb;

		$table  = ucpf_table( 'consent_logs' );
		$limit  = 50;
		$offset = ( $page - 1 ) * $limit;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);

		return array(
			'items' => $rows ? $rows : array(),
			'page'  => $page,
		);
	}

	/**
	 * Export logs as CSV string.
	 *
	 * @return string
	 */
	public function export_csv() {
		global $wpdb;

		$table = ucpf_table( 'consent_logs' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 5000", ARRAY_A );

		$out = "id,consent_uuid,user_id,action,policy_version,consent_version,created_at\n";
		foreach ( $rows as $row ) {
			$out .= implode(
				',',
				array(
					$row['id'],
					$row['consent_uuid'],
					$row['user_id'],
					$row['action'],
					$row['policy_version'],
					$row['consent_version'],
					$row['created_at'],
				)
			) . "\n";
		}

		return $out;
	}

	/**
	 * Purge expired log rows.
	 */
	public function purge_expired() {
		global $wpdb;

		$table = ucpf_table( 'consent_logs' );
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE expires_at IS NOT NULL AND expires_at < %s",
				$now
			)
		);
	}

	/**
	 * Anonymize logs for email/user.
	 *
	 * @param string $email Email address.
	 * @return int Rows affected.
	 */
	public function anonymize_by_email( $email ) {
		global $wpdb;

		$user_id = email_exists( $email );
		if ( ! $user_id ) {
			return 0;
		}

		$table = ucpf_table( 'consent_logs' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET ip_hash = NULL, user_agent_hash = NULL, session_hash = NULL WHERE user_id = %d",
				$user_id
			)
		);
	}

	/**
	 * Delete logs for user.
	 *
	 * @param string $email Email.
	 * @return int
	 */
	public function delete_by_email( $email ) {
		global $wpdb;

		$user_id = email_exists( $email );
		if ( ! $user_id ) {
			return 0;
		}

		$table = ucpf_table( 'consent_logs' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE user_id = %d",
				$user_id
			)
		);
	}

	/**
	 * Session hash.
	 *
	 * @return string|null
	 */
	private function session_hash() {
		if ( empty( $_COOKIE[ Consent_Manager::COOKIE_NAME ] ) ) {
			return null;
		}
		return hash( 'sha256', wp_unslash( $_COOKIE[ Consent_Manager::COOKIE_NAME ] ) . wp_salt() );
	}

	/**
	 * Detect region from Cloudflare header if present.
	 *
	 * @return string|null
	 */
	private function detect_region() {
		if ( ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) );
		}
		return null;
	}
}
