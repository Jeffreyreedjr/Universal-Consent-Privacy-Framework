<?php
/**
 * Privacy tools — exporter, eraser, DSAR.
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Privacy tools integration.
 */
class Privacy_Tools {

	/**
	 * Instance.
	 *
	 * @var Privacy_Tools|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Privacy_Tools
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
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_action( 'admin_init', array( $this, 'register_policy_content' ) );
	}

	/**
	 * Register exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public function register_exporter( $exporters ) {
		$exporters['universal-consent-privacy-framework'] = array(
			'exporter_friendly_name' => __( 'Cookie Consent Records', 'universal-consent-privacy-framework' ),
			'callback'               => array( $this, 'export_consent_data' ),
		);
		return $exporters;
	}

	/**
	 * Register eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public function register_eraser( $erasers ) {
		$erasers['universal-consent-privacy-framework'] = array(
			'eraser_friendly_name' => __( 'Cookie Consent Records', 'universal-consent-privacy-framework' ),
			'callback'             => array( $this, 'erase_consent_data' ),
		);
		return $erasers;
	}

	/**
	 * Export consent logs for email.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public function export_consent_data( $email, $page = 1 ) {
		global $wpdb;

		$user    = get_user_by( 'email', $email );
		$user_id = $user ? $user->ID : 0;
		$limit   = 100;
		$offset  = ( max( 1, (int) $page ) - 1 ) * $limit;
		$table   = ucpf_table( 'consent_logs' );
		$items   = array();

		$rows = array();
		if ( $user_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
					$user_id,
					$limit,
					$offset
				),
				ARRAY_A
			);
		}
		foreach ( (array) $rows as $row ) {
			$items[] = array(
				'group_id'    => 'cookie-consent',
				'group_label' => __( 'Cookie Consent', 'universal-consent-privacy-framework' ),
				'item_id'     => 'consent-' . $row['id'],
				'data'        => array(
					array( 'name' => __( 'Date', 'universal-consent-privacy-framework' ), 'value' => $row['created_at'] ),
					array( 'name' => __( 'Action', 'universal-consent-privacy-framework' ), 'value' => $row['action'] ),
					array( 'name' => __( 'Categories', 'universal-consent-privacy-framework' ), 'value' => $row['categories'] ),
					array( 'name' => __( 'Policy Version', 'universal-consent-privacy-framework' ), 'value' => $row['policy_version'] ),
				),
			);
		}

		return array(
			'data' => $items,
			'done' => empty( $rows ) || count( $rows ) < $limit,
		);
	}

	/**
	 * Erase consent data.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public function erase_consent_data( $email, $page = 1 ) {
		$retention = (int) Settings::get( 'legal_retention_days' );
		$removed   = false;
		$retained  = false;
		$messages  = array();

		if ( $retention > 0 ) {
			$count = Audit_Log::instance()->anonymize_by_email( $email );
			$retained = $count > 0;
			if ( $retained ) {
				$messages[] = __( 'Some consent audit records were anonymized but retained for legal compliance.', 'universal-consent-privacy-framework' );
			}
		} else {
			$count   = Audit_Log::instance()->delete_by_email( $email );
			$removed = $count > 0;
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * Handle front-end data request.
	 *
	 * @param array $body Form data.
	 * @return true|\WP_Error|array
	 */
	public function handle_data_request( array $body ) {
		$email = isset( $body['email'] ) ? sanitize_email( $body['email'] ) : '';
		$type  = isset( $body['request_type'] ) ? sanitize_key( $body['request_type'] ) : '';

		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'ucpf_invalid_email', __( 'Valid email is required.', 'universal-consent-privacy-framework' ), array( 'status' => 400 ) );
		}

		$valid_types = array( 'access', 'deletion', 'correction', 'withdraw', 'do_not_sell' );
		if ( ! in_array( $type, $valid_types, true ) ) {
			return new \WP_Error( 'ucpf_invalid_type', __( 'Invalid request type.', 'universal-consent-privacy-framework' ), array( 'status' => 400 ) );
		}

		$scope = isset( $body['scope'] ) ? sanitize_key( $body['scope'] ) : 'site';
		if ( ! in_array( $scope, array( 'site', 'controller', 'selected' ), true ) ) {
			$scope = 'site';
		}
		$global_mode = ! empty( $body['global_privacy_mode'] );

		global $wpdb;

		$table     = ucpf_table( 'data_requests' );
		$email_hmac = Privacy_Identity::hmac_email( $email );
		// Keep legacy column populated for older rows; prefer HMAC in meta.
		$hash = $email_hmac ? $email_hmac : hash( 'sha256', $email . wp_salt() );

		$meta = array(
			'message'            => isset( $body['message'] ) ? sanitize_textarea_field( $body['message'] ) : '',
			'scope'              => $scope,
			'global_privacy_mode'=> (bool) $global_mode,
			'identity_hmac'      => $email_hmac,
			'controller_id'      => sanitize_key( (string) Settings::get( 'privacy_controller_id', '' ) ),
			'policy_version'     => Settings::get( 'policy_version' ),
			'site_host'          => wp_parse_url( home_url( '/' ), PHP_URL_HOST ),
			'gpc_at_submit'      => Privacy_State::gpc_signal_present(),
			'vendor_status'      => array(),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'request_type' => $type,
				'email_hash'   => $hash,
				'status'       => 'pending',
				'meta'         => wp_json_encode( $meta ),
				'created_at'   => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
		$request_row_id = (int) $wpdb->insert_id;

		$wp_action = in_array( $type, array( 'access', 'correction' ), true ) ? 'export_personal_data' : 'remove_personal_data';
		if ( in_array( $type, array( 'access', 'deletion' ), true ) && function_exists( 'wp_create_user_request' ) ) {
			$request_id = wp_create_user_request( $email, $wp_action );
			if ( ! is_wp_error( $request_id ) ) {
				wp_send_user_request( $request_id );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->update(
					$table,
					array( 'user_request_id' => $request_id, 'status' => 'sent' ),
					array( 'id' => $request_row_id ),
					array( '%d', '%s' ),
					array( '%d' )
				);
			}
		}

		if ( 'withdraw' === $type ) {
			Consent_Manager::instance()->withdraw_consent();
		}

		$local_enforced = false;
		if ( 'do_not_sell' === $type ) {
			$opt_sale     = ! isset( $body['opt_out_sale'] ) || ! empty( $body['opt_out_sale'] );
			$opt_share    = ! isset( $body['opt_out_sharing'] ) || ! empty( $body['opt_out_sharing'] );
			$opt_targeted = ! isset( $body['opt_out_targeted'] ) || ! empty( $body['opt_out_targeted'] );
			$limit_sens   = ! empty( $body['limit_sensitive'] );
			$prefs        = array(
				'sale'                 => $opt_sale ? false : true,
				'sharing'              => $opt_share ? false : true,
				'targeted_advertising' => $opt_targeted ? false : true,
				'profiling'            => ( $opt_sale || $opt_share || $opt_targeted ) ? false : true,
				// Classic DNS does not force full analytics off unless global mode or limit sensitive + marketing.
				'nonessential_tracking'=> ( $global_mode || $limit_sens ) ? false : true,
				'scope'                => $scope,
				'limit_sensitive'      => (bool) $limit_sens,
			);
			Privacy_State::instance()->set_dns_cookie( $prefs );
			// Also withdraw optional consent so cookie + privacy state agree.
			Consent_Manager::instance()->withdraw_consent();
			$local_enforced = true;

			$deny = array();
			if ( $opt_sale ) {
				$deny[] = 'sale';
			}
			if ( $opt_share ) {
				$deny[] = 'sharing';
			}
			if ( $opt_targeted ) {
				$deny[] = 'targeted_advertising';
			}
			if ( $opt_sale || $opt_share || $opt_targeted ) {
				$deny[] = 'profiling';
			}
			if ( $global_mode || $limit_sens ) {
				$deny[] = 'nonessential_tracking';
			}

			$record = array(
				'id'            => $request_row_id,
				'request_type'  => $type,
				'scope'         => $scope,
				'identity_hmac' => $email_hmac,
				'controller_id' => $meta['controller_id'],
				'policy_version'=> $meta['policy_version'],
				'deny'          => $deny,
				'limit_sensitive' => $limit_sens,
			);

			$vendor_status         = Vendor_Suppression::dispatch( $record );
			$meta['vendor_status'] = $vendor_status;
			$meta['local_enforced']= true;
			$meta['limit_sensitive'] = $limit_sens;
			$meta['deny']          = $deny;
			$meta['completed_at']  = current_time( 'mysql', true );
			$meta['sla_due_at']    = gmdate( 'Y-m-d H:i:s', time() + ( 45 * DAY_IN_SECONDS ) );
			$meta['processor_checklist'] = Rights_Inbox::instance()->default_checklist( 'do_not_sell' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->update(
				$table,
				array(
					'status' => 'completed',
					'meta'   => wp_json_encode( $meta ),
				),
				array( 'id' => $request_row_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);

			/**
			 * Audit: privacy request completed locally.
			 *
			 * @param array $meta   Meta.
			 * @param array $record Record.
			 */
			do_action( 'ucpf_privacy_request_audited', $meta, $record );

			// Optional forward to central agency API (never default).
			if ( Privacy_Preference_Client::is_configured() && in_array( $scope, array( 'controller', 'selected' ), true ) ) {
				do_action( 'ucpf_privacy_forward_central', $record, $body );
			}
		}

		Audit_Log::instance()->log(
			'do_not_sell' === $type ? 'privacy_do_not_sell' : 'data_request_' . $type,
			array(
				'uuid'           => '',
				'version'        => Settings::get( 'consent_version' ),
				'policy_version' => Settings::get( 'policy_version' ),
				'state'          => $type,
				'categories'     => array(),
				'services'       => array(),
				'timestamp'      => time(),
				'expires'        => 0,
			)
		);

		return array(
			'success'        => true,
			'local_enforced' => $local_enforced,
			'scope'          => $scope,
			'request_id'     => $request_row_id,
		);
	}

	/**
	 * Suggest privacy policy content.
	 */
	public function register_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p class="privacy-policy-tutorial">' .
			esc_html__( 'This text is a suggestion for site administrators. Review with legal counsel before publishing.', 'universal-consent-privacy-framework' ) .
			'</p>' .
			'<p>' . esc_html__(
				'This site uses the Universal Consent & Privacy Framework plugin. When you interact with the cookie banner, we may log your consent choices, date and time, a hashed IP address, browser type, and the version of our cookie policy you accepted. This data is stored to help demonstrate privacy compliance.',
				'universal-consent-privacy-framework'
			) . '</p>';

		wp_add_privacy_policy_content(
			__( 'Universal Consent & Privacy Framework', 'universal-consent-privacy-framework' ),
			wp_kses_post( wpautop( $content, false ) )
		);
	}
}
