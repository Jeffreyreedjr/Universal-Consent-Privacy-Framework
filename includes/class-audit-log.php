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
	 * Skips insert when the same UUID + action + categories + services was logged
	 * within the last 5 seconds (burst / double-click noise). Meaningful changes still insert.
	 *
	 * @param string $action      Action name.
	 * @param array  $cookie_data Cookie payload.
	 */
	public function log( $action, array $cookie_data ) {
		if ( ! Settings::get( 'consent_logging' ) ) {
			return;
		}

		global $wpdb;

		$table_raw = ucpf_table( 'consent_logs' );
		if ( '' === $table_raw ) {
			return;
		}

		$uuid = isset( $cookie_data['uuid'] ) ? sanitize_text_field( (string) $cookie_data['uuid'] ) : '';
		if ( '' === $uuid ) {
			$uuid = wp_generate_uuid4();
		}

		$action           = sanitize_key( $action );
		$categories_json  = $this->normalize_map_json( isset( $cookie_data['categories'] ) ? $cookie_data['categories'] : array() );
		$services_json    = $this->normalize_map_json( isset( $cookie_data['services'] ) ? $cookie_data['services'] : array() );
		$signature        = md5( $action . '|' . $categories_json . '|' . $services_json );
		$transient_key    = 'ucpf_log_dedupe_' . md5( $uuid );
		$prev_sig         = get_transient( $transient_key );

		if ( is_string( $prev_sig ) && $prev_sig === $signature ) {
			return;
		}

		$table = esc_sql( $table_raw );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- latest row for burst dedupe.
		$latest = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from esc_sql( ucpf_table() ) whitelist.
				"SELECT action, categories, services, created_at FROM `{$table}` WHERE consent_uuid = %s ORDER BY id DESC LIMIT 1",
				$uuid
			),
			ARRAY_A
		);

		if ( is_array( $latest ) ) {
			$same_action = isset( $latest['action'] ) && sanitize_key( (string) $latest['action'] ) === $action;
			$same_cats   = $this->normalize_map_json( isset( $latest['categories'] ) ? $latest['categories'] : '' ) === $categories_json;
			$same_svcs   = $this->normalize_map_json( isset( $latest['services'] ) ? $latest['services'] : '' ) === $services_json;
			$created     = isset( $latest['created_at'] ) ? strtotime( $latest['created_at'] . ' UTC' ) : 0;
			$within      = $created && ( time() - $created ) <= 5;

			if ( $same_action && $same_cats && $same_svcs && $within ) {
				set_transient( $transient_key, $signature, 5 );
				return;
			}
		}

		$retention = (int) Settings::get( 'log_retention_days' );
		$retention = max( 1, min( 3650, $retention ) );
		$expires   = gmdate( 'Y-m-d H:i:s', time() + ( $retention * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table_raw,
			array(
				'consent_uuid'    => $uuid,
				'user_id'         => get_current_user_id() ?: null,
				'session_hash'    => $this->session_hash(),
				'ip_hash'         => null,
				'user_agent_hash' => null,
				'region'          => $this->detect_region(),
				'policy_version'  => isset( $cookie_data['policy_version'] ) ? $cookie_data['policy_version'] : '',
				'consent_version' => isset( $cookie_data['version'] ) ? $cookie_data['version'] : '',
				'action'          => $action,
				'categories'      => $categories_json,
				'services'        => $services_json,
				'created_at'      => current_time( 'mysql', true ),
				'expires_at'      => $expires,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		set_transient( $transient_key, $signature, 5 );
	}

	/**
	 * Normalize a category/service map to stable JSON for compare + storage.
	 *
	 * @param mixed $raw Array or JSON string.
	 * @return string
	 */
	private function normalize_map_json( $raw ) {
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		ksort( $raw );
		$out = array();
		foreach ( $raw as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			$out[ $key ] = (bool) $value;
		}
		$json = wp_json_encode( $out );
		return is_string( $json ) ? $json : '{}';
	}

	/**
	 * Get paginated logs (events) or visitor summaries.
	 *
	 * @param int               $page     Page number (1-based).
	 * @param int               $per_page Rows per page (1–200).
	 * @param array<string,mixed> $args {
	 *     Optional filters.
	 *
	 *     @type string $view      events|visitors. Default events.
	 *     @type string $uuid      UUID exact or prefix match.
	 *     @type string $action    Action key filter.
	 *     @type string $date_from Y-m-d (UTC, inclusive).
	 *     @type string $date_to   Y-m-d (UTC, inclusive).
	 * }
	 * @return array{items:array,page:int,per_page:int,total:int,pages:int,view:string,filters:array}
	 */
	public function get_logs( $page = 1, $per_page = 50, array $args = array() ) {
		$page     = max( 1, (int) $page );
		$per_page = max( 1, min( 200, (int) $per_page ) );
		$filters  = $this->sanitize_log_filters( $args );
		$view     = $filters['view'];

		$empty = array(
			'items'    => array(),
			'page'     => $page,
			'per_page' => $per_page,
			'total'    => 0,
			'pages'    => 0,
			'view'     => $view,
			'filters'  => $filters,
		);

		if ( '' === ucpf_table( 'consent_logs' ) ) {
			return $empty;
		}

		if ( 'visitors' === $view ) {
			return $this->get_visitor_summaries( $page, $per_page, $filters );
		}

		return $this->get_event_logs( $page, $per_page, $filters );
	}

	/**
	 * Sanitize list/export filter args.
	 *
	 * @param array<string,mixed> $args Raw args.
	 * @return array{view:string,uuid:string,action:string,date_from:string,date_to:string}
	 */
	public function sanitize_log_filters( array $args ) {
		$view = isset( $args['view'] ) ? sanitize_key( (string) $args['view'] ) : 'events';
		if ( 'visitors' !== $view ) {
			$view = 'events';
		}

		$uuid = isset( $args['uuid'] ) ? sanitize_text_field( (string) $args['uuid'] ) : '';
		$uuid = preg_replace( '/[^a-zA-Z0-9\-]/', '', $uuid );
		if ( ! is_string( $uuid ) ) {
			$uuid = '';
		}
		if ( strlen( $uuid ) > 64 ) {
			$uuid = substr( $uuid, 0, 64 );
		}

		$action = isset( $args['action'] ) ? sanitize_key( (string) $args['action'] ) : '';
		$allowed_actions = array( 'accept_all', 'reject_all', 'save_preferences', 'withdraw' );
		if ( $action && ! in_array( $action, $allowed_actions, true ) ) {
			$action = '';
		}

		$date_from = isset( $args['date_from'] ) ? sanitize_text_field( (string) $args['date_from'] ) : '';
		$date_to   = isset( $args['date_to'] ) ? sanitize_text_field( (string) $args['date_to'] ) : '';
		if ( $date_from && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) {
			$date_from = '';
		}
		if ( $date_to && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
			$date_to = '';
		}

		return array(
			'view'      => $view,
			'uuid'      => $uuid,
			'action'    => $action,
			'date_from' => $date_from,
			'date_to'   => $date_to,
		);
	}

	/**
	 * Build a fully prepared WHERE clause for consent log filters.
	 *
	 * Each dynamic value is escaped via $wpdb->prepare(); no unbound placeholders remain.
	 *
	 * @param array{uuid:string,action:string,date_from:string,date_to:string} $filters Filters.
	 * @param string                                                            $alias   '' or 'l'.
	 * @return string SQL boolean expression (no leading WHERE).
	 */
	private function build_log_where( array $filters, $alias = '' ) {
		global $wpdb;

		$use_alias = ( 'l' === $alias );
		$clauses   = array( '1=1' );

		if ( '' !== $filters['uuid'] ) {
			$like = $filters['uuid'] . '%';
			if ( $use_alias ) {
				$frag = $wpdb->prepare( 'l.consent_uuid LIKE %s', $like );
			} else {
				$frag = $wpdb->prepare( 'consent_uuid LIKE %s', $like );
			}
			if ( is_string( $frag ) && '' !== $frag ) {
				$clauses[] = $frag;
			}
		}
		if ( '' !== $filters['action'] ) {
			if ( $use_alias ) {
				$frag = $wpdb->prepare( 'l.action = %s', $filters['action'] );
			} else {
				$frag = $wpdb->prepare( 'action = %s', $filters['action'] );
			}
			if ( is_string( $frag ) && '' !== $frag ) {
				$clauses[] = $frag;
			}
		}
		if ( '' !== $filters['date_from'] ) {
			$from = $filters['date_from'] . ' 00:00:00';
			if ( $use_alias ) {
				$frag = $wpdb->prepare( 'l.created_at >= %s', $from );
			} else {
				$frag = $wpdb->prepare( 'created_at >= %s', $from );
			}
			if ( is_string( $frag ) && '' !== $frag ) {
				$clauses[] = $frag;
			}
		}
		if ( '' !== $filters['date_to'] ) {
			$to = $filters['date_to'] . ' 23:59:59';
			if ( $use_alias ) {
				$frag = $wpdb->prepare( 'l.created_at <= %s', $to );
			} else {
				$frag = $wpdb->prepare( 'created_at <= %s', $to );
			}
			if ( is_string( $frag ) && '' !== $frag ) {
				$clauses[] = $frag;
			}
		}

		return implode( ' AND ', $clauses );
	}

	/**
	 * Flat event list.
	 *
	 * @param int                  $page     Page.
	 * @param int                  $per_page Per page.
	 * @param array<string,string> $filters  Filters.
	 * @return array{items:array,page:int,per_page:int,total:int,pages:int,view:string,filters:array}
	 */
	private function get_event_logs( $page, $per_page, array $filters ) {
		global $wpdb;

		$table = esc_sql( ucpf_table( 'consent_logs' ) );
		$where = $this->build_log_where( $filters );

		if ( '' === $table ) {
			return array(
				'items'    => array(),
				'page'     => $page,
				'per_page' => $per_page,
				'total'    => 0,
				'pages'    => 0,
				'view'     => 'events',
				'filters'  => $filters,
			);
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table via esc_sql( ucpf_table() ); $where/$limit_sql from $wpdb->prepare() fragments.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin consent log list.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE {$where}" );

		$pages  = $total > 0 ? (int) ceil( $total / $per_page ) : 0;
		$page   = $pages > 0 ? min( $page, $pages ) : 1;
		$offset = ( $page - 1 ) * $per_page;

		$limit_sql = $wpdb->prepare( 'LIMIT %d OFFSET %d', $per_page, $offset );
		if ( ! is_string( $limit_sql ) ) {
			$limit_sql = 'LIMIT 0';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin consent log list.
		$rows = $wpdb->get_results( "SELECT * FROM `{$table}` WHERE {$where} ORDER BY created_at DESC, id DESC {$limit_sql}", ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return array(
			'items'    => $rows ? $rows : array(),
			'page'     => $page,
			'per_page' => $per_page,
			'total'    => $total,
			'pages'    => $pages,
			'view'     => 'events',
			'filters'  => $filters,
		);
	}

	/**
	 * One row per visitor UUID (latest event + count).
	 *
	 * @param int                  $page     Page.
	 * @param int                  $per_page Per page.
	 * @param array<string,string> $filters  Filters.
	 * @return array{items:array,page:int,per_page:int,total:int,pages:int,view:string,filters:array}
	 */
	private function get_visitor_summaries( $page, $per_page, array $filters ) {
		global $wpdb;

		$table = esc_sql( ucpf_table( 'consent_logs' ) );
		$where = $this->build_log_where( $filters, 'l' );

		if ( '' === $table ) {
			return array(
				'items'    => array(),
				'page'     => $page,
				'per_page' => $per_page,
				'total'    => 0,
				'pages'    => 0,
				'view'     => 'visitors',
				'filters'  => $filters,
			);
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table via esc_sql( ucpf_table() ); $where/$limit_sql from $wpdb->prepare() fragments.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin visitor summary.
		$total = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT l.consent_uuid) FROM `{$table}` l WHERE {$where}" );

		$pages  = $total > 0 ? (int) ceil( $total / $per_page ) : 0;
		$page   = $pages > 0 ? min( $page, $pages ) : 1;
		$offset = ( $page - 1 ) * $per_page;

		$limit_sql = $wpdb->prepare( 'LIMIT %d OFFSET %d', $per_page, $offset );
		if ( ! is_string( $limit_sql ) ) {
			$limit_sql = 'LIMIT 0';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin visitor summary.
		$rows = $wpdb->get_results( "SELECT l.*, c.event_count FROM ( SELECT consent_uuid, COUNT(*) AS event_count, MAX(id) AS last_id FROM `{$table}` l WHERE {$where} GROUP BY consent_uuid ) c INNER JOIN `{$table}` l ON l.id = c.last_id ORDER BY l.created_at DESC, l.id DESC {$limit_sql}", ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return array(
			'items'    => $rows ? $rows : array(),
			'page'     => $page,
			'per_page' => $per_page,
			'total'    => $total,
			'pages'    => $pages,
			'view'     => 'visitors',
			'filters'  => $filters,
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
	 * @param array<string,mixed> $args Optional filters (same as get_logs; view ignored — always events).
	 * @return string
	 */
	public function export_csv( array $args = array() ) {
		global $wpdb;

		$filters         = $this->sanitize_log_filters( $args );
		$filters['view'] = 'events';

		$table = esc_sql( ucpf_table( 'consent_logs' ) );
		if ( '' === $table ) {
			return '';
		}

		$where = $this->build_log_where( $filters );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table via esc_sql( ucpf_table() ); $where from $wpdb->prepare() fragments.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin CSV export.
		$rows = $wpdb->get_results( "SELECT * FROM `{$table}` WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT 5000", ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$lines   = array();
		$lines[] = implode(
			',',
			array(
				$this->csv_cell( 'id' ),
				$this->csv_cell( 'consent_uuid' ),
				$this->csv_cell( 'user_id' ),
				$this->csv_cell( 'action' ),
				$this->csv_cell( 'categories' ),
				$this->csv_cell( 'region' ),
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
					$this->csv_cell( isset( $row['categories'] ) ? $row['categories'] : '' ),
					$this->csv_cell( isset( $row['region'] ) ? $row['region'] : '' ),
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
