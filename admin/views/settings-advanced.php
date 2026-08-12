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
// Site form: show overrides only (blank = inherit network on multisite).
$scanner_url = is_multisite()
	? (string) \UCPF\Network_Settings::site_override_value( 'scanner_api_url' )
	: ( isset( $settings['scanner_api_url'] ) ? (string) $settings['scanner_api_url'] : '' );
$scanner_key_set = \UCPF\Settings::secret_is_set( 'scanner_api_key' );
$registry_mode = is_multisite()
	? (string) \UCPF\Network_Settings::site_override_value( 'registry_mode' )
	: ( isset( $settings['registry_mode'] ) ? (string) $settings['registry_mode'] : 'local' );
if ( ! is_multisite() && '' === $registry_mode ) {
	$registry_mode = 'local';
}
$privacy_api_url = is_multisite()
	? (string) \UCPF\Network_Settings::site_override_value( 'privacy_api_url' )
	: ( isset( $settings['privacy_api_url'] ) ? (string) $settings['privacy_api_url'] : '' );
$privacy_api_key_set = \UCPF\Settings::secret_is_set( 'privacy_api_key' );
$privacy_controller = is_multisite()
	? (string) \UCPF\Network_Settings::site_override_value( 'privacy_controller_id' )
	: ( isset( $settings['privacy_controller_id'] ) ? (string) $settings['privacy_controller_id'] : '' );
$gpc_enforcement = isset( $settings['gpc_enforcement'] ) ? (string) $settings['gpc_enforcement'] : 'nonessential';
$privacy_fail_closed = ! isset( $settings['privacy_fail_closed'] ) || ! empty( $settings['privacy_fail_closed'] );
if ( is_multisite() && ! \UCPF\Network_Settings::site_has_override( 'privacy_fail_closed' ) ) {
	$privacy_fail_closed = (bool) \UCPF\Settings::get( 'privacy_fail_closed', true );
}
if ( is_multisite() && ! \UCPF\Network_Settings::site_has_override( 'remote_registry_enabled' ) ) {
	$remote_on = (bool) \UCPF\Settings::get( 'remote_registry_enabled', false );
}
if ( is_multisite() ) {
	$remote_ov = \UCPF\Network_Settings::site_override_value( 'remote_registry_url' );
	$remote_url = (string) $remote_ov;
}
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
$cf_purge_on     = ! empty( $settings['cloudflare_purge_enabled'] );
$cf_domain       = isset( $settings['cloudflare_domain'] ) ? (string) $settings['cloudflare_domain'] : '';
if ( '' === $cf_domain ) {
	$cf_domain = \UCPF\Cloudflare_Cache::default_domain();
}
$cf_zone         = isset( $settings['cloudflare_zone_id'] ) ? (string) $settings['cloudflare_zone_id'] : '';
$cf_token_set    = \UCPF\Settings::secret_is_set( 'cloudflare_api_token' );
$cf_on_updates   = ! isset( $settings['cloudflare_purge_on_updates'] ) || ! empty( $settings['cloudflare_purge_on_updates'] );
$cf_on_ucpf      = ! isset( $settings['cloudflare_purge_on_ucpf_update'] ) || ! empty( $settings['cloudflare_purge_on_ucpf_update'] );
$el_clear_css    = ! isset( $settings['elementor_clear_css_on_updates'] ) || ! empty( $settings['elementor_clear_css_on_updates'] );
$cf_last         = \UCPF\Cloudflare_Cache::instance()->get_last_status();

