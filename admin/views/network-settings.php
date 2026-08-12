<?php
/**
 * Network Admin — shared scanner / privacy / registry connection settings.
 *
 * @package UCPF
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- included template.

$net            = \UCPF\Network_Settings::all();
$scanner_url    = isset( $net['scanner_api_url'] ) ? (string) $net['scanner_api_url'] : '';
$scanner_key_set = \UCPF\Network_Settings::secret_is_set( 'scanner_api_key' );
$privacy_url    = isset( $net['privacy_api_url'] ) ? (string) $net['privacy_api_url'] : '';
$privacy_key_set = \UCPF\Network_Settings::secret_is_set( 'privacy_api_key' );
$controller     = isset( $net['privacy_controller_id'] ) ? (string) $net['privacy_controller_id'] : '';
$fail_closed    = ! isset( $net['privacy_fail_closed'] ) || ! empty( $net['privacy_fail_closed'] );
$registry_mode  = isset( $net['registry_mode'] ) ? (string) $net['registry_mode'] : '';
$remote_on      = ! empty( $net['remote_registry_enabled'] );
$remote_url     = isset( $net['remote_registry_url'] ) ? (string) $net['remote_registry_url'] : '';

$notice = isset( $_GET['ucpf_net'] ) ? sanitize_key( wp_unslash( $_GET['ucpf_net'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
?>
<div class="wrap ucpf-admin">
	<h1><?php echo esc_html( sprintf( /* translators: %s: product name */ __( '%s — Network settings', 'universal-consent-privacy-framework' ), \UCPF\Brand::product_name() ) ); ?></h1>

	<?php if ( 'saved' === $notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Network settings saved. Sites inherit these when their Advanced fields are blank; filled site fields override.', 'universal-consent-privacy-framework' ); ?></p></div>
	<?php elseif ( 'promoted' === $notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Current site connection settings were copied to the network defaults.', 'universal-consent-privacy-framework' ); ?></p></div>
	<?php elseif ( 'cleared' === $notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Site overrides for scanner/privacy/registry keys were cleared. Sites now inherit network defaults.', 'universal-consent-privacy-framework' ); ?></p></div>
	<?php elseif ( 'error' === $notice ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Could not update network settings. Check your permissions and try again.', 'universal-consent-privacy-framework' ); ?></p></div>
	<?php endif; ?>

	<p class="description">
		<?php esc_html_e( 'Configure the shared Playwright scanner host, Privacy Preference API, and agency knowledge hub once for the whole network. Banner branding, consent categories, cookie inventory, and scheduled scan paths stay per-site.', 'universal-consent-privacy-framework' ); ?>
	</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'ucpf_save_network_settings' ); ?>
		<input type="hidden" name="action" value="ucpf_save_network_settings" />

		<h2><?php esc_html_e( 'Scanner API', 'universal-consent-privacy-framework' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="ucpf-net-scanner-url"><?php esc_html_e( 'Scanner API URL', 'universal-consent-privacy-framework' ); ?></label></th>
				<td>
					<input type="url" class="regular-text" id="ucpf-net-scanner-url" name="scanner_api_url" value="<?php echo esc_attr( $scanner_url ); ?>" placeholder="https://scanner.example.com" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ucpf-net-scanner-key"><?php esc_html_e( 'Scanner API key', 'universal-consent-privacy-framework' ); ?></label></th>
				<td>
					<input type="password" class="regular-text" id="ucpf-net-scanner-key" name="scanner_api_key" value="" autocomplete="new-password" placeholder="<?php echo $scanner_key_set ? esc_attr__( '•••••••• (leave blank to keep)', 'universal-consent-privacy-framework' ) : ''; ?>" />
					<p class="description"><?php esc_html_e( 'Shared key for the network (encrypted at rest). Prefer UCPF_SCANNER_API_KEY in wp-config.php on production. Sites may still set a per-site override key.', 'universal-consent-privacy-framework' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Privacy Preference API', 'universal-consent-privacy-framework' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="ucpf-net-privacy-url"><?php esc_html_e( 'API URL', 'universal-consent-privacy-framework' ); ?></label></th>
				<td>
					<input type="url" class="regular-text" id="ucpf-net-privacy-url" name="privacy_api_url" value="<?php echo esc_attr( $privacy_url ); ?>" placeholder="https://privacy-api.example.com" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ucpf-net-privacy-key"><?php esc_html_e( 'API key', 'universal-consent-privacy-framework' ); ?></label></th>
				<td>
					<input type="password" class="regular-text" id="ucpf-net-privacy-key" name="privacy_api_key" value="" autocomplete="new-password" placeholder="<?php echo $privacy_key_set ? esc_attr__( '•••••••• (leave blank to keep)', 'universal-consent-privacy-framework' ) : ''; ?>" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ucpf-net-controller"><?php esc_html_e( 'Controller ID', 'universal-consent-privacy-framework' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="ucpf-net-controller" name="privacy_controller_id" value="<?php echo esc_attr( $controller ); ?>" placeholder="acme-media" />
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Fail closed', 'universal-consent-privacy-framework' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="privacy_fail_closed" value="1" <?php checked( $fail_closed ); ?> />
						<?php esc_html_e( 'Fail closed for marketing when the Privacy API is unreachable', 'universal-consent-privacy-framework' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Agency knowledge hub', 'universal-consent-privacy-framework' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="ucpf-net-registry-mode"><?php esc_html_e( 'Registry mode', 'universal-consent-privacy-framework' ); ?></label></th>
				<td>
					<select id="ucpf-net-registry-mode" name="registry_mode">
						<option value="" <?php selected( $registry_mode, '' ); ?>><?php esc_html_e( 'Not set (sites use their own / local default)', 'universal-consent-privacy-framework' ); ?></option>
						<option value="local" <?php selected( $registry_mode, 'local' ); ?>><?php esc_html_e( 'Local only', 'universal-consent-privacy-framework' ); ?></option>
						<option value="agency" <?php selected( $registry_mode, 'agency' ); ?>><?php esc_html_e( 'Agency — private registry.json', 'universal-consent-privacy-framework' ); ?></option>
						<option value="community" <?php selected( $registry_mode, 'community' ); ?>><?php esc_html_e( 'Community — double opt-in only', 'universal-consent-privacy-framework' ); ?></option>
						<option value="disabled" <?php selected( $registry_mode, 'disabled' ); ?>><?php esc_html_e( 'Disabled', 'universal-consent-privacy-framework' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Remote sync', 'universal-consent-privacy-framework' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="remote_registry_enabled" value="1" <?php checked( $remote_on ); ?> />
						<?php esc_html_e( 'Enable remote metadata sync', 'universal-consent-privacy-framework' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ucpf-net-remote-url"><?php esc_html_e( 'Raw registry.json URL', 'universal-consent-privacy-framework' ); ?></label></th>
				<td>
					<input type="url" class="regular-text" id="ucpf-net-remote-url" name="remote_registry_url" value="<?php echo esc_attr( $remote_url ); ?>" placeholder="https://raw.githubusercontent.com/org/repo/main/registry.json" />
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Save network settings', 'universal-consent-privacy-framework' ) ); ?>
	</form>

	<hr />

	<h2><?php esc_html_e( 'Recover existing multisite installs', 'universal-consent-privacy-framework' ); ?></h2>
	<p class="description"><?php esc_html_e( 'If you already entered Scanner/Privacy settings on individual sites, promote from a site that has the correct values, then optionally clear per-site copies so every blog inherits.', 'universal-consent-privacy-framework' ); ?></p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:1em;">
		<?php wp_nonce_field( 'ucpf_promote_network_settings' ); ?>
		<input type="hidden" name="action" value="ucpf_promote_network_settings" />
		<p>
			<label for="ucpf-promote-blog"><?php esc_html_e( 'Copy connection settings from site', 'universal-consent-privacy-framework' ); ?></label><br />
			<?php
			$sites = get_sites(
				array(
					'number' => 100,
					'orderby' => 'id',
					'order'  => 'ASC',
				)
			);
			$main  = (int) get_main_site_id();
			?>
			<select id="ucpf-promote-blog" name="blog_id">
				<?php foreach ( (array) $sites as $site ) : ?>
					<?php
					$bid  = (int) $site->blog_id;
					$name = get_blog_option( $bid, 'blogname', '' );
					$url  = get_home_url( $bid );
					$label = sprintf( '#%1$d — %2$s (%3$s)', $bid, $name ? $name : $url, $url );
					?>
					<option value="<?php echo esc_attr( (string) $bid ); ?>" <?php selected( $bid, $main ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php submit_button( __( 'Use this site’s settings as network defaults', 'universal-consent-privacy-framework' ), 'secondary', 'submit', false ); ?>
	</form>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Clear scanner/privacy/registry overrides on ALL sites? Sites will inherit network defaults. Banner and scan inventory are not affected.', 'universal-consent-privacy-framework' ) ); ?>');">
		<?php wp_nonce_field( 'ucpf_clear_network_overrides' ); ?>
		<input type="hidden" name="action" value="ucpf_clear_network_overrides" />
		<?php submit_button( __( 'Clear site overrides for network keys on all sites', 'universal-consent-privacy-framework' ), 'delete', 'submit', false ); ?>
	</form>
</div>
