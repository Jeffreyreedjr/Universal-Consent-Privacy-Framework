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

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- included template; locals are not plugin globals.

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
$review     = ! empty( $last_scan['review'] ) && is_array( $last_scan['review'] ) ? $last_scan['review'] : array();
$new_unknown = ! empty( $review['new_unknown_names'] ) && is_array( $review['new_unknown_names'] ) ? $review['new_unknown_names'] : array();
$new_unknown_map = array();
foreach ( $new_unknown as $n ) {
	$new_unknown_map[ (string) $n ] = true;
}
$drift_new_count = 0;
foreach ( $unknown as $urow ) {
	if ( is_array( $urow ) && ( ! empty( $urow['is_new'] ) || ( ! empty( $urow['name'] ) && ! empty( $new_unknown_map[ (string) $urow['name'] ] ) ) ) ) {
		++$drift_new_count;
	}
}

// Scanner: only show services seen on this site (cookies, matches, local catalog, overrides) — not the full catalog wall.
if ( $is_scanner && is_array( $services ) ) {
	$relevant = array();
	foreach ( $known as $crow ) {
		if ( is_array( $crow ) && ! empty( $crow['service'] ) ) {
			$relevant[ sanitize_key( (string) $crow['service'] ) ] = true;
		}
	}
	foreach ( array( 'detected_services', 'script_matches', 'matches' ) as $bucket ) {
		if ( empty( $last_scan[ $bucket ] ) || ! is_array( $last_scan[ $bucket ] ) ) {
			continue;
		}
		foreach ( $last_scan[ $bucket ] as $row ) {
			if ( ! is_array( $row ) ) {
				if ( is_string( $row ) && '' !== $row ) {
					$relevant[ sanitize_key( $row ) ] = true;
				}
				continue;
			}
			foreach ( array( 'key', 'service', 'service_key', 'id' ) as $fk ) {
				if ( ! empty( $row[ $fk ] ) ) {
					$relevant[ sanitize_key( (string) $row[ $fk ] ) ] = true;
				}
			}
		}
	}
	foreach ( \UCPF\Catalog_Suggestions::get_local_services() as $lsvc ) {
		if ( ! empty( $lsvc['key'] ) && ! \UCPF\Scan_Noise_Filter::should_omit_detected_service( (string) $lsvc['key'] ) ) {
			$relevant[ sanitize_key( (string) $lsvc['key'] ) ] = true;
		}
	}
	$svc_overrides = \UCPF\Settings::get( 'service_overrides', array() );
	if ( is_array( $svc_overrides ) ) {
		foreach ( array_keys( $svc_overrides ) as $okey ) {
			$relevant[ sanitize_key( (string) $okey ) ] = true;
		}
	}
	if ( ! empty( $relevant ) ) {
		$services = array_values(
			array_filter(
				$services,
				static function ( $service ) use ( $relevant ) {
					if ( ! is_array( $service ) ) {
						return false;
					}
					$key = isset( $service['key'] ) ? sanitize_key( (string) $service['key'] ) : '';
					if ( '' === $key || \UCPF\Scan_Noise_Filter::should_omit_detected_service( $key ) ) {
						return false;
					}
					return ! empty( $relevant[ $key ] );
				}
			)
		);
	}
}

