<?php
/**
 * Setup wizard view.
 *
 * @package UCPF
 */

defined( 'ABSPATH' ) || exit;

$step     = isset( $wizard_step ) ? (int) $wizard_step : 1;
$last_scan = isset( $last_scan ) ? $last_scan : array();
$services  = isset( $services ) ? $services : array();
$categories = \UCPF\Consent_Manager::instance()->get_categories();

$nav = array(
	'general' => array(
		'label' => __( 'General', 'universal-consent-privacy-framework' ),
		'steps' => array(
			1 => __( 'Visitors', 'universal-consent-privacy-framework' ),
			2 => __( 'Documents', 'universal-consent-privacy-framework' ),
			3 => __( 'Website information', 'universal-consent-privacy-framework' ),
			4 => __( 'Security & Consent', 'universal-consent-privacy-framework' ),
		),
	),
	'consent' => array(
		'label' => __( 'Consent', 'universal-consent-privacy-framework' ),
		'steps' => array(
			5 => __( 'Website Scan', 'universal-consent-privacy-framework' ),
			6 => __( 'Statistics', 'universal-consent-privacy-framework' ),
			7 => __( 'Services', 'universal-consent-privacy-framework' ),
			8 => __( 'Cookie review', 'universal-consent-privacy-framework' ),
		),
	),
	'documents' => array(
		'label' => __( 'Documents', 'universal-consent-privacy-framework' ),
		'steps' => array(
			9 => __( 'Generate pages', 'universal-consent-privacy-framework' ),
		),
	),
	'finish' => array(
		'label' => __( 'Finish', 'universal-consent-privacy-framework' ),
		'steps' => array(
			10 => __( 'Finish', 'universal-consent-privacy-framework' ),
		),
	),
);

