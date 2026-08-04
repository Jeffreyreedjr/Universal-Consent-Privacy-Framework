<?php
/**
 * First-party vendor suppression connectors (local tags + queue CRM).
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Connectors for Meta, Google Ads, Klaviyo, Mailchimp — stop tags + mark CRM pending.
 */
class Vendor_Connectors {

	const OPTION_KEY = 'ucpf_vendor_suppress_queue';

	const STATUSES = array( 'queued', 'completed', 'skipped', 'failed' );

	/**
	 * Instance.
	 *
	 * @var Vendor_Connectors|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Vendor_Connectors
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire filters.
	 */
	public function init() {
		add_filter( 'ucpf_vendor_suppress', array( $this, 'handle' ), 10, 3 );
	}

	/**
	 * Handle vendor suppression.
	 *
	 * @param string $result Default.
	 * @param string $vendor Vendor id.
	 * @param array  $record Request.
	 * @return string
	 */
	public function handle( $result, $vendor, $record ) {
		$vendor = sanitize_key( $vendor );
		switch ( $vendor ) {
			case 'meta_ads':
			case 'meta_pixel':
				$this->queue_job( 'meta_ads', $record );
				// Tag blocking is handled by Script_Blocker + network gate when marketing denied.
				return 'completed';
			case 'google_ads':
				$this->queue_job( 'google_ads', $record );
				return 'completed';
			case 'email_crm':
			case 'klaviyo':
				$this->queue_job( 'klaviyo', $record );
				return 'pending'; // Operator confirms CRM API suppress.
			case 'mailchimp':
				$this->queue_job( 'mailchimp', $record );
				return 'pending';
			case 'server_gtm':
				$this->queue_job( 'server_gtm', $record );
				return 'pending';
			case 'data_export':
				return 'skipped';
			default:
				return $result;
		}
	}

	/**
	 * List queue jobs (newest last).
	 *
	 * @param string $status Optional status filter.
	 * @return array
	 */
	public static function list_jobs( $status = '' ) {
		$jobs = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $jobs ) ) {
			return array();
		}
		$status = sanitize_key( (string) $status );
		$out    = array();
		foreach ( $jobs as $i => $job ) {
			if ( ! is_array( $job ) ) {
				continue;
			}
			$row          = $job;
			$row['index'] = (int) $i;
			if ( $status && ( ! isset( $job['status'] ) || $job['status'] !== $status ) ) {
				continue;
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Update job status by index.
	 *
	 * @param int    $index  Index.
	 * @param string $status Status.
	 * @return bool
	 */
	public static function update_job( $index, $status ) {
		$index  = (int) $index;
		$status = sanitize_key( $status );
		if ( $index < 0 || ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}
		$jobs = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $jobs ) || ! isset( $jobs[ $index ] ) || ! is_array( $jobs[ $index ] ) ) {
			return false;
		}
		$jobs[ $index ]['status'] = $status;
		if ( 'completed' === $status ) {
			$jobs[ $index ]['completed_at'] = time();
		}
		update_option( self::OPTION_KEY, $jobs, false );
		return true;
	}

	/**
	 * Clear completed/skipped jobs.
	 */
	public static function clear_completed() {
		$jobs = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $jobs ) ) {
			return;
		}
		$next = array();
		foreach ( $jobs as $job ) {
			if ( ! is_array( $job ) ) {
				continue;
			}
			$st = isset( $job['status'] ) ? $job['status'] : '';
			if ( in_array( $st, array( 'completed', 'skipped' ), true ) ) {
				continue;
			}
			$next[] = $job;
		}
		update_option( self::OPTION_KEY, $next, false );
	}

	/**
	 * Clear entire queue.
	 */
	public static function clear_all() {
		update_option( self::OPTION_KEY, array(), false );
	}

	/**
	 * Store a suppression job for ops (no remote call by default).
	 *
	 * @param string $vendor Vendor.
	 * @param array  $record Record.
	 */
	private function queue_job( $vendor, array $record ) {
		$jobs = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $jobs ) ) {
			$jobs = array();
		}
		$jobs[] = array(
			'vendor'        => sanitize_key( $vendor ),
			'identity_hmac' => isset( $record['identity_hmac'] ) ? (string) $record['identity_hmac'] : '',
			'request_id'    => isset( $record['id'] ) ? (int) $record['id'] : 0,
			'deny'          => isset( $record['deny'] ) ? $record['deny'] : array(),
			'queued_at'     => time(),
			'status'        => 'queued',
		);
		// Keep last 200 jobs.
		if ( count( $jobs ) > 200 ) {
			$jobs = array_slice( $jobs, -200 );
		}
		update_option( self::OPTION_KEY, $jobs, false );
		do_action( 'ucpf_vendor_suppress_queued', $vendor, $record );
	}
}
