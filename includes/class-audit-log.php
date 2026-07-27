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
		if ( '' === $table ) {
			return;
		}

		$retention = (int) Settings::get( 'log_retention_days' );
		$retention = max( 1, min( 3650, $retention ) );
		$expires   = gmdate( 'Y-m-d H:i:s', time() + ( $retention * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'consent_uuid'    => isset( $cookie_data['uuid'] ) ? $cookie_data['uuid'] : wp_generate_uuid4(),
				'user_id'         => get_current_user_id() ?: null,
				'session_hash'    => $this->session_hash(),
				'ip_hash'         => null,
				'user_agent_hash' => null,
				'region'          => $this->detect_region(),
				'policy_version'  => isset( $cookie_data['policy_version'] ) ? $cookie_data['policy_version'] : '',
				'consent_version' => isset( $cookie_data['version'] ) ? $cookie_data['version'] : '',
				'action'          => sanitize_key( $action ),
				'categories'      => wp_json_encode( isset( $cookie_data['categories'] ) ? $cookie_data['categories'] : array() ),
				'services'        => wp_json_encode( isset( $cookie_data['services'] ) ? $cookie_data['services'] : array() ),
				'created_at'      => current_time( 'mysql', true ),
				'expires_at'      => $expires,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Get paginated logs.
	 *
	 * @param int $page     Page number (1-based).
	 * @param int $per_page Rows per page (1–200).
	 * @return array{items:array,page:int,per_page:int,total:int,pages:int}
	 */
	public function get_logs( $page = 1, $per_page = 50 ) {
		global $wpdb;

		$page     = max( 1, (int) $page );
		$per_page = max( 1, min( 200, (int) $per_page ) );

		$empty = array(
			'items'    => array(),
			'page'     => $page,
			'per_page' => $per_page,
			'total'    => 0,
			'pages'    => 0,
		);

		$table = esc_sql( ucpf_table( 'consent_logs' ) );
		if ( '' === $table ) {
			return $empty;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin log count.
		$total = (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from esc_sql( ucpf_table() ) whitelist.
			"SELECT COUNT(*) FROM `{$table}`"
		);

		$pages  = $total > 0 ? (int) ceil( $total / $per_page ) : 0;
		$page   = $pages > 0 ? min( $page, $pages ) : 1;
		$offset = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin log read; not front-end cached content.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from esc_sql( ucpf_table() ) whitelist.
				"SELECT * FROM `{$table}` ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);

		return array(
			'items'    => $rows ? $rows : array(),
			'page'     => $page,
			'per_page' => $per_page,
			'total'    => $total,
			'pages'    => $pages,
		);
	}

	/**
	 * Recompute expires_at from created_at using current (or given) retention days.
	 * Extends or shortens the retention window for existing light consent log rows.
	 *
	 * @param int|null $days Retention days; null reads settings.
	 * @return int Rows updated.
	 */
	public function recompute_expires( $days = null ) {
		global $wpdb;

		$days = null === $days ? (int) Settings::get( 'log_retention_days' ) : (int) $days;
		$days = max( 1, min( 3650, $days ) );

		$table = esc_sql( ucpf_table( 'consent_logs' ) );
		if ( '' === $table ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- retention backfill.
		return (int) $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from esc_sql( ucpf_table() ) whitelist.
				"UPDATE `{$table}` SET expires_at = DATE_ADD(created_at, INTERVAL %d DAY) WHERE created_at IS NOT NULL",
				$days
			)
		);
	}

	/**
	 * Export logs as CSV string.
	 *
	 * @return string
	 */
	public function export_csv() {
		global $wpdb;

		$table = esc_sql( ucpf_table( 'consent_logs' ) );
		if ( '' === $table ) {
			return '';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- CSV export read.
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from esc_sql( ucpf_table() ) whitelist.
			"SELECT * FROM `{$table}` ORDER BY created_at DESC LIMIT 5000",
			ARRAY_A
		);

		$lines   = array();
		$lines[] = implode(
			',',
			array(
				$this->csv_cell( 'id' ),
				$this->csv_cell( 'consent_uuid' ),
				$this->csv_cell( 'user_id' ),
				$this->csv_cell( 'action' ),
				$this->csv_cell( 'policy_version' ),
				$this->csv_cell( 'consent_version' ),
				$this->csv_cell( 'created_at' ),
			)
		);
		foreach ( (array) $rows as $row ) {
			$lines[] = implode(
				',',
				array(
					$this->csv_cell( isset( $row['id'] ) ? $row['id'] : '' ),
					$this->csv_cell( isset( $row['consent_uuid'] ) ? $row['consent_uuid'] : '' ),
					$this->csv_cell( isset( $row['user_id'] ) ? $row['user_id'] : '' ),
					$this->csv_cell( isset( $row['action'] ) ? $row['action'] : '' ),
					$this->csv_cell( isset( $row['policy_version'] ) ? $row['policy_version'] : '' ),
					$this->csv_cell( isset( $row['consent_version'] ) ? $row['consent_version'] : '' ),
					$this->csv_cell( isset( $row['created_at'] ) ? $row['created_at'] : '' ),
				)
			);
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Escape a CSV cell (RFC-style quoting).
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	private function csv_cell( $value ) {
		$value = (string) $value;
		if ( 1 === preg_match( '/[",\r\n]/', $value ) ) {
			return '"' . str_replace( '"', '""', $value ) . '"';
		}
		return $value;
	}

	/**
	 * Purge expired log rows.
	 */
	public function purge_expired() {
		global $wpdb;

		$table = esc_sql( ucpf_table( 'consent_logs' ) );
		if ( '' === $table ) {
			return;
		}
		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- retention purge.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from esc_sql( ucpf_table() ) whitelist.
				"DELETE FROM `{$table}` WHERE expires_at IS NOT NULL AND expires_at < %s",
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

		$table = esc_sql( ucpf_table( 'consent_logs' ) );
		if ( '' === $table ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy eraser.
		return (int) $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from esc_sql( ucpf_table() ) whitelist.
				"UPDATE `{$table}` SET ip_hash = NULL, user_agent_hash = NULL, session_hash = NULL WHERE user_id = %d",
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

		$table = esc_sql( ucpf_table( 'consent_logs' ) );
		if ( '' === $table ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy eraser.
		return (int) $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from esc_sql( ucpf_table() ) whitelist.
				"DELETE FROM `{$table}` WHERE user_id = %d",
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
		$raw = sanitize_text_field( wp_unslash( $_COOKIE[ Consent_Manager::COOKIE_NAME ] ) );
		return hash( 'sha256', $raw . wp_salt() );
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
