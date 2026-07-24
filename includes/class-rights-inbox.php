<?php
/**
 * Rights inbox — admin queue for DSAR / DNS requests (fulfillment ops).
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * List and update data_requests rows for agency ops.
 */
class Rights_Inbox {

	/**
	 * Valid workflow statuses.
	 *
	 * @var string[]
	 */
	const STATUSES = array( 'pending', 'sent', 'verified', 'in_progress', 'completed', 'rejected' );

	/**
	 * Instance.
	 *
	 * @var Rights_Inbox|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Rights_Inbox
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Init hooks.
	 */
	public function init() {
		// Table already created by Activator; ensure columns via Migration when needed.
	}

	/**
	 * List requests newest first.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public function list_requests( $limit = 100 ) {
		global $wpdb;
		$table = ucpf_table( 'data_requests' );
		$limit = max( 1, min( 500, (int) $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, request_type, email_hash, status, user_request_id, meta, created_at FROM {$table} ORDER BY id DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$out = array();
		foreach ( $rows as $row ) {
			$meta = array();
			if ( ! empty( $row['meta'] ) ) {
				$decoded = json_decode( (string) $row['meta'], true );
				if ( is_array( $decoded ) ) {
					$meta = $decoded;
				}
			}
			$out[] = array(
				'id'              => (int) $row['id'],
				'request_type'    => (string) $row['request_type'],
				'email_hash'      => (string) $row['email_hash'],
				'status'          => (string) $row['status'],
				'user_request_id' => $row['user_request_id'] ? (int) $row['user_request_id'] : null,
				'created_at'      => (string) $row['created_at'],
				'scope'           => isset( $meta['scope'] ) ? (string) $meta['scope'] : '',
				'notes'           => isset( $meta['ops_notes'] ) ? (string) $meta['ops_notes'] : '',
				'vendor_status'   => isset( $meta['vendor_status'] ) && is_array( $meta['vendor_status'] ) ? $meta['vendor_status'] : array(),
				'checklist'       => isset( $meta['processor_checklist'] ) && is_array( $meta['processor_checklist'] ) ? $meta['processor_checklist'] : $this->default_checklist( (string) $row['request_type'] ),
				'verified_at'     => isset( $meta['verified_at'] ) ? (string) $meta['verified_at'] : '',
				'sla_due_at'      => isset( $meta['sla_due_at'] ) ? (string) $meta['sla_due_at'] : '',
				'local_enforced'  => ! empty( $meta['local_enforced'] ),
			);
		}
		return $out;
	}

	/**
	 * Default processor checklist by type.
	 *
	 * @param string $type Request type.
	 * @return array<string, bool>
	 */
	public function default_checklist( $type ) {
		$base = array(
			'site_consent_logs' => false,
			'wp_user_tools'     => false,
			'email_crm'         => false,
			'ads_platforms'     => false,
			'analytics'         => false,
		);
		if ( 'do_not_sell' === $type ) {
			$base['dns_local_cookie'] = false;
			$base['vendor_suppress']  = false;
		}
		return $base;
	}

	/**
	 * Update request status / notes / checklist.
	 *
	 * @param int   $id   Row id.
	 * @param array $args status, notes, checklist, mark_verified.
	 * @return array|\WP_Error
	 */
	public function update_request( $id, array $args ) {
		global $wpdb;
		$id    = (int) $id;
		$table = ucpf_table( 'data_requests' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			return new \WP_Error( 'ucpf_not_found', __( 'Request not found.', 'universal-consent-privacy-framework' ), array( 'status' => 404 ) );
		}
		$meta = array();
		if ( ! empty( $row['meta'] ) ) {
			$decoded = json_decode( (string) $row['meta'], true );
			if ( is_array( $decoded ) ) {
				$meta = $decoded;
			}
		}

		$status = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : (string) $row['status'];
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return new \WP_Error( 'ucpf_bad_status', __( 'Invalid status.', 'universal-consent-privacy-framework' ), array( 'status' => 400 ) );
		}

		if ( isset( $args['notes'] ) ) {
			$meta['ops_notes'] = sanitize_textarea_field( (string) $args['notes'] );
		}
		if ( isset( $args['checklist'] ) && is_array( $args['checklist'] ) ) {
			$clean = array();
			foreach ( $args['checklist'] as $k => $v ) {
				$clean[ sanitize_key( $k ) ] = (bool) $v;
			}
			$meta['processor_checklist'] = $clean;
		}
		if ( ! empty( $args['mark_verified'] ) ) {
			$meta['verified_at'] = current_time( 'mysql', true );
			if ( 'pending' === $status || 'sent' === $status ) {
				$status = 'verified';
			}
		}
		if ( empty( $meta['sla_due_at'] ) && ! empty( $row['created_at'] ) ) {
			$ts = strtotime( $row['created_at'] . ' UTC' );
			if ( $ts ) {
				// Soft SLA: 45 days (CPRA-ish window); not a legal guarantee.
				$meta['sla_due_at'] = gmdate( 'Y-m-d H:i:s', $ts + ( 45 * DAY_IN_SECONDS ) );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$table,
			array(
				'status' => $status,
				'meta'   => wp_json_encode( $meta ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return array(
			'success' => true,
			'id'      => $id,
			'status'  => $status,
		);
	}

	/**
	 * Create email verification token (stored hashed in meta).
	 *
	 * @param int $id Request id.
	 * @return string|false Plain token once, or false.
	 */
	public function issue_verification_token( $id ) {
		global $wpdb;
		$id    = (int) $id;
		$table = ucpf_table( 'data_requests' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT meta FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			return false;
		}
		$meta = array();
		if ( ! empty( $row['meta'] ) ) {
			$decoded = json_decode( (string) $row['meta'], true );
			if ( is_array( $decoded ) ) {
				$meta = $decoded;
			}
		}
		$token = wp_generate_password( 32, false, false );
		$meta['verify_token_hash'] = hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
		$meta['verify_expires']    = time() + DAY_IN_SECONDS;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$table,
			array( 'meta' => wp_json_encode( $meta ) ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
		return $token;
	}
}
