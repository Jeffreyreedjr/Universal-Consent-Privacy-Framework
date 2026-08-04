<?php
/**
 * Interactive Playwright deep-scan job persistence (WP-Cron poll + reconnect).
 *
 * Unlike Scheduled_Scan (fleet/nightly), this tracks the admin Scanner UI job so
 * progress/logs survive leaving the page and Stop always has a job_id.
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Persist and poll interactive remote deep scans.
 */
class Active_Scan {

	const OPTION_KEY = 'ucpf_active_deep_scan';
	const HOOK_POLL  = 'ucpf_active_scan_poll';
	/** ~90 × 60s ≈ 90 minutes — covers Deep + shared-scanner queue wait. */
	const MAX_POLLS  = 90;
	const POLL_DELAY = 60;
	/** Keep finished snapshot for UI reconnect briefly. */
	const FINISHED_TTL = DAY_IN_SECONDS;
	const LOG_MAX      = 80;

	/**
	 * @var Active_Scan|null
	 */
	private static $instance = null;

	/**
	 * @return Active_Scan
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire cron hook.
	 */
	public function init() {
		add_action( self::HOOK_POLL, array( $this, 'run_poll' ) );
	}

	/**
	 * Read stored job state.
	 *
	 * @return array
	 */
	public function get() {
		$raw = get_option( self::OPTION_KEY, array() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Persist job state.
	 *
	 * @param array $status Status.
	 */
	public function set( array $status ) {
		update_option( self::OPTION_KEY, $status, false );
	}

	/**
	 * Remove stored job (and pending polls).
	 */
	public function clear() {
		delete_option( self::OPTION_KEY );
		wp_clear_scheduled_hook( self::HOOK_POLL );
	}

	/**
	 * Whether a deep scan is still in flight.
	 *
	 * @param array|null $status Optional status snapshot.
	 * @return bool
	 */
	public function is_active( $status = null ) {
		$status = is_array( $status ) ? $status : $this->get();
		if ( empty( $status['job_id'] ) ) {
			return false;
		}
		$state = isset( $status['state'] ) ? sanitize_key( (string) $status['state'] ) : '';
		return in_array( $state, array( 'queued', 'running', 'polling', 'cancelling' ), true );
	}

	/**
	 * Job id for the active (or last) interactive scan.
	 *
	 * @return string
	 */
	public function job_id() {
		$status = $this->get();
		return ! empty( $status['job_id'] ) ? sanitize_text_field( (string) $status['job_id'] ) : '';
	}

	/**
	 * Normalize remote progress for storage / UI.
	 *
	 * @param mixed $progress Remote progress.
	 * @return array
	 */
	public function normalize_progress( $progress ) {
		$p = is_array( $progress ) ? $progress : array();
		$log = array();
		if ( ! empty( $p['log'] ) && is_array( $p['log'] ) ) {
			foreach ( $p['log'] as $line ) {
				if ( is_string( $line ) && '' !== $line ) {
					$log[] = $line;
				} elseif ( is_scalar( $line ) ) {
					$log[] = (string) $line;
				}
			}
			$log = array_slice( $log, -1 * self::LOG_MAX );
		}
		return array(
			'percent'        => isset( $p['percent'] ) ? (float) $p['percent'] : 0,
			'phase'          => isset( $p['phase'] ) ? sanitize_key( (string) $p['phase'] ) : '',
			'message'        => isset( $p['message'] ) ? sanitize_text_field( (string) $p['message'] ) : '',
			'step'           => isset( $p['step'] ) ? (int) $p['step'] : null,
			'total'          => isset( $p['total'] ) ? (int) $p['total'] : null,
			'session_index'  => isset( $p['session_index'] ) ? (int) $p['session_index'] : null,
			'sessions_total' => isset( $p['sessions_total'] ) ? (int) $p['sessions_total'] : null,
			'page_index'     => isset( $p['page_index'] ) ? (int) $p['page_index'] : null,
			'pages_total'    => isset( $p['pages_total'] ) ? (int) $p['pages_total'] : null,
			'log'            => $log,
		);
	}

	/**
	 * Register a newly started remote job and schedule cron polling.
	 *
	 * @param array $job     Remote start response (must include id).
	 * @param array $context Optional url/paths/depth.
	 * @return array|\WP_Error Stored status or error if another job is active.
	 */
	public function register( array $job, array $context = array() ) {
		$current = $this->get();
		if ( $this->is_active( $current ) ) {
			$existing = isset( $current['job_id'] ) ? (string) $current['job_id'] : '';
			return new \WP_Error(
				'ucpf_scan_already_active',
				__( 'A Playwright scan is already running. Stop it before starting another, or wait for it to finish.', 'universal-consent-privacy-framework' ),
				array(
					'status' => 409,
					'job_id' => $existing,
					'active' => $current,
				)
			);
		}

		$job_id = isset( $job['id'] ) ? sanitize_text_field( (string) $job['id'] ) : '';
		if ( ! $job_id ) {
			return new \WP_Error(
				'ucpf_scanner_no_id',
				__( 'Scanner did not return a job id.', 'universal-consent-privacy-framework' ),
				array( 'status' => 502 )
			);
		}

		$remote_state = isset( $job['status'] ) ? sanitize_key( (string) $job['status'] ) : 'running';
		$state        = ( 'queued' === $remote_state ) ? 'queued' : 'running';

		$status = array(
			'job_id'     => $job_id,
			'state'      => $state,
			'started'    => current_time( 'mysql' ),
			'finished'   => '',
			'poll_count' => 0,
			'imported'   => false,
			'message'    => ! empty( $job['estimated_wait_hint'] )
				? sprintf(
					/* translators: %s: wait hint */
					__( 'Scan job accepted (%s).', 'universal-consent-privacy-framework' ),
					sanitize_text_field( (string) $job['estimated_wait_hint'] )
				)
				: __( 'Scan job started. Progress is saved on this site — you can leave this page.', 'universal-consent-privacy-framework' ),
			'progress'   => $this->normalize_progress( isset( $job['progress'] ) ? $job['progress'] : array() ),
			'url'        => ! empty( $context['url'] ) ? esc_url_raw( (string) $context['url'] ) : home_url( '/' ),
			'paths'      => ! empty( $context['paths'] ) && is_array( $context['paths'] )
				? array_values( array_map( 'strval', $context['paths'] ) )
				: array( '/' ),
			'depth'      => ! empty( $context['depth'] ) ? sanitize_key( (string) $context['depth'] ) : 'standard',
			'position'   => isset( $job['position'] ) ? (int) $job['position'] : 0,
		);

		if ( empty( $status['progress']['message'] ) ) {
			$status['progress']['message'] = $status['message'];
			$status['progress']['phase']   = $state;
		}

		$this->set( $status );
		$this->schedule_poll( $job_id );

		return $status;
	}

	/**
	 * Schedule next cron poll (replaces prior pending polls).
	 *
	 * @param string $job_id Job id.
	 * @param int    $delay  Seconds.
	 */
	public function schedule_poll( $job_id, $delay = null ) {
		$job_id = sanitize_text_field( (string) $job_id );
		if ( ! $job_id ) {
			return;
		}
		$delay = null === $delay ? self::POLL_DELAY : max( 5, (int) $delay );
		wp_clear_scheduled_hook( self::HOOK_POLL );
		wp_schedule_single_event( time() + $delay, self::HOOK_POLL, array( $job_id ) );
	}

	/**
	 * Update progress snapshot from a remote job payload (browser or cron poll).
	 *
	 * @param array $job Remote job.
	 * @return array Updated status.
	 */
	public function sync_from_job( array $job ) {
		$status = $this->get();
		$job_id = isset( $job['id'] ) ? sanitize_text_field( (string) $job['id'] ) : '';
		if ( $job_id && ( empty( $status['job_id'] ) || $status['job_id'] === $job_id ) ) {
			$status['job_id'] = $job_id;
		}
		if ( empty( $status['job_id'] ) ) {
			return $status;
		}

		$job_state = isset( $job['status'] ) ? sanitize_key( (string) $job['status'] ) : '';
		if ( in_array( $job_state, array( 'queued', 'running', 'pending', 'processing', 'cancelling' ), true ) ) {
			$status['state'] = ( 'cancelling' === $job_state ) ? 'cancelling' : ( ( 'queued' === $job_state ) ? 'queued' : 'running' );
		}

		if ( ! empty( $job['progress'] ) ) {
			$status['progress'] = $this->normalize_progress( $job['progress'] );
			if ( ! empty( $status['progress']['message'] ) ) {
				$status['message'] = $status['progress']['message'];
			}
		}
		if ( ! empty( $job['position'] ) ) {
			$status['position'] = (int) $job['position'];
		}
		if ( empty( $status['started'] ) ) {
			$status['started'] = current_time( 'mysql' );
		}

		$this->set( $status );
		return $status;
	}

	/**
	 * Mark job finished after successful import (browser poll or cron).
	 *
	 * @param string $job_id  Job id.
	 * @param string $state   completed|cancelled|failed.
	 * @param string $message Message.
	 * @param array  $extra   Extra fields.
	 */
	public function mark_finished( $job_id, $state, $message = '', array $extra = array() ) {
		$status = $this->get();
		$job_id = sanitize_text_field( (string) $job_id );
		if ( $job_id && ! empty( $status['job_id'] ) && $status['job_id'] !== $job_id ) {
			return;
		}
		if ( $job_id ) {
			$status['job_id'] = $job_id;
		}
		$status['state']    = sanitize_key( (string) $state );
		$status['finished'] = current_time( 'mysql' );
		$status['message']  = $message ? sanitize_text_field( $message ) : (string) ( $status['message'] ?? '' );
		$status['imported'] = ! empty( $extra['imported'] ) || ! empty( $status['imported'] );
		if ( isset( $extra['partial'] ) ) {
			$status['partial'] = (bool) $extra['partial'];
		}
		if ( ! empty( $extra['inventory'] ) && is_array( $extra['inventory'] ) ) {
			$status['inventory'] = $extra['inventory'];
		}
		if ( ! empty( $extra['progress'] ) ) {
			$status['progress'] = $this->normalize_progress( $extra['progress'] );
		}
		$this->set( $status );
		wp_clear_scheduled_hook( self::HOOK_POLL );
	}

	/**
	 * Cancel helper: set cancelling and keep job_id until remote resolves.
	 *
	 * @param string $job_id Job id.
	 */
	public function mark_cancelling( $job_id = '' ) {
		$status = $this->get();
		if ( $job_id ) {
			$status['job_id'] = sanitize_text_field( (string) $job_id );
		}
		if ( empty( $status['job_id'] ) ) {
			return;
		}
		$status['state']   = 'cancelling';
		$status['message'] = __( 'Stop requested — cancelling remote Playwright job…', 'universal-consent-privacy-framework' );
		if ( empty( $status['progress'] ) || ! is_array( $status['progress'] ) ) {
			$status['progress'] = $this->normalize_progress( array() );
		}
		$status['progress']['phase']   = 'cancelling';
		$status['progress']['message'] = $status['message'];
		$this->set( $status );
	}

	/**
	 * Cron: poll remote job until complete, persist progress, import report.
	 *
	 * @param string $job_id Job id.
	 */
	public function run_poll( $job_id = '' ) {
		$job_id = sanitize_text_field( (string) $job_id );
		$status = $this->get();
		if ( ! $job_id && ! empty( $status['job_id'] ) ) {
			$job_id = sanitize_text_field( (string) $status['job_id'] );
		}
		if ( ! $job_id ) {
			return;
		}

		// Browser poll already finished this job.
		if ( ! empty( $status['imported'] ) && ! empty( $status['job_id'] ) && $status['job_id'] === $job_id
			&& in_array( isset( $status['state'] ) ? $status['state'] : '', array( 'completed', 'cancelled', 'failed' ), true ) ) {
			wp_clear_scheduled_hook( self::HOOK_POLL );
			return;
		}

		$poll_count           = isset( $status['poll_count'] ) ? (int) $status['poll_count'] + 1 : 1;
		$status['poll_count'] = $poll_count;
		if ( $this->is_active( $status ) || empty( $status['state'] ) ) {
			$status['state'] = 'polling';
		}
		$this->set( $status );

		$job = Privacy_Scan_Importer::get_remote_scan( $job_id );
		if ( is_wp_error( $job ) ) {
			$err_data = $job->get_error_data();
			$code     = is_array( $err_data ) && isset( $err_data['status'] ) ? (int) $err_data['status'] : 0;
			if ( in_array( $code, array( 502, 503, 504 ), true ) && $poll_count < self::MAX_POLLS ) {
				$status['message'] = $job->get_error_message();
				$this->set( $status );
				$this->schedule_poll( $job_id );
				return;
			}
			$this->mark_finished(
				$job_id,
				'failed',
				$job->get_error_message()
			);
			return;
		}

		$this->sync_from_job( $job );
		$status    = $this->get();
		$job_state = isset( $job['status'] ) ? sanitize_key( (string) $job['status'] ) : '';

		$still_running = in_array( $job_state, array( 'queued', 'running', 'pending', 'processing', 'cancelling' ), true )
			|| ( empty( $job['report'] ) && ! in_array( $job_state, array( 'completed', 'failed', 'cancelled' ), true ) );

		if ( $still_running ) {
			if ( $poll_count >= self::MAX_POLLS ) {
				$this->mark_finished(
					$job_id,
					'failed',
					__( 'Interactive scan timed out waiting for the remote scanner (including queue wait).', 'universal-consent-privacy-framework' )
				);
				return;
			}
			$status['state'] = ( 'cancelling' === $job_state ) ? 'cancelling' : ( ( 'queued' === $job_state ) ? 'queued' : 'running' );
			$this->set( $status );
			$this->schedule_poll( $job_id );
			return;
		}

		if ( 'failed' === $job_state || ( ! empty( $job['error'] ) && empty( $job['report'] ) ) ) {
			$msg = ! empty( $job['error'] ) ? (string) $job['error'] : __( 'Remote scan failed.', 'universal-consent-privacy-framework' );
			$this->mark_finished( $job_id, 'failed', $msg, array( 'progress' => isset( $job['progress'] ) ? $job['progress'] : array() ) );
			return;
		}

		$report = isset( $job['report'] ) && is_array( $job['report'] ) ? $job['report'] : null;
		if ( ! $report ) {
			$this->mark_finished(
				$job_id,
				'failed',
				__( 'Remote scan completed without a report.', 'universal-consent-privacy-framework' )
			);
			return;
		}

		// Avoid double-import if browser already imported.
		$status = $this->get();
		if ( ! empty( $status['imported'] ) ) {
			$this->mark_finished(
				$job_id,
				( 'cancelled' === $job_state ) ? 'cancelled' : 'completed',
				isset( $status['message'] ) ? (string) $status['message'] : __( 'Scan imported.', 'universal-consent-privacy-framework' ),
				array(
					'imported' => true,
					'progress' => isset( $job['progress'] ) ? $job['progress'] : array(),
				)
			);
			return;
		}

		$previous = Cookie_Scanner::instance()->get_last_scan();
		$imported = Privacy_Scan_Importer::import_report( $report );
		if ( is_wp_error( $imported ) ) {
			$this->mark_finished( $job_id, 'failed', $imported->get_error_message() );
			return;
		}

		if ( Settings::get( 'scheduled_scan_auto_apply', true ) ) {
			Privacy_Scan_Importer::apply_safe_updates( $imported, is_array( $previous ) ? $previous : array() );
		}

		$final_state = ( 'cancelled' === $job_state ) ? 'cancelled' : 'completed';
		$partial     = ! empty( $job['report']['partial'] ) || 'cancelled' === $job_state;
		$message     = $partial
			? __( 'Scan stopped. Partial Playwright results were imported.', 'universal-consent-privacy-framework' )
			: __( 'Playwright scan finished. Results were imported on this site.', 'universal-consent-privacy-framework' );

		$this->mark_finished(
			$job_id,
			$final_state,
			$message,
			array(
				'imported'  => true,
				'partial'   => $partial,
				'progress'  => isset( $job['progress'] ) ? $job['progress'] : array(),
				'inventory' => array(
					'cookies'         => isset( $imported['cookies'] ) ? count( $imported['cookies'] ) : 0,
					'unknown_cookies' => isset( $imported['unknown_cookies'] ) ? count( $imported['unknown_cookies'] ) : 0,
					'results'         => isset( $imported['results'] ) ? count( $imported['results'] ) : 0,
				),
			)
		);
	}

	/**
	 * Public payload for GET /scan/active (strip nothing sensitive — admin only).
	 *
	 * @return array
	 */
	public function get_for_rest() {
		$status = $this->get();
		if ( ! $status ) {
			return array(
				'active' => false,
				'job'    => null,
			);
		}

		// Drop stale finished snapshots.
		if ( ! $this->is_active( $status ) && ! empty( $status['finished'] ) ) {
			$finished_ts = mysql2date( 'U', (string) $status['finished'], false );
			if ( ! $finished_ts ) {
				$finished_ts = strtotime( (string) $status['finished'] );
			}
			if ( $finished_ts && ( time() - (int) $finished_ts ) > self::FINISHED_TTL ) {
				$this->clear();
				return array(
					'active' => false,
					'job'    => null,
				);
			}
		}

		return array(
			'active' => $this->is_active( $status ),
			'job'    => $status,
		);
	}
}
