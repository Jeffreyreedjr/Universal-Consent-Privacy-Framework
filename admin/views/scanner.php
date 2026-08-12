<?php
/**
 * Cookie scanner admin view.
 *
 * @package UCPF
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- included template; locals are not plugin globals.

if ( ! isset( $last_scan ) || ! is_array( $last_scan ) ) {
	$last_scan = array();
}

$has_scan   = ! empty( $last_scan['date'] );
$cookie_n   = ! empty( $last_scan['cookies'] ) && is_array( $last_scan['cookies'] ) ? count( $last_scan['cookies'] ) : 0;
$unknown_n  = ! empty( $last_scan['unknown_cookies'] ) && is_array( $last_scan['unknown_cookies'] ) ? count( $last_scan['unknown_cookies'] ) : 0;
$auto_on    = (bool) \UCPF\Settings::get( 'auto_refresh_cookie_policy_after_scan', true );
$policy_url = \UCPF\Page_Generator::instance()->get_page_url( 'cookie_policy' );
$woo_active = \UCPF\Cookie_Scanner::instance()->is_woo_active();
$scanner_ready = (bool) \UCPF\Privacy_Scan_Importer::api_base();
$advanced_url  = admin_url( 'admin.php?page=ucpf-advanced' );
if ( ! isset( $active_scan ) || ! is_array( $active_scan ) ) {
	$active_scan = array( 'active' => false, 'job' => null );
}
$active_job     = ( ! empty( $active_scan['active'] ) && ! empty( $active_scan['job'] ) && is_array( $active_scan['job'] ) ) ? $active_scan['job'] : null;
$active_progress = ( $active_job && ! empty( $active_job['progress'] ) && is_array( $active_job['progress'] ) ) ? $active_job['progress'] : array();
$active_pct      = isset( $active_progress['percent'] ) ? max( 0, min( 100, (int) round( (float) $active_progress['percent'] ) ) ) : 0;
$active_msg      = ! empty( $active_progress['message'] ) ? (string) $active_progress['message'] : ( ! empty( $active_job['message'] ) ? (string) $active_job['message'] : '' );
$active_log      = ( ! empty( $active_progress['log'] ) && is_array( $active_progress['log'] ) ) ? $active_progress['log'] : array();
?>
<div class="wrap ucpf-admin">
	<h1><?php esc_html_e( 'Cookie Scanner', 'universal-consent-privacy-framework' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Two different tools: (1) Playwright scan — real Chromium via the Scanner API on Advanced Settings (or import a local CLI report). (2) WordPress helper — lighter fallback in this browser. Consent coverage below only applies to Playwright. Technical inventory only — not a legal determination.', 'universal-consent-privacy-framework' ); ?></p>
	<?php if ( is_multisite() ) : ?>
		<p class="notice notice-info inline"><strong><?php esc_html_e( 'Multisite:', 'universal-consent-privacy-framework' ); ?></strong> <?php esc_html_e( 'Scans and inventory on this screen apply to this site only. Prefer a distinct Scanner API key per site on a shared scanner host.', 'universal-consent-privacy-framework' ); ?></p>
	<?php endif; ?>
	<p class="description"><?php esc_html_e( 'Cookie descriptions: UCPF service catalog + site knowledge log + bundled Open Cookie Database (offline). Does not call cookiedatabase.org.', 'universal-consent-privacy-framework' ); ?></p>

	<div class="ucpf-cookie-lookup" id="ucpf-cookie-lookup">
		<h2><?php esc_html_e( 'Cookie lookup', 'universal-consent-privacy-framework' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Search the local vendor catalog, this site’s knowledge log, and the Open Cookie Database snapshot. Export knowledge pack includes last scan cookies + review overrides (for your agency hub). Use Contribute for a scrubbed public GitHub pack.', 'universal-consent-privacy-framework' ); ?></p>
		<p class="ucpf-cookie-lookup__row">
			<label class="screen-reader-text" for="ucpf-cookie-lookup-q"><?php esc_html_e( 'Cookie name', 'universal-consent-privacy-framework' ); ?></label>
			<input type="search" class="regular-text" id="ucpf-cookie-lookup-q" placeholder="<?php esc_attr_e( 'e.g. _ga, sbjs_session, VISITOR_INFO1_LIVE', 'universal-consent-privacy-framework' ); ?>" />
			<button type="button" class="button button-primary" id="ucpf-cookie-lookup-go"><?php esc_html_e( 'Search', 'universal-consent-privacy-framework' ); ?></button>
			<button type="button" class="button" id="ucpf-knowledge-export"><?php esc_html_e( 'Export knowledge pack', 'universal-consent-privacy-framework' ); ?></button>
			<button type="button" class="button" id="ucpf-knowledge-import"><?php esc_html_e( 'Import knowledge pack', 'universal-consent-privacy-framework' ); ?></button>
			<input type="file" id="ucpf-knowledge-import-file" accept="application/json,.json" hidden />
		</p>
		<p id="ucpf-cookie-lookup-status" class="description" aria-live="polite"></p>
		<div class="ucpf-table-scroll">
			<table class="widefat striped" id="ucpf-cookie-lookup-table" hidden>
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'universal-consent-privacy-framework' ); ?></th>
						<th><?php esc_html_e( 'Source', 'universal-consent-privacy-framework' ); ?></th>
						<th><?php esc_html_e( 'Provider', 'universal-consent-privacy-framework' ); ?></th>
						<th><?php esc_html_e( 'Category', 'universal-consent-privacy-framework' ); ?></th>
						<th><?php esc_html_e( 'Purpose', 'universal-consent-privacy-framework' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'universal-consent-privacy-framework' ); ?></th>
					</tr>
				</thead>
				<tbody></tbody>
			</table>
		</div>
	</div>

	<div class="ucpf-contribute" id="ucpf-contribute">
		<h2><?php esc_html_e( 'Contribute cookie knowledge', 'universal-consent-privacy-framework' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Help grow the public UCPF catalog. Download a scrubbed, anonymized pack (generalized cookie patterns — never values, site URL, first-party hosts, or property-specific ids like _ga_XXXX). WordPress does not upload anything. You attach the file on GitHub yourself.', 'universal-consent-privacy-framework' ); ?></p>
		<p>
			<label for="ucpf-contribute-consent">
				<input type="checkbox" id="ucpf-contribute-consent" value="1" />
				<?php esc_html_e( 'I confirm this pack has no cookie values, emails, auth tokens, or client domains I am not allowed to share, and I offer it under GPL-2.0-or-later.', 'universal-consent-privacy-framework' ); ?>
			</label>
		</p>
		<p class="ucpf-contribute__actions">
			<button type="button" class="button button-primary" id="ucpf-contribute-download" disabled><?php esc_html_e( 'Download contribution pack', 'universal-consent-privacy-framework' ); ?></button>
			<button type="button" class="button" id="ucpf-contribute-github" disabled><?php esc_html_e( 'Open GitHub issue', 'universal-consent-privacy-framework' ); ?></button>
		</p>
		<p id="ucpf-contribute-status" class="description" aria-live="polite"></p>
	</div>

	<div class="ucpf-scanner-picker" id="ucpf-scanner-picker">
		<h2 class="ucpf-scanner-picker__title"><?php esc_html_e( '1. Pages to scan', 'universal-consent-privacy-framework' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Pick real front-end pages (forms, shop, landing pages). Your selection is remembered for the next scan on this site.', 'universal-consent-privacy-framework' ); ?></p>
		<p id="ucpf-scan-remembered" class="description ucpf-scan-remembered" hidden></p>

		<p class="ucpf-scanner-toolbar">
			<input type="search" id="ucpf-scan-page-filter" class="regular-text" placeholder="<?php esc_attr_e( 'Filter pages…', 'universal-consent-privacy-framework' ); ?>" />
			<button type="button" class="button" id="ucpf-scan-select-visible"><?php esc_html_e( 'Select visible', 'universal-consent-privacy-framework' ); ?></button>
			<?php if ( $woo_active ) : ?>
				<button type="button" class="button" id="ucpf-scan-select-woo"><?php esc_html_e( 'Select WooCommerce pages', 'universal-consent-privacy-framework' ); ?></button>
			<?php endif; ?>
			<button type="button" class="button" id="ucpf-scan-clear"><?php esc_html_e( 'Clear selection', 'universal-consent-privacy-framework' ); ?></button>
			<button type="button" class="button" id="ucpf-scan-rediscover"><?php esc_html_e( 'Rediscover pages', 'universal-consent-privacy-framework' ); ?></button>
		</p>

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

	<div class="ucpf-scanner-coverage" id="ucpf-scanner-coverage"<?php echo $scanner_ready ? '' : ' hidden'; ?>>
		<h2><?php esc_html_e( '2. Consent coverage (Playwright only)', 'universal-consent-privacy-framework' ); ?></h2>
		<p class="ucpf-scan-depth">
			<label for="ucpf-scan-depth"><strong><?php esc_html_e( 'How many consent checks to run', 'universal-consent-privacy-framework' ); ?></strong></label>
			<select id="ucpf-scan-depth"<?php disabled( ! $scanner_ready ); ?>>
				<option value="quick"><?php esc_html_e( 'Light — 2 consent sessions × selected pages (faster)', 'universal-consent-privacy-framework' ); ?></option>
				<option value="standard" selected><?php esc_html_e( 'Standard — 6 sessions × selected pages (core + GPC / DNS)', 'universal-consent-privacy-framework' ); ?></option>
				<option value="deep"><?php esc_html_e( 'Thorough — 10 sessions × selected pages (slowest, fullest checks)', 'universal-consent-privacy-framework' ); ?></option>
			</select>
		</p>
		<p class="description"><?php esc_html_e( 'Coverage is not a separate “scan type.” It only controls how many consent personas Playwright uses on the pages you selected. Each session re-walks those URLs. Speed ≈ pages × coverage — Light + fewer pages is fastest; Thorough is for compliance. Prefer lowering UCPF_SCANNER_SETTLE_MS / PAGE_GAP_MS over raising Chromium concurrency.', 'universal-consent-privacy-framework' ); ?></p>
		<p id="ucpf-scan-selection-hint" class="description ucpf-scan-selection-hint" hidden></p>
	</div>

	<div class="ucpf-scanner-run" id="ucpf-scanner-run">
		<h2><?php echo $scanner_ready ? esc_html__( '3. Run a scan', 'universal-consent-privacy-framework' ) : esc_html__( '2. Run a scan', 'universal-consent-privacy-framework' ); ?></h2>

		<?php if ( $scanner_ready ) : ?>
			<div class="ucpf-scanner-run__primary">
				<h3 class="ucpf-scanner-run__heading"><?php esc_html_e( 'Playwright scan (recommended)', 'universal-consent-privacy-framework' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Calls the Scanner API from Advanced Settings (self-hosted Playwright / Chromium). Uses the pages and consent coverage above. Progress and logs are saved on this WordPress site — you can leave this page and the scan keeps running; reopen Cookie Scanner (or any WP admin screen) to see status. Stop still works when you return.', 'universal-consent-privacy-framework' ); ?></p>
				<p class="notice notice-warning inline"><strong><?php esc_html_e( 'Scanner API + plugin:', 'universal-consent-privacy-framework' ); ?></strong> <?php esc_html_e( 'Multi-page Playwright scans require both this plugin and a redeployed Scanner API (tools/ucpf-scanner) that honors exactPaths. Updating the plugin alone is not enough if the scanner host is still on an older build.', 'universal-consent-privacy-framework' ); ?></p>
				<p>
					<label><input type="checkbox" id="ucpf-playwright-merge-auth" value="1" /> <?php esc_html_e( 'Also capture logged-in cookies after Playwright (helper, homepage once) — optional inventory completeness', 'universal-consent-privacy-framework' ); ?></label>
				</p>
				<p class="description"><?php esc_html_e( 'Playwright stays guest-only for consent proof. When checked, WordPress merges one logged-in HTTP pass into the inventory after import (no WP login inside Chromium).', 'universal-consent-privacy-framework' ); ?></p>
				<p class="ucpf-scanner-run__actions">
					<button type="button" class="button button-primary button-hero" id="ucpf-deep-scan"><?php esc_html_e( 'Run Playwright scan', 'universal-consent-privacy-framework' ); ?></button>
					<button type="button" class="button button-link-delete" id="ucpf-stop-scan"<?php echo $active_job ? '' : ' hidden'; ?>><?php esc_html_e( 'Stop scan', 'universal-consent-privacy-framework' ); ?></button>
				</p>
			</div>
		<?php else : ?>
			<div class="ucpf-scanner-run__setup notice notice-info inline">
				<p>
					<?php
					echo wp_kses(
						sprintf(
							/* translators: %s: Advanced Settings URL */
							__( '<strong>Playwright scan needs the Scanner API.</strong> Set the Scanner API URL (and key) under <a href="%s">Advanced Settings</a>, or run the local CLI and import the report JSON below. Until then, use the WordPress helper scan.', 'universal-consent-privacy-framework' ),
							esc_url( $advanced_url )
						),
						array(
							'strong' => array(),
							'a'      => array(
								'href' => array(),
							),
						)
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<div class="ucpf-scanner-run__fallback">
			<h3 class="ucpf-scanner-run__heading"><?php echo $scanner_ready ? esc_html__( 'WordPress helper (fallback)', 'universal-consent-privacy-framework' ) : esc_html__( 'WordPress helper scan', 'universal-consent-privacy-framework' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Runs in this admin browser (HTTP + limited iframe). Often misses HttpOnly cookies and many JS trackers. Prefer Playwright via the Scanner API or a local CLI import when available.', 'universal-consent-privacy-framework' ); ?></p>
			<p>
				<label><input type="checkbox" id="ucpf-scan-browser" value="1" checked /> <?php esc_html_e( 'Guest browser crawl (loads selected pages as a visitor)', 'universal-consent-privacy-framework' ); ?></label>
			</p>
			<p>
				<label><input type="checkbox" id="ucpf-scan-auth" value="1" /> <?php esc_html_e( 'Also scan homepage as logged-in (admin session) — optional', 'universal-consent-privacy-framework' ); ?></label>
			</p>
			<p class="ucpf-scanner-run__actions">
				<button type="button" class="button<?php echo $scanner_ready ? '' : ' button-primary'; ?>" id="ucpf-run-scan"><?php esc_html_e( 'Run WordPress helper scan', 'universal-consent-privacy-framework' ); ?></button>
			</p>
		</div>

		<div class="ucpf-toolbar ucpf-scanner-run__utils" role="group" aria-label="<?php esc_attr_e( 'Scan utilities', 'universal-consent-privacy-framework' ); ?>">
			<?php if ( $scanner_ready ) : ?>
				<button type="button" class="button" id="ucpf-run-scheduled-scan"><?php esc_html_e( 'Run scheduled scan now', 'universal-consent-privacy-framework' ); ?></button>
			<?php endif; ?>
			<button type="button" class="button" id="ucpf-import-scan-json"><?php esc_html_e( 'Import scan JSON', 'universal-consent-privacy-framework' ); ?></button>
			<button type="button" class="button" id="ucpf-export-scan"><?php esc_html_e( 'Export scan JSON for catalog', 'universal-consent-privacy-framework' ); ?></button>
			<button type="button" class="button" id="ucpf-knowledge-export-toolbar"><?php esc_html_e( 'Export knowledge pack', 'universal-consent-privacy-framework' ); ?></button>
			<?php if ( $has_scan ) : ?>
				<button type="button" class="button" id="ucpf-refresh-cookie-policy"><?php esc_html_e( 'Refresh Cookie Policy now', 'universal-consent-privacy-framework' ); ?></button>
			<?php endif; ?>
			<button type="button" class="button" id="ucpf-live-capture"><?php esc_html_e( 'Admin tab only (debug)', 'universal-consent-privacy-framework' ); ?></button>
		</div>
	</div>
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
	<div id="ucpf-scan-status" class="ucpf-wizard__status"<?php echo $active_job ? '' : ' hidden'; ?>><?php
	if ( $active_job ) {
		echo esc_html(
			sprintf(
				/* translators: %s: remote job id */
				__( 'Playwright scan in progress (job %s). Progress is saved on this site — reconnecting…', 'universal-consent-privacy-framework' ),
				isset( $active_job['job_id'] ) ? (string) $active_job['job_id'] : ''
			)
		);
	}
	?></div>
	<div id="ucpf-scan-progress" class="ucpf-scan-progress"<?php echo $active_job ? '' : ' hidden'; ?>>
		<div class="ucpf-scan-progress__meta">
			<span id="ucpf-scan-progress-pct"><?php echo esc_html( $active_pct . '%' ); ?></span>
			<span id="ucpf-scan-progress-step"></span>
		</div>
		<div class="ucpf-scan-progress__track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) $active_pct ); ?>" id="ucpf-scan-progress-bar">
			<span class="ucpf-scan-progress__fill" style="width:<?php echo esc_attr( (string) $active_pct ); ?>%"></span>
		</div>
		<p id="ucpf-scan-progress-msg" class="ucpf-scan-progress__msg"><?php echo esc_html( $active_msg ? $active_msg : '' ); ?></p>
		<pre id="ucpf-scan-progress-log" class="ucpf-scan-progress__log"<?php echo $active_log ? '' : ' hidden'; ?>><?php
		if ( $active_log ) {
			echo esc_html( implode( "\n", array_slice( $active_log, -12 ) ) );
		}
		?></pre>
		<p class="description ucpf-scan-progress__leave-hint"><?php esc_html_e( 'Safe to leave this page — reopen Cookie Scanner (or any admin screen) to see status. The job keeps running on the scanner host. Browser DevTools console is per-tab and will not mirror progress in another tab.', 'universal-consent-privacy-framework' ); ?></p>
	</div>
	<div id="ucpf-pages-status" class="ucpf-wizard__status" hidden></div>

	<?php if ( ! $has_scan ) : ?>
		<div class="ucpf-scanner-empty">
			<h2><?php esc_html_e( 'No scan stored yet', 'universal-consent-privacy-framework' ); ?></h2>
			<p><?php echo $scanner_ready ? esc_html__( 'Select pages above, choose consent coverage, then run a Playwright scan (or the WordPress helper as a fallback). Results stay on this WordPress site.', 'universal-consent-privacy-framework' ) : esc_html__( 'Select pages above, then run the WordPress helper scan — or set the Scanner API under Advanced Settings for Playwright, or import a local CLI report. Results stay on this WordPress site.', 'universal-consent-privacy-framework' ); ?></p>
			<ol>
				<li><?php esc_html_e( 'Select front-end pages (Home, Contact, shop, forms) — picks are remembered next time.', 'universal-consent-privacy-framework' ); ?></li>
				<?php if ( $scanner_ready ) : ?>
					<li><?php esc_html_e( 'Choose Light / Standard / Thorough consent coverage for Playwright.', 'universal-consent-privacy-framework' ); ?></li>
					<li><?php esc_html_e( 'Click Run Playwright scan (or import a local Playwright report).', 'universal-consent-privacy-framework' ); ?></li>
				<?php else : ?>
					<li><?php esc_html_e( 'Optional: configure Scanner API URL under Advanced Settings to enable Run Playwright scan.', 'universal-consent-privacy-framework' ); ?></li>
					<li><?php esc_html_e( 'Click Run WordPress helper scan, or import a local Playwright report JSON.', 'universal-consent-privacy-framework' ); ?></li>
				<?php endif; ?>
			</ol>
		</div>
	<?php else : ?>
		<?php
		$cf_live     = \UCPF\Cookie_Scanner::instance()->detect_cloudflare_proxy(
			array(
				'cf_challenged' => ! empty( $last_scan['cf_challenged'] ),
				'cookie_hit'    => ! empty( $last_scan['cloudflare_proxied'] ) || ( ! empty( $last_scan['detected_services'] ) && in_array( 'cloudflare', (array) $last_scan['detected_services'], true ) ),
				'fetch_signals' => isset( $last_scan['cloudflare_signals'] ) && is_array( $last_scan['cloudflare_signals'] ) ? $last_scan['cloudflare_signals'] : array(),
			)
		);
		$cf_ok       = ! empty( $last_scan['cloudflare_proxied'] ) || ! empty( $cf_live['proxied'] );
		$cf_signals  = ! empty( $last_scan['cloudflare_signals'] ) && is_array( $last_scan['cloudflare_signals'] ) ? $last_scan['cloudflare_signals'] : ( isset( $cf_live['signals'] ) ? $cf_live['signals'] : array() );
		$tx_meta     = isset( $last_scan['transactional_email'] ) && is_array( $last_scan['transactional_email'] ) ? $last_scan['transactional_email'] : array();
		$tx_ok       = ! empty( $tx_meta['detected'] );
		if ( ! $tx_ok && ! empty( $last_scan['detected_services'] ) && is_array( $last_scan['detected_services'] ) ) {
			$tx_keys = array_merge( array( 'transactional_email', 'gravity_smtp' ), \UCPF\Cookie_Scanner::transactional_provider_keys() );
			foreach ( $last_scan['detected_services'] as $ds ) {
				if ( in_array( $ds, $tx_keys, true ) ) {
					$tx_ok = true;
					break;
				}
			}
		}
		$tx_providers = ! empty( $tx_meta['providers'] ) && is_array( $tx_meta['providers'] ) ? $tx_meta['providers'] : array();
		?>
		<div class="ucpf-infra-status" aria-label="<?php esc_attr_e( 'Infrastructure detection', 'universal-consent-privacy-framework' ); ?>">
			<p class="ucpf-infra-status__row">
				<span class="ucpf-infra-status__mark" aria-hidden="true"><?php echo $cf_ok ? '✓' : '—'; ?></span>
				<strong><?php esc_html_e( 'Cloudflare proxy', 'universal-consent-privacy-framework' ); ?></strong>
				<?php if ( $cf_ok ) : ?>
					<span class="description">
						<?php
						echo esc_html(
							$cf_signals
								? sprintf(
									/* translators: %s: comma-separated detection methods */
									__( 'Detected (%s). Necessary security/CDN — disclose in privacy policy.', 'universal-consent-privacy-framework' ),
									implode( ', ', array_map( 'strval', $cf_signals ) )
								)
								: __( 'Detected. Necessary security/CDN — disclose in privacy policy.', 'universal-consent-privacy-framework' )
						);
						?>
					</span>
				<?php else : ?>
					<span class="description"><?php esc_html_e( 'Not detected on last scan (headers, cookies, NS, or challenge).', 'universal-consent-privacy-framework' ); ?></span>
				<?php endif; ?>
			</p>
			<p class="ucpf-infra-status__row">
				<span class="ucpf-infra-status__mark" aria-hidden="true"><?php echo $tx_ok ? '✓' : '—'; ?></span>
				<strong><?php esc_html_e( 'Transactional email', 'universal-consent-privacy-framework' ); ?></strong>
				<?php if ( $tx_ok ) : ?>
					<span class="description">
						<?php
						if ( $tx_providers ) {
							$labels = array();
							foreach ( $tx_providers as $pk ) {
								$svc = \UCPF\Script_Registry::instance()->get_service( $pk );
								$labels[] = $svc ? $svc['name'] : $pk;
							}
							echo esc_html(
								sprintf(
									/* translators: %s: provider names */
									__( 'Detected — %s (server-side delivery; not a visitor tracker).', 'universal-consent-privacy-framework' ),
									implode( ', ', $labels )
								)
							);
						} else {
							esc_html_e( 'Detected (SMTP plugin / ESP). Server-side delivery; not a visitor tracker.', 'universal-consent-privacy-framework' );
						}
						?>
					</span>
				<?php else : ?>
					<span class="description"><?php esc_html_e( 'No SMTP plugin / Gravity SMTP connector detected yet (WordPress-side — Playwright cannot see outbound email). Re-run helper or Playwright import after configuring SMTP.', 'universal-consent-privacy-framework' ); ?></span>
				<?php endif; ?>
			</p>
		</div>
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
		$delta = ! empty( $last_scan['verify_delta'] ) && is_array( $last_scan['verify_delta'] ) ? $last_scan['verify_delta'] : array();
		$registry_url = admin_url( 'admin.php?page=ucpf-registry' );
		$banner_url   = admin_url( 'admin.php?page=ucpf-banner' );
		?>

		<?php if ( ! empty( $last_scan['findings_summary'] ) || ! empty( $last_scan['consent_leaks'] ) || $score || $dark ) : ?>
			<div class="ucpf-verify-banner notice notice-info inline" id="ucpf-verify-banner">
				<p>
					<strong><?php esc_html_e( 'These findings verify live blocking.', 'universal-consent-privacy-framework' ); ?></strong>
					<?php esc_html_e( 'Fix Cookie review / Script Registry for pre-consent leaks (Loaded before consent), then re-run Playwright. Leftover cookies after revoke are cleanup warnings — not active tracking. The WordPress helper scan is inventory only — it cannot verify blocking.', 'universal-consent-privacy-framework' ); ?>
				</p>
				<p class="description" style="margin:0.35rem 0 0;">
					<?php esc_html_e( 'Re-verify uses a fast Playwright pass (Light sessions on pages from the last scan; Standard only if GPC/DNS fails remain). Use Run Playwright scan below for a full inventory crawl.', 'universal-consent-privacy-framework' ); ?>
				</p>
				<p class="ucpf-verify-banner__actions">
					<?php if ( $scanner_ready ) : ?>
						<button type="button" class="button button-primary" id="ucpf-reverify-playwright"><?php esc_html_e( 'Re-verify (fast Playwright)', 'universal-consent-privacy-framework' ); ?></button>
					<?php else : ?>
						<a class="button" href="<?php echo esc_url( $advanced_url ); ?>"><?php esc_html_e( 'Set Scanner API (Advanced)', 'universal-consent-privacy-framework' ); ?></a>
						<button type="button" class="button" id="ucpf-scroll-import"><?php esc_html_e( 'Import Playwright report', 'universal-consent-privacy-framework' ); ?></button>
					<?php endif; ?>
					<a class="button" href="#ucpf-cookie-review"><?php esc_html_e( 'Open Cookie review', 'universal-consent-privacy-framework' ); ?></a>
				</p>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $delta['has_previous'] ) ) : ?>
			<div class="ucpf-verify-delta" id="ucpf-verify-delta">
				<h2><?php esc_html_e( 'Since last Playwright verify', 'universal-consent-privacy-framework' ); ?></h2>
				<ul>
					<li>
						<?php
						printf(
							/* translators: 1: previous leak count, 2: current leak count */
							esc_html__( 'Consent leaks: %1$d → %2$d', 'universal-consent-privacy-framework' ),
							(int) $delta['previous_leaks'],
							(int) $delta['current_leaks']
						);
						if ( ! empty( $delta['leaks_delta'] ) ) {
							$ld = (int) $delta['leaks_delta'];
							echo ' ';
							echo esc_html( $ld < 0 ? sprintf( /* translators: delta */ __( '(%d)', 'universal-consent-privacy-framework' ), $ld ) : sprintf( /* translators: delta */ __( '(+%d)', 'universal-consent-privacy-framework' ), $ld ) );
						}
						?>
					</li>
					<li>
						<?php
						printf(
							/* translators: 1: previous fail count, 2: current fail count */
							esc_html__( 'Differential fails: %1$d → %2$d', 'universal-consent-privacy-framework' ),
							(int) $delta['previous_fail'],
							(int) $delta['current_fail']
						);
						?>
					</li>
					<?php if ( null !== $delta['previous_score'] && null !== $delta['current_score'] ) : ?>
						<li>
							<?php
							printf(
								/* translators: 1: previous score, 2: current score */
								esc_html__( 'Technical score: %1$d → %2$d', 'universal-consent-privacy-framework' ),
								(int) $delta['previous_score'],
								(int) $delta['current_score']
							);
							?>
						</li>
					<?php endif; ?>
				</ul>
			</div>
		<?php endif; ?>

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
								<th class="ucpf-cell-type"><?php esc_html_e( 'Type', 'universal-consent-privacy-framework' ); ?></th>
								<th class="ucpf-cell-sev"><?php esc_html_e( 'Severity', 'universal-consent-privacy-framework' ); ?></th>
								<th class="ucpf-cell-reason"><?php esc_html_e( 'Description', 'universal-consent-privacy-framework' ); ?></th>
								<th class="ucpf-cell-actions"><?php esc_html_e( 'Fix', 'universal-consent-privacy-framework' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $dark as $issue ) : ?>
								<?php
								$issue_type = isset( $issue['type'] ) ? (string) $issue['type'] : '';
								?>
								<tr>
									<td class="ucpf-cell-verdict"><code><?php echo esc_html( $issue_type ); ?></code></td>
									<td class="ucpf-cell-sev"><?php echo esc_html( isset( $issue['severity'] ) ? $issue['severity'] : '' ); ?></td>
									<td class="ucpf-cell-reason"><?php echo esc_html( isset( $issue['description'] ) ? $issue['description'] : '' ); ?></td>
									<td class="ucpf-cell-actions">
										<?php if ( 'missing-info' === $issue_type ) : ?>
											<a class="button button-small" href="<?php echo esc_url( $banner_url ); ?>"><?php esc_html_e( 'Edit banner copy', 'universal-consent-privacy-framework' ); ?></a>
										<?php elseif ( 'auto-consent' === $issue_type ) : ?>
											<a class="button button-small" href="#ucpf-consent-leaks"><?php esc_html_e( 'Review consent leaks', 'universal-consent-privacy-framework' ); ?></a>
										<?php else : ?>
											—
										<?php endif; ?>
									</td>
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
			$ucpf_fail_codes = array(
				'incorrectly_loaded_before_consent',
				'still_loaded_after_reject',
				'still_loaded_after_dns',
				'still_loaded_after_gpc',
				'category_mismatch',
			);
			$ucpf_disp_fail  = isset( $fs['fail'] ) ? (int) $fs['fail'] : 0;
			$ucpf_disp_warn  = isset( $fs['warn'] ) ? (int) $fs['warn'] : 0;
			$ucpf_disp_info  = isset( $fs['info'] ) ? (int) $fs['info'] : 0;
			$ucpf_disp_total = isset( $fs['total'] ) ? (int) $fs['total'] : 0;
			if ( ! empty( $last_scan['findings'] ) && is_array( $last_scan['findings'] ) ) {
				$ucpf_disp_fail = 0;
				$ucpf_disp_warn = 0;
				$ucpf_disp_info = 0;
				foreach ( $last_scan['findings'] as $ucpf_fr ) {
					$ucpf_fc      = isset( $ucpf_fr['finding'] ) ? (string) $ucpf_fr['finding'] : '';
					$ucpf_fsess   = isset( $ucpf_fr['sessions'] ) && is_array( $ucpf_fr['sessions'] ) ? $ucpf_fr['sessions'] : array();
					$ucpf_freason = isset( $ucpf_fr['reason'] ) ? strtolower( (string) $ucpf_fr['reason'] ) : '';
					if ( 'still_loaded_after_reject' === $ucpf_fc ) {
						$ucpf_rev_only = in_array( 'revoke', $ucpf_fsess, true ) && ! in_array( 'no_consent', $ucpf_fsess, true ) && ! in_array( 'reject_all', $ucpf_fsess, true );
						if ( $ucpf_rev_only || false !== strpos( $ucpf_freason, 'after revoke' ) ) {
							$ucpf_fc = 'retained_after_revoke';
						}
					}
					if ( in_array( $ucpf_fc, $ucpf_fail_codes, true ) ) {
						++$ucpf_disp_fail;
					} elseif ( 'retained_after_revoke' === $ucpf_fc ) {
						++$ucpf_disp_warn;
					} else {
						++$ucpf_disp_info;
					}
				}
				$ucpf_disp_total = count( $last_scan['findings'] );
			}
			$fs_pass   = ( 0 === $ucpf_disp_fail );
			$fs_border = $fs_pass
				? ( $ucpf_disp_warn > 0 ? 'var(--ucpf-admin-warn, #b45309)' : 'var(--ucpf-admin-ok, #0b5cad)' )
				: 'var(--ucpf-admin-fail, #b91c1c)';
			if ( $fs_pass && 0 === $ucpf_disp_warn ) {
				$fs_headline = __( 'Blocking looks good', 'universal-consent-privacy-framework' );
			} elseif ( $fs_pass ) {
				$fs_headline = __( 'Blocking OK — cookie cleanup warnings', 'universal-consent-privacy-framework' );
			} else {
				$fs_headline = __( 'Pre-consent / opt-out issues found', 'universal-consent-privacy-framework' );
			}
			?>
			<div class="ucpf-card ucpf-findings-summary" style="margin:1rem 0;padding:1rem 1.25rem;border-left:4px solid <?php echo esc_attr( $fs_border ); ?>;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Consent differential', 'universal-consent-privacy-framework' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Compares cookies across consent personas (fresh visit, reject, accept, revoke). Critical issues mean cookies appeared when they should not — not “your Network tab is full of trackers.” Technical check only.', 'universal-consent-privacy-framework' ); ?></p>
				<p>
					<strong><?php echo esc_html( $fs_headline ); ?></strong>
					—
					<?php
					if ( $fs_pass ) {
						printf(
							/* translators: 1: warn count, 2: info count, 3: total */
							esc_html__( '%1$d cleanup warnings · %2$d info · %3$d total', 'universal-consent-privacy-framework' ),
							absint( $ucpf_disp_warn ),
							absint( $ucpf_disp_info ),
							absint( $ucpf_disp_total )
						);
					} else {
						printf(
							/* translators: 1: critical fail count, 2: warn count, 3: total */
							esc_html__( '%1$d critical · %2$d cleanup warnings · %3$d total', 'universal-consent-privacy-framework' ),
							absint( $ucpf_disp_fail ),
							absint( $ucpf_disp_warn ),
							absint( $ucpf_disp_total )
						);
					}
					?>
					<?php if ( ! empty( $last_scan['scan_profile'] ) ) : ?>
						<span class="description">(<?php
						$ucpf_profile = sanitize_key( (string) $last_scan['scan_profile'] );
						$ucpf_labels  = array(
							'quick'    => __( 'Light coverage', 'universal-consent-privacy-framework' ),
							'standard' => __( 'Standard coverage', 'universal-consent-privacy-framework' ),
							'deep'     => __( 'Thorough coverage', 'universal-consent-privacy-framework' ),
						);
						$ucpf_label = isset( $ucpf_labels[ $ucpf_profile ] ) ? $ucpf_labels[ $ucpf_profile ] : $ucpf_profile;
						echo esc_html(
							sprintf(
								/* translators: consent coverage label */
								__( 'coverage: %s', 'universal-consent-privacy-framework' ),
								$ucpf_label
							)
						);
						?>)</span>
					<?php endif; ?>
				</p>
				<?php if ( $fs_pass && $ucpf_disp_warn > 0 ) : ?>
					<p class="description" style="margin-bottom:0;"><?php esc_html_e( 'Leftover cookies after Accept → Revoke usually mean the jar was not cleared — not that trackers are still firing. Fix pre-consent leaks first if any appear below.', 'universal-consent-privacy-framework' ); ?></p>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $last_scan['findings'] ) && is_array( $last_scan['findings'] ) ) : ?>
			<h2 class="ucpf-needs-review-title"><?php esc_html_e( 'Differential findings', 'universal-consent-privacy-framework' ); ?></h2>
			<div class="ucpf-table-scroll">
			<table class="widefat ucpf-unknown-table ucpf-findings-table">
				<thead><tr>
					<th class="ucpf-cell-verdict"><?php esc_html_e( 'Verdict', 'universal-consent-privacy-framework' ); ?></th>
					<th class="ucpf-cell-type"><?php esc_html_e( 'Type', 'universal-consent-privacy-framework' ); ?></th>
					<th class="ucpf-cell-name"><?php esc_html_e( 'Name / host', 'universal-consent-privacy-framework' ); ?></th>
					<th class="ucpf-cell-sev"><?php esc_html_e( 'Severity', 'universal-consent-privacy-framework' ); ?></th>
					<th class="ucpf-cell-reason"><?php esc_html_e( 'Reason', 'universal-consent-privacy-framework' ); ?></th>
					<th class="ucpf-cell-actions"><?php esc_html_e( 'Remediation', 'universal-consent-privacy-framework' ); ?></th>
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
				$finding_labels = array(
					'incorrectly_loaded_before_consent' => __( 'Loaded before consent', 'universal-consent-privacy-framework' ),
					'still_loaded_after_reject'         => __( 'Still after reject', 'universal-consent-privacy-framework' ),
					'still_loaded_after_dns'            => __( 'Still with DNS opt-out', 'universal-consent-privacy-framework' ),
					'still_loaded_after_gpc'            => __( 'Still with GPC', 'universal-consent-privacy-framework' ),
					'retained_after_revoke'             => __( 'Left after revoke (cleanup)', 'universal-consent-privacy-framework' ),
					'correctly_loaded_after_accept'     => __( 'OK after accept', 'universal-consent-privacy-framework' ),
					'removed_after_revocation'          => __( 'Cleared on revoke', 'universal-consent-privacy-framework' ),
					'blocked_before_consent'            => __( 'Present before consent (necessary)', 'universal-consent-privacy-framework' ),
					'category_mismatch'                 => __( 'Category mismatch', 'universal-consent-privacy-framework' ),
					'indeterminate'                     => __( 'Indeterminate', 'universal-consent-privacy-framework' ),
				);
				foreach ( $last_scan['findings'] as $finding_row ) :
					$fcode       = isset( $finding_row['finding'] ) ? $finding_row['finding'] : '';
					$fsess       = isset( $finding_row['sessions'] ) && is_array( $finding_row['sessions'] ) ? $finding_row['sessions'] : array();
					$freason_raw = isset( $finding_row['reason'] ) ? (string) $finding_row['reason'] : '';
					if ( 'still_loaded_after_reject' === $fcode ) {
						$rev_only = in_array( 'revoke', $fsess, true ) && ! in_array( 'no_consent', $fsess, true ) && ! in_array( 'reject_all', $fsess, true );
						if ( $rev_only || false !== stripos( $freason_raw, 'after revoke' ) ) {
							$fcode = 'retained_after_revoke';
							if ( '' === $freason_raw || false !== stripos( $freason_raw, 'still present after revoke' ) ) {
								$freason_raw = __( 'Cookie remained after withdraw. Tracking scripts may already be stopped; leftover third-party cookies are common until cleared.', 'universal-consent-privacy-framework' );
							}
						}
					}
					$is_fail   = in_array( $fcode, $fail_codes, true );
					$is_warn   = ( 'retained_after_revoke' === $fcode );
					$row_class = $is_fail ? 'ucpf-row--critical' : ( $is_warn ? 'ucpf-row--warn' : '' );
					$flabel    = isset( $finding_labels[ $fcode ] ) ? $finding_labels[ $fcode ] : $fcode;
					$rem       = \UCPF\Privacy_Scan_Importer::remediation_for_signal(
						isset( $finding_row['type'] ) ? $finding_row['type'] : '',
						isset( $finding_row['name'] ) ? $finding_row['name'] : '',
						isset( $finding_row['provider'] ) ? $finding_row['provider'] : ''
					);
					if ( ! empty( $finding_row['service_key'] ) ) {
						$rem['service_key']  = sanitize_key( $finding_row['service_key'] );
						$rem['service_name'] = ! empty( $finding_row['service_name'] ) ? $finding_row['service_name'] : $rem['service_name'];
						$rem['action']       = ! empty( $finding_row['action'] ) ? $finding_row['action'] : $rem['action'];
					}
					?>
					<tr class="<?php echo esc_attr( $row_class ); ?>">
						<td class="ucpf-cell-verdict"><code title="<?php echo esc_attr( $fcode ); ?>"><?php echo esc_html( $flabel ); ?></code></td>
						<td class="ucpf-cell-type"><?php echo esc_html( isset( $finding_row['type'] ) ? $finding_row['type'] : '' ); ?></td>
						<td class="ucpf-cell-name"><code><?php echo esc_html( isset( $finding_row['name'] ) ? $finding_row['name'] : '' ); ?></code></td>
						<td class="ucpf-cell-sev">
							<?php if ( $is_fail ) : ?>
								<span class="ucpf-badge ucpf-badge--alert"><?php echo esc_html( isset( $finding_row['severity'] ) ? $finding_row['severity'] : 'high' ); ?></span>
							<?php elseif ( $is_warn ) : ?>
								<span class="ucpf-badge ucpf-badge--warn"><?php esc_html_e( 'cleanup', 'universal-consent-privacy-framework' ); ?></span>
							<?php else : ?>
								<span class="ucpf-badge"><?php echo esc_html( isset( $finding_row['severity'] ) ? $finding_row['severity'] : 'info' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="ucpf-cell-reason"><?php echo esc_html( $freason_raw ); ?></td>
						<td class="ucpf-cell-actions">
							<?php if ( $is_warn ) : ?>
								<span class="description"><?php esc_html_e( 'Optional: clear cookies on withdraw', 'universal-consent-privacy-framework' ); ?></span>
							<?php elseif ( ! $is_fail ) : ?>
								—
							<?php elseif ( ! empty( $rem['service_key'] ) ) : ?>
								<a class="button button-small" href="#ucpf-service-<?php echo esc_attr( $rem['service_key'] ); ?>">
									<?php
									echo esc_html(
										! empty( $rem['service_name'] )
											? $rem['service_name']
											: $rem['service_key']
									);
									?>
								</a>
								<?php if ( 'enable_blocking' === $rem['action'] ) : ?>
									<button type="button" class="button button-small button-primary ucpf-enable-blocking" data-service="<?php echo esc_attr( $rem['service_key'] ); ?>"><?php esc_html_e( 'Enable blocking', 'universal-consent-privacy-framework' ); ?></button>
								<?php endif; ?>
							<?php elseif ( 'catalog_suggestion' === $rem['action'] ) : ?>
								<a class="button button-small" href="#ucpf-catalog-suggestions"><?php esc_html_e( 'Add host override', 'universal-consent-privacy-framework' ); ?></a>
							<?php else : ?>
								<a class="button button-small" href="#ucpf-cookie-review"><?php esc_html_e( 'Cookie review', 'universal-consent-privacy-framework' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $last_scan['consent_leaks'] ) && is_array( $last_scan['consent_leaks'] ) ) : ?>
			<?php
			$leak_services = array();
			foreach ( $last_scan['consent_leaks'] as $leak_pre ) {
				$r = \UCPF\Privacy_Scan_Importer::remediation_for_signal(
					isset( $leak_pre['type'] ) ? $leak_pre['type'] : '',
					isset( $leak_pre['name'] ) ? $leak_pre['name'] : '',
					isset( $leak_pre['provider'] ) ? $leak_pre['provider'] : ''
				);
				if ( ! empty( $leak_pre['service_key'] ) ) {
					$r['service_key'] = sanitize_key( $leak_pre['service_key'] );
				}
				if ( ! empty( $r['service_key'] ) && ( empty( $r['blocking_on'] ) || 'enable_blocking' === $r['action'] ) ) {
					$leak_services[ $r['service_key'] ] = true;
				} elseif ( ! empty( $r['service_key'] ) ) {
					$leak_services[ $r['service_key'] ] = isset( $leak_services[ $r['service_key'] ] ) ? $leak_services[ $r['service_key'] ] : false;
				}
			}
			$enable_keys = array_keys( array_filter( $leak_services ) );
			?>
			<h2 class="ucpf-needs-review-title" id="ucpf-consent-leaks"><?php esc_html_e( 'Consent leaks (high priority)', 'universal-consent-privacy-framework' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Consent-required cookies or hosts observed in both no_consent and reject_all. Technical finding only — not a legal determination. Use remediation links, then re-verify with Playwright.', 'universal-consent-privacy-framework' ); ?></p>
			<?php if ( $enable_keys ) : ?>
				<p>
					<button type="button" class="button button-primary" id="ucpf-enable-leak-blocking" data-services="<?php echo esc_attr( wp_json_encode( array_values( $enable_keys ) ) ); ?>">
						<?php
						printf(
							/* translators: %d: service count */
							esc_html__( 'Enable blocking for %d matched service(s)', 'universal-consent-privacy-framework' ),
							count( $enable_keys )
						);
						?>
					</button>
					<span class="description"><?php esc_html_e( 'Sets treatment to consent + blocking on for catalog matches found in this leak list.', 'universal-consent-privacy-framework' ); ?></span>
				</p>
			<?php endif; ?>
			<div class="ucpf-table-scroll">
			<table class="widefat ucpf-unknown-table ucpf-findings-table">
				<thead><tr>
					<th class="ucpf-cell-type"><?php esc_html_e( 'Type', 'universal-consent-privacy-framework' ); ?></th>
					<th class="ucpf-cell-name"><?php esc_html_e( 'Name / host', 'universal-consent-privacy-framework' ); ?></th>
					<th><?php esc_html_e( 'Provider', 'universal-consent-privacy-framework' ); ?></th>
					<th class="ucpf-cell-cat"><?php esc_html_e( 'Category', 'universal-consent-privacy-framework' ); ?></th>
					<th class="ucpf-cell-sev"><?php esc_html_e( 'Severity', 'universal-consent-privacy-framework' ); ?></th>
					<th class="ucpf-cell-reason"><?php esc_html_e( 'Reason', 'universal-consent-privacy-framework' ); ?></th>
					<th class="ucpf-cell-actions"><?php esc_html_e( 'Remediation', 'universal-consent-privacy-framework' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $last_scan['consent_leaks'] as $leak ) : ?>
					<?php
					$rem = \UCPF\Privacy_Scan_Importer::remediation_for_signal(
						isset( $leak['type'] ) ? $leak['type'] : '',
						isset( $leak['name'] ) ? $leak['name'] : '',
						isset( $leak['provider'] ) ? $leak['provider'] : ''
					);
					if ( ! empty( $leak['service_key'] ) ) {
						$rem['service_key']  = sanitize_key( $leak['service_key'] );
						$rem['service_name'] = ! empty( $leak['service_name'] ) ? $leak['service_name'] : $rem['service_name'];
						$rem['action']       = ! empty( $leak['action'] ) ? $leak['action'] : $rem['action'];
						$rem['blocking_on']  = ! empty( $leak['blocking_on'] ) || ! empty( $rem['blocking_on'] );
					}
					?>
					<tr class="ucpf-row--critical">
						<td class="ucpf-cell-type"><?php echo esc_html( isset( $leak['type'] ) ? $leak['type'] : '' ); ?></td>
						<td class="ucpf-cell-name"><code><?php echo esc_html( isset( $leak['name'] ) ? $leak['name'] : '' ); ?></code></td>
						<td><?php echo esc_html( isset( $leak['provider'] ) ? $leak['provider'] : '' ); ?></td>
						<td class="ucpf-cell-cat"><?php echo esc_html( isset( $leak['category'] ) ? $leak['category'] : '' ); ?></td>
						<td class="ucpf-cell-sev"><span class="ucpf-badge ucpf-badge--alert"><?php echo esc_html( isset( $leak['severity'] ) ? $leak['severity'] : 'high' ); ?></span></td>
						<td class="ucpf-cell-reason"><?php echo esc_html( isset( $leak['reason'] ) ? $leak['reason'] : '' ); ?></td>
						<td class="ucpf-cell-actions">
							<?php if ( ! empty( $rem['service_key'] ) ) : ?>
								<a class="button button-small" href="#ucpf-service-<?php echo esc_attr( $rem['service_key'] ); ?>">
									<?php
									echo esc_html(
										! empty( $rem['service_name'] )
											? $rem['service_name']
											: $rem['service_key']
									);
									?>
								</a>
								<?php if ( empty( $rem['blocking_on'] ) || 'enable_blocking' === $rem['action'] ) : ?>
									<button type="button" class="button button-small button-primary ucpf-enable-blocking" data-service="<?php echo esc_attr( $rem['service_key'] ); ?>"><?php esc_html_e( 'Enable blocking', 'universal-consent-privacy-framework' ); ?></button>
								<?php else : ?>
									<span class="description"><?php esc_html_e( 'Blocking on — re-verify', 'universal-consent-privacy-framework' ); ?></span>
								<?php endif; ?>
							<?php elseif ( 'catalog_suggestion' === $rem['action'] ) : ?>
								<a class="button button-small" href="#ucpf-catalog-suggestions"><?php esc_html_e( 'Add host override', 'universal-consent-privacy-framework' ); ?></a>
							<?php else : ?>
								<a class="button button-small" href="<?php echo esc_url( $registry_url ); ?>"><?php esc_html_e( 'Script Registry', 'universal-consent-privacy-framework' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		<?php endif; ?>

		<?php
		$suspicious_scripts = array();
		if ( ! empty( $last_scan['suspicious_scripts'] ) && is_array( $last_scan['suspicious_scripts'] ) ) {
			$suspicious_scripts = $last_scan['suspicious_scripts'];
		}
		$ignored_sus = \UCPF\Suspicion::get_ignored_patterns();
		?>
		<?php if ( ! empty( $suspicious_scripts ) ) : ?>
		<div class="ucpf-card" id="ucpf-suspicious-scripts" style="margin:1.5rem 0;padding:1rem 1.25rem;border-left:4px solid #d63638;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Suspicious scripts (needs review)', 'universal-consent-privacy-framework' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Path/filename heuristics flagged these as tracking-like (e.g. pixel-tracking.js). Apply a site override to keep them gated, or Ignore if they are false positives. They are fail-closed as marketing until resolved.', 'universal-consent-privacy-framework' ); ?>
			</p>
			<div class="ucpf-table-scroll">
			<table class="widefat striped ucpf-unknown-table" id="ucpf-suspicious-scripts-table">
				<thead><tr>
					<th><?php esc_html_e( 'URL / path', 'universal-consent-privacy-framework' ); ?></th>
					<th><?php esc_html_e( 'Suggested', 'universal-consent-privacy-framework' ); ?></th>
					<th><?php esc_html_e( 'Confidence', 'universal-consent-privacy-framework' ); ?></th>
					<th><?php esc_html_e( 'Pattern', 'universal-consent-privacy-framework' ); ?></th>
					<th class="ucpf-cell-actions"><?php esc_html_e( 'Actions', 'universal-consent-privacy-framework' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $suspicious_scripts as $sus ) : ?>
					<?php
					$url     = isset( $sus['url'] ) ? (string) $sus['url'] : '';
					$pattern = isset( $sus['pattern'] ) ? (string) $sus['pattern'] : \UCPF\Suspicion::suggest_pattern_from_url( $url );
					$cat     = ! empty( $sus['suggested_category'] ) ? (string) $sus['suggested_category'] : 'marketing';
					if ( $pattern && in_array( strtolower( $pattern ), $ignored_sus, true ) ) {
						continue;
					}
					?>
					<tr data-url="<?php echo esc_attr( $url ); ?>" data-pattern="<?php echo esc_attr( $pattern ); ?>" data-category="<?php echo esc_attr( $cat ); ?>" data-label="<?php echo esc_attr( isset( $sus['provider'] ) ? (string) $sus['provider'] : '' ); ?>">
						<td><code style="word-break:break-all;"><?php echo esc_html( $url ); ?></code>
							<?php if ( ! empty( $sus['note'] ) ) : ?>
								<br /><span class="description"><?php echo esc_html( (string) $sus['note'] ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<select class="ucpf-sus-category">
								<?php foreach ( array( 'marketing', 'analytics', 'preferences', 'functional', 'security' ) as $opt ) : ?>
									<option value="<?php echo esc_attr( $opt ); ?>" <?php selected( $cat, $opt ); ?>><?php echo esc_html( $opt ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						<td><?php echo esc_html( isset( $sus['suspicion'] ) ? (string) $sus['suspicion'] : 'medium' ); ?></td>
						<td><code><?php echo esc_html( $pattern ); ?></code></td>
						<td class="ucpf-cell-actions">
							<button type="button" class="button button-primary ucpf-sus-apply"><?php esc_html_e( 'Apply override', 'universal-consent-privacy-framework' ); ?></button>
							<button type="button" class="button ucpf-sus-ignore"><?php esc_html_e( 'Ignore', 'universal-consent-privacy-framework' ); ?></button>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<p class="description" id="ucpf-sus-status" hidden></p>
		</div>
		<script>
		(function () {
			if (!window.ucpfAdmin) return;
			var statusEl = document.getElementById('ucpf-sus-status');
			function flash(msg) {
				if (!statusEl) return;
				statusEl.hidden = false;
				statusEl.textContent = msg;
			}
			function postSus(body, row) {
				fetch(ucpfAdmin.restUrl + 'catalog-suggestions/apply', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ucpfAdmin.nonce },
					body: JSON.stringify(body)
				}).then(function (r) { return r.json(); }).then(function (data) {
					flash((data && data.message) || 'Done.');
					if (data && data.success && row) {
						row.style.opacity = '0.45';
					}
				}).catch(function () { flash('Request failed.'); });
			}
			document.querySelectorAll('.ucpf-sus-apply').forEach(function (btn) {
				btn.addEventListener('click', function () {
					var row = btn.closest('tr');
					postSus({
						url: row.getAttribute('data-url'),
						pattern: row.getAttribute('data-pattern'),
						category: (row.querySelector('.ucpf-sus-category') || {}).value || 'marketing',
						label: row.getAttribute('data-label') || '',
						action: 'apply'
					}, row);
				});
			});
			document.querySelectorAll('.ucpf-sus-ignore').forEach(function (btn) {
				btn.addEventListener('click', function () {
					var row = btn.closest('tr');
					postSus({
						pattern: row.getAttribute('data-pattern'),
						url: row.getAttribute('data-url'),
						action: 'ignore'
					}, row);
				});
			});
		})();
		</script>
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
			<table class="widefat striped ucpf-unknown-table ucpf-catalog-suggestions-table" id="ucpf-catalog-suggestions">
					<thead><tr>
						<th class="ucpf-cell-host"><?php esc_html_e( 'Host', 'universal-consent-privacy-framework' ); ?></th>
						<th class="ucpf-cell-cat"><?php esc_html_e( 'Suggested category', 'universal-consent-privacy-framework' ); ?></th>
						<th><?php esc_html_e( 'Sources', 'universal-consent-privacy-framework' ); ?></th>
						<th class="ucpf-cell-actions"><?php esc_html_e( 'Actions', 'universal-consent-privacy-framework' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $catalog_suggestions as $sug ) : ?>
						<tr data-host="<?php echo esc_attr( $sug['host'] ); ?>" data-category="<?php echo esc_attr( $sug['category'] ); ?>">
							<td class="ucpf-cell-host"><code><?php echo esc_html( $sug['host'] ); ?></code>
								<?php if ( ! empty( $sug['applied'] ) ) : ?>
									<span class="ucpf-badge"><?php esc_html_e( 'applied', 'universal-consent-privacy-framework' ); ?></span>
								<?php endif; ?>
							</td>
							<td class="ucpf-cell-cat">
								<select class="ucpf-sug-category">
									<?php foreach ( array( 'analytics', 'marketing', 'preferences', 'functional' ) as $cat ) : ?>
										<option value="<?php echo esc_attr( $cat ); ?>" <?php selected( $sug['category'], $cat ); ?>><?php echo esc_html( $cat ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td><?php echo esc_html( implode( ', ', (array) $sug['sources'] ) ); ?></td>
							<td class="ucpf-cell-actions">
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
						<th class="ucpf-cell-host"><?php esc_html_e( 'Key', 'universal-consent-privacy-framework' ); ?></th>
						<th><?php esc_html_e( 'Name', 'universal-consent-privacy-framework' ); ?></th>
						<th class="ucpf-cell-cat"><?php esc_html_e( 'Category', 'universal-consent-privacy-framework' ); ?></th>
						<th class="ucpf-cell-name"><?php esc_html_e( 'Patterns', 'universal-consent-privacy-framework' ); ?></th>
						<th class="ucpf-cell-actions"><?php esc_html_e( 'Actions', 'universal-consent-privacy-framework' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $local_catalog as $svc ) : ?>
						<tr data-key="<?php echo esc_attr( $svc['key'] ); ?>">
							<td class="ucpf-cell-host"><code><?php echo esc_html( $svc['key'] ); ?></code></td>
							<td><?php echo esc_html( $svc['name'] ); ?></td>
							<td class="ucpf-cell-cat"><?php echo esc_html( $svc['category'] ); ?></td>
							<td class="ucpf-cell-name"><code><?php echo esc_html( implode( ', ', (array) $svc['script_patterns'] ) ); ?></code></td>
							<td class="ucpf-cell-actions"><button type="button" class="button ucpf-sug-remove"><?php esc_html_e( 'Remove', 'universal-consent-privacy-framework' ); ?></button></td>
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
						<th class="ucpf-cell-cat"><?php esc_html_e( 'Category', 'universal-consent-privacy-framework' ); ?></th>
						<th class="ucpf-cell-type"><?php esc_html_e( 'Importance', 'universal-consent-privacy-framework' ); ?></th>
						<th class="ucpf-cell-name"><?php esc_html_e( 'URL / host', 'universal-consent-privacy-framework' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( array_slice( $signals[ $sig_key ], 0, 40 ) as $sig ) : ?>
						<tr>
							<td><?php echo esc_html( isset( $sig['provider'] ) ? $sig['provider'] : '' ); ?></td>
							<td class="ucpf-cell-cat"><?php echo esc_html( isset( $sig['category'] ) ? $sig['category'] : '' ); ?></td>
							<td class="ucpf-cell-type"><?php echo esc_html( isset( $sig['importance'] ) ? $sig['importance'] : '' ); ?></td>
							<td class="ucpf-cell-name"><code><?php echo esc_html( ! empty( $sig['url'] ) ? $sig['url'] : ( isset( $sig['host'] ) ? $sig['host'] : '' ) ); ?></code></td>
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
					<th class="ucpf-cell-name"><?php esc_html_e( 'Pattern', 'universal-consent-privacy-framework' ); ?></th>
					<th class="ucpf-cell-type"><?php esc_html_e( 'Confidence', 'universal-consent-privacy-framework' ); ?></th>
					<th class="ucpf-cell-type"><?php esc_html_e( 'Context', 'universal-consent-privacy-framework' ); ?></th>
					<th class="ucpf-cell-name"><?php esc_html_e( 'Page', 'universal-consent-privacy-framework' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $last_scan['results'] as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['service_name'] ); ?></td>
						<td class="ucpf-cell-name"><code><?php echo esc_html( $row['pattern'] ); ?></code></td>
						<td class="ucpf-cell-type"><?php echo esc_html( $row['confidence'] ); ?></td>
						<td class="ucpf-cell-type"><?php echo esc_html( isset( $row['context'] ) ? $row['context'] : '' ); ?></td>
						<td class="ucpf-cell-name"><?php echo esc_html( $row['page_url'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</div>