$ucpf_net_hint = static function ( $key ) {
	if ( ! is_multisite() || \UCPF\Network_Settings::site_has_override( $key ) ) {
		return '';
	}
	if ( ! \UCPF\Network_Settings::has( $key ) && ! ( \UCPF\Secrets::is_secret_key( $key ) && \UCPF\Network_Settings::secret_is_set( $key ) ) ) {
		$eff = \UCPF\Settings::get( $key, null );
		if ( null === $eff || '' === $eff || false === $eff ) {
			return '';
		}
	}
	$label = __( 'Using network default', 'universal-consent-privacy-framework' );
	$eff   = \UCPF\Settings::get( $key, '' );
	if ( \UCPF\Secrets::is_secret_key( $key ) ) {
		return '<p class="description"><em>' . esc_html( $label ) . '</em> — ' . esc_html__( 'key is set at the network level (leave blank to keep inheriting).', 'universal-consent-privacy-framework' ) . '</p>';
	}
	if ( is_bool( $eff ) ) {
		$eff = $eff ? __( 'on', 'universal-consent-privacy-framework' ) : __( 'off', 'universal-consent-privacy-framework' );
	}
	$eff_s = is_scalar( $eff ) ? (string) $eff : '';
	if ( '' === $eff_s ) {
		return '<p class="description"><em>' . esc_html( $label ) . '</em></p>';
	}
	return '<p class="description"><em>' . esc_html( $label ) . '</em>: <code>' . esc_html( $eff_s ) . '</code></p>';
};

