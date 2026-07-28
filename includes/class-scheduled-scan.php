<?php
/**
 * Per-site scheduled Deep privacy scans (WP-Cron).
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Schedules remote Playwright scans, polls to completion, safe-applies, emails on review.
 */
class Scheduled_Scan {

	const HOOK_START = 'ucpf_scheduled_scan_start';
	const HOOK_POLL  = 'ucpf_scheduled_scan_poll';
	/** ~90 × 60s ≈ 90 minutes — covers Deep + shared-scanner queue wait. */
	const MAX_POLLS  = 90;
	const POLL_DELAY = 60;
	const MAX_START_RETRIES = 5;

	/**
	 * @var Scheduled_Scan|null
	 */
	private static $instance = null;

	/**
	 * @return Scheduled_Scan
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire hooks.
	 */
	public function init() {
		add_filter( 'cron_schedules', array( $this, 'register_schedules' ) );
		add_action( self::HOOK_START, array( $this, 'run_start' ) );
		add_action( self::HOOK_POLL, array( $this, 'run_poll' ) );
		add_action( 'updated_option', array( $this, 'maybe_resync_on_settings' ), 20, 3 );
		$this->ensure_schedule();
	}

	/**
	 * Custom WP-Cron intervals.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public function register_schedules( $schedules ) {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once Weekly', 'universal-consent-privacy-framework' ),
			);
		}
		$schedules['ucpf_monthly'] = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( 'Once Monthly (UCPF)', 'universal-consent-privacy-framework' ),
		);
		return $schedules;
	}

	/**
	 * Map setting interval → cron schedule key.
	 *
	 * @return string
	 */
	public function cron_recurrence() {
		$interval = Settings::get( 'scheduled_scan_interval', 'monthly' );
		return ( 'weekly' === $interval ) ? 'weekly' : 'ucpf_monthly';
	}