$selected_services = isset( $settings['selected_services'] ) && is_array( $settings['selected_services'] ) ? $settings['selected_services'] : array();
$doc_sources       = isset( $settings['document_sources'] ) && is_array( $settings['document_sources'] ) ? $settings['document_sources'] : array();
?>
<div class="wrap ucpf-admin ucpf-wizard">
	<h1><?php esc_html_e( 'Setup Wizard', 'universal-consent-privacy-framework' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Helps support privacy compliance. Final legal review is the site owner\'s responsibility.', 'universal-consent-privacy-framework' ); ?></p>

	<div class="ucpf-wizard__layout">
		<aside class="ucpf-wizard__nav">
			<p class="ucpf-wizard__nav-title"><?php esc_html_e( 'Wizard', 'universal-consent-privacy-framework' ); ?></p>
			<?php foreach ( $nav as $section ) : ?>
				<div class="ucpf-wizard__section">
					<strong><?php echo esc_html( $section['label'] ); ?></strong>
					<ul>
						<?php foreach ( $section['steps'] as $num => $label ) :
							$num       = (int) $num;
							$is_active = $step === $num;
							$can_jump  = $num < $step || ( ! empty( $settings['wizard_completed'] ) && $num <= 10 );
							?>
							<li class="<?php echo $is_active ? 'is-active' : ( $num < $step ? 'is-complete' : '' ); ?>">
								<?php if ( $can_jump && ! $is_active ) : ?>
									<button type="submit" class="ucpf-wizard__nav-link" form="ucpf-wizard-form" formaction="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" name="wizard_goto" value="<?php echo esc_attr( $num ); ?>" formnovalidate>
										<span class="ucpf-wizard__check" aria-hidden="true"></span>
										<?php echo esc_html( $label ); ?>
									</button>
								<?php else : ?>
									<span class="ucpf-wizard__check" aria-hidden="true"></span>
									<?php echo esc_html( $label ); ?>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endforeach; ?>
		</aside>

		<main class="ucpf-wizard__panel">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="ucpf-wizard-form">
				<?php wp_nonce_field( 'ucpf_wizard' ); ?>
				<input type="hidden" name="action" value="ucpf_save_wizard" />
				<input type="hidden" name="wizard_step" value="<?php echo esc_attr( $step ); ?>" />

				<?php if ( 1 === $step ) : ?>
					<h2><?php esc_html_e( 'Visitors', 'universal-consent-privacy-framework' ); ?></h2>
					<p><?php esc_html_e( 'Choose the default privacy mode for your visitors. This sets banner and blocking defaults.', 'universal-consent-privacy-framework' ); ?></p>
					<fieldset class="ucpf-wizard__fieldset">
						<label><input type="radio" name="compliance_mode" value="strict_gdpr" <?php checked( $settings['compliance_mode'], 'strict_gdpr' ); ?> /> <?php esc_html_e( 'European Union (GDPR / ePrivacy) — strict', 'universal-consent-privacy-framework' ); ?></label>
						<label><input type="radio" name="compliance_mode" value="us_baseline" <?php checked( $settings['compliance_mode'], 'us_baseline' ); ?> /> <?php esc_html_e( 'United States privacy baseline', 'universal-consent-privacy-framework' ); ?></label>
						<label><input type="radio" name="compliance_mode" value="us_california" <?php checked( $settings['compliance_mode'], 'us_california' ); ?> /> <?php esc_html_e( 'California CPRA', 'universal-consent-privacy-framework' ); ?></label>
						<label><input type="radio" name="compliance_mode" value="us_colorado" <?php checked( $settings['compliance_mode'], 'us_colorado' ); ?> /> <?php esc_html_e( 'Colorado CPA', 'universal-consent-privacy-framework' ); ?></label>
						<label><input type="radio" name="compliance_mode" value="us_connecticut" <?php checked( $settings['compliance_mode'], 'us_connecticut' ); ?> /> <?php esc_html_e( 'Connecticut CTDPA', 'universal-consent-privacy-framework' ); ?></label>
						<label><input type="radio" name="compliance_mode" value="us_virginia" <?php checked( $settings['compliance_mode'], 'us_virginia' ); ?> /> <?php esc_html_e( 'Virginia VCDPA', 'universal-consent-privacy-framework' ); ?></label>
						<label><input type="radio" name="compliance_mode" value="br_lgpd" <?php checked( $settings['compliance_mode'], 'br_lgpd' ); ?> /> <?php esc_html_e( 'Brazil LGPD', 'universal-consent-privacy-framework' ); ?></label>
						<label><input type="radio" name="compliance_mode" value="ca_quebec" <?php checked( $settings['compliance_mode'], 'ca_quebec' ); ?> /> <?php esc_html_e( 'Quebec Law 25', 'universal-consent-privacy-framework' ); ?></label>
						<label><input type="radio" name="compliance_mode" value="global_balanced" <?php checked( $settings['compliance_mode'], 'global_balanced' ); ?> /> <?php esc_html_e( 'Global balanced', 'universal-consent-privacy-framework' ); ?></label>
						<label><input type="radio" name="compliance_mode" value="custom" <?php checked( $settings['compliance_mode'], 'custom' ); ?> /> <?php esc_html_e( 'Custom', 'universal-consent-privacy-framework' ); ?></label>
					</fieldset>
					<p class="description"><?php esc_html_e( 'Packs set consent model, copy, and GPC defaults. They help support privacy workflows — they are not a compliance guarantee. Optional geo routing is under Advanced Settings.', 'universal-consent-privacy-framework' ); ?></p>

				<?php elseif ( 2 === $step ) : ?>
					<h2><?php esc_html_e( 'Documents', 'universal-consent-privacy-framework' ); ?></h2>
					<p><?php esc_html_e( 'How should UCPF handle your Cookie Policy and Privacy Policy?', 'universal-consent-privacy-framework' ); ?></p>
					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'Cookie Policy', 'universal-consent-privacy-framework' ); ?></th>
							<td>
								<label><input type="radio" name="document_sources[cookie_policy]" value="generate" <?php checked( isset( $doc_sources['cookie_policy'] ) ? $doc_sources['cookie_policy'] : 'generate', 'generate' ); ?> /> <?php esc_html_e( 'Generate with UCPF', 'universal-consent-privacy-framework' ); ?></label><br />
								<label><input type="radio" name="document_sources[cookie_policy]" value="existing" <?php checked( isset( $doc_sources['cookie_policy'] ) ? $doc_sources['cookie_policy'] : '', 'existing' ); ?> /> <?php esc_html_e( 'Use existing page later', 'universal-consent-privacy-framework' ); ?></label>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Privacy Policy', 'universal-consent-privacy-framework' ); ?></th>
							<td>
								<label><input type="radio" name="document_sources[privacy_policy]" value="generate" <?php checked( isset( $doc_sources['privacy_policy'] ) ? $doc_sources['privacy_policy'] : 'generate', 'generate' ); ?> /> <?php esc_html_e( 'Generate with UCPF', 'universal-consent-privacy-framework' ); ?></label><br />
								<label><input type="radio" name="document_sources[privacy_policy]" value="existing" <?php checked( isset( $doc_sources['privacy_policy'] ) ? $doc_sources['privacy_policy'] : '', 'existing' ); ?> /> <?php esc_html_e( 'Use existing page later', 'universal-consent-privacy-framework' ); ?></label>
							</td>
						</tr>
					</table>

				<?php elseif ( 3 === $step ) : ?>
					<h2><?php esc_html_e( 'Website information', 'universal-consent-privacy-framework' ); ?></h2>
					<p><?php esc_html_e( 'Used in generated documents and consent records.', 'universal-consent-privacy-framework' ); ?></p>
					<table class="form-table">
						<tr><th><label for="business_name"><?php esc_html_e( 'Owner / business name', 'universal-consent-privacy-framework' ); ?></label></th>
							<td><input name="business_name" id="business_name" type="text" class="regular-text" value="<?php echo esc_attr( $settings['business_name'] ); ?>" required /></td></tr>
						<tr><th><label for="business_address"><?php esc_html_e( 'Address', 'universal-consent-privacy-framework' ); ?></label></th>
							<td><input name="business_address" id="business_address" type="text" class="regular-text" value="<?php echo esc_attr( $settings['business_address'] ); ?>" /></td></tr>
						<tr><th><label for="business_country"><?php esc_html_e( 'Country', 'universal-consent-privacy-framework' ); ?></label></th>
							<td><input name="business_country" id="business_country" type="text" class="regular-text" value="<?php echo esc_attr( $settings['business_country'] ); ?>" /></td></tr>
						<tr><th><label for="contact_email"><?php esc_html_e( 'Privacy contact email', 'universal-consent-privacy-framework' ); ?></label></th>
							<td><input name="contact_email" id="contact_email" type="email" class="regular-text" value="<?php echo esc_attr( $settings['contact_email'] ); ?>" required /></td></tr>
						<tr><th><label for="business_phone"><?php esc_html_e( 'Telephone', 'universal-consent-privacy-framework' ); ?></label></th>
							<td><input name="business_phone" id="business_phone" type="text" class="regular-text" value="<?php echo esc_attr( $settings['business_phone'] ); ?>" /></td></tr>
					</table>

				<?php elseif ( 4 === $step ) : ?>
					<h2><?php esc_html_e( 'Security & Consent', 'universal-consent-privacy-framework' ); ?></h2>
					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'Records of consent', 'universal-consent-privacy-framework' ); ?></th>
							<td>
								<label><input type="radio" name="consent_logging" value="1" <?php checked( ! empty( $settings['consent_logging'] ) ); ?> /> <?php esc_html_e( 'Yes', 'universal-consent-privacy-framework' ); ?></label>
								<label><input type="radio" name="consent_logging" value="0" <?php checked( empty( $settings['consent_logging'] ) ); ?> /> <?php esc_html_e( 'No', 'universal-consent-privacy-framework' ); ?></label>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Data request forms', 'universal-consent-privacy-framework' ); ?></th>
							<td>
								<label><input type="radio" name="enable_data_request_forms" value="1" <?php checked( ! empty( $settings['enable_data_request_forms'] ) ); ?> /> <?php esc_html_e( 'Yes', 'universal-consent-privacy-framework' ); ?></label>
								<label><input type="radio" name="enable_data_request_forms" value="0" <?php checked( empty( $settings['enable_data_request_forms'] ) ); ?> /> <?php esc_html_e( 'No', 'universal-consent-privacy-framework' ); ?></label>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Respect Do Not Track / GPC', 'universal-consent-privacy-framework' ); ?></th>
							<td>
								<label><input type="radio" name="respect_dnt_gpc" value="1" <?php checked( ! empty( $settings['respect_dnt_gpc'] ) ); ?> /> <?php esc_html_e( 'Yes', 'universal-consent-privacy-framework' ); ?></label>
								<label><input type="radio" name="respect_dnt_gpc" value="0" <?php checked( empty( $settings['respect_dnt_gpc'] ) ); ?> /> <?php esc_html_e( 'No', 'universal-consent-privacy-framework' ); ?></label>
							</td>
						</tr>
					</table>

				<?php elseif ( 5 === $step ) : ?>
					<h2><?php esc_html_e( 'Website Scan', 'universal-consent-privacy-framework' ); ?></h2>
					<p><?php esc_html_e( 'Emulates a logged-out front-end visitor. Pick pages below (or use the Cookie Scanner screen for full controls). Guest crawl temporarily allows tags during discover so cookies can be observed.', 'universal-consent-privacy-framework' ); ?></p>
					<div class="ucpf-scanner-picker" id="ucpf-scanner-picker">
						<div class="ucpf-scanner-chips" id="ucpf-scanner-chips"></div>
						<div class="ucpf-scanner-pages" id="ucpf-scanner-pages">
							<p class="description"><?php esc_html_e( 'Loading pages…', 'universal-consent-privacy-framework' ); ?></p>
						</div>
						<p class="ucpf-scanner-custom">
							<label for="ucpf-scan-custom-url"><?php esc_html_e( 'Add custom URL', 'universal-consent-privacy-framework' ); ?></label>
							<span class="ucpf-scanner-custom__row">
								<input type="text" class="regular-text" id="ucpf-scan-custom-url" />
								<button type="button" class="button" id="ucpf-scan-add-url"><?php esc_html_e( 'Add', 'universal-consent-privacy-framework' ); ?></button>
							</span>
						</p>
					</div>
					<p>
						<label><input type="checkbox" id="ucpf-scan-browser" value="1" checked /> <?php esc_html_e( 'Guest browser crawl (recommended)', 'universal-consent-privacy-framework' ); ?></label>
					</p>
					<p>
						<label><input type="checkbox" id="ucpf-scan-auth" value="1" /> <?php esc_html_e( 'Also scan homepage as logged-in (optional)', 'universal-consent-privacy-framework' ); ?></label>
					</p>
					<p>
						<button type="button" class="button button-primary" id="ucpf-wizard-run-scan"><?php esc_html_e( 'Scan as visitor', 'universal-consent-privacy-framework' ); ?></button>
						<button type="button" class="button" id="ucpf-wizard-live-capture"><?php esc_html_e( 'Admin tab only (debug)', 'universal-consent-privacy-framework' ); ?></button>
					</p>
					<div id="ucpf-scan-status" class="ucpf-wizard__status" hidden></div>
					<?php if ( ! empty( $last_scan['date'] ) ) : ?>
						<p><?php echo esc_html( sprintf( __( 'Last scan: %s — %d services, %d cookies, %d unknown', 'universal-consent-privacy-framework' ), $last_scan['date'], isset( $last_scan['results'] ) ? count( $last_scan['results'] ) : 0, isset( $last_scan['cookies'] ) ? count( $last_scan['cookies'] ) : 0, isset( $last_scan['unknown_cookies'] ) ? count( $last_scan['unknown_cookies'] ) : 0 ) ); ?></p>
					<?php endif; ?>

				<?php elseif ( 6 === $step ) : ?>
					<h2><?php esc_html_e( 'Statistics', 'universal-consent-privacy-framework' ); ?></h2>
					<p><?php esc_html_e( 'Enable every analytics tool you run on this site and enter each tag/ID. You can select more than one (for example GA4 + Clarity + Hotjar).', 'universal-consent-privacy-framework' ); ?></p>
					<?php
					$templates   = \UCPF\Tracking_Templates::all();
					$service_ids = isset( $settings['service_ids'] ) && is_array( $settings['service_ids'] ) ? $settings['service_ids'] : array();
					$selected_stats = isset( $settings['selected_statistics'] ) ? $settings['selected_statistics'] : array();
					if ( is_string( $selected_stats ) ) {
						$selected_stats = ( '' !== $selected_stats && 'other' !== $selected_stats ) ? array( $selected_stats ) : array();
					}
					if ( ! is_array( $selected_stats ) ) {
						$selected_stats = array();
					}
					// Pre-check from enabled service_ids when selected_statistics empty.
					if ( empty( $selected_stats ) ) {
						foreach ( $templates as $key => $meta ) {
							if ( 'analytics' === $meta['category'] && ! empty( $service_ids[ $key ]['enabled'] ) ) {
								$selected_stats[] = $key;
							}
						}
					}
					$stats_tools = array();
					foreach ( $templates as $key => $meta ) {
						if ( 'analytics' === $meta['category'] ) {
							$stats_tools[ $key ] = $meta;
						}
					}
					?>
					<p class="description"><?php esc_html_e( 'Leave all unchecked if you do not compile statistics. Check a tool to enter its ID.', 'universal-consent-privacy-framework' ); ?></p>
					<div class="ucpf-wizard__service-list" data-ucpf-toggle-ids>
						<?php foreach ( $stats_tools as $key => $meta ) :
							$checked = in_array( $key, $selected_stats, true ) || ! empty( $service_ids[ $key ]['enabled'] );
							$row_id  = isset( $service_ids[ $key ]['id'] ) ? $service_ids[ $key ]['id'] : '';
							$tag_id  = isset( $service_ids[ $key ]['tag_id'] ) ? $service_ids[ $key ]['tag_id'] : '';
							?>
							<div class="ucpf-wizard__service-item">
								<label class="ucpf-wizard__service-check">
									<input type="checkbox" name="selected_statistics[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $checked ); ?> data-ucpf-id-toggle />
									<?php echo esc_html( $meta['label'] ); ?>
								</label>
								<div class="ucpf-wizard__id-slot" <?php echo $checked ? '' : 'hidden'; ?>>
									<label>
										<span><?php echo esc_html( $meta['id_label'] ); ?></span>
										<input
											type="text"
											class="regular-text"
											name="service_ids[<?php echo esc_attr( $key ); ?>][id]"
											value="<?php echo esc_attr( $row_id ); ?>"
											placeholder="<?php echo esc_attr( $meta['placeholder'] ); ?>"
											autocomplete="off"
										/>
									</label>
									<p class="description"><?php echo esc_html( $meta['help'] ); ?></p>
									<?php if ( ! empty( $meta['tag_id_label'] ) ) : ?>
										<label>
											<span><?php echo esc_html( $meta['tag_id_label'] ); ?></span>
											<input
												type="text"
												class="regular-text"
												name="service_ids[<?php echo esc_attr( $key ); ?>][tag_id]"
												value="<?php echo esc_attr( $tag_id ); ?>"
												placeholder="<?php echo esc_attr( isset( $meta['tag_placeholder'] ) ? $meta['tag_placeholder'] : 'GT-XXXXXXXX' ); ?>"
												autocomplete="off"
											/>
										</label>
										<p class="description"><?php echo esc_html( isset( $meta['tag_help'] ) ? $meta['tag_help'] : '' ); ?></p>
									<?php endif; ?>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
					<p class="description"><?php esc_html_e( 'Marketing tags (Meta Pixel, TikTok, LinkedIn) are on the next Services step.', 'universal-consent-privacy-framework' ); ?></p>
					<script>
					(function () {
						document.querySelectorAll('[data-ucpf-toggle-ids]').forEach(function (list) {
							list.querySelectorAll('[data-ucpf-id-toggle]').forEach(function (cb) {
								var item = cb.closest('.ucpf-wizard__service-item');
								var slot = item ? item.querySelector('.ucpf-wizard__id-slot') : null;
								if (!slot) return;
								function sync() { slot.hidden = !cb.checked; }
								cb.addEventListener('change', sync);
								sync();
							});
						});
					})();
					</script>

				<?php elseif ( 7 === $step ) : ?>
					<h2><?php esc_html_e( 'Services', 'universal-consent-privacy-framework' ); ?></h2>
					<p><?php esc_html_e( 'Confirm other third-party services on this site. Analytics tools you enabled under Statistics are already configured.', 'universal-consent-privacy-framework' ); ?></p>
					<?php
					$templates   = \UCPF\Tracking_Templates::all();
					$service_ids = isset( $settings['service_ids'] ) && is_array( $settings['service_ids'] ) ? $settings['service_ids'] : array();

					$selected_stats = isset( $settings['selected_statistics'] ) ? $settings['selected_statistics'] : array();
					if ( is_string( $selected_stats ) ) {
						$selected_stats = ( '' !== $selected_stats && 'other' !== $selected_stats ) ? array( $selected_stats ) : array();
					}
					if ( ! is_array( $selected_stats ) ) {
						$selected_stats = array();
					}

					$analytics_enabled = array();
					foreach ( $templates as $key => $meta ) {
						if ( 'analytics' !== $meta['category'] ) {
							continue;
						}
						if ( in_array( $key, $selected_stats, true ) || ! empty( $service_ids[ $key ]['enabled'] ) ) {
							$analytics_enabled[ $key ] = $meta;
						}
					}

					$marketing_tools = array();
					foreach ( $templates as $key => $meta ) {
						if ( 'marketing' === $meta['category'] ) {
							$marketing_tools[ $key ] = $meta;
						}
					}

					$detected = array();
					if ( ! empty( $last_scan['detected_services'] ) ) {
						foreach ( (array) $last_scan['detected_services'] as $key ) {
							$detected[ $key ] = true;
						}
					}
					if ( ! empty( $last_scan['results'] ) ) {
						foreach ( $last_scan['results'] as $row ) {
							if ( ! empty( $row['service'] ) ) {
								$detected[ $row['service'] ] = true;
							}
						}
					}
					// Drop analytics/marketing templates from the generic list (handled above).
					foreach ( array_keys( $templates ) as $track_key ) {
						unset( $detected[ $track_key ] );
					}
					?>

					<?php if ( ! empty( $analytics_enabled ) ) : ?>
						<h3><?php esc_html_e( 'From Statistics', 'universal-consent-privacy-framework' ); ?></h3>
						<p class="description"><?php esc_html_e( 'Already enabled on the previous step. Change them there if needed.', 'universal-consent-privacy-framework' ); ?></p>
						<ul class="ucpf-wizard__summary-list">
							<?php foreach ( $analytics_enabled as $key => $meta ) :
								$aid = isset( $service_ids[ $key ]['id'] ) ? $service_ids[ $key ]['id'] : '';
								?>
								<li>
									<strong><?php echo esc_html( $meta['label'] ); ?></strong>
									<?php if ( $aid ) : ?>
										— <code><?php echo esc_html( $aid ); ?></code>
									<?php else : ?>
										— <span class="description"><?php esc_html_e( 'No ID saved yet', 'universal-consent-privacy-framework' ); ?></span>
									<?php endif; ?>
									<input type="hidden" name="selected_services[]" value="<?php echo esc_attr( $key ); ?>" />
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>

					<?php if ( ! empty( $marketing_tools ) ) : ?>
						<h3><?php esc_html_e( 'Marketing tags', 'universal-consent-privacy-framework' ); ?></h3>
						<p class="description"><?php esc_html_e( 'Check a tag to reveal its ID field.', 'universal-consent-privacy-framework' ); ?></p>
						<div class="ucpf-wizard__service-list" data-ucpf-toggle-ids>
							<?php foreach ( $marketing_tools as $key => $meta ) :
								$checked = in_array( $key, $selected_services, true ) || ! empty( $service_ids[ $key ]['enabled'] );
								$row_id  = isset( $service_ids[ $key ]['id'] ) ? $service_ids[ $key ]['id'] : '';
								?>
								<div class="ucpf-wizard__service-item">
									<label class="ucpf-wizard__service-check">
										<input type="checkbox" name="selected_services[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $checked ); ?> data-ucpf-id-toggle />
										<?php echo esc_html( $meta['label'] ); ?>
									</label>
									<div class="ucpf-wizard__id-slot" <?php echo $checked ? '' : 'hidden'; ?>>
										<label>
											<span><?php echo esc_html( $meta['id_label'] ); ?></span>
											<input
												type="text"
												class="regular-text"
												name="service_ids[<?php echo esc_attr( $key ); ?>][id]"
												value="<?php echo esc_attr( $row_id ); ?>"
												placeholder="<?php echo esc_attr( $meta['placeholder'] ); ?>"
												autocomplete="off"
											/>
										</label>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<h3><?php esc_html_e( 'Other services', 'universal-consent-privacy-framework' ); ?></h3>
					<?php if ( empty( $detected ) ) : ?>
						<p class="description"><?php esc_html_e( 'No other services detected yet. Run a site scan earlier in the wizard if you want auto-detection.', 'universal-consent-privacy-framework' ); ?></p>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'Detected on this site. Check the ones you use.', 'universal-consent-privacy-framework' ); ?></p>
						<div class="ucpf-wizard__checklist">
							<?php
							foreach ( array_keys( $detected ) as $key ) {
								$service = isset( $services[ $key ] ) ? $services[ $key ] : null;
								$label   = $service ? $service['name'] : $key;
								$checked = in_array( $key, $selected_services, true )
									|| ( empty( $selected_services ) && ! empty( $last_scan['results'] ) );
								printf(
									'<label class="ucpf-wizard__chip"><input type="checkbox" name="selected_services[]" value="%1$s" %2$s /> %3$s</label>',
									esc_attr( $key ),
									checked( $checked, true, false ),
									esc_html( $label )
								);
							}
							?>
						</div>
					<?php endif; ?>
					<script>
					(function () {
						document.querySelectorAll('[data-ucpf-toggle-ids]').forEach(function (list) {
							list.querySelectorAll('[data-ucpf-id-toggle]').forEach(function (cb) {
								var item = cb.closest('.ucpf-wizard__service-item');
								var slot = item ? item.querySelector('.ucpf-wizard__id-slot') : null;
								if (!slot) return;
								function sync() { slot.hidden = !cb.checked; }
								cb.addEventListener('change', sync);
								sync();
							});
						});
					})();
					</script>

				<?php elseif ( 8 === $step ) : ?>
					<h2><?php esc_html_e( 'Cookie review', 'universal-consent-privacy-framework' ); ?></h2>
					<p><?php esc_html_e( 'Review detected cookies and set how each service is dealt with. Necessary cookies (WordPress login, WooCommerce cart/session) stay allowed.', 'universal-consent-privacy-framework' ); ?></p>
					<?php
					$ucpf_review_mode = 'wizard';
					include UCPF_PLUGIN_DIR . 'admin/views/partials/cookie-review.php';
					?>

				<?php elseif ( 9 === $step ) : ?>
					<h2><?php esc_html_e( 'Generate pages', 'universal-consent-privacy-framework' ); ?></h2>
					<p><?php esc_html_e( 'Create Cookie Policy, Privacy Policy, and Consent Preferences from templates. Do Not Sell and Data Request are not auto-generated — build those pages on your site, paste [ucpf_do_not_sell_form] / [ucpf_data_request_form], then set their URLs under Generated Pages.', 'universal-consent-privacy-framework' ); ?></p>
					<p><button type="button" class="button button-primary" id="ucpf-wizard-generate-pages"><?php esc_html_e( 'Generate policy pages now', 'universal-consent-privacy-framework' ); ?></button></p>
					<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ucpf-pages' ) ); ?>"><?php esc_html_e( 'Open Generated Pages (rights URLs)', 'universal-consent-privacy-framework' ); ?></a></p>
					<div id="ucpf-pages-status" class="ucpf-wizard__status" hidden></div>

				<?php else : ?>
					<h2><?php esc_html_e( 'Finish', 'universal-consent-privacy-framework' ); ?></h2>
					<p><?php esc_html_e( 'Almost there. Enable the consent banner and script blocker to go live.', 'universal-consent-privacy-framework' ); ?></p>
					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'Show consent banner', 'universal-consent-privacy-framework' ); ?></th>
							<td>
								<label><input type="radio" name="banner_enabled" value="1" <?php checked( ! empty( $settings['banner_enabled'] ) ); ?> /> <?php esc_html_e( 'Yes', 'universal-consent-privacy-framework' ); ?></label>
								<label><input type="radio" name="banner_enabled" value="0" <?php checked( empty( $settings['banner_enabled'] ) ); ?> /> <?php esc_html_e( 'No', 'universal-consent-privacy-framework' ); ?></label>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Enable cookie and script blocker', 'universal-consent-privacy-framework' ); ?></th>
							<td>
								<label><input type="radio" name="blocker_enabled" value="1" <?php checked( ! empty( $settings['blocker_enabled'] ) ); ?> /> <?php esc_html_e( 'Yes', 'universal-consent-privacy-framework' ); ?></label>
								<label><input type="radio" name="blocker_enabled" value="0" <?php checked( empty( $settings['blocker_enabled'] ) ); ?> /> <?php esc_html_e( 'No', 'universal-consent-privacy-framework' ); ?></label>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Banner layout', 'universal-consent-privacy-framework' ); ?></th>
							<td>
								<select name="banner_layout">
									<option value="bar" <?php selected( $settings['banner_layout'], 'bar' ); ?>><?php esc_html_e( 'Bottom bar', 'universal-consent-privacy-framework' ); ?></option>
									<option value="modal" <?php selected( $settings['banner_layout'], 'modal' ); ?>><?php esc_html_e( 'Center modal', 'universal-consent-privacy-framework' ); ?></option>
									<option value="corner" <?php selected( $settings['banner_layout'], 'corner' ); ?>><?php esc_html_e( 'Corner card', 'universal-consent-privacy-framework' ); ?></option>
								</select>
							</td>
						</tr>
					</table>
				<?php endif; ?>

				<div class="ucpf-wizard__footer">
					<?php if ( $step > 1 ) : ?>
						<button type="submit" class="button" name="wizard_direction" value="prev" formnovalidate><?php esc_html_e( 'Previous', 'universal-consent-privacy-framework' ); ?></button>
					<?php endif; ?>
					<button type="submit" class="button" name="wizard_direction" value="stay" formnovalidate><?php esc_html_e( 'Save', 'universal-consent-privacy-framework' ); ?></button>
					<?php if ( $step < 10 ) : ?>
						<button type="submit" class="button button-primary" name="wizard_direction" value="next"><?php esc_html_e( 'Save and Continue', 'universal-consent-privacy-framework' ); ?></button>
					<?php else : ?>
						<button type="submit" class="button button-primary" name="wizard_direction" value="finish"><?php esc_html_e( 'Finish', 'universal-consent-privacy-framework' ); ?></button>
					<?php endif; ?>
				</div>
			</form>
		</main>
	</div>
</div>