$ucpf_net_notice = isset( $_GET['ucpf_net'] ) ? sanitize_key( wp_unslash( $_GET['ucpf_net'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$allowed_tabs = array( 'general', 'scanner', 'privacy', 'cloudflare', 'data' );
$current_tab  = 'general';
if ( isset( $_GET['ucpf_cf_purged'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$current_tab = 'cloudflare';
} elseif ( isset( $_GET['ucpf_tab'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$tab_in = sanitize_key( wp_unslash( (string) $_GET['ucpf_tab'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( in_array( $tab_in, $allowed_tabs, true ) ) {
		$current_tab = $tab_in;
	}
}

$tab_url = static function ( $tab ) {
	return add_query_arg(
		array(
			'page'     => 'ucpf-advanced',
			'ucpf_tab' => $tab,
		),
		admin_url( 'admin.php' )
	);
};

$tab_labels = array(
	'general'    => __( 'General', 'universal-consent-privacy-framework' ),
	'scanner'    => __( 'Scanner', 'universal-consent-privacy-framework' ),
	'privacy'    => __( 'Privacy', 'universal-consent-privacy-framework' ),
	'cloudflare' => __( 'Cloudflare', 'universal-consent-privacy-framework' ),
	'data'       => __( 'Data', 'universal-consent-privacy-framework' ),
);
?>
<div class="wrap ucpf-admin">
	<h1><?php esc_html_e( 'Advanced Settings', 'universal-consent-privacy-framework' ); ?></h1>
	<?php if ( 'promoted' === $ucpf_net_notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'This site’s scanner/privacy settings were copied to the network defaults.', 'universal-consent-privacy-framework' ); ?></p></div>
	<?php elseif ( 'error' === $ucpf_net_notice ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Could not update network settings.', 'universal-consent-privacy-framework' ); ?></p></div>
	<?php endif; ?>
	<?php
	$ucpf_cf_purged = isset( $_GET['ucpf_cf_purged'] ) ? sanitize_text_field( wp_unslash( $_GET['ucpf_cf_purged'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( '' !== $ucpf_cf_purged ) :
		?>
		<?php if ( '1' === $ucpf_cf_purged ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Cloudflare cache purge requested successfully.', 'universal-consent-privacy-framework' ); ?></p></div>
		<?php else : ?>
			<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Cloudflare purge did not complete. Check domain / API token and last status below.', 'universal-consent-privacy-framework' ); ?></p></div>
		<?php endif; ?>
	<?php endif; ?>

	<nav class="nav-tab-wrapper ucpf-adv-tabs" aria-label="<?php echo esc_attr__( 'Advanced settings sections', 'universal-consent-privacy-framework' ); ?>">
		<?php foreach ( $tab_labels as $tab_key => $tab_label ) : ?>
			<a href="<?php echo esc_url( $tab_url( $tab_key ) ); ?>" class="nav-tab <?php echo $current_tab === $tab_key ? 'nav-tab-active' : ''; ?>">
				<?php echo esc_html( $tab_label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<form method="post" action="options.php" class="ucpf-advanced-form">
		<?php settings_fields( 'ucpf_settings_group' ); ?>
		<input type="hidden" name="<?php echo esc_attr( $option_key ); ?>[_ucpf_advanced_form]" value="1" />

		<section class="ucpf-panel"<?php echo 'general' === $current_tab ? '' : ' hidden'; ?> data-ucpf-tab="general">
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Site profile', 'universal-consent-privacy-framework' ); ?></th>
				<td>
					<?php
					$site_profile = \UCPF\Site_Profiles::current();
					$profiles     = \UCPF\Site_Profiles::definitions();
					$woo_active   = \UCPF\Cookie_Scanner::instance()->is_woo_active();
					?>
					<fieldset>
						<?php foreach ( $profiles as $profile_key => $profile_meta ) : ?>
							<label style="display:block;margin-bottom:0.5rem;">
								<input type="radio" name="<?php echo esc_attr( $option_key ); ?>[site_profile]" value="<?php echo esc_attr( $profile_key ); ?>" <?php checked( $site_profile, $profile_key ); ?>
									<?php disabled( \UCPF\Site_Profiles::WOOCOMMERCE === $profile_key && ! $woo_active ); ?> />
								<strong><?php echo esc_html( $profile_meta['label'] ); ?></strong>
								<span class="description" style="display:block;margin-left:1.6rem;"><?php echo esc_html( $profile_meta['description'] ); ?></span>
							</label>
						<?php endforeach; ?>
					</fieldset>
					<p class="description"><?php esc_html_e( 'Saving reapplies scan page defaults for this profile (Woo pack and/or logged-in homepage). Optional trackers remain consent-gated.', 'universal-consent-privacy-framework' ); ?></p>
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
			</tr>
		</table>
		</section>

		<section class="ucpf-panel"<?php echo 'scanner' === $current_tab ? '' : ' hidden'; ?> data-ucpf-tab="scanner">
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Playwright scanner (API)', 'universal-consent-privacy-framework' ); ?></th>
				<td>
					<p>
						<label for="ucpf-scanner-api-url"><?php esc_html_e( 'Scanner API URL', 'universal-consent-privacy-framework' ); ?></label><br />
						<input type="url" class="regular-text" id="ucpf-scanner-api-url" name="<?php echo esc_attr( $option_key ); ?>[scanner_api_url]" value="<?php echo esc_attr( $scanner_url ); ?>" placeholder="<?php echo is_multisite() ? esc_attr__( 'Leave blank to use network default', 'universal-consent-privacy-framework' ) : 'https://scanner.example.com'; ?>" />
					</p>
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns escaped HTML.
					echo $ucpf_net_hint( 'scanner_api_url' );
					?>
					<p>
						<label for="ucpf-scanner-api-key"><?php esc_html_e( 'Scanner API key', 'universal-consent-privacy-framework' ); ?></label><br />
						<input type="password" class="regular-text" id="ucpf-scanner-api-key" name="<?php echo esc_attr( $option_key ); ?>[scanner_api_key]" value="" autocomplete="new-password" placeholder="<?php echo $scanner_key_set ? esc_attr__( '•••••••• (leave blank to keep)', 'universal-consent-privacy-framework' ) : ''; ?>" />
					</p>
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns escaped HTML.
					echo $ucpf_net_hint( 'scanner_api_key' );
					?>
					<p class="description"><?php esc_html_e( 'Required to use “Run Playwright scan” on the Cookie Scanner screen (self-hosted Chromium service — HTTPS JSON only, no remote executable code). Leave blank to use the WordPress helper scan or local CLI + Import report instead. Keys are encrypted at rest. Prefer defining UCPF_SCANNER_API_KEY in wp-config.php on production (never written to the database).', 'universal-consent-privacy-framework' ); ?></p>
					<?php if ( is_multisite() ) : ?>
						<p class="description">
							<?php
							printf(
								/* translators: %s: network admin URL */
								esc_html__( 'Multisite: set a shared URL/key under %s, or leave these blank to inherit. Filled values override the network.', 'universal-consent-privacy-framework' ),
								'<a href="' . esc_url( network_admin_url( 'admin.php?page=ucpf-network' ) ) . '">' . esc_html__( 'Network Admin → Privacy Consent', 'universal-consent-privacy-framework' ) . '</a>'
							);
							?>
						</p>
						<?php if ( current_user_can( 'manage_network_options' ) ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
								<?php wp_nonce_field( 'ucpf_promote_network_from_site' ); ?>
								<input type="hidden" name="action" value="ucpf_promote_network_from_site" />
								<?php submit_button( __( 'Use this site’s scanner/privacy settings as network defaults', 'universal-consent-privacy-framework' ), 'secondary', 'submit', false ); ?>
							</form>
						<?php endif; ?>
					<?php endif; ?>
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
					<p class="description"><?php esc_html_e( 'Agency fleets (many sites): use one API key per site, point cohorts at different scanner nodes if needed, and stagger scheduled scans. Shared scanners queue jobs. Never use cancel-all except the emergency reset below.', 'universal-consent-privacy-framework' ); ?></p>
					<p>
						<button type="button" class="button" id="ucpf-scanner-reset-all"><?php esc_html_e( 'Emergency: reset all scanner jobs', 'universal-consent-privacy-framework' ); ?></button>
						<span id="ucpf-scanner-reset-status" class="description" style="margin-left:8px;" aria-live="polite"></span>
					</p>
					<p class="description"><?php esc_html_e( 'Admin only. Cancels every job on the scanner host and resets concurrency slots. Affects every site sharing that scanner.', 'universal-consent-privacy-framework' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Agency knowledge hub', 'universal-consent-privacy-framework' ); ?></th>
				<td>
					<?php
					$hub_status = \UCPF\Script_Registry::get_remote_registry_status();
					$hub_mode   = \UCPF\Community_Registry::mode();
					$hub_ok     = ! empty( $hub_status['ok'] );
					?>
					<p class="description"><?php esc_html_e( 'Shared cookie intelligence via a Git/CDN registry.json (no hosted DB, no phone-home). Requires all three: mode Agency (or Community), enable sync, and a raw JSON URL.', 'universal-consent-privacy-framework' ); ?></p>
					<p>
						<label for="ucpf-registry-mode"><strong><?php esc_html_e( '1. Registry mode', 'universal-consent-privacy-framework' ); ?></strong></label><br />
						<select id="ucpf-registry-mode" name="<?php echo esc_attr( $option_key ); ?>[registry_mode]">
							<?php if ( is_multisite() ) : ?>
								<option value="" <?php selected( $registry_mode, '' ); ?>><?php esc_html_e( 'Use network default', 'universal-consent-privacy-framework' ); ?></option>
							<?php endif; ?>
							<option value="local" <?php selected( $registry_mode, 'local' ); ?>><?php esc_html_e( 'Local only (default — no remote pull)', 'universal-consent-privacy-framework' ); ?></option>
							<option value="agency" <?php selected( $registry_mode, 'agency' ); ?>><?php esc_html_e( 'Agency — private GitHub/CDN registry.json', 'universal-consent-privacy-framework' ); ?></option>
							<option value="community" <?php selected( $registry_mode, 'community' ); ?>><?php esc_html_e( 'Community — double opt-in only', 'universal-consent-privacy-framework' ); ?></option>
							<option value="disabled" <?php selected( $registry_mode, 'disabled' ); ?>><?php esc_html_e( 'Disabled', 'universal-consent-privacy-framework' ); ?></option>
						</select>
					</p>
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns escaped HTML.
					echo $ucpf_net_hint( 'registry_mode' );
					?>
					<p class="description"><?php esc_html_e( 'Override with UCPF_REGISTRY_MODE in wp-config.php. Catalogs are metadata only — never remote executable code.', 'universal-consent-privacy-framework' ); ?></p>
					<p>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $option_key ); ?>[remote_registry_enabled]" value="1" <?php checked( $remote_on ); ?> />
							<strong><?php esc_html_e( '2. Enable remote metadata sync', 'universal-consent-privacy-framework' ); ?></strong>
						</label>
					</p>
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns escaped HTML.
					echo $ucpf_net_hint( 'remote_registry_enabled' );
					?>
					<p>
						<label for="ucpf-remote-registry-url"><strong><?php esc_html_e( '3. Raw registry.json URL', 'universal-consent-privacy-framework' ); ?></strong></label><br />
						<input type="url" class="regular-text" id="ucpf-remote-registry-url" name="<?php echo esc_attr( $option_key ); ?>[remote_registry_url]" value="<?php echo esc_attr( $remote_url ); ?>" placeholder="<?php echo is_multisite() ? esc_attr__( 'Leave blank to use network default', 'universal-consent-privacy-framework' ) : 'https://raw.githubusercontent.com/org/repo/main/registry.json'; ?>" />
					</p>
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns escaped HTML.
					echo $ucpf_net_hint( 'remote_registry_url' );
					?>
					<p>
						<button type="button" class="button" id="ucpf-registry-refresh"><?php esc_html_e( 'Refresh registry now', 'universal-consent-privacy-framework' ); ?></button>
						<span id="ucpf-registry-sync-status" class="description" style="margin-left:8px;" aria-live="polite">
							<?php
							if ( ! empty( $hub_status['message'] ) ) {
								echo esc_html(
									( $hub_ok ? __( 'OK:', 'universal-consent-privacy-framework' ) : __( 'Last sync:', 'universal-consent-privacy-framework' ) ) . ' ' .
									(string) $hub_status['message'] .
									( ! empty( $hub_status['at'] ) ? ' (' . (string) $hub_status['at'] . ')' : '' )
								);
							} else {
								echo esc_html(
									sprintf(
										/* translators: %s: effective mode */
										__( 'Effective mode: %s. Save settings, then refresh.', 'universal-consent-privacy-framework' ),
										$hub_mode
									)
								);
							}
							?>
						</span>
					</p>
					<p class="description"><?php esc_html_e( 'Pull is cached about one day. Refresh after you push a new registry.json. See docs/COOKIE-KNOWLEDGE-HUB.md and tools/merge-knowledge-hub.ps1.', 'universal-consent-privacy-framework' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Scheduled deep scan', 'universal-consent-privacy-framework' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $option_key ); ?>[scheduled_scan_enabled]" value="1" <?php checked( $sched_on ); ?> />
						<?php esc_html_e( 'Run Playwright scan automatically on this site (requires Scanner API URL + key above)', 'universal-consent-privacy-framework' ); ?>
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
		</table>
		</section>

		<section class="ucpf-panel"<?php echo 'privacy' === $current_tab ? '' : ' hidden'; ?> data-ucpf-tab="privacy">
		<table class="form-table" role="presentation">
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
						<input type="text" class="regular-text" id="ucpf-privacy-controller" name="<?php echo esc_attr( $option_key ); ?>[privacy_controller_id]" value="<?php echo esc_attr( $privacy_controller ); ?>" placeholder="<?php echo is_multisite() ? esc_attr__( 'Leave blank to use network default', 'universal-consent-privacy-framework' ) : 'acme-media'; ?>" />
					</p>
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns escaped HTML.
					echo $ucpf_net_hint( 'privacy_controller_id' );
					?>
					<p>
						<label for="ucpf-privacy-api-url"><?php esc_html_e( 'Privacy Preference API URL (optional)', 'universal-consent-privacy-framework' ); ?></label><br />
						<input type="url" class="regular-text" id="ucpf-privacy-api-url" name="<?php echo esc_attr( $option_key ); ?>[privacy_api_url]" value="<?php echo esc_attr( $privacy_api_url ); ?>" placeholder="<?php echo is_multisite() ? esc_attr__( 'Leave blank to use network default', 'universal-consent-privacy-framework' ) : 'https://privacy-api.example.com'; ?>" />
					</p>
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns escaped HTML.
					echo $ucpf_net_hint( 'privacy_api_url' );
					?>
					<p>
						<label for="ucpf-privacy-api-key"><?php esc_html_e( 'Privacy API key', 'universal-consent-privacy-framework' ); ?></label><br />
						<input type="password" class="regular-text" id="ucpf-privacy-api-key" name="<?php echo esc_attr( $option_key ); ?>[privacy_api_key]" value="" autocomplete="new-password" placeholder="<?php echo $privacy_api_key_set ? esc_attr__( '•••••••• (leave blank to keep)', 'universal-consent-privacy-framework' ) : ''; ?>" />
					</p>
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns escaped HTML.
					echo $ucpf_net_hint( 'privacy_api_key' );
					?>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $option_key ); ?>[privacy_fail_closed]" value="1" <?php checked( $privacy_fail_closed ); ?> />
						<?php esc_html_e( 'Fail closed for marketing when the Privacy API is unreachable', 'universal-consent-privacy-framework' ); ?>
					</label>
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns escaped HTML.
					echo $ucpf_net_hint( 'privacy_fail_closed' );
					?>
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
					<?php
					// Reflect effective state (auto-on when Cloudflare is detected).
					$ucpf_jurisdiction = \UCPF\Jurisdiction::instance();
					$ucpf_jurisdiction->maybe_auto_enable_geo_for_cloudflare();
					$settings       = \UCPF\Settings::all();
					$ucpf_cf_locked = $ucpf_jurisdiction->cloudflare_detected_for_geo();
					$geo_routing    = ! empty( $settings['geo_jurisdiction_routing'] ) || $ucpf_jurisdiction->geo_routing_enabled();
					?>
					<?php if ( $ucpf_cf_locked ) : ?>
						<input type="hidden" name="<?php echo esc_attr( $option_key ); ?>[geo_jurisdiction_routing]" value="1" />
					<?php endif; ?>
					<label>
						<input
							type="checkbox"
							<?php echo $ucpf_cf_locked ? '' : 'name="' . esc_attr( $option_key ) . '[geo_jurisdiction_routing]"'; ?>
							value="1"
							<?php checked( $geo_routing ); ?>
							<?php disabled( $ucpf_cf_locked ); ?>
						/>
						<?php
						echo $ucpf_cf_locked
							? esc_html__( 'Geo pack routing (on — Cloudflare detected)', 'universal-consent-privacy-framework' )
							: esc_html__( 'Enable geo pack routing (auto-on when Cloudflare is detected)', 'universal-consent-privacy-framework' );
						?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Uses Cloudflare CF-IPCountry (and the ucpf_visitor_region filter). When Cloudflare is detected (headers, proxy, or last scan), this turns on automatically and stays on. Matrix: US → United States privacy baseline (banner + optional cookies gated until choice; Do Not Sell/Share + GPC for sale/sharing); EEA/UK/CH → strict GDPR; Brazil → LGPD; unknown/missing country → strict GDPR (fail closed). Other countries keep the default pack above. Not a legal guarantee.', 'universal-consent-privacy-framework' ); ?>
					</p>
					<?php
					$ucpf_region = $ucpf_jurisdiction->detect_visitor_region();
					?>
					<p class="description">
						<?php
						if ( $ucpf_cf_locked ) {
							esc_html_e( 'Cloudflare detected — geo pack routing is enabled and locked on.', 'universal-consent-privacy-framework' );
						} else {
							esc_html_e( 'Cloudflare not detected yet — geo stays off until CF is present (or you enable it manually). Run Cookie Scanner after putting the site behind Cloudflare if needed.', 'universal-consent-privacy-framework' );
						}
						?>
						<?php if ( $ucpf_region ) : ?>
							<?php
							echo ' ';
							printf(
								/* translators: %s: country/region code */
								esc_html__( 'Detected region (this request): %s.', 'universal-consent-privacy-framework' ),
								esc_html( $ucpf_region )
							);
							?>
						<?php endif; ?>
					</p>
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
		</table>
		</section>

		<section class="ucpf-panel"<?php echo 'cloudflare' === $current_tab ? '' : ' hidden'; ?> data-ucpf-tab="cloudflare">
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'CDN / Cloudflare assets', 'universal-consent-privacy-framework' ); ?></th>
				<td>
					<p class="description"><?php esc_html_e( 'UCPF reshapes HTML from the consent cookie and reloads with ?_ucpf= after Accept / Decline / Save. Year-long Cache Files on *.css/*.js will poison any WordPress site (theme, Elementor, Divi, plugins) when a soft-404 HTML body is stored as a stylesheet — upload then looks “broken” until you purge.', 'universal-consent-privacy-framework' ); ?></p>
					<p class="description"><strong><?php esc_html_e( 'Cloudflare Bypass Cache Rule — use this expression (or OR into your existing Bypass; place Bypass so it wins over Cache Files / Cache Everything):', 'universal-consent-privacy-framework' ); ?></strong></p>
					<p><code style="display:block;white-space:pre-wrap;word-break:break-word;font-size:12px;line-height:1.45;">(http.request.uri.path contains "/wp-content/plugins/universal-consent-privacy-framework/") or (http.request.uri.path contains "/wp-content/uploads/") or (ends_with(http.request.uri.path, ".css")) or (ends_with(http.request.uri.path, ".js")) or (http.request.uri.query contains "_ucpf") or (http.cookie contains "ucpf_consent") or (http.cookie contains "ucpf_dns")</code></p>
					<p class="description"><?php esc_html_e( 'Also on Cache Files: 4xx/5xx → no cache; do not Ignore Query String for CSS/JS. Prefer year TTL for images/media only. Rocket Loader off (or never rewrite UCPF tags). Full guide: docs/CLOUDFLARE-CACHE.md.', 'universal-consent-privacy-framework' ); ?></p>

					<hr style="margin:1.25rem 0;" />
					<p><strong><?php esc_html_e( 'Automatic Cloudflare purge API', 'universal-consent-privacy-framework' ); ?></strong></p>
					<p class="description"><?php esc_html_e( 'Optional. After plugin/theme updates (or UCPF itself), schedule one debounced purge_everything via the Cloudflare API so edge HTML/CSS cannot keep a broken deploy. Coalesces bulk updates; max one API call every 10 minutes. Does not clear Autoptimize/LiteSpeed on origin.', 'universal-consent-privacy-framework' ); ?></p>
					<label style="display:block;margin:0.5rem 0;">
						<input type="checkbox" name="<?php echo esc_attr( $option_key ); ?>[cloudflare_purge_enabled]" value="1" <?php checked( $cf_purge_on ); ?> />
						<?php esc_html_e( 'Enable Cloudflare cache purge on updates', 'universal-consent-privacy-framework' ); ?>
					</label>
					<label style="display:block;margin:0.5rem 0;">
						<input type="checkbox" name="<?php echo esc_attr( $option_key ); ?>[cloudflare_purge_on_updates]" value="1" <?php checked( $cf_on_updates ); ?> />
						<?php esc_html_e( 'Purge after any plugin or theme update', 'universal-consent-privacy-framework' ); ?>
					</label>
					<label style="display:block;margin:0.5rem 0;">
						<input type="checkbox" name="<?php echo esc_attr( $option_key ); ?>[cloudflare_purge_on_ucpf_update]" value="1" <?php checked( $cf_on_ucpf ); ?> />
						<?php esc_html_e( 'Purge after UCPF activate / version upgrade', 'universal-consent-privacy-framework' ); ?>
					</label>
					<label style="display:block;margin:0.5rem 0;">
						<input type="checkbox" name="<?php echo esc_attr( $option_key ); ?>[elementor_clear_css_on_updates]" value="1" <?php checked( $el_clear_css ); ?> />
						<?php esc_html_e( 'Clear Elementor CSS cache after plugin / theme / UCPF updates (rebuilds on next page view)', 'universal-consent-privacy-framework' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'When Elementor is active, clears its generated CSS so layouts rebuild without opening every template. Does not delete Autoptimize/Rocket files. Still require Cloudflare Bypass for /wp-content/uploads/elementor/css/.', 'universal-consent-privacy-framework' ); ?></p>
					<p>
						<label for="ucpf-cf-domain"><?php esc_html_e( 'Domain', 'universal-consent-privacy-framework' ); ?></label><br />
						<input type="text" class="regular-text" id="ucpf-cf-domain" name="<?php echo esc_attr( $option_key ); ?>[cloudflare_domain]" value="<?php echo esc_attr( $cf_domain ); ?>" placeholder="example.com" autocomplete="off" />
					</p>
					<?php if ( '' !== $cf_zone ) : ?>
						<?php
						$cf_zone_len = strlen( $cf_zone );
						$cf_zone_disp = $cf_zone_len > 8
							? substr( $cf_zone, 0, 4 ) . '…' . substr( $cf_zone, -4 )
							: $cf_zone;
						?>
						<p class="description">
							<?php
							printf(
								/* translators: %s: truncated Cloudflare zone id */
								esc_html__( 'Zone resolved: %s', 'universal-consent-privacy-framework' ),
								esc_html( $cf_zone_disp )
							);
							?>
						</p>
					<?php endif; ?>
					<p>
						<label for="ucpf-cf-api-token"><?php esc_html_e( 'Cloudflare API Token', 'universal-consent-privacy-framework' ); ?></label><br />
						<input type="password" class="regular-text" id="ucpf-cf-api-token" name="<?php echo esc_attr( $option_key ); ?>[cloudflare_api_token]" value="" autocomplete="new-password" placeholder="<?php echo $cf_token_set ? esc_attr__( '•••••••• (leave blank to keep)', 'universal-consent-privacy-framework' ) : ''; ?>" />
					</p>
					<p class="description"><?php esc_html_e( 'Create a token with Zone → Cache Purge (and Zone → Zone → Read). Leave blank when saving to keep the existing token. Tokens are encrypted at rest; prefer UCPF_CLOUDFLARE_API_TOKEN in wp-config.php on production.', 'universal-consent-privacy-framework' ); ?></p>
					<?php if ( ! empty( $cf_last['time'] ) ) : ?>
						<p class="description">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: date/time, 2: ok/fail, 3: reason, 4: message */
									__( 'Last purge: %1$s — %2$s (%3$s). %4$s', 'universal-consent-privacy-framework' ),
									wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $cf_last['time'] ),
									! empty( $cf_last['ok'] ) ? __( 'success', 'universal-consent-privacy-framework' ) : __( 'failed', 'universal-consent-privacy-framework' ),
									isset( $cf_last['reason'] ) ? (string) $cf_last['reason'] : '',
									isset( $cf_last['message'] ) ? (string) $cf_last['message'] : ''
								)
							);
							?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		</section>

		<section class="ucpf-panel"<?php echo 'data' === $current_tab ? '' : ' hidden'; ?> data-ucpf-tab="data">
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Consent logging', 'universal-consent-privacy-framework' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $option_key ); ?>[consent_logging]" value="1" <?php checked( $log_on ); ?> />
						<?php esc_html_e( 'Enable consent logging', 'universal-consent-privacy-framework' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Stores consent UUID, action, categories, and timestamps only — not IP addresses. Real preference changes are logged; identical re-saves are skipped; rapid spam is rate-limited (burst debounce + daily cap).', 'universal-consent-privacy-framework' ); ?></p>
					<label>
						<?php esc_html_e( 'Retention days', 'universal-consent-privacy-framework' ); ?>
						<input type="number" name="<?php echo esc_attr( $option_key ); ?>[log_retention_days]" value="<?php echo esc_attr( (string) max( 1, min( 3650, $log_days ) ) ); ?>" min="1" max="3650" />
					</label>
					<p class="description"><?php esc_html_e( 'Default 360 days. Consent logs are light (no IP). Changing this updates expiry on existing rows.', 'universal-consent-privacy-framework' ); ?></p>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $option_key ); ?>[login_security_notice]" value="1" <?php checked( ! empty( $settings['login_security_notice'] ) ); ?> />
						<?php esc_html_e( 'Show login activity notice', 'universal-consent-privacy-framework' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Informs admins and people signing in (WordPress / WooCommerce) that login attempts and related account-security events may be logged (e.g. password-policy plugins such as miniOrange). Recommended for staff and client login sites.', 'universal-consent-privacy-framework' ); ?></p>
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
		</section>

		<?php submit_button(); ?>
	</form>

	<?php if ( 'cloudflare' === $current_tab ) : ?>
	<section class="ucpf-panel" style="margin-top:1.5rem;">
		<h2><?php esc_html_e( 'Purge Cloudflare cache now', 'universal-consent-privacy-framework' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Runs immediately (still rate-limited to one API call per 10 minutes). Requires Enable + domain + API Token saved above.', 'universal-consent-privacy-framework' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="ucpf_purge_cloudflare" />
			<?php wp_nonce_field( 'ucpf_purge_cloudflare' ); ?>
			<?php submit_button( __( 'Purge Cloudflare cache now', 'universal-consent-privacy-framework' ), 'secondary', 'submit', false ); ?>
		</form>
	</section>
	<?php endif; ?>
</div>