	/**
	 * Ensure recurring start event matches settings.
	 */
	public function ensure_schedule() {
		$enabled = (bool) Settings::get( 'scheduled_scan_enabled', false );
		if ( ! $enabled ) {
			$this->clear_schedule();
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK_START ) ) {
			wp_schedule_event( time() + $this->stagger_offset_seconds(), $this->cron_recurrence(), self::HOOK_START );
		}
	}

	/**
	 * Spread fleet schedules so 300+ sites do not all enqueue at :00.
	 *
	 * @return int Seconds (1h–7h) derived from site URL.
	 */
	public function stagger_offset_seconds() {
		$host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$sum  = 0;
		$len  = strlen( $host );
		for ( $i = 0; $i < $len; $i++ ) {
			$sum += ord( $host[ $i ] );
		}
		return HOUR_IN_SECONDS + ( $sum % ( 6 * HOUR_IN_SECONDS ) );
	}

	/**
	 * Clear start + pending poll events.
	 */
	public function clear_schedule() {
		wp_clear_scheduled_hook( self::HOOK_START );
		wp_clear_scheduled_hook( self::HOOK_POLL );
	}

	/**
	 * Reschedule after Advanced settings save.
	 *
	 * @param string $option Option name.
	 * @param mixed  $old    Old value.
	 * @param mixed  $new    New value.
	 */
	public function maybe_resync_on_settings( $option, $old, $new ) {
		if ( Settings::OPTION_KEY !== $option ) {
			return;
		}
		$this->clear_schedule();
		$enabled = is_array( $new ) && ! empty( $new['scheduled_scan_enabled'] );
		if ( $enabled ) {
			$interval = ( is_array( $new ) && ! empty( $new['scheduled_scan_interval'] ) && 'weekly' === $new['scheduled_scan_interval'] )
				? 'weekly'
				: 'ucpf_monthly';
			wp_schedule_event( time() + $this->stagger_offset_seconds(), $interval, self::HOOK_START );
		}
	}

	/**
	 * Parse paths CSV from settings.
	 *
	 * @return string[]
	 */
	public function paths() {
		$raw   = (string) Settings::get( 'scheduled_scan_paths', '/' );
		$parts = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		$out   = array();
		foreach ( $parts as $p ) {
			if ( '' === $p ) {
				continue;
			}
			if ( 0 !== strpos( $p, '/' ) ) {
				$p = '/' . $p;
			}
			$out[] = $p;
		}
		return $out ? array_values( array_unique( $out ) ) : array( '/' );
	}

	/**
	 * Notify email list.
	 *
	 * @return string[]
	 */
	public function notify_emails() {
		$raw = trim( (string) Settings::get( 'scheduled_scan_notify_email', '' ) );
		if ( '' === $raw ) {
			$raw = (string) get_option( 'admin_email' );
		}
		$emails = array_filter(
			array_map(
				'sanitize_email',
				array_map( 'trim', explode( ',', $raw ) )
			)
		);
		return array_values( $emails );
	}

	/**
	 * Persist last run status.
	 *
	 * @param array $status Status.
	 */
	public function set_status( array $status ) {
		Settings::update( array( 'scheduled_scan_last_status' => $status ) );
	}

	/**
	 * Manual or cron: start a remote scan job.
	 *
	 * @param bool $manual Manual trigger.
	 * @return array|\WP_Error
	 */
	public function run_start( $manual = false ) {
		if ( ! Settings::get( 'scheduled_scan_enabled', false ) && ! $manual ) {
			return new \WP_Error( 'ucpf_scheduled_disabled', __( 'Scheduled scan is disabled.', 'universal-consent-privacy-framework' ) );
		}

		$key = Privacy_Scan_Importer::api_key();
		$api = Privacy_Scan_Importer::api_base();
		if ( ! $api || ! $key ) {
			$err = __( 'Scanner API URL and key are required for scheduled scans.', 'universal-consent-privacy-framework' );
			$this->set_status(
				array(
					'state'    => 'failed',
					'started'  => current_time( 'mysql' ),
					'finished' => current_time( 'mysql' ),
					'message'  => $err,
					'manual'   => (bool) $manual,
				)
			);
			$this->send_failure_email( $err );
			return new \WP_Error( 'ucpf_scanner_unconfigured', $err );
		}

		$status = Settings::get( 'scheduled_scan_last_status', array() );
		if ( ! is_array( $status ) ) {
			$status = array();
		}

		$result = Privacy_Scan_Importer::start_remote_scan( home_url( '/' ), $this->paths() );
		if ( is_wp_error( $result ) ) {
			$err_data    = $result->get_error_data();
			$status_code = is_array( $err_data ) && isset( $err_data['status'] ) ? (int) $err_data['status'] : 0;
			$retry_after = is_array( $err_data ) && isset( $err_data['retry_after'] ) ? (int) $err_data['retry_after'] : 0;
			$retries     = isset( $status['start_retries'] ) ? (int) $status['start_retries'] : 0;
			$busy        = in_array( $status_code, array( 429, 503 ), true )
				|| false !== stripos( $result->get_error_message(), 'busy' )
				|| false !== stripos( $result->get_error_message(), 'queue' );

			if ( $busy && $retries < self::MAX_START_RETRIES && ! $manual ) {
				$delay = $retry_after > 0 ? min( 900, $retry_after ) : min( 900, 60 * ( $retries + 1 ) );
				$this->set_status(
					array(
						'state'         => 'waiting',
						'started'       => current_time( 'mysql' ),
						'start_retries' => $retries + 1,
						'manual'        => false,
						'message'       => sprintf(
							/* translators: 1: attempt number, 2: seconds */
							__( 'Scanner busy/queue full — retry %1$d in %2$d seconds (other sites left alone).', 'universal-consent-privacy-framework' ),
							$retries + 1,
							$delay
						),
					)
				);
				wp_schedule_single_event( time() + $delay, self::HOOK_START );
				return $result;
			}

			$this->set_status(
				array(
					'state'    => 'failed',
					'started'  => current_time( 'mysql' ),
					'finished' => current_time( 'mysql' ),
					'message'  => $result->get_error_message(),
					'manual'   => (bool) $manual,
				)
			);
			$this->send_failure_email( $result->get_error_message() );
			return $result;
		}

		$job_id = isset( $result['id'] ) ? sanitize_text_field( (string) $result['id'] ) : '';
		if ( ! $job_id ) {
			$err = __( 'Scanner did not return a job id.', 'universal-consent-privacy-framework' );
			$this->set_status(
				array(
					'state'    => 'failed',
					'started'  => current_time( 'mysql' ),
					'finished' => current_time( 'mysql' ),
					'message'  => $err,
					'manual'   => (bool) $manual,
				)
			);
			$this->send_failure_email( $err );
			return new \WP_Error( 'ucpf_scanner_no_id', $err );
		}

		$this->set_status(
			array(
				'state'      => isset( $result['status'] ) && 'queued' === $result['status'] ? 'queued' : 'running',
				'job_id'     => $job_id,
				'started'    => current_time( 'mysql' ),
				'poll_count' => 0,
				'position'   => isset( $result['position'] ) ? (int) $result['position'] : 0,
				'manual'     => (bool) $manual,
				'message'    => ! empty( $result['estimated_wait_hint'] )
					? sprintf(
						/* translators: %s: wait hint */
						__( 'Scan job accepted (%s).', 'universal-consent-privacy-framework' ),
						sanitize_text_field( (string) $result['estimated_wait_hint'] )
					)
					: __( 'Scan job started.', 'universal-consent-privacy-framework' ),
			)
		);

		wp_clear_scheduled_hook( self::HOOK_POLL );
		wp_schedule_single_event( time() + self::POLL_DELAY, self::HOOK_POLL, array( $job_id ) );

		return array(
			'success' => true,
			'job_id'  => $job_id,
		);
	}

	/**
	 * Poll remote job until complete or give up.
	 *
	 * @param string $job_id Job id.
	 */
	public function run_poll( $job_id = '' ) {
		$job_id = sanitize_text_field( (string) $job_id );
		$status = Settings::get( 'scheduled_scan_last_status', array() );
		if ( ! is_array( $status ) ) {
			$status = array();
		}
		if ( ! $job_id && ! empty( $status['job_id'] ) ) {
			$job_id = sanitize_text_field( (string) $status['job_id'] );
		}
		if ( ! $job_id ) {
			return;
		}

		$poll_count = isset( $status['poll_count'] ) ? (int) $status['poll_count'] + 1 : 1;
		$status['poll_count'] = $poll_count;
		$status['state']      = 'polling';
		$this->set_status( $status );

		$job = Privacy_Scan_Importer::get_remote_scan( $job_id );
		if ( is_wp_error( $job ) ) {
			$err_data = $job->get_error_data();
			$code     = is_array( $err_data ) && isset( $err_data['status'] ) ? (int) $err_data['status'] : 0;
			// Transient scanner blips — keep polling instead of failing the fleet job.
			if ( in_array( $code, array( 502, 503, 504 ), true ) && $poll_count < self::MAX_POLLS ) {
				$status['message'] = $job->get_error_message();
				$this->set_status( $status );
				wp_schedule_single_event( time() + self::POLL_DELAY, self::HOOK_POLL, array( $job_id ) );
				return;
			}
			$status['state']    = 'failed';
			$status['finished'] = current_time( 'mysql' );
			$status['message']  = $job->get_error_message();
			$this->set_status( $status );
			$this->send_failure_email( $job->get_error_message() );
			return;
		}

		$job_state = isset( $job['status'] ) ? sanitize_key( (string) $job['status'] ) : '';
		if ( ! empty( $job['position'] ) ) {
			$status['position'] = (int) $job['position'];
			$status['message']  = sprintf(
				/* translators: %d: queue position */
				__( 'Queued on scanner — position %d.', 'universal-consent-privacy-framework' ),
				(int) $job['position']
			);
			$this->set_status( $status );
		}
		if ( in_array( $job_state, array( 'queued', 'running', 'pending', 'processing', 'cancelling' ), true ) || ( empty( $job['report'] ) && 'completed' !== $job_state && 'failed' !== $job_state && 'cancelled' !== $job_state ) ) {
			if ( $poll_count >= self::MAX_POLLS ) {
				$msg = __( 'Scheduled scan timed out waiting for the remote scanner (including queue wait).', 'universal-consent-privacy-framework' );
				$status['state']    = 'failed';
				$status['finished'] = current_time( 'mysql' );
				$status['message']  = $msg;
				$this->set_status( $status );
				$this->send_failure_email( $msg );
				return;
			}
			wp_schedule_single_event( time() + self::POLL_DELAY, self::HOOK_POLL, array( $job_id ) );
			return;
		}

		if ( 'failed' === $job_state || ! empty( $job['error'] ) ) {
			$msg = ! empty( $job['error'] ) ? (string) $job['error'] : __( 'Remote scan failed.', 'universal-consent-privacy-framework' );
			$status['state']    = 'failed';
			$status['finished'] = current_time( 'mysql' );
			$status['message']  = $msg;
			$this->set_status( $status );
			$this->send_failure_email( $msg );
			return;
		}

		$report = isset( $job['report'] ) && is_array( $job['report'] ) ? $job['report'] : null;
		if ( ! $report ) {
			$msg = __( 'Remote scan completed without a report.', 'universal-consent-privacy-framework' );
			$status['state']    = 'failed';
			$status['finished'] = current_time( 'mysql' );
			$status['message']  = $msg;
			$this->set_status( $status );
			$this->send_failure_email( $msg );
			return;
		}

		$previous = Cookie_Scanner::instance()->get_last_scan();
		$imported = Privacy_Scan_Importer::import_report( $report );
		if ( is_wp_error( $imported ) ) {
			$status['state']    = 'failed';
			$status['finished'] = current_time( 'mysql' );
			$status['message']  = $imported->get_error_message();
			$this->set_status( $status );
			$this->send_failure_email( $imported->get_error_message() );
			return;
		}

		$review = array();
		if ( Settings::get( 'scheduled_scan_auto_apply', true ) ) {
			$review = Privacy_Scan_Importer::apply_safe_updates( $imported, is_array( $previous ) ? $previous : array() );
		} else {
			$review = Privacy_Scan_Importer::diff_scan_for_review( $imported, is_array( $previous ) ? $previous : array() );
		}

		$needs = ! empty( $review['needs_review'] );
		$status['state']            = $needs ? 'needs_review' : 'ok';
		$status['finished']         = current_time( 'mysql' );
		$status['unknown_cookies']  = isset( $review['unknown_count'] ) ? (int) $review['unknown_count'] : 0;
		$status['new_leaks']        = isset( $review['new_leak_count'] ) ? (int) $review['new_leak_count'] : 0;
		$status['services_enabled'] = isset( $review['services_enabled'] ) ? (int) $review['services_enabled'] : 0;
		$status['message']          = $needs
			? __( 'Scan imported — review required.', 'universal-consent-privacy-framework' )
			: __( 'Scan imported — no review items.', 'universal-consent-privacy-framework' );
		$this->set_status( $status );

		if ( $needs ) {
			$this->send_review_email( $review );
		}
	}

	/**
	 * Email on hard failure.
	 *
	 * @param string $message Message.
	 */
	public function send_failure_email( $message ) {
		$emails = $this->notify_emails();
		if ( ! $emails ) {
			return;
		}
		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject = sprintf( '[%s] UCPF: scheduled privacy scan failed', $site );
		$body    = "A scheduled UCPF Deep privacy scan failed on {$site}.\n\n";
		$body   .= 'Error: ' . $message . "\n\n";
		$body   .= 'Scanner: ' . admin_url( 'admin.php?page=ucpf-scanner' ) . "\n";
		$body   .= 'Advanced settings: ' . admin_url( 'admin.php?page=ucpf-advanced' ) . "\n\n";
		$body   .= "Technical inventory only — not a legal compliance determination.\n";
		wp_mail( $emails, $subject, $body );
	}

	/**
	 * Email when unknowns / new leaks need human review.
	 *
	 * @param array $review Review summary.
	 */
	public function send_review_email( array $review ) {
		$emails = $this->notify_emails();
		if ( ! $emails ) {
			return;
		}
		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject = sprintf( '[%s] UCPF: privacy scan needs review', $site );
		$unknown = isset( $review['unknown_count'] ) ? (int) $review['unknown_count'] : 0;
		$leaks   = isset( $review['new_leak_count'] ) ? (int) $review['new_leak_count'] : 0;
		$body    = "A scheduled UCPF Deep privacy scan on {$site} needs human review.\n\n";
		$body   .= "Unknown cookies needing category assignment: {$unknown}\n";
		$body   .= "New consent leak signals: {$leaks}\n\n";
		if ( ! empty( $review['new_unknown_names'] ) && is_array( $review['new_unknown_names'] ) ) {
			$body .= 'New unknown names: ' . implode( ', ', array_slice( $review['new_unknown_names'], 0, 30 ) ) . "\n";
		}
		if ( ! empty( $review['new_leak_names'] ) && is_array( $review['new_leak_names'] ) ) {
			$body .= 'New leak hosts/scripts: ' . implode( ', ', array_slice( $review['new_leak_names'], 0, 30 ) ) . "\n";
		}
		$body .= "\nOpen Cookie Scanner / Cookie review:\n" . admin_url( 'admin.php?page=ucpf-scanner' ) . "\n\n";
		$body .= "Technical finding only — not a legal determination.\n";
		wp_mail( $emails, $subject, $body );
	}
}
