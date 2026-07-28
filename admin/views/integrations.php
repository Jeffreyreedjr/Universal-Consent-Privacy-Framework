<?php
/**
 * Integrations / tracking tags admin view.
 *
 * @package UCPF
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- included template; locals are not plugin globals.

$templates   = \UCPF\Tracking_Templates::all();
$service_ids = isset( $settings['service_ids'] ) && is_array( $settings['service_ids'] ) ? $settings['service_ids'] : array();
$option_key  = \UCPF\Settings::OPTION_KEY;
$gcm         = isset( $settings['google_consent_mode'] ) ? $settings['google_consent_mode'] : 'basic';
?>
<div class="wrap ucpf-admin">
	<h1><?php esc_html_e( 'Integrations & tracking tags', 'universal-consent-privacy-framework' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Enable each service you use on this site and enter its ID/tag. UCPF loads the official script only after the visitor consents to that category.', 'universal-consent-privacy-framework' ); ?></p>

	<form method="post" action="options.php">
		<?php settings_fields( 'ucpf_settings_group' ); ?>
		<input type="hidden" name="<?php echo esc_attr( $option_key ); ?>[_ucpf_tracking_form]" value="1" />

		<div class="ucpf-table-scroll">
		<table class="widefat striped ucpf-tracking-table">
			<thead>
				<tr>
					<th class="ucpf-col-check"><?php esc_html_e( 'Enable', 'universal-consent-privacy-framework' ); ?></th>
					<th class="ucpf-col-service"><?php esc_html_e( 'Service', 'universal-consent-privacy-framework' ); ?></th>
					<th class="ucpf-col-id"><?php esc_html_e( 'Tag / ID', 'universal-consent-privacy-framework' ); ?></th>
					<th class="ucpf-col-code"><?php esc_html_e( 'Optional custom JS', 'universal-consent-privacy-framework' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $templates as $key => $meta ) :
					$row     = isset( $service_ids[ $key ] ) && is_array( $service_ids[ $key ] ) ? $service_ids[ $key ] : array();
					$enabled = ! empty( $row['enabled'] );
					$id      = isset( $row['id'] ) ? $row['id'] : '';
					$tag_id  = isset( $row['tag_id'] ) ? $row['tag_id'] : '';
					$code    = isset( $row['code'] ) ? $row['code'] : '';
					?>
					<tr>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $option_key ); ?>[service_ids][<?php echo esc_attr( $key ); ?>][enabled]" value="1" <?php checked( $enabled ); ?> />
								<span class="screen-reader-text"><?php echo esc_html( $meta['label'] ); ?></span>
							</label>
						</td>
						<td>
							<strong><?php echo esc_html( $meta['label'] ); ?></strong>
							<br /><code><?php echo esc_html( $key ); ?></code>
							<br /><span class="description"><?php echo esc_html( $meta['category'] ); ?></span>
						</td>
						<td>
							<label class="ucpf-tracking-id-label" for="ucpf-id-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $meta['id_label'] ); ?></label>
							<input
								type="text"
								class="regular-text"
								id="ucpf-id-<?php echo esc_attr( $key ); ?>"
								name="<?php echo esc_attr( $option_key ); ?>[service_ids][<?php echo esc_attr( $key ); ?>][id]"
								value="<?php echo esc_attr( $id ); ?>"
								placeholder="<?php echo esc_attr( $meta['placeholder'] ); ?>"
								autocomplete="off"
							/>
							<p class="description"><?php echo esc_html( $meta['help'] ); ?></p>
							<?php if ( ! empty( $meta['tag_id_label'] ) ) : ?>
								<label class="ucpf-tracking-id-label" for="ucpf-tag-id-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $meta['tag_id_label'] ); ?></label>
								<input
									type="text"
									class="regular-text"
									id="ucpf-tag-id-<?php echo esc_attr( $key ); ?>"
									name="<?php echo esc_attr( $option_key ); ?>[service_ids][<?php echo esc_attr( $key ); ?>][tag_id]"
									value="<?php echo esc_attr( $tag_id ); ?>"
									placeholder="<?php echo esc_attr( isset( $meta['tag_placeholder'] ) ? $meta['tag_placeholder'] : 'GT-XXXXXXXX' ); ?>"
									autocomplete="off"
								/>
								<p class="description"><?php echo esc_html( isset( $meta['tag_help'] ) ? $meta['tag_help'] : '' ); ?></p>
							<?php endif; ?>
						</td>
						<td>
							<textarea
								class="large-text code"
								rows="3"
								name="<?php echo esc_attr( $option_key ); ?>[service_ids][<?php echo esc_attr( $key ); ?>][code]"
								placeholder="<?php echo esc_attr__( 'Optional extra JS after consent (no script tags)', 'universal-consent-privacy-framework' ); ?>"
							><?php echo esc_textarea( $code ); ?></textarea>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>

		<h2><?php esc_html_e( 'Google Consent Mode', 'universal-consent-privacy-framework' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'Mode', 'universal-consent-privacy-framework' ); ?></th>
				<td>
					<select name="<?php echo esc_attr( $option_key ); ?>[google_consent_mode]">
						<option value="off" <?php selected( $gcm, 'off' ); ?>><?php esc_html_e( 'Off', 'universal-consent-privacy-framework' ); ?></option>
						<option value="basic" <?php selected( $gcm, 'basic' ); ?>><?php esc_html_e( 'Basic (recommended)', 'universal-consent-privacy-framework' ); ?></option>
						<option value="advanced" <?php selected( $gcm, 'advanced' ); ?>><?php esc_html_e( 'Advanced (warning)', 'universal-consent-privacy-framework' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Basic defaults denied until consent — best match for strict GDPR.', 'universal-consent-privacy-framework' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Platform', 'universal-consent-privacy-framework' ); ?></th>
				<td>
					<p><?php echo class_exists( 'WooCommerce' ) ? esc_html__( 'WooCommerce detected — cart/session treated as necessary.', 'universal-consent-privacy-framework' ) : esc_html__( 'WooCommerce not active.', 'universal-consent-privacy-framework' ); ?></p>
					<p><?php echo did_action( 'elementor/loaded' ) ? esc_html__( 'Elementor detected — banner z-index compatibility enabled.', 'universal-consent-privacy-framework' ) : esc_html__( 'Elementor not detected.', 'universal-consent-privacy-framework' ); ?></p>
					<?php
					$ucpf_cf   = \UCPF\Cookie_Scanner::instance()->detect_cloudflare_proxy();
					$ucpf_scan = \UCPF\Cookie_Scanner::instance()->get_last_scan();
					$ucpf_tx   = isset( $ucpf_scan['transactional_email'] ) && is_array( $ucpf_scan['transactional_email'] ) ? $ucpf_scan['transactional_email'] : array();
					$ucpf_tx_on = ! empty( $ucpf_tx['detected'] );
					if ( ! $ucpf_tx_on && ! empty( $ucpf_scan['detected_services'] ) && is_array( $ucpf_scan['detected_services'] ) ) {
						$ucpf_tx_keys = array_merge( array( 'transactional_email', 'gravity_smtp' ), \UCPF\Cookie_Scanner::transactional_provider_keys() );
						foreach ( $ucpf_scan['detected_services'] as $ds ) {
							if ( in_array( $ds, $ucpf_tx_keys, true ) ) {
								$ucpf_tx_on = true;
								break;
							}
						}
					}
					if ( ! $ucpf_tx_on && \UCPF\Cookie_Scanner::instance()->service_has_active_plugin_public( 'transactional_email' ) ) {
						$ucpf_tx_on = true;
					}
					?>
					<p>
						<?php
						echo ! empty( $ucpf_cf['proxied'] )
							? esc_html__( '✓ Cloudflare proxy detected — treat as necessary security/CDN (disclose in privacy policy).', 'universal-consent-privacy-framework' )
							: esc_html__( 'Cloudflare proxy not detected on this request (headers / NS).', 'universal-consent-privacy-framework' );
						?>
					</p>
					<p>
						<?php
						echo $ucpf_tx_on
							? esc_html__( '✓ Transactional email (SMTP / ESP) detected — server-side delivery; disclose as a processor.', 'universal-consent-privacy-framework' )
							: esc_html__( 'Transactional email (SMTP plugin) not detected yet. Run Cookie Scanner after installing an SMTP plugin.', 'universal-consent-privacy-framework' );
						?>
					</p>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Save tracking tags', 'universal-consent-privacy-framework' ) ); ?>
	</form>
</div>
