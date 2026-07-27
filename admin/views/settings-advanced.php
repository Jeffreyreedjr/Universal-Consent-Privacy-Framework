<?php
/**
 * Advanced settings view.
 *
 * @package UCPF
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- included template; locals are not plugin globals.

if ( ! isset( $settings ) || ! is_array( $settings ) ) {
	$settings = \UCPF\Settings::all();
}

$option_key = \UCPF\Settings::OPTION_KEY;
$ob         = ! empty( $settings['output_buffer_blocking'] );
$remote_on  = ! empty( $settings['remote_registry_enabled'] );
$remote_url = isset( $settings['remote_registry_url'] ) ? (string) $settings['remote_registry_url'] : '';
$log_on     = ! empty( $settings['consent_logging'] );
$log_days   = isset( $settings['log_retention_days'] ) ? (int) $settings['log_retention_days'] : 360;
$uninstall  = ! empty( $settings['delete_data_on_uninstall'] );
$scanner_url = isset( $settings['scanner_api_url'] ) ? (string) $settings['scanner_api_url'] : '';
$scanner_key = isset( $settings['scanner_api_key'] ) ? (string) $settings['scanner_api_key'] : '';
$registry_mode = isset( $settings['registry_mode'] ) ? (string) $settings['registry_mode'] : 'local';
$privacy_api_url = isset( $settings['privacy_api_url'] ) ? (string) $settings['privacy_api_url'] : '';
$privacy_api_key = isset( $settings['privacy_api_key'] ) ? (string) $settings['privacy_api_key'] : '';
$privacy_controller = isset( $settings['privacy_controller_id'] ) ? (string) $settings['privacy_controller_id'] : '';
$gpc_enforcement = isset( $settings['gpc_enforcement'] ) ? (string) $settings['gpc_enforcement'] : 'nonessential';
$privacy_fail_closed = ! isset( $settings['privacy_fail_closed'] ) || ! empty( $settings['privacy_fail_closed'] );
$geo_routing = ! empty( $settings['geo_jurisdiction_routing'] );
$ob_safe = ! empty( $settings['output_buffer_safe_iframes'] );
$compliance_mode = isset( $settings['compliance_mode'] ) ? (string) $settings['compliance_mode'] : 'strict_gdpr';
$packs = \UCPF\Jurisdiction::instance()->get_packs();
$sched_on    = ! empty( $settings['scheduled_scan_enabled'] );
$sched_auto  = ! empty( $settings['scheduled_scan_auto_apply'] );
$sched_int   = isset( $settings['scheduled_scan_interval'] ) ? (string) $settings['scheduled_scan_interval'] : 'monthly';
$sched_paths = isset( $settings['scheduled_scan_paths'] ) ? (string) $settings['scheduled_scan_paths'] : '/';
$sched_email = isset( $settings['scheduled_scan_notify_email'] ) ? (string) $settings['scheduled_scan_notify_email'] : '';
$sched_last  = isset( $settings['scheduled_scan_last_status'] ) && is_array( $settings['scheduled_scan_last_status'] ) ? $settings['scheduled_scan_last_status'] : array();
if ( '' === $sched_email ) {
	$sched_email = (string) get_option( 'admin_email' );
}
?>
<div class="wrap ucpf-admin">
	<h1><?php esc_html_e( 'Advanced Settings', 'universal-consent-privacy-framework' ); ?></h1>
	<form method="post" action="options.php">
		<?php settings_fields( 'ucpf_settings_group' ); ?>
		<input type="hidden" name="<?php echo esc_attr( $option_key ); ?>[_ucpf_advanced_form]" value="1" />
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Deep privacy scanner', 'universal-consent-privacy-framework' ); ?></th>
				<td>
					<p>
						<label for="ucpf-scanner-api-url"><?php esc_html_e( 'Scanner API URL', 'universal-consent-privacy-framework' ); ?></label><br />
						<input type="url" class="regular-text" id="ucpf-scanner-api-url" name="<?php echo esc_attr( $option_key ); ?>[scanner_api_url]" value="<?php echo esc_attr( $scanner_url ); ?>" placeholder="https://scanner.example.com" />
					</p>
					<p>
						<label for="ucpf-scanner-api-key"><?php esc_html_e( 'Scanner API key', 'universal-consent-privacy-framework' ); ?></label><br />
						<input type="password" class="regular-text" id="ucpf-scanner-api-key" name="<?php echo esc_attr( $option_key ); ?>[scanner_api_key]" value="<?php echo esc_attr( $scanner_key ); ?>" autocomplete="new-password" />
					</p>
					<p class="description"><?php esc_html_e( 'Optional self-hosted Playwright scanner (HTTPS JSON only — no remote executable code). Leave blank to use local CLI import instead. Prefer defining UCPF_SCANNER_API_KEY in wp-config.php on production.', 'universal-consent-privacy-framework' ); ?></p>
					<p class="description">
						<?php
						printf(
							/* translators: %s: local|agency */
							esc_html__( 'Active scanner mode: %s. See docs/PRIVACY-BEHAVIOR-SCANNER.md (Local CLI | Agency URL | Community later).', 'universal-consent-privacy-framework' ),
							esc_html( \UCPF\Agency_Scanner::scanner_mode() )
						);
						?>
					</p>
					<p class="description"><?php esc_html_e( 'Cookie descriptions use the UCPF catalog plus a bundled Open Cookie Database snapshot (offline — no phone-home).', 'universal-consent-privacy-framework' ); ?></p>
					<p>
						<label for="ucpf-registry-mode"><?php esc_html_e( 'Intelligence registry mode', 'universal-consent-privacy-framework' ); ?></label><br />
						<select id="ucpf-registry-mode" name="<?php echo esc_attr( $option_key ); ?>[registry_mode]">
							<option value="local" <?php selected( $registry_mode, 'local' ); ?>><?php esc_html_e( 'Local only (default)', 'universal-consent-privacy-framework' ); ?></option>
							<option value="agency" <?php selected( $registry_mode, 'agency' ); ?>><?php esc_html_e( 'Agency (private URL)', 'universal-consent-privacy-framework' ); ?></option>
							<option value="community" <?php selected( $registry_mode, 'community' ); ?>><?php esc_html_e( 'Community (requires enable below)', 'universal-consent-privacy-framework' ); ?></option>
							<option value="disabled" <?php selected( $registry_mode, 'disabled' ); ?>><?php esc_html_e( 'Disabled', 'universal-consent-privacy-framework' ); ?></option>
						</select>
					</p>
					<p class="description"><?php esc_html_e( 'Override with UCPF_REGISTRY_MODE in wp-config.php. Community never phones home unless Remote registry is also enabled. Catalogs are data/rules only — never remote executable code.', 'universal-consent-privacy-framework' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Cross-site privacy enforcement', 'universal-consent-privacy-framework' ); ?></th>
				<td>
					<p class="description"><?php esc_html_e( 'GPC (Sec-GPC) is detected on every request and blocks applicable tags before they run. Do Not Sell forms set a first-party opt-out on this site. Optional agency Privacy API is off by default (no phone-home).', 'universal-consent-privacy-framework' ); ?></p>
					<p>
						<label for="ucpf-gpc-enforcement"><?php esc_html_e( 'When GPC is present', 'universal-consent-privacy-framework' ); ?></label><br />
						<select id="ucpf-gpc-enforcement" name="<?php echo esc_attr( $option_key ); ?>[gpc_enforcement]">
							<option value="nonessential" <?php selected( $gpc_enforcement, 'nonessential' ); ?>><?php esc_html_e( 'Block all nonessential tracking (recommended)', 'universal-consent-privacy-framework' ); ?></option>
							<option value="sale_share" <?php selected( $gpc_enforcement, 'sale_share' ); ?>><?php esc_html_e( 'Block sale / share / targeted advertising only', 'universal-consent-privacy-framework' ); ?></option>
						</select>
					</p>
					<p>
						<label for="ucpf-privacy-controller"><?php esc_html_e( 'Controller ID (same business)', 'universal-consent-privacy-framework' ); ?></label><br />
						<input type="text" class="regular-text" id="ucpf-privacy-controller" name="<?php echo esc_attr( $option_key ); ?>[privacy_controller_id]" value="<?php echo esc_attr( $privacy_controller ); ?>" placeholder="acme-media" />
					</p>
					<p>
						<label for="ucpf-privacy-api-url"><?php esc_html_e( 'Privacy Preference API URL (optional)', 'universal-consent-privacy-framework' ); ?></label><br />
						<input type="url" class="regular-text" id="ucpf-privacy-api-url" name="<?php echo esc_attr( $option_key ); ?>[privacy_api_url]" value="<?php echo esc_attr( $privacy_api_url ); ?>" placeholder="https://privacy-api.example.com" />
					</p>
					<p>
						<label for="ucpf-privacy-api-key"><?php esc_html_e( 'Privacy API key', 'universal-consent-privacy-framework' ); ?></label><br />
						<input type="password" class="regular-text" id="ucpf-privacy-api-key" name="<?php echo esc_attr( $option_key ); ?>[privacy_api_key]" value="<?php echo esc_attr( $privacy_api_key ); ?>" autocomplete="new-password" />
					</p>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $option_key ); ?>[privacy_fail_closed]" value="1" <?php checked( $privacy_fail_closed ); ?> />
						<?php esc_html_e( 'Fail closed for marketing when the Privacy API is unreachable', 'universal-consent-privacy-framework' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Nginx tip: map $http_sec_gpc $ucpf_gpc { default 0; "1" 1; } and pass fastcgi_param UCPF_GPC $ucpf_gpc; See docs/PRIVACY-PREFERENCE-ENFORCEMENT.md.', 'universal-consent-privacy-framework' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Jurisdiction packs', 'universal-consent-privacy-framework' ); ?></th>
				<td>
					<p>
						<label for="ucpf-compliance-mode"><?php esc_html_e( 'Default pack / compliance mode', 'universal-consent-privacy-framework' ); ?></label><br />
						<select id="ucpf-compliance-mode" name="<?php echo esc_attr( $option_key ); ?>[compliance_mode]">
							<?php foreach ( $packs as $pid => $ppack ) : ?>
								<option value="<?php echo esc_attr( $pid ); ?>" <?php selected( $compliance_mode, $pid ); ?>>
									<?php echo esc_html( isset( $ppack['label'] ) ? $ppack['label'] : $pid ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</p>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $option_key ); ?>[geo_jurisdiction_routing]" value="1" <?php checked( $geo_routing ); ?> />
						<?php esc_html_e( 'Enable geo pack routing (Cloudflare CF-IPCountry + ucpf_visitor_region filter)', 'universal-consent-privacy-framework' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Off by default. When on, visitor country can select a more specific pack (e.g. US → us_baseline). No paid GeoIP SaaS. Packs change UX/signals only — not a legal guarantee.', 'universal-consent-privacy-framework' ); ?></p>
					<p>
						<button type="button" class="button" id="ucpf-agency-preset"><?php esc_html_e( 'Apply recommended defaults (strict GDPR)', 'universal-consent-privacy-framework' ); ?></button>
					</p>
					<p class="description" id="ucpf-agency-preset-status" hidden></p>
					<script>
					(function () {
						var btn = document.getElementById('ucpf-agency-preset');
						if (!btn || !window.ucpfAdmin) return;
						btn.addEventListener('click', function () {
							fetch(ucpfAdmin.restUrl + 'agency-preset', {
								method: 'POST',
								headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ucpfAdmin.nonce },
								credentials: 'same-origin',
								body: '{}'
							}).then(function (r) { return r.json(); }).then(function (res) {
								var el = document.getElementById('ucpf-agency-preset-status');
								if (el) {
									el.hidden = false;
									el.textContent = (res && res.message) ? res.message : 'Preset applied.';
								}
							});
						});
					})();
					</script>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Scheduled deep scan', 'universal-consent-privacy-framework' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $option_key ); ?>[scheduled_scan_enabled]" value="1" <?php checked( $sched_on ); ?> />
						<?php esc_html_e( 'Run Deep privacy scan automatically on this site', 'universal-consent-privacy-framework' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Uses WP-Cron. Low-traffic sites should ping wp-cron.php via real server cron so scans are not delayed. Technical inventory only — not a compliance guarantee.', 'universal-consent-privacy-framework' ); ?></p>
					<p>
						<label for="ucpf-scheduled-scan-interval"><?php esc_html_e( 'Interval', 'universal-consent-privacy-framework' ); ?></label><br />
						<select id="ucpf-scheduled-scan-interval" name="<?php echo esc_attr( $option_key ); ?>[scheduled_scan_interval]">
							<option value="monthly" <?php selected( $sched_int, 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'universal-consent-privacy-framework' ); ?></option>
							<option value="weekly" <?php selected( $sched_int, 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'universal-consent-privacy-framework' ); ?></option>
						</select>
					</p>
					<p>
						<label for="ucpf-scheduled-scan-paths"><?php esc_html_e( 'Paths (comma-separated)', 'universal-consent-privacy-framework' ); ?></label><br />
						<input type="text" class="regular-text" id="ucpf-scheduled-scan-paths" name="<?php echo esc_attr( $option_key ); ?>[scheduled_scan_paths]" value="<?php echo esc_attr( $sched_paths ); ?>" placeholder="/,/contact,/about" />
					</p>
					<p>
						<label for="ucpf-scheduled-scan-email"><?php esc_html_e( 'Notify emails (comma-separated)', 'universal-consent-privacy-framework' ); ?></label><br />
						<input type="text" class="regular-text" id="ucpf-scheduled-scan-email" name="<?php echo esc_attr( $option_key ); ?>[scheduled_scan_notify_email]" value="<?php echo esc_attr( $sched_email ); ?>" />
					</p>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $option_key ); ?>[scheduled_scan_auto_apply]" value="1" <?php checked( $sched_auto ); ?> />
						<?php esc_html_e( 'Safe auto-apply: select known services (with existing IDs) and refresh Cookie Policy', 'universal-consent-privacy-framework' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Never auto-classifies unknown cookies. Emails only when unknowns, new consent leaks, or a scan failure need review.', 'universal-consent-privacy-framework' ); ?></p>
					<p>
						<button type="button" class="button" id="ucpf-run-scheduled-scan"><?php esc_html_e( 'Run scheduled scan now', 'universal-consent-privacy-framework' ); ?></button>
						<span id="ucpf-scheduled-scan-status" class="ucpf-wizard__status" hidden></span>
					</p>
					<?php if ( $sched_last ) : ?>
						<p class="description">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: status, 2: datetime */
									__( 'Last run: %1$s — %2$s', 'universal-consent-privacy-framework' ),
									isset( $sched_last['state'] ) ? $sched_last['state'] : '—',
									isset( $sched_last['finished'] ) ? $sched_last['finished'] : ( isset( $sched_last['started'] ) ? $sched_last['started'] : '—' )
								)
							);
							if ( ! empty( $sched_last['message'] ) ) {
								echo ' — ' . esc_html( (string) $sched_last['message'] );
							}
							?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Output buffer blocking', 'universal-consent-privacy-framework' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $option_key ); ?>[output_buffer_blocking]" value="1" <?php checked( $ob ); ?> />
						<?php esc_html_e( 'Enable advanced HTML rewriting (may break themes)', 'universal-consent-privacy-framework' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Off by default. Full-page HTML rewrite can cause issues with Elementor and page builders.', 'universal-consent-privacy-framework' ); ?></p>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $option_key ); ?>[output_buffer_safe_iframes]" value="1" <?php checked( $ob_safe ); ?> />
						<?php esc_html_e( 'Safe iframe mode: when OB is on, only rewrite known embed hosts (YouTube, Vimeo, Google Maps)', 'universal-consent-privacy-framework' ); ?>
					</label>
				</td>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Remote registry', 'universal-consent-privacy-framework' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $option_key ); ?>[remote_registry_enabled]" value="1" <?php checked( $remote_on ); ?> />
						<?php esc_html_e( 'Enable optional remote metadata sync (admin opt-in)', 'universal-consent-privacy-framework' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Leave off unless you intentionally sync a remote JSON catalog. Local catalog updates ship in the plugin zip.', 'universal-consent-privacy-framework' ); ?></p>
					<p>
						<input type="url" class="regular-text" name="<?php echo esc_attr( $option_key ); ?>[remote_registry_url]" value="<?php echo esc_attr( $remote_url ); ?>" placeholder="https://example.com/registry.json" />
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Consent logging', 'universal-consent-privacy-framework' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $option_key ); ?>[consent_logging]" value="1" <?php checked( $log_on ); ?> />
						<?php esc_html_e( 'Enable consent logging', 'universal-consent-privacy-framework' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Stores consent UUID, action, categories, and timestamps only — not IP addresses.', 'universal-consent-privacy-framework' ); ?></p>
					<label>
						<?php esc_html_e( 'Retention days', 'universal-consent-privacy-framework' ); ?>
						<input type="number" name="<?php echo esc_attr( $option_key ); ?>[log_retention_days]" value="<?php echo esc_attr( (string) max( 1, min( 3650, $log_days ) ) ); ?>" min="1" max="3650" />
					</label>
					<p class="description"><?php esc_html_e( 'Default 360 days. Consent logs are light (no IP). Changing this updates expiry on existing rows.', 'universal-consent-privacy-framework' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Uninstall', 'universal-consent-privacy-framework' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $option_key ); ?>[delete_data_on_uninstall]" value="1" <?php checked( $uninstall ); ?> />
						<?php esc_html_e( 'Delete plugin data on uninstall', 'universal-consent-privacy-framework' ); ?>
					</label>
				</td>
			</tr>
		</table>
		<?php submit_button(); ?>
	</form>
</div>
