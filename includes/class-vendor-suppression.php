<?php
/**
 * Vendor suppression stubs — push opt-outs to CRM/ads/email (agency connectors).
 *
 * @package UCPF
 */

namespace UCPF;

defined( 'ABSPATH' ) || exit;

/**
 * Fan-out privacy opt-outs to downstream systems.
 */
class Vendor_Suppression {

	/**
	 * Dispatch suppression jobs for a privacy request.
	 *
	 * @param array $record Request record (no raw email — use hmac).
	 * @return array Status map vendor => pending|completed|skipped.
	 */
	public static function dispatch( array $record ) {
		$vendors = array(
			'google_ads'   => 'pending',
			'meta_ads'     => 'pending',
			'klaviyo'      => 'pending',
			'mailchimp'    => 'pending',
			'email_crm'    => 'pending',
			'server_gtm'   => 'pending',
			'data_export'  => 'pending',
		);

		/**
		 * Filter list of vendor suppression targets.
		 *
		 * @param array $vendors Map.
		 * @param array $record  Request.
		 */
		$vendors = apply_filters( 'ucpf_vendor_suppression_targets', $vendors, $record );

		$status = array();
		foreach ( $vendors as $vendor => $default ) {
			$vendor = sanitize_key( $vendor );
			/**
			 * Handle a single vendor suppression. Callbacks should return
			 * 'completed', 'pending', 'awaiting_review', or 'skipped'.
			 *
			 * @param string $result Default pending.
			 * @param string $vendor Vendor id.
			 * @param array  $record Request.
			 */
			$result           = apply_filters( 'ucpf_vendor_suppress', $default, $vendor, $record );
			$status[ $vendor ] = sanitize_key( (string) $result );
			do_action( 'ucpf_vendor_suppress_' . $vendor, $record, $status[ $vendor ] );
		}

		/**
		 * After all vendor suppression hooks.
		 *
		 * @param array $status Status map.
		 * @param array $record Request.
		 */
		do_action( 'ucpf_privacy_opt_out', $record, $status );

		return $status;
	}
}
