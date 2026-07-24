<?php
/**
 * Shared cookie review UI (wizard step 8 + Cookie Scanner).
 *
 * Expects:
 * - $last_scan (array)
 * - $categories (array)
 * - $services (array) registry services with key/name/category/treatment
 * - $ucpf_review_mode (string) 'wizard' | 'scanner' (default wizard)
 *
 * @package UCPF
 */

defined( 'ABSPATH' ) || exit;

if ( ! isset( $last_scan ) || ! is_array( $last_scan ) ) {
	$last_scan = array();
}
if ( ! isset( $categories ) || ! is_array( $categories ) ) {
	$categories = \UCPF\Consent_Manager::instance()->get_categories();
}
if ( ! isset( $services ) || ! is_array( $services ) ) {
	$services = \UCPF\Script_Registry::instance()->get_services();
}

$ucpf_review_mode = isset( $ucpf_review_mode ) ? sanitize_key( $ucpf_review_mode ) : 'wizard';
if ( ! in_array( $ucpf_review_mode, array( 'wizard', 'scanner' ), true ) ) {
	$ucpf_review_mode = 'wizard';
}
$is_scanner = ( 'scanner' === $ucpf_review_mode );

$known      = ! empty( $last_scan['cookies'] ) && is_array( $last_scan['cookies'] ) ? $last_scan['cookies'] : array();
$unknown    = ! empty( $last_scan['unknown_cookies'] ) && is_array( $last_scan['unknown_cookies'] ) ? $last_scan['unknown_cookies'] : array();
$overrides  = \UCPF\Cookie_Scanner::get_display_overrides();
?>
<div class="ucpf-cookie-review" id="ucpf-cookie-review" data-ucpf-review-mode="<?php echo esc_attr( $ucpf_review_mode ); ?>">
	<?php if ( $is_scanner ) : ?>
		<h2><?php esc_html_e( 'Cookie review', 'universal-consent-privacy-framework' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Edit visitor-facing titles and purposes, set visibility, and choose treatments. Necessary cookies stay allowed. Ignore = do not gate/block; use Visibility to hide or mark “document only” on the Cookie / Privacy Policy.', 'universal-consent-privacy-framework' ); ?></p>
		<?php if ( ! empty( $last_scan['source'] ) && 'playwright' === $last_scan['source'] ) : ?>
			<p class="description"><?php esc_html_e( 'Source: Playwright deep scan import. Classified cookies are included in the Cookie Policy inventory.', 'universal-consent-privacy-framework' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( $known ) : ?>
		<h3><?php esc_html_e( 'Known cookies (edit public labels)', 'universal-consent-privacy-framework' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Display title and purpose appear on the Cookie Policy and Privacy Policy. Visibility: Show, Hide (omit from public tables), or Document only (list but not gated).', 'universal-consent-privacy-framework' ); ?></p>
		<div class="ucpf-table-scroll">
		<table class="widefat striped ucpf-cookie-review__known">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Cookie', 'universal-consent-privacy-framework' ); ?></th>
					<th><?php esc_html_e( 'Display title', 'universal-consent-privacy-framework' ); ?></th>
					<th><?php esc_html_e( 'Purpose', 'universal-consent-privacy-framework' ); ?></th>
					<th><?php esc_html_e( 'Category', 'universal-consent-privacy-framework' ); ?></th>
					<th><?php esc_html_e( 'Treatment', 'universal-consent-privacy-framework' ); ?></th>
					<th><?php esc_html_e( 'Visibility', 'universal-consent-privacy-framework' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $known as $i => $cookie ) : ?>
					<?php
					$name      = isset( $cookie['name'] ) ? (string) $cookie['name'] : '';
					if ( '' === $name || \UCPF\Scan_Noise_Filter::should_omit_cookie( $name ) ) {
						continue;
					}
					$key       = strtolower( $name );
					$ov        = isset( $overrides[ $key ] ) ? $overrides[ $key ] : array();
					$from_ocd  = ! empty( $cookie['description_source'] ) && 'open_cookie_database' === $cookie['description_source'];
					$svc       = isset( $cookie['service_name'] ) ? (string) $cookie['service_name'] : '';
					$label_val = ! empty( $ov['label'] ) ? $ov['label'] : $svc;
					$purp_val  = ! empty( $ov['purpose'] ) ? $ov['purpose'] : ( isset( $cookie['purpose'] ) ? (string) $cookie['purpose'] : '' );
					$cat_val   = ! empty( $ov['category'] ) ? $ov['category'] : ( isset( $cookie['category'] ) ? $cookie['category'] : '' );
					$treat_val = ! empty( $ov['treatment'] ) ? $ov['treatment'] : ( isset( $cookie['treatment'] ) ? $cookie['treatment'] : 'consent' );
					$vis_val   = ! empty( $ov['visibility'] ) ? $ov['visibility'] : 'show';
					?>
					<tr class="ucpf-known-cookie-row" data-cookie-name="<?php echo esc_attr( $name ); ?>">
						<td class="ucpf-cookie-review__id">
							<code class="ucpf-cookie-review__name" title="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $name ); ?></code>
							<?php if ( $from_ocd ) : ?>
								<span class="description ucpf-cookie-review__meta"><?php esc_html_e( 'Source: Open Cookie Database', 'universal-consent-privacy-framework' ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $cookie['domain'] ) ) : ?>
								<span class="description ucpf-cookie-review__meta" title="<?php echo esc_attr( (string) $cookie['domain'] ); ?>"><?php echo esc_html( (string) $cookie['domain'] ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<input type="text" class="widefat ucpf-known-label" value="<?php echo esc_attr( $label_val ); ?>" placeholder="<?php echo esc_attr( $svc ? $svc : $name ); ?>" />
						</td>
						<td>
							<textarea class="widefat ucpf-known-purpose" rows="2"><?php echo esc_textarea( $purp_val ); ?></textarea>
						</td>
						<td>
							<select class="ucpf-known-category">
								<?php foreach ( $categories as $cat_key => $cat ) : ?>
									<option value="<?php echo esc_attr( $cat_key ); ?>" <?php selected( $cat_val, $cat_key ); ?>><?php echo esc_html( isset( $cat['label'] ) ? $cat['label'] : $cat_key ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						<td>
							<select class="ucpf-known-treatment">
								<option value="consent" <?php selected( $treat_val, 'consent' ); ?>><?php esc_html_e( 'Consent required', 'universal-consent-privacy-framework' ); ?></option>
								<option value="necessary" <?php selected( $treat_val, 'necessary' ); ?>><?php esc_html_e( 'Necessary (always allow)', 'universal-consent-privacy-framework' ); ?></option>
								<option value="ignore" <?php selected( $treat_val, 'ignore' ); ?>><?php esc_html_e( 'Ignore / do not gate', 'universal-consent-privacy-framework' ); ?></option>
							</select>
						</td>
						<td>
							<select class="ucpf-known-visibility">
								<option value="show" <?php selected( $vis_val, 'show' ); ?>><?php esc_html_e( 'Show', 'universal-consent-privacy-framework' ); ?></option>
								<option value="document_only" <?php selected( $vis_val, 'document_only' ); ?>><?php esc_html_e( 'Document only', 'universal-consent-privacy-framework' ); ?></option>
								<option value="hide" <?php selected( $vis_val, 'hide' ); ?>><?php esc_html_e( 'Hide from policy', 'universal-consent-privacy-framework' ); ?></option>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
	<?php else : ?>
		<p><?php echo esc_html( $is_scanner
			? __( 'No cookies recorded yet. Run or import a scan above.', 'universal-consent-privacy-framework' )
			: __( 'No cookies recorded yet. Run a scan in the previous step.', 'universal-consent-privacy-framework' )
		); ?></p>
	<?php endif; ?>

	<?php if ( $unknown ) : ?>
		<h3 class="ucpf-needs-review-title"><?php esc_html_e( 'Needs category assignment', 'universal-consent-privacy-framework' ); ?></h3>
		<p class="description"><?php echo esc_html( $is_scanner
			? __( 'These cookies cannot stay unclassified. Assign a category and treatment, then Save cookie review.', 'universal-consent-privacy-framework' )
			: __( 'These cookies cannot stay unclassified. Pick a category for each before finishing.', 'universal-consent-privacy-framework' )
		); ?></p>
		<div class="ucpf-table-scroll">
		<table class="widefat ucpf-unknown-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Cookie', 'universal-consent-privacy-framework' ); ?></th>
					<th><?php esc_html_e( 'Display title', 'universal-consent-privacy-framework' ); ?></th>
					<th><?php esc_html_e( 'Purpose', 'universal-consent-privacy-framework' ); ?></th>
					<th><?php esc_html_e( 'Category (required)', 'universal-consent-privacy-framework' ); ?></th>
					<th><?php esc_html_e( 'Treatment', 'universal-consent-privacy-framework' ); ?></th>
					<th><?php esc_html_e( 'Visibility', 'universal-consent-privacy-framework' ); ?></th>
					<?php if ( $is_scanner ) : ?>
						<th><?php esc_html_e( 'Action', 'universal-consent-privacy-framework' ); ?></th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $unknown as $i => $cookie ) : ?>
					<?php
					$name        = isset( $cookie['name'] ) ? $cookie['name'] : '';
					$cat_val     = isset( $cookie['category'] ) ? $cookie['category'] : '';
					$treat_val   = isset( $cookie['treatment'] ) ? $cookie['treatment'] : 'consent';
					$is_critical = '' === $cat_val || 'unclassified' === $cat_val || ( isset( $cookie['importance'] ) && 'unclassified' === $cookie['importance'] );
					$row_class   = $is_critical ? 'ucpf-row--critical' : 'ucpf-row--warn';
					$provider    = isset( $cookie['provider'] ) ? $cookie['provider'] : '';
					$context     = isset( $cookie['context'] ) ? $cookie['context'] : '';
					$from_ocd    = ! empty( $cookie['description_source'] ) && 'open_cookie_database' === $cookie['description_source'];
					$purpose     = isset( $cookie['purpose'] ) ? $cookie['purpose'] : '';
					$key         = strtolower( (string) $name );
					$ov          = isset( $overrides[ $key ] ) ? $overrides[ $key ] : array();
					$label_val   = ! empty( $ov['label'] ) ? $ov['label'] : $provider;
					$purp_val    = ! empty( $ov['purpose'] ) ? $ov['purpose'] : $purpose;
					$vis_val     = ! empty( $ov['visibility'] ) ? $ov['visibility'] : 'show';
					?>
					<tr class="<?php echo esc_attr( $row_class ); ?>" data-cookie-name="<?php echo esc_attr( $name ); ?>">
						<td class="ucpf-cookie-review__id">
							<code class="ucpf-cookie-review__name" title="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $name ); ?></code>
							<span class="ucpf-badge ucpf-badge--alert"><?php esc_html_e( 'Assign category', 'universal-consent-privacy-framework' ); ?></span>
							<?php if ( $from_ocd ) : ?>
								<span class="description ucpf-cookie-review__meta"><?php esc_html_e( 'Source: Open Cookie Database (suggestion — confirm category)', 'universal-consent-privacy-framework' ); ?></span>
							<?php endif; ?>
							<?php if ( $provider || $context ) : ?>
								<span class="description ucpf-cookie-review__meta" title="<?php echo esc_attr( trim( $provider . ( $provider && $context ? ' — ' : '' ) . $context ) ); ?>"><?php echo esc_html( trim( $provider . ( $provider && $context ? ' — ' : '' ) . $context ) ); ?></span>
							<?php endif; ?>
							<?php if ( ! $is_scanner ) : ?>
								<input type="hidden" name="unknown_cookies[<?php echo esc_attr( (string) $i ); ?>][name]" value="<?php echo esc_attr( $name ); ?>" />
							<?php endif; ?>
						</td>
						<td>
							<input type="text" class="widefat ucpf-unknown-label" value="<?php echo esc_attr( $label_val ); ?>"
								<?php if ( ! $is_scanner ) : ?>
									name="unknown_cookies[<?php echo esc_attr( (string) $i ); ?>][label]"
								<?php endif; ?>
							/>
						</td>
						<td>
							<textarea class="widefat ucpf-unknown-purpose" rows="2"
								<?php if ( ! $is_scanner ) : ?>
									name="unknown_cookies[<?php echo esc_attr( (string) $i ); ?>][purpose]"
								<?php endif; ?>
							><?php echo esc_textarea( $purp_val ); ?></textarea>
						</td>
						<td>
							<label class="screen-reader-text" for="ucpf-unknown-cat-<?php echo esc_attr( $ucpf_review_mode . '-' . (string) $i ); ?>"><?php esc_html_e( 'Category', 'universal-consent-privacy-framework' ); ?></label>
							<select
								id="ucpf-unknown-cat-<?php echo esc_attr( $ucpf_review_mode . '-' . (string) $i ); ?>"
								class="ucpf-unknown-category"
								<?php if ( ! $is_scanner ) : ?>
									name="unknown_cookies[<?php echo esc_attr( (string) $i ); ?>][category]"
								<?php endif; ?>
								required
							>
								<option value=""><?php esc_html_e( '— Select category —', 'universal-consent-privacy-framework' ); ?></option>
								<?php foreach ( $categories as $cat_key => $cat ) : ?>
									<option value="<?php echo esc_attr( $cat_key ); ?>" <?php selected( $cat_val, $cat_key ); ?>><?php echo esc_html( isset( $cat['label'] ) ? $cat['label'] : $cat_key ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						<td>
							<label class="screen-reader-text" for="ucpf-unknown-treat-<?php echo esc_attr( $ucpf_review_mode . '-' . (string) $i ); ?>"><?php esc_html_e( 'Treatment', 'universal-consent-privacy-framework' ); ?></label>
							<select
								id="ucpf-unknown-treat-<?php echo esc_attr( $ucpf_review_mode . '-' . (string) $i ); ?>"
								class="ucpf-unknown-treatment"
								<?php if ( ! $is_scanner ) : ?>
									name="unknown_cookies[<?php echo esc_attr( (string) $i ); ?>][treatment]"
								<?php endif; ?>
							>
								<option value="consent" <?php selected( $treat_val, 'consent' ); ?>><?php esc_html_e( 'Consent required', 'universal-consent-privacy-framework' ); ?></option>
								<option value="necessary" <?php selected( $treat_val, 'necessary' ); ?>><?php esc_html_e( 'Necessary (always allow)', 'universal-consent-privacy-framework' ); ?></option>
								<option value="ignore" <?php selected( $treat_val, 'ignore' ); ?>><?php esc_html_e( 'Ignore / do not gate', 'universal-consent-privacy-framework' ); ?></option>
							</select>
						</td>
						<td>
							<select class="ucpf-unknown-visibility"
								<?php if ( ! $is_scanner ) : ?>
									name="unknown_cookies[<?php echo esc_attr( (string) $i ); ?>][visibility]"
								<?php endif; ?>
							>
								<option value="show" <?php selected( $vis_val, 'show' ); ?>><?php esc_html_e( 'Show', 'universal-consent-privacy-framework' ); ?></option>
								<option value="document_only" <?php selected( $vis_val, 'document_only' ); ?>><?php esc_html_e( 'Document only', 'universal-consent-privacy-framework' ); ?></option>
								<option value="hide" <?php selected( $vis_val, 'hide' ); ?>><?php esc_html_e( 'Hide from policy', 'universal-consent-privacy-framework' ); ?></option>
							</select>
						</td>
						<?php if ( $is_scanner ) : ?>
							<td>
								<button type="button" class="button button-primary ucpf-save-unknown-cookie"><?php esc_html_e( 'Save', 'universal-consent-privacy-framework' ); ?></button>
							</td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Service treatments', 'universal-consent-privacy-framework' ); ?></h3>
	<p class="description"><?php esc_html_e( 'Ignore = do not gate or block this service’s scripts. Public listing of its cookies is controlled per cookie via Visibility above.', 'universal-consent-privacy-framework' ); ?></p>
	<div class="ucpf-table-scroll">
	<table class="widefat striped ucpf-cookie-review__services">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Service', 'universal-consent-privacy-framework' ); ?></th>
				<th><?php esc_html_e( 'Category', 'universal-consent-privacy-framework' ); ?></th>
				<th><?php esc_html_e( 'Treatment', 'universal-consent-privacy-framework' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $services as $service ) : ?>
				<?php
				$skey     = isset( $service['key'] ) ? $service['key'] : '';
				$sname    = isset( $service['name'] ) ? $service['name'] : $skey;
				$scat     = isset( $service['category'] ) ? $service['category'] : '';
				$streat   = isset( $service['treatment'] ) ? $service['treatment'] : 'consent';
				?>
				<tr data-service-key="<?php echo esc_attr( $skey ); ?>">
					<td><?php echo esc_html( $sname ); ?>
						<?php if ( ! $is_scanner ) : ?>
							<input type="hidden" name="service_overrides[<?php echo esc_attr( $skey ); ?>][key]" value="<?php echo esc_attr( $skey ); ?>" />
						<?php endif; ?>
					</td>
					<td>
						<select
							class="ucpf-service-override-category"
							<?php if ( ! $is_scanner ) : ?>
								name="service_overrides[<?php echo esc_attr( $skey ); ?>][category]"
							<?php endif; ?>
						>
							<?php foreach ( $categories as $cat_key => $cat ) : ?>
								<option value="<?php echo esc_attr( $cat_key ); ?>" <?php selected( $scat, $cat_key ); ?>><?php echo esc_html( isset( $cat['label'] ) ? $cat['label'] : $cat_key ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
					<td>
						<select
							class="ucpf-service-override-treatment"
							<?php if ( ! $is_scanner ) : ?>
								name="service_overrides[<?php echo esc_attr( $skey ); ?>][treatment]"
							<?php endif; ?>
						>
							<option value="consent" <?php selected( $streat, 'consent' ); ?>><?php esc_html_e( 'Consent required', 'universal-consent-privacy-framework' ); ?></option>
							<option value="necessary" <?php selected( $streat, 'necessary' ); ?>><?php esc_html_e( 'Necessary (always allow)', 'universal-consent-privacy-framework' ); ?></option>
							<option value="ignore" <?php selected( $streat, 'ignore' ); ?>><?php esc_html_e( 'Ignore / do not gate', 'universal-consent-privacy-framework' ); ?></option>
						</select>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	</div>

	<?php if ( $is_scanner ) : ?>
		<p style="margin-top:1rem;">
			<button type="button" class="button button-primary" id="ucpf-save-cookie-review"><?php esc_html_e( 'Save cookie review', 'universal-consent-privacy-framework' ); ?></button>
		</p>
		<p class="description" id="ucpf-cookie-review-status" hidden></p>
	<?php endif; ?>
</div>