$known_count = 0;
foreach ( $known as $crow ) {
	if ( is_array( $crow ) && ! empty( $crow['name'] ) && ! \UCPF\Scan_Noise_Filter::should_omit_cookie( (string) $crow['name'] ) ) {
		++$known_count;
	}
}
$unknown_count  = is_array( $unknown ) ? count( $unknown ) : 0;
$services_count = is_array( $services ) ? count( $services ) : 0;
$default_tab    = $unknown_count > 0 ? 'attention' : 'known';
?>
<div class="ucpf-cookie-review" id="ucpf-cookie-review" data-ucpf-review-mode="<?php echo esc_attr( $ucpf_review_mode ); ?>"<?php echo $is_scanner ? ' data-ucpf-default-tab="' . esc_attr( $default_tab ) . '"' : ''; ?>>
	<?php if ( $is_scanner ) : ?>
		<h2><?php esc_html_e( 'Cookie review', 'universal-consent-privacy-framework' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Edit visitor-facing titles and purposes, set visibility, and choose treatments. Necessary cookies stay allowed. Ignore = do not gate/block; use Visibility to hide or mark “document only” on the Cookie / Privacy Policy.', 'universal-consent-privacy-framework' ); ?></p>
		<?php if ( ! empty( $last_scan['source'] ) && 'playwright' === $last_scan['source'] ) : ?>
			<p class="description"><?php esc_html_e( 'Source: Playwright deep scan import. Classified cookies are included in the Cookie Policy inventory.', 'universal-consent-privacy-framework' ); ?></p>
		<?php endif; ?>

		<nav class="nav-tab-wrapper ucpf-cookie-review-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Cookie review sections', 'universal-consent-privacy-framework' ); ?>">
			<a href="#ucpf-review-tab-attention" class="nav-tab<?php echo 'attention' === $default_tab ? ' nav-tab-active' : ''; ?>" data-ucpf-review-tab="attention" role="tab" aria-controls="ucpf-review-tab-attention" aria-selected="<?php echo 'attention' === $default_tab ? 'true' : 'false'; ?>">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: unknown cookie count */
						__( 'Needs attention (%d)', 'universal-consent-privacy-framework' ),
						$unknown_count
					)
				);
				?>
			</a>
			<a href="#ucpf-review-tab-known" class="nav-tab<?php echo 'known' === $default_tab ? ' nav-tab-active' : ''; ?>" data-ucpf-review-tab="known" role="tab" aria-controls="ucpf-review-tab-known" aria-selected="<?php echo 'known' === $default_tab ? 'true' : 'false'; ?>">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: known cookie count */
						__( 'Known cookies (%d)', 'universal-consent-privacy-framework' ),
						$known_count
					)
				);
				?>
			</a>
			<a href="#ucpf-review-tab-services" class="nav-tab<?php echo 'services' === $default_tab ? ' nav-tab-active' : ''; ?>" data-ucpf-review-tab="services" role="tab" aria-controls="ucpf-review-tab-services" aria-selected="<?php echo 'services' === $default_tab ? 'true' : 'false'; ?>">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: service count */
						__( 'Service treatments (%d)', 'universal-consent-privacy-framework' ),
						$services_count
					)
				);
				?>
			</a>
		</nav>

		<?php /* Attention panel first in DOM so deep-links and focus land on unknowns when that is the default. */ ?>
		<div id="ucpf-review-tab-attention" class="ucpf-review-tab-panel" role="tabpanel" data-ucpf-review-tab="attention"<?php echo 'attention' === $default_tab ? '' : ' hidden'; ?>>
			<?php if ( $unknown ) : ?>
				<div class="notice notice-warning inline ucpf-cookie-review__drift">
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: unknown count, 2: new-since-last-scan count */
								_n(
									'%1$d unknown cookie needs a category (default treatment: consent). %2$d new since the last scan.',
									'%1$d unknown cookies need a category (default treatment: consent). %2$d new since the last scan.',
									count( $unknown ),
									'universal-consent-privacy-framework'
								),
								count( $unknown ),
								$drift_new_count
							)
						);
						?>
					</p>
					<p class="description"><?php esc_html_e( 'Unknowns are never auto-marked necessary. Assign a category to promote them into the known inventory and Cookie Policy.', 'universal-consent-privacy-framework' ); ?></p>
				</div>
				<h3 class="ucpf-needs-review-title"><?php esc_html_e( 'Needs category assignment', 'universal-consent-privacy-framework' ); ?></h3>
				<p class="description"><?php esc_html_e( 'These cookies cannot stay unclassified. Assign a category and treatment, then Save cookie review.', 'universal-consent-privacy-framework' ); ?></p>
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
							<th><?php esc_html_e( 'Action', 'universal-consent-privacy-framework' ); ?></th>
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
							$is_new      = ! empty( $cookie['is_new'] ) || ( $name && ! empty( $new_unknown_map[ (string) $name ] ) );
							?>
							<tr class="<?php echo esc_attr( $row_class ); ?>" data-cookie-name="<?php echo esc_attr( $name ); ?>">
								<td class="ucpf-cookie-review__id">
									<code class="ucpf-cookie-review__name" title="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $name ); ?></code>
									<span class="ucpf-badge ucpf-badge--alert"><?php esc_html_e( 'Assign category', 'universal-consent-privacy-framework' ); ?></span>
									<?php if ( $is_new ) : ?>
										<span class="ucpf-badge"><?php esc_html_e( 'New', 'universal-consent-privacy-framework' ); ?></span>
									<?php endif; ?>
									<?php if ( $from_ocd ) : ?>
										<span class="description ucpf-cookie-review__meta"><?php esc_html_e( 'Source: Open Cookie Database (suggestion — confirm category)', 'universal-consent-privacy-framework' ); ?></span>
									<?php endif; ?>
									<?php if ( $provider || $context ) : ?>
										<span class="description ucpf-cookie-review__meta" title="<?php echo esc_attr( trim( $provider . ( $provider && $context ? ' — ' : '' ) . $context ) ); ?>"><?php echo esc_html( trim( $provider . ( $provider && $context ? ' — ' : '' ) . $context ) ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<input type="text" class="widefat ucpf-unknown-label" value="<?php echo esc_attr( $label_val ); ?>" />
								</td>
								<td>
									<textarea class="widefat ucpf-unknown-purpose" rows="2"><?php echo esc_textarea( $purp_val ); ?></textarea>
								</td>
								<td>
									<label class="screen-reader-text" for="ucpf-unknown-cat-scanner-<?php echo esc_attr( (string) $i ); ?>"><?php esc_html_e( 'Category', 'universal-consent-privacy-framework' ); ?></label>
									<select id="ucpf-unknown-cat-scanner-<?php echo esc_attr( (string) $i ); ?>" class="ucpf-unknown-category" required>
										<option value=""><?php esc_html_e( '— Select category —', 'universal-consent-privacy-framework' ); ?></option>
										<?php foreach ( $categories as $cat_key => $cat ) : ?>
											<option value="<?php echo esc_attr( $cat_key ); ?>" <?php selected( $cat_val, $cat_key ); ?>><?php echo esc_html( isset( $cat['label'] ) ? $cat['label'] : $cat_key ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
								<td>
									<label class="screen-reader-text" for="ucpf-unknown-treat-scanner-<?php echo esc_attr( (string) $i ); ?>"><?php esc_html_e( 'Treatment', 'universal-consent-privacy-framework' ); ?></label>
									<select id="ucpf-unknown-treat-scanner-<?php echo esc_attr( (string) $i ); ?>" class="ucpf-unknown-treatment">
										<option value="consent" <?php selected( $treat_val, 'consent' ); ?>><?php esc_html_e( 'Consent required', 'universal-consent-privacy-framework' ); ?></option>
										<option value="necessary" <?php selected( $treat_val, 'necessary' ); ?>><?php esc_html_e( 'Necessary (always allow)', 'universal-consent-privacy-framework' ); ?></option>
										<option value="ignore" <?php selected( $treat_val, 'ignore' ); ?>><?php esc_html_e( 'Ignore / do not gate', 'universal-consent-privacy-framework' ); ?></option>
									</select>
								</td>
								<td>
									<select class="ucpf-unknown-visibility">
										<option value="show" <?php selected( $vis_val, 'show' ); ?>><?php esc_html_e( 'Show', 'universal-consent-privacy-framework' ); ?></option>
										<option value="document_only" <?php selected( $vis_val, 'document_only' ); ?>><?php esc_html_e( 'Document only', 'universal-consent-privacy-framework' ); ?></option>
										<option value="hide" <?php selected( $vis_val, 'hide' ); ?>><?php esc_html_e( 'Hide from policy', 'universal-consent-privacy-framework' ); ?></option>
									</select>
								</td>
								<td>
									<button type="button" class="button button-primary ucpf-save-unknown-cookie"><?php esc_html_e( 'Save', 'universal-consent-privacy-framework' ); ?></button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'No unknown cookies need a category. Review Known cookies or Service treatments when you want to edit labels or gating.', 'universal-consent-privacy-framework' ); ?></p>
			<?php endif; ?>
		</div>

		<div id="ucpf-review-tab-known" class="ucpf-review-tab-panel" role="tabpanel" data-ucpf-review-tab="known"<?php echo 'known' === $default_tab ? '' : ' hidden'; ?>>
			<h3 class="screen-reader-text"><?php esc_html_e( 'Known cookies (edit public labels)', 'universal-consent-privacy-framework' ); ?></h3>
			<?php if ( $known ) : ?>
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
						<?php foreach ( $known as $cookie ) : ?>
							<?php
							$name = isset( $cookie['name'] ) ? (string) $cookie['name'] : '';
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
				<p><?php esc_html_e( 'No cookies recorded yet. Run or import a scan above.', 'universal-consent-privacy-framework' ); ?></p>
			<?php endif; ?>
		</div>

		<div id="ucpf-review-tab-services" class="ucpf-review-tab-panel" role="tabpanel" data-ucpf-review-tab="services"<?php echo 'services' === $default_tab ? '' : ' hidden'; ?>>
			<h3 id="ucpf-service-treatments" class="screen-reader-text"><?php esc_html_e( 'Service treatments', 'universal-consent-privacy-framework' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Services found on this site. Ignore = do not gate or block. Cookie policy listing is controlled per cookie via Visibility on Known cookies.', 'universal-consent-privacy-framework' ); ?></p>
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
						$skey   = isset( $service['key'] ) ? $service['key'] : '';
						$sname  = isset( $service['name'] ) ? $service['name'] : $skey;
						$scat   = isset( $service['category'] ) ? $service['category'] : '';
						$streat = isset( $service['treatment'] ) ? $service['treatment'] : 'consent';
						?>
						<tr id="ucpf-service-<?php echo esc_attr( $skey ); ?>" data-service-key="<?php echo esc_attr( $skey ); ?>">
							<td><?php echo esc_html( $sname ); ?></td>
							<td>
								<select class="ucpf-service-override-category">
									<?php foreach ( $categories as $cat_key => $cat ) : ?>
										<option value="<?php echo esc_attr( $cat_key ); ?>" <?php selected( $scat, $cat_key ); ?>><?php echo esc_html( isset( $cat['label'] ) ? $cat['label'] : $cat_key ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td>
								<select class="ucpf-service-override-treatment">
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
		</div>

		<p class="ucpf-cookie-review__save">
			<button type="button" class="button button-primary" id="ucpf-save-cookie-review"><?php esc_html_e( 'Save cookie review', 'universal-consent-privacy-framework' ); ?></button>
		</p>
		<p class="description" id="ucpf-cookie-review-status" hidden></p>

	<?php else : ?>
		<?php /* Wizard: stacked known → unknown → services (unchanged flow). */ ?>
		<?php if ( $unknown ) : ?>
			<div class="notice notice-warning inline ucpf-cookie-review__drift">
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: unknown count, 2: new-since-last-scan count */
							_n(
								'%1$d unknown cookie needs a category (default treatment: consent). %2$d new since the last scan.',
								'%1$d unknown cookies need a category (default treatment: consent). %2$d new since the last scan.',
								count( $unknown ),
								'universal-consent-privacy-framework'
							),
							count( $unknown ),
							$drift_new_count
						)
					);
					?>
				</p>
				<p class="description"><?php esc_html_e( 'Unknowns are never auto-marked necessary. Assign a category to promote them into the known inventory and Cookie Policy.', 'universal-consent-privacy-framework' ); ?></p>
			</div>
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
					<?php foreach ( $known as $cookie ) : ?>
						<?php
						$name = isset( $cookie['name'] ) ? (string) $cookie['name'] : '';
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
			<p><?php esc_html_e( 'No cookies recorded yet. Run a scan in the previous step.', 'universal-consent-privacy-framework' ); ?></p>
		<?php endif; ?>

		<?php if ( $unknown ) : ?>
			<h3 class="ucpf-needs-review-title"><?php esc_html_e( 'Needs category assignment', 'universal-consent-privacy-framework' ); ?></h3>
			<p class="description"><?php esc_html_e( 'These cookies cannot stay unclassified. Pick a category for each before finishing.', 'universal-consent-privacy-framework' ); ?></p>
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
						$is_new      = ! empty( $cookie['is_new'] ) || ( $name && ! empty( $new_unknown_map[ (string) $name ] ) );
						?>
						<tr class="<?php echo esc_attr( $row_class ); ?>" data-cookie-name="<?php echo esc_attr( $name ); ?>">
							<td class="ucpf-cookie-review__id">
								<code class="ucpf-cookie-review__name" title="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $name ); ?></code>
								<span class="ucpf-badge ucpf-badge--alert"><?php esc_html_e( 'Assign category', 'universal-consent-privacy-framework' ); ?></span>
								<?php if ( $is_new ) : ?>
									<span class="ucpf-badge"><?php esc_html_e( 'New', 'universal-consent-privacy-framework' ); ?></span>
								<?php endif; ?>
								<?php if ( $from_ocd ) : ?>
									<span class="description ucpf-cookie-review__meta"><?php esc_html_e( 'Source: Open Cookie Database (suggestion — confirm category)', 'universal-consent-privacy-framework' ); ?></span>
								<?php endif; ?>
								<?php if ( $provider || $context ) : ?>
									<span class="description ucpf-cookie-review__meta" title="<?php echo esc_attr( trim( $provider . ( $provider && $context ? ' — ' : '' ) . $context ) ); ?>"><?php echo esc_html( trim( $provider . ( $provider && $context ? ' — ' : '' ) . $context ) ); ?></span>
								<?php endif; ?>
								<input type="hidden" name="unknown_cookies[<?php echo esc_attr( (string) $i ); ?>][name]" value="<?php echo esc_attr( $name ); ?>" />
							</td>
							<td>
								<input type="text" class="widefat ucpf-unknown-label" name="unknown_cookies[<?php echo esc_attr( (string) $i ); ?>][label]" value="<?php echo esc_attr( $label_val ); ?>" />
							</td>
							<td>
								<textarea class="widefat ucpf-unknown-purpose" name="unknown_cookies[<?php echo esc_attr( (string) $i ); ?>][purpose]" rows="2"><?php echo esc_textarea( $purp_val ); ?></textarea>
							</td>
							<td>
								<label class="screen-reader-text" for="ucpf-unknown-cat-wizard-<?php echo esc_attr( (string) $i ); ?>"><?php esc_html_e( 'Category', 'universal-consent-privacy-framework' ); ?></label>
								<select id="ucpf-unknown-cat-wizard-<?php echo esc_attr( (string) $i ); ?>" class="ucpf-unknown-category" name="unknown_cookies[<?php echo esc_attr( (string) $i ); ?>][category]" required>
									<option value=""><?php esc_html_e( '— Select category —', 'universal-consent-privacy-framework' ); ?></option>
									<?php foreach ( $categories as $cat_key => $cat ) : ?>
										<option value="<?php echo esc_attr( $cat_key ); ?>" <?php selected( $cat_val, $cat_key ); ?>><?php echo esc_html( isset( $cat['label'] ) ? $cat['label'] : $cat_key ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td>
								<label class="screen-reader-text" for="ucpf-unknown-treat-wizard-<?php echo esc_attr( (string) $i ); ?>"><?php esc_html_e( 'Treatment', 'universal-consent-privacy-framework' ); ?></label>
								<select id="ucpf-unknown-treat-wizard-<?php echo esc_attr( (string) $i ); ?>" class="ucpf-unknown-treatment" name="unknown_cookies[<?php echo esc_attr( (string) $i ); ?>][treatment]">
									<option value="consent" <?php selected( $treat_val, 'consent' ); ?>><?php esc_html_e( 'Consent required', 'universal-consent-privacy-framework' ); ?></option>
									<option value="necessary" <?php selected( $treat_val, 'necessary' ); ?>><?php esc_html_e( 'Necessary (always allow)', 'universal-consent-privacy-framework' ); ?></option>
									<option value="ignore" <?php selected( $treat_val, 'ignore' ); ?>><?php esc_html_e( 'Ignore / do not gate', 'universal-consent-privacy-framework' ); ?></option>
								</select>
							</td>
							<td>
								<select class="ucpf-unknown-visibility" name="unknown_cookies[<?php echo esc_attr( (string) $i ); ?>][visibility]">
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
		<?php endif; ?>

		<h3 id="ucpf-service-treatments"><?php esc_html_e( 'Service treatments', 'universal-consent-privacy-framework' ); ?></h3>
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
					$skey   = isset( $service['key'] ) ? $service['key'] : '';
					$sname  = isset( $service['name'] ) ? $service['name'] : $skey;
					$scat   = isset( $service['category'] ) ? $service['category'] : '';
					$streat = isset( $service['treatment'] ) ? $service['treatment'] : 'consent';
					?>
					<tr id="ucpf-service-<?php echo esc_attr( $skey ); ?>" data-service-key="<?php echo esc_attr( $skey ); ?>">
						<td><?php echo esc_html( $sname ); ?>
							<input type="hidden" name="service_overrides[<?php echo esc_attr( $skey ); ?>][key]" value="<?php echo esc_attr( $skey ); ?>" />
						</td>
						<td>
							<select class="ucpf-service-override-category" name="service_overrides[<?php echo esc_attr( $skey ); ?>][category]">
								<?php foreach ( $categories as $cat_key => $cat ) : ?>
									<option value="<?php echo esc_attr( $cat_key ); ?>" <?php selected( $scat, $cat_key ); ?>><?php echo esc_html( isset( $cat['label'] ) ? $cat['label'] : $cat_key ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						<td>
							<select class="ucpf-service-override-treatment" name="service_overrides[<?php echo esc_attr( $skey ); ?>][treatment]">
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
	<?php endif; ?>
</div>
