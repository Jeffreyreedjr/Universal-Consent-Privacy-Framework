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
		$table = esc_sql( ucpf_table( 'consent_logs' ) );
		$items = array();

		$rows = array();
		if ( $user_id && '' !== $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy export.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from esc_sql( ucpf_table() ) whitelist.
					"SELECT * FROM `{$table}` WHERE user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
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
				'This site uses the Universal Consent & Privacy Framework plugin. When you interact with the cookie banner, we may log your consent choices, date and time, a consent identifier, and the version of our cookie policy you accepted. This data is stored to help demonstrate privacy compliance. Rights request forms are handled on separate pages configured by the site owner — not through this plugin’s local inbox.',
				'universal-consent-privacy-framework'
			) . '</p>';

		wp_add_privacy_policy_content(
			__( 'Universal Consent & Privacy Framework', 'universal-consent-privacy-framework' ),
			wp_kses_post( wpautop( $content, false ) )
		);
	}
}
