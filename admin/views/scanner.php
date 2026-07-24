<?php
/**
 * Cookie scanner admin view.
 *
 * @package UCPF
 */

defined( 'ABSPATH' ) || exit;

if ( ! isset( $last_scan ) || ! is_array( $last_scan ) ) {
	$last_scan = array();
}

$has_scan   = ! empty( $last_scan['date'] );
$cookie_n   = ! empty( $last_scan['cookies'] ) && is_array( $last_scan['cookies'] ) ? count( $last_scan['cookies'] ) : 0;
$unknown_n  = ! empty( $last_scan['unknown_cookies'] ) && is_array( $last_scan['unknown_cookies'] ) ? count( $last_scan['unknown_cookies'] ) : 0;
$auto_on    = (bool) \UCPF\Settings::get( 'auto_refresh_cookie_policy_after_scan', true );
$policy_url = \UCPF\Page_Generator::instance()->get_page_url( 'cookie_policy' );
$woo_active = \UCPF\Cookie_Scanner::instance()->is_woo_active();
?>
<div class="wrap ucpf-admin">
	<h1><?php esc_html_e( 'Cookie Scanner', 'universal-consent-privacy-framework' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Prefer Deep privacy scan (Playwright) for a full inventory. Quick scan emulates a logged-out visitor in this browser as a fallback. Sites behind Cloudflare are supported by the real Chromium scanner. Helper only — not a guarantee of full detection.', 'universal-consent-privacy-framework' ); ?></p>
	<p class="description"><?php esc_html_e( 'Cookie descriptions: UCPF service catalog + bundled Open Cookie Database (offline). Technical inventory only — not a legal determination.', 'universal-consent-privacy-framework' ); ?></p>

	<div class="ucpf-scanner-picker" id="ucpf-scanner-picker">
		<h2 class="ucpf-scanner-picker__title"><?php esc_html_e( 'Pages to scan (as visitor)', 'universal-consent-privacy-framework' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Pick real front-end pages (forms, landing pages). Marketing cookies appear after scripts run during the guest crawl.', 'universal-consent-privacy-framework' ); ?></p>
		<p class="ucpf-scan-depth">
			<label for="ucpf-scan-depth"><strong><?php esc_html_e( 'Discovery depth', 'universal-consent-privacy-framework' ); ?></strong></label>
			<select id="ucpf-scan-depth">
				<option value="quick"><?php esc_html_e( 'Quick — ~10 pages (home + priority)', 'universal-consent-privacy-framework' ); ?></option>
				<option value="standard" selected><?php esc_html_e( 'Standard — ~40 pages (sitemap + links)', 'universal-consent-privacy-framework' ); ?></option>
				<option value="deep"><?php esc_html_e( 'Deep — up to 100 pages (max discovery)', 'universal-consent-privacy-framework' ); ?></option>
			</select>
			<button type="button" class="button" id="ucpf-scan-rediscover"><?php esc_html_e( 'Rediscover pages', 'universal-consent-privacy-framework' ); ?></button>
		</p>
		<p id="ucpf-scan-selection-hint" class="description ucpf-scan-selection-hint" hidden></p>

		<div class="ucpf-scanner-chips" id="ucpf-scanner-chips" aria-label="<?php esc_attr_e( 'Quick page picks', 'universal-consent-privacy-framework' ); ?>">
			<span class="spinner is-active" style="float:none;margin:0;"></span>
		</div>

		<div class="ucpf-scanner-pages" id="ucpf-scanner-pages">
			<p class="description"><?php esc_html_e( 'Loading pages…', 'universal-consent-privacy-framework' ); ?></p>
		</div>

		<p class="ucpf-scanner-custom">
			<label for="ucpf-scan-custom-url"><?php esc_html_e( 'Add custom URL (this site)', 'universal-consent-privacy-framework' ); ?></label>
			<span class="ucpf-scanner-custom__row">
				<input type="text" class="regular-text" id="ucpf-scan-custom-url" placeholder="<?php esc_attr_e( '/contact/ or https://yoursite.com/page/', 'universal-consent-privacy-framework' ); ?>" />
				<button type="button" class="button" id="ucpf-scan-add-url"><?php esc_html_e( 'Add', 'universal-consent-privacy-framework' ); ?></button>
			</span>
		</p>
	</div>

	<p>
		<label><input type="checkbox" id="ucpf-scan-browser" value="1" checked /> <?php esc_html_e( 'Guest browser crawl (recommended — loads selected pages as a visitor)', 'universal-consent-privacy-framework' ); ?></label>
	</p>
	<p>
		<label><input type="checkbox" id="ucpf-scan-auth" value="1" /> <?php esc_html_e( 'Also scan homepage as logged-in (admin session) — optional', 'universal-consent-privacy-framework' ); ?></label>
	</p>
	<div class="ucpf-toolbar" role="group" aria-label="<?php esc_attr_e( 'Scan actions', 'universal-consent-privacy-framework' ); ?>">
		<button type="button" class="button button-primary" id="ucpf-deep-scan"><?php esc_html_e( 'Deep privacy scan', 'universal-consent-privacy-framework' ); ?></button>
		<button type="button" class="button button-primary" id="ucpf-run-scan"><?php esc_html_e( 'Quick scan as visitor', 'universal-consent-privacy-framework' ); ?></button>
		<button type="button" class="button" id="ucpf-run-scheduled-scan"><?php esc_html_e( 'Run scheduled scan now', 'universal-consent-privacy-framework' ); ?></button>
		<button type="button" class="button button-link-delete" id="ucpf-stop-scan" hidden><?php esc_html_e( 'Stop scan', 'universal-consent-privacy-framework' ); ?></button>
		<button type="button" class="button" id="ucpf-import-scan-json"><?php esc_html_e( 'Import scan JSON', 'universal-consent-privacy-framework' ); ?></button>
		<button type="button" class="button" id="ucpf-export-scan"><?php esc_html_e( 'Export scan JSON for catalog', 'universal-consent-privacy-framework' ); ?></button>
		<?php if ( $has_scan ) : ?>
			<button type="button" class="button" id="ucpf-refresh-cookie-policy"><?php esc_html_e( 'Refresh Cookie Policy now', 'universal-consent-privacy-framework' ); ?></button>
		<?php endif; ?>
		<button type="button" class="button" id="ucpf-live-capture"><?php esc_html_e( 'Admin tab only (debug)', 'universal-consent-privacy-framework' ); ?></button>
	</div>
	<p class="description"><?php esc_html_e( 'Quick scan is a WordPress helper (HTTP + limited iframe). It often misses HttpOnly cookies (Cloudflare) and JS trackers (GA). Prefer local Playwright scan + Import, or Deep privacy scan when the hosted service is up. Not a compliance guarantee.', 'universal-consent-privacy-framework' ); ?></p>
	<div class="ucpf-import-box">
		<label for="ucpf-import-scan-file"><strong><?php esc_html_e( 'Import Playwright report', 'universal-consent-privacy-framework' ); ?></strong></label>
		<p class="description"><?php esc_html_e( 'Choose the report-….json file from your local scan (preferred), or paste JSON below. Import replaces the stored inventory and selects matched services.', 'universal-consent-privacy-framework' ); ?></p>
		<p>
			<input type="file" id="ucpf-import-scan-file" accept=".json,application/json" />
		</p>
		<textarea id="ucpf-import-scan-json-text" class="large-text code" rows="4" placeholder="<?php esc_attr_e( 'Or paste Playwright report JSON here, then click Import scan JSON', 'universal-consent-privacy-framework' ); ?>"></textarea>
	</div>
	<?php if ( ! $woo_active ) : ?>
		<p class="description"><?php esc_html_e( 'WooCommerce is not active — shop/cart/checkout pages are not included.', 'universal-consent-privacy-framework' ); ?></p>
	<?php endif; ?>
	<p class="description">
		<?php if ( $auto_on ) : ?>
			<?php esc_html_e( 'Auto-refresh Cookie Policy after scan is ON (Generated Pages). Inventory is stored on this site only.', 'universal-consent-privacy-framework' ); ?>
		<?php else : ?>
			<?php esc_html_e( 'Auto-refresh Cookie Policy after scan is OFF. Use “Refresh Cookie Policy now” or Generated Pages after scanning.', 'universal-consent-privacy-framework' ); ?>
		<?php endif; ?>
		<?php if ( $policy_url ) : ?>
			<a href="<?php echo esc_url( $policy_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View Cookie Policy', 'universal-consent-privacy-framework' ); ?></a>
		<?php endif; ?>
	</p>
	<div id="ucpf-scan-status" class="ucpf-wizard__status" hidden></div>
	<div id="ucpf-pages-status" class="ucpf-wizard__status" hidden></div>

	<?php if ( ! $has_scan ) : ?>
		<div class="ucpf-scanner-empty">
			<h2><?php esc_html_e( 'No scan stored yet', 'universal-consent-privacy-framework' ); ?></h2>
			<p><?php esc_html_e( 'Run Scan as visitor to build the cookie inventory from front-end pages (guest persona). Results stay on this WordPress site.', 'universal-consent-privacy-framework' ); ?></p>
			<ol>
				<li><?php esc_html_e( 'Select front-end pages (Home, Contact, forms) and click Scan as visitor.', 'universal-consent-privacy-framework' ); ?></li>
				<li><?php esc_html_e( 'Leave guest browser crawl on so JS trackers can set cookies under discover mode.', 'universal-consent-privacy-framework' ); ?></li>
				<li><?php esc_html_e( 'Confirm the Cookie Policy page refreshed (or refresh it manually).', 'universal-consent-privacy-framework' ); ?></li>
			</ol>
		</div>
	<?php else : ?>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: datetime, 2: known count, 3: unknown count */
					__( 'Last scan: %1$s — %2$d known cookie(s), %3$d unknown.', 'universal-consent-privacy-framework' ),
					$last_scan['date'],
					$cookie_n,
					$unknown_n
				)
			);
			?>
		</p>

		<?php if ( 0 === $cookie_n && 0 === $unknown_n ) : ?>
			<div class="ucpf-scanner-empty">
				<h2><?php esc_html_e( 'Scan completed with an empty inventory', 'universal-consent-privacy-framework' ); ?></h2>
				<p><?php esc_html_e( 'No cookie names were observed. Select pages that load tracking scripts, keep guest browser crawl enabled, and retry.', 'universal-consent-privacy-framework' ); ?></p>
			</div>
		<?php endif; ?>

		<?php
		$score = ! empty( $last_scan['compliance_score'] ) && is_array( $last_scan['compliance_score'] ) ? $last_scan['compliance_score'] : array();
		$dark  = ! empty( $last_scan['dark_patterns'] ) && is_array( $last_scan['dark_patterns'] ) ? $last_scan['dark_patterns'] : array();
		$cmp   = ! empty( $last_scan['cmp'] ) && is_array( $last_scan['cmp'] ) ? $last_scan['cmp'] : null;
		$tcf   = ! empty( $last_scan['tcf'] ) && is_array( $last_scan['tcf'] ) ? $last_scan['tcf'] : array();
		?>
		<?php if ( $score || $dark ) : ?>
			<div class="ucpf-tech-score" id="ucpf-tech-score">
				<h2><?php esc_html_e( 'Technical consent checks', 'universal-consent-privacy-framework' ); ?></h2>
				<p class="description"><?php echo esc_html( ! empty( $score['disclaimer'] ) ? $score['disclaimer'] : __( 'Technical automated checks only — not a GDPR compliance determination or legal audit.', 'universal-consent-privacy-framework' ) ); ?></p>
				<?php if ( $score ) : ?>
					<p>
						<strong><?php esc_html_e( 'Score', 'universal-consent-privacy-framework' ); ?>:</strong>
						<?php echo esc_html( (string) ( isset( $score['total'] ) ? $score['total'] : 0 ) ); ?>/100
						(<?php echo esc_html( isset( $score['grade'] ) ? $score['grade'] : '—' ); ?>)
					</p>
					<?php if ( ! empty( $score['breakdown'] ) && is_array( $score['breakdown'] ) ) : ?>
						<ul>
							<li><?php esc_html_e( 'Consent validity', 'universal-consent-privacy-framework' ); ?>: <?php echo esc_html( (string) ( $score['breakdown']['consent_validity'] ?? 0 ) ); ?>/25</li>
							<li><?php esc_html_e( 'Easy refusal', 'universal-consent-privacy-framework' ); ?>: <?php echo esc_html( (string) ( $score['breakdown']['easy_refusal'] ?? 0 ) ); ?>/25</li>
							<li><?php esc_html_e( 'Transparency signals', 'universal-consent-privacy-framework' ); ?>: <?php echo esc_html( (string) ( $score['breakdown']['transparency'] ?? 0 ) ); ?>/25</li>
							<li><?php esc_html_e( 'Cookie behavior', 'universal-consent-privacy-framework' ); ?>: <?php echo esc_html( (string) ( $score['breakdown']['cookie_behavior'] ?? 0 ) ); ?>/25</li>
						</ul>
					<?php endif; ?>
				<?php endif; ?>
				<?php if ( $cmp && ! empty( $cmp['name'] ) ) : ?>
					<p><?php echo esc_html( sprintf( /* translators: %s: CMP name */ __( 'Detected CMP: %s', 'universal-consent-privacy-framework' ), $cmp['name'] ) ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $tcf['detected'] ) ) : ?>
					<p><?php esc_html_e( 'IAB TCF signals detected (informational only).', 'universal-consent-privacy-framework' ); ?></p>
				<?php endif; ?>
				<?php if ( $dark ) : ?>
					<h3><?php esc_html_e( 'Issues checklist', 'universal-consent-privacy-framework' ); ?></h3>
					<div class="ucpf-table-scroll">
			<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Type', 'universal-consent-privacy-framework' ); ?></th>
								<th><?php esc_html_e( 'Severity', 'universal-consent-privacy-framework' ); ?></th>
								<th><?php esc_html_e( 'Description', 'universal-consent-privacy-framework' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $dark as $issue ) : ?>
								<tr>
									<td><code><?php echo esc_html( isset( $issue['type'] ) ? $issue['type'] : '' ); ?></code></td>
									<td><?php echo esc_html( isset( $issue['severity'] ) ? $issue['severity'] : '' ); ?></td>
									<td><?php echo esc_html( isset( $issue['description'] ) ? $issue['description'] : '' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
			</div>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $last_scan['findings_summary'] ) && is_array( $last_scan['findings_summary'] ) ) : ?>
			<?php
			$fs = $last_scan['findings_summary'];
			$fs_pass = ! empty( $fs['pass'] );
			?>
			<div class="ucpf-card ucpf-findings-summary" style="margin:1rem 0;padding:1rem 1.25rem;border-left:4px solid <?php echo $fs_pass ? '#2e7d32' : '#c62828'; ?>;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Consent differential (pass / fail)', 'universal-consent-privacy-framework' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Compares cookies and tracking-like requests across consent states. Technical finding only — not a legal determination.', 'universal-consent-privacy-framework' ); ?></p>
				<p>
					<strong><?php echo $fs_pass ? esc_html__( 'PASS', 'universal-consent-privacy-framework' ) : esc_html__( 'FAIL', 'universal-consent-privacy-framework' ); ?></strong>
					—
					<?php
					printf(
						/* translators: 1: fail count, 2: total findings */
						esc_html__( '%1$d fail · %2$d total findings', 'universal-consent-privacy-framework' ),
						isset( $fs['fail'] ) ? (int) $fs['fail'] : 0,
						isset( $fs['total'] ) ? (int) $fs['total'] : 0
					);
					?>
					<?php if ( ! empty( $last_scan['scan_profile'] ) ) : ?>
						<span class="description">(<?php echo esc_html( sprintf( /* translators: scan profile id */ __( 'profile: %s', 'universal-consent-privacy-framework' ), $last_scan['scan_profile'] ) ); ?>)</span>
					<?php endif; ?>
				</p>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $last_scan['findings'] ) && is_array( $last_scan['findings'] ) ) : ?>
			<h2 class="ucpf-needs-review-title"><?php esc_html_e( 'Differential findings', 'universal-consent-privacy-framework' ); ?></h2>
			<div class="ucpf-table-scroll">
			<table class="widefat ucpf-unknown-table">
				<thead><tr>
					<th><?php esc_html_e( 'Verdict', 'universal-consent-privacy-framework' ); ?></th>
					<th><?php esc_html_e( 'Type', 'universal-consent-privacy-framework' ); ?></th>
					<th><?php esc_html_e( 'Name / host', 'universal-consent-privacy-framework' ); ?></th>
					<th><?php esc_html_e( 'Severity', 'universal-consent-privacy-framework' ); ?></th>
					<th><?php esc_html_e( 'Reason', 'universal-consent-privacy-framework' ); ?></th>
				</tr></thead>
				<tbody>
				<?php
				$fail_codes = array(
					'incorrectly_loaded_before_consent',
					'still_loaded_after_reject',
					'still_loaded_after_dns',
					'still_loaded_after_gpc',
					'category_mismatch',
				);
				foreach ( $last_scan['findings'] as $finding_row ) :
					$fcode = isset( $finding_row['finding'] ) ? $finding_row['finding'] : '';
					$is_fail = in_array( $fcode, $fail_codes, true );
					?>
					<tr class="<?php echo $is_fail ? 'ucpf-row--critical' : ''; ?>">
						<td><code><?php echo esc_html( $fcode ); ?></code></td>
						<td class="ucpf-cell-type"><?php echo esc_html( isset( $finding_row['type'] ) ? $finding_row['type'] : '' ); ?></td>
						<td><code><?php echo esc_html( isset( $finding_row['name'] ) ? $finding_row['name'] : '' ); ?></code></td>
						<td class="ucpf-cell-sev">
							<?php if ( $is_fail ) : ?>
								<span class="ucpf-badge ucpf-badge--alert"><?php echo esc_html( isset( $finding_row['severity'] ) ? $finding_row['severity'] : 'high' ); ?></span>
							<?php else : ?>
								<span class="ucpf-badge"><?php echo esc_html( isset( $finding_row['severity'] ) ? $finding_row['severity'] : 'info' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( isset( $finding_row['reason'] ) ? $finding_row['reason'] : '' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $last_scan['consent_leaks'] ) && is_array( $last_scan['consent_leaks'] ) ) : ?>
			<h2 class="ucpf-needs-review-title"><?php esc_html_e( 'Consent leaks (high priority)', 'universal-consent-privacy-framework' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Consent-required cookies or hosts observed in both no_consent and reject_all. Technical finding only — not a legal determination.', 'universal-consent-privacy-framework' ); ?></p>
			<div class="ucpf-table-scroll">
			<table class="widefat ucpf-unknown-table">
				<thead><tr>
					<th><?php esc_html_e( 'Type', 'universal-consent-privacy-framework' ); ?></th>
					<th><?php esc_html_e( 'Name / host', 'universal-consent-privacy-framework' ); ?></th>
					<th><?php esc_html_e( 'Provider', 'universal-consent-privacy-framework' ); ?></th>
					<th><?php esc_html_e( 'Category', 'universal-consent-privacy-framework' ); ?></th>
					<th><?php esc_html_e( 'Severity', 'universal-consent-privacy-framework' ); ?></th>
					<th><?php esc_html_e( 'Reason', 'universal-consent-privacy-framework' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $last_scan['consent_leaks'] as $leak ) : ?>
					<tr class="ucpf-row--critical">
						<td class="ucpf-cell-type"><?php echo esc_html( isset( $leak['type'] ) ? $leak['type'] : '' ); ?></td>
						<td><code><?php echo esc_html( isset( $leak['name'] ) ? $leak['name'] : '' ); ?></code></td>
						<td><?php echo esc_html( isset( $leak['provider'] ) ? $leak['provider'] : '' ); ?></td>
						<td class="ucpf-cell-cat"><?php echo esc_html( isset( $leak['category'] ) ? $leak['category'] : '' ); ?></td>
						<td class="ucpf-cell-sev"><span class="ucpf-badge ucpf-badge--alert"><?php echo esc_html( isset( $leak['severity'] ) ? $leak['severity'] : 'high' ); ?></span></td>
						<td><?php echo esc_html( isset( $leak['reason'] ) ? $leak['reason'] : '' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		<?php endif; ?>

		<?php
		$catalog_suggestions = \UCPF\Catalog_Suggestions::compute();
		$local_catalog       = \UCPF\Catalog_Suggestions::get_local_services();
		?>
		<div class="ucpf-card" style="margin:1.5rem 0;padding:1rem 1.25rem;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Unknown host → catalog suggestions', 'universal-consent-privacy-framework' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Hosts from the last scan that are not in the bundled vendor catalog. Apply as a site-local pattern (feeds Script Registry + network gate). Copy JSON to merge into assets/vendor-catalog for a plugin release. Never writes plugin files from WordPress.', 'universal-consent-privacy-framework' ); ?>
			</p>
			<?php if ( empty( $catalog_suggestions ) ) : ?>
				<p><?php esc_html_e( 'No unmatched hosts from the last scan (or no scan yet).', 'universal-consent-privacy-framework' ); ?></p>
			<?php else : ?>
				<div class="ucpf-table-scroll">
			<table class="widefat striped ucpf-unknown-table" id="ucpf-catalog-suggestions">
					<thead><tr>
						<th><?php esc_html_e( 'Host', 'universal-consent-privacy-framework' ); ?></th>
						<th><?php esc_html_e( 'Suggested category', 'universal-consent-privacy-framework' ); ?></th>
						<th><?php esc_html_e( 'Sources', 'universal-consent-privacy-framework' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'universal-consent-privacy-framework' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $catalog_suggestions as $sug ) : ?>
						<tr data-host="<?php echo esc_attr( $sug['host'] ); ?>" data-category="<?php echo esc_attr( $sug['category'] ); ?>">
							<td><code><?php echo esc_html( $sug['host'] ); ?></code>
								<?php if ( ! empty( $sug['applied'] ) ) : ?>
									<span class="ucpf-badge"><?php esc_html_e( 'applied', 'universal-consent-privacy-framework' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<select class="ucpf-sug-category">
									<?php foreach ( array( 'analytics', 'marketing', 'preferences', 'functional' ) as $cat ) : ?>
										<option value="<?php echo esc_attr( $cat ); ?>" <?php selected( $sug['category'], $cat ); ?>><?php echo esc_html( $cat ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td><?php echo esc_html( implode( ', ', (array) $sug['sources'] ) ); ?></td>
							<td>
								<button type="button" class="button button-primary ucpf-sug-apply"><?php esc_html_e( 'Apply site override', 'universal-consent-privacy-framework' ); ?></button>
								<button type="button" class="button ucpf-sug-copy" data-json="<?php echo esc_attr( $sug['json'] ); ?>"><?php esc_html_e( 'Copy JSON', 'universal-consent-privacy-framework' ); ?></button>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>

			<?php if ( ! empty( $local_catalog ) ) : ?>
				<h3><?php esc_html_e( 'Site-local catalog services', 'universal-consent-privacy-framework' ); ?></h3>
				<div class="ucpf-table-scroll">
			<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'Key', 'universal-consent-privacy-framework' ); ?></th>
						<th><?php esc_html_e( 'Name', 'universal-consent-privacy-framework' ); ?></th>
						<th><?php esc_html_e( 'Category', 'universal-consent-privacy-framework' ); ?></th>
						<th><?php esc_html_e( 'Patterns', 'universal-consent-privacy-framework' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'universal-consent-privacy-framework' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $local_catalog as $svc ) : ?>
						<tr data-key="<?php echo esc_attr( $svc['key'] ); ?>">
							<td><code><?php echo esc_html( $svc['key'] ); ?></code></td>
							<td><?php echo esc_html( $svc['name'] ); ?></td>
							<td><?php echo esc_html( $svc['category'] ); ?></td>
							<td><code><?php echo esc_html( implode( ', ', (array) $svc['script_patterns'] ) ); ?></code></td>
							<td><button type="button" class="button ucpf-sug-remove"><?php esc_html_e( 'Remove', 'universal-consent-privacy-framework' ); ?></button></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
			<p class="description" id="ucpf-sug-status" hidden></p>
		</div>
		<script>
		(function () {
			if (!window.ucpfAdmin) return;
			var statusEl = document.getElementById('ucpf-sug-status');
			function flash(msg) {
				if (!statusEl) return;
				statusEl.hidden = false;
				statusEl.textContent = msg;
			}
			document.querySelectorAll('.ucpf-sug-apply').forEach(function (btn) {
				btn.addEventListener('click', function () {
					var row = btn.closest('tr');
					var host = row.getAttribute('data-host');
					var cat = (row.querySelector('.ucpf-sug-category') || {}).value || 'analytics';
					fetch(ucpfAdmin.restUrl + 'catalog-suggestions/apply', {
						method: 'POST',
						headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ucpfAdmin.nonce },
						body: JSON.stringify({ host: host, category: cat })
					}).then(function (r) { return r.json(); }).then(function (data) {
						flash((data && data.message) || 'Applied.');
						if (data && data.success) { row.querySelector('.ucpf-badge') || row.cells[0].insertAdjacentHTML('beforeend', ' <span class="ucpf-badge">applied</span>'); }
					}).catch(function () { flash('Request failed.'); });
				});
			});
			document.querySelectorAll('.ucpf-sug-copy').forEach(function (btn) {
				btn.addEventListener('click', function () {
					var json = btn.getAttribute('data-json') || '';
					if (navigator.clipboard && navigator.clipboard.writeText) {
						navigator.clipboard.writeText(json).then(function () { flash('JSON copied.'); });
					} else {
						flash('Copy manually from export.');
					}
				});
			});
			document.querySelectorAll('.ucpf-sug-remove').forEach(function (btn) {
				btn.addEventListener('click', function () {
					var row = btn.closest('tr');
					var key = row.getAttribute('data-key');
					fetch(ucpfAdmin.restUrl + 'catalog-suggestions/' + encodeURIComponent(key), {
						method: 'DELETE',
						headers: { 'X-WP-Nonce': ucpfAdmin.nonce }
					}).then(function (r) { return r.json(); }).then(function () {
						row.remove();
						flash('Removed site-local service.');
					});
				});
			});
		})();
		</script>

		<?php
		// Shared cookie review (known + unknown + service treatments) — same as wizard step 8.
		$categories        = \UCPF\Consent_Manager::instance()->get_categories();
		$services          = isset( $services ) && is_array( $services ) ? $services : \UCPF\Script_Registry::instance()->get_services();
		$ucpf_review_mode  = 'scanner';
		include UCPF_PLUGIN_DIR . 'admin/views/partials/cookie-review.php';
		?>

		<?php if ( ! empty( $last_scan['storage'] ) && is_array( $last_scan['storage'] ) ) : ?>
			<h2><?php esc_html_e( 'Storage keys', 'universal-consent-privacy-framework' ); ?></h2>
			<div class="ucpf-table-scroll">
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Kind', 'universal-consent-privacy-framework' ); ?></th>
					<th><?php esc_html_e( 'Key', 'universal-consent-privacy-framework' ); ?></th>
					<th><?php esc_html_e( 'Contexts', 'universal-consent-privacy-framework' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $last_scan['storage'] as $row ) : ?>
					<tr>
						<td><?php echo esc_html( isset( $row['kind'] ) ? $row['kind'] : '' ); ?></td>
						<td><code><?php echo esc_html( isset( $row['key'] ) ? $row['key'] : '' ); ?></code></td>
						<td><?php echo esc_html( isset( $row['contexts'] ) && is_array( $row['contexts'] ) ? implode( ', ', $row['contexts'] ) : '' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		<?php endif; ?>

		<?php
		$signals = isset( $last_scan['privacy_signals'] ) && is_array( $last_scan['privacy_signals'] ) ? $last_scan['privacy_signals'] : array();
		if ( $signals ) :
			?>
			<h2><?php esc_html_e( 'Privacy signals (scripts / requests / iframes)', 'universal-consent-privacy-framework' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Tracking can occur without cookies. Review these hosts and providers alongside the cookie list.', 'universal-consent-privacy-framework' ); ?></p>
			<?php
			foreach ( array( 'scripts' => __( 'Scripts', 'universal-consent-privacy-framework' ), 'iframes' => __( 'Iframes', 'universal-consent-privacy-framework' ), 'beacons' => __( 'Beacons', 'universal-consent-privacy-framework' ), 'pixels' => __( 'Pixels', 'universal-consent-privacy-framework' ) ) as $sig_key => $sig_label ) :
				if ( empty( $signals[ $sig_key ] ) || ! is_array( $signals[ $sig_key ] ) ) {
					continue;
				}
				?>
				<h3><?php echo esc_html( $sig_label ); ?></h3>
				<div class="ucpf-table-scroll">
			<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'Provider', 'universal-consent-privacy-framework' ); ?></th>
						<th><?php esc_html_e( 'Category', 'universal-consent-privacy-framework' ); ?></th>
						<th><?php esc_html_e( 'Importance', 'universal-consent-privacy-framework' ); ?></th>
						<th><?php esc_html_e( 'URL / host', 'universal-consent-privacy-framework' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( array_slice( $signals[ $sig_key ], 0, 40 ) as $sig ) : ?>
						<tr>
							<td><?php echo esc_html( isset( $sig['provider'] ) ? $sig['provider'] : '' ); ?></td>
							<td class="ucpf-cell-cat"><?php echo esc_html( isset( $sig['category'] ) ? $sig['category'] : '' ); ?></td>
							<td class="ucpf-cell-type"><?php echo esc_html( isset( $sig['importance'] ) ? $sig['importance'] : '' ); ?></td>
							<td><code><?php echo esc_html( ! empty( $sig['url'] ) ? $sig['url'] : ( isset( $sig['host'] ) ? $sig['host'] : '' ) ); ?></code></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endforeach; ?>
		<?php endif; ?>

		<?php if ( ! empty( $last_scan['results'] ) ) : ?>
			<h2><?php esc_html_e( 'Script / service matches', 'universal-consent-privacy-framework' ); ?></h2>
			<div class="ucpf-table-scroll">
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Service', 'universal-consent-privacy-framework' ); ?></th>
					<th><?php esc_html_e( 'Pattern', 'universal-consent-privacy-framework' ); ?></th>
					<th><?php esc_html_e( 'Confidence', 'universal-consent-privacy-framework' ); ?></th>
					<th><?php esc_html_e( 'Context', 'universal-consent-privacy-framework' ); ?></th>
					<th><?php esc_html_e( 'Page', 'universal-consent-privacy-framework' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $last_scan['results'] as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['service_name'] ); ?></td>
						<td><code><?php echo esc_html( $row['pattern'] ); ?></code></td>
						<td><?php echo esc_html( $row['confidence'] ); ?></td>
						<td><?php echo esc_html( isset( $row['context'] ) ? $row['context'] : '' ); ?></td>
						<td><?php echo esc_html( $row['page_url'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</div>
