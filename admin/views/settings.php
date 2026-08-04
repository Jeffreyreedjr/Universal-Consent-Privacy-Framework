<?php
/**
 * Banner & branding settings view.
 *
 * @package UCPF
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- included template; locals are not plugin globals.

if ( ! isset( $settings ) || ! is_array( $settings ) ) {
	$settings = \UCPF\Settings::all();
}

$option_key = \UCPF\Settings::OPTION_KEY;

if ( ! isset( $presets ) || ! is_array( $presets ) || ! $presets ) {
	$presets = \UCPF\Theme_Manager::instance()->get_preset_options();
}

$current_theme = isset( $settings['banner_theme'] ) ? sanitize_key( (string) $settings['banner_theme'] ) : 'classic';
$current_theme = \UCPF\Theme_Manager::instance()->resolve_preset( $current_theme );
if ( ! isset( $presets[ $current_theme ] ) ) {
	$current_theme = 'classic';
}
$current_layout = isset( $settings['banner_layout'] ) ? sanitize_key( (string) $settings['banner_layout'] ) : 'bar';
if ( ! in_array( $current_layout, array( 'bar', 'modal', 'corner' ), true ) ) {
	$current_layout = 'bar';
}
$current_position = isset( $settings['banner_position'] ) ? sanitize_key( (string) $settings['banner_position'] ) : 'left';
if ( ! in_array( $current_position, array( 'left', 'center', 'right' ), true ) ) {
	$current_position = 'left';
}
$accent     = isset( $settings['accent_color'] ) ? (string) $settings['accent_color'] : '';
$accent_2   = isset( $settings['accent_2_color'] ) ? (string) $settings['accent_2_color'] : '';
$surface    = isset( $settings['surface_color'] ) ? (string) $settings['surface_color'] : '';
$custom_css = isset( $settings['custom_css'] ) ? (string) $settings['custom_css'] : '';
$business   = isset( $settings['business_name'] ) ? (string) $settings['business_name'] : '';
$logo_url   = isset( $settings['logo_url'] ) ? (string) $settings['logo_url'] : '';
?>
<div class="wrap ucpf-admin">
	<h1><?php esc_html_e( 'Banner & Branding', 'universal-consent-privacy-framework' ); ?></h1>
	<form method="post" action="options.php">
		<?php settings_fields( 'ucpf_settings_group' ); ?>
		<input type="hidden" name="<?php echo esc_attr( $option_key ); ?>[_ucpf_banner_form]" value="1" />

		<section class="ucpf-panel">
			<h2 class="ucpf-panel__title"><?php esc_html_e( 'Business branding', 'universal-consent-privacy-framework' ); ?></h2>
			<p class="ucpf-panel__lede"><?php esc_html_e( 'Shown on generated policy pages and optionally in the consent banner.', 'universal-consent-privacy-framework' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ucpf-business-name"><?php esc_html_e( 'Business name', 'universal-consent-privacy-framework' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="ucpf-business-name" name="<?php echo esc_attr( $option_key ); ?>[business_name]" value="<?php echo esc_attr( $business ); ?>" />
						<p class="description"><?php esc_html_e( 'Used on generated privacy / cookie policy pages.', 'universal-consent-privacy-framework' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ucpf-logo-url"><?php esc_html_e( 'Logo URL', 'universal-consent-privacy-framework' ); ?></label></th>
					<td>
						<input type="url" class="regular-text" id="ucpf-logo-url" name="<?php echo esc_attr( $option_key ); ?>[logo_url]" value="<?php echo esc_attr( $logo_url ); ?>" placeholder="https://example.com/logo.svg" />
						<p class="description"><?php esc_html_e( 'Optional. Shown in the consent banner when set.', 'universal-consent-privacy-framework' ); ?></p>
					</td>
				</tr>
			</table>
		</section>

		<section class="ucpf-panel">
			<h2 class="ucpf-panel__title"><?php esc_html_e( 'Banner appearance', 'universal-consent-privacy-framework' ); ?></h2>
			<p class="ucpf-panel__lede"><?php esc_html_e( 'Theme, layout, and visitor controls for the consent banner.', 'universal-consent-privacy-framework' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ucpf-banner-theme"><?php esc_html_e( 'Theme preset', 'universal-consent-privacy-framework' ); ?></label></th>
					<td>
						<select name="<?php echo esc_attr( $option_key ); ?>[banner_theme]" id="ucpf-banner-theme">
							<?php foreach ( $presets as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current_theme, $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ucpf-banner-layout"><?php esc_html_e( 'Banner layout', 'universal-consent-privacy-framework' ); ?></label></th>
					<td>
						<select name="<?php echo esc_attr( $option_key ); ?>[banner_layout]" id="ucpf-banner-layout">
							<option value="bar" <?php selected( $current_layout, 'bar' ); ?>><?php esc_html_e( 'Bottom bar', 'universal-consent-privacy-framework' ); ?></option>
							<option value="modal" <?php selected( $current_layout, 'modal' ); ?>><?php esc_html_e( 'Center modal', 'universal-consent-privacy-framework' ); ?></option>
							<option value="corner" <?php selected( $current_layout, 'corner' ); ?>><?php esc_html_e( 'Corner card', 'universal-consent-privacy-framework' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Save, then hard-refresh the front end (Ctrl+F5). Clear any page cache if the layout still looks unchanged.', 'universal-consent-privacy-framework' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ucpf-banner-position"><?php esc_html_e( 'Banner position', 'universal-consent-privacy-framework' ); ?></label></th>
					<td>
						<select name="<?php echo esc_attr( $option_key ); ?>[banner_position]" id="ucpf-banner-position">
							<option value="left" <?php selected( $current_position, 'left' ); ?>><?php esc_html_e( 'Left', 'universal-consent-privacy-framework' ); ?></option>
							<option value="center" <?php selected( $current_position, 'center' ); ?>><?php esc_html_e( 'Center', 'universal-consent-privacy-framework' ); ?></option>
							<option value="right" <?php selected( $current_position, 'right' ); ?>><?php esc_html_e( 'Right', 'universal-consent-privacy-framework' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Horizontal placement for the consent banner and the floating Cookie Settings button (bar and corner layouts). Prefer Left when an accessibility widget sits bottom-right. Modal stays centered.', 'universal-consent-privacy-framework' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Buttons', 'universal-consent-privacy-framework' ); ?></th>
					<td>
						<div class="ucpf-check-grid">
							<label><input type="checkbox" name="<?php echo esc_attr( $option_key ); ?>[show_reject_all]" value="1" <?php checked( ! empty( $settings['show_reject_all'] ) ); ?> /> <?php esc_html_e( 'Show Reject All', 'universal-consent-privacy-framework' ); ?></label>
							<label><input type="checkbox" name="<?php echo esc_attr( $option_key ); ?>[show_accept_all]" value="1" <?php checked( ! empty( $settings['show_accept_all'] ) ); ?> /> <?php esc_html_e( 'Show Accept All', 'universal-consent-privacy-framework' ); ?></label>
							<label><input type="checkbox" name="<?php echo esc_attr( $option_key ); ?>[show_customize]" value="1" <?php checked( ! empty( $settings['show_customize'] ) ); ?> /> <?php esc_html_e( 'Show Customize', 'universal-consent-privacy-framework' ); ?></label>
							<label><input type="checkbox" name="<?php echo esc_attr( $option_key ); ?>[floating_prefs_button]" value="1" <?php checked( ! empty( $settings['floating_prefs_button'] ) ); ?> /> <?php esc_html_e( 'Floating preferences button', 'universal-consent-privacy-framework' ); ?></label>
						</div>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ucpf-accent-color"><?php esc_html_e( 'Accent color', 'universal-consent-privacy-framework' ); ?></label></th>
					<td>
						<div class="ucpf-color-row">
							<input type="text" id="ucpf-accent-color" name="<?php echo esc_attr( $option_key ); ?>[accent_color]" value="<?php echo esc_attr( $accent ); ?>" placeholder="#0b5cad" />
						</div>
						<p class="description"><?php esc_html_e( 'Optional. Leave blank to use the theme preset accent (e.g. neon green / ocean orange).', 'universal-consent-privacy-framework' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ucpf-accent-2-color"><?php esc_html_e( 'Accent 2', 'universal-consent-privacy-framework' ); ?></label></th>
					<td>
						<div class="ucpf-color-row">
							<input type="text" id="ucpf-accent-2-color" name="<?php echo esc_attr( $option_key ); ?>[accent_2_color]" value="<?php echo esc_attr( $accent_2 ); ?>" placeholder="#094a8c" />
						</div>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ucpf-surface-color"><?php esc_html_e( 'Surface color', 'universal-consent-privacy-framework' ); ?></label></th>
					<td>
						<div class="ucpf-color-row">
							<input type="text" id="ucpf-surface-color" name="<?php echo esc_attr( $option_key ); ?>[surface_color]" value="<?php echo esc_attr( $surface ); ?>" placeholder="#111111" />
						</div>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ucpf-custom-css"><?php esc_html_e( 'Custom CSS', 'universal-consent-privacy-framework' ); ?></label></th>
					<td><textarea id="ucpf-custom-css" name="<?php echo esc_attr( $option_key ); ?>[custom_css]" rows="5" class="large-text code"><?php echo esc_textarea( $custom_css ); ?></textarea></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</section>
	</form>

	<section class="ucpf-panel ucpf-theme-pack">
		<h2 class="ucpf-panel__title"><?php esc_html_e( 'Import / export theme', 'universal-consent-privacy-framework' ); ?></h2>
		<p class="ucpf-panel__lede">
			<?php esc_html_e( 'Share banner appearance (preset, layout, colors, custom CSS, button toggles, logo URL) as a JSON pack. Does not export privacy, scanner, or legal settings.', 'universal-consent-privacy-framework' ); ?>
		</p>
		<p>
			<label for="ucpf-theme-pack-name"><strong><?php esc_html_e( 'Pack name (optional)', 'universal-consent-privacy-framework' ); ?></strong></label><br />
			<input type="text" class="regular-text" id="ucpf-theme-pack-name" placeholder="<?php echo esc_attr__( 'My agency blue', 'universal-consent-privacy-framework' ); ?>" />
		</p>
		<p>
			<button type="button" class="button button-primary" id="ucpf-theme-export"><?php esc_html_e( 'Export theme JSON', 'universal-consent-privacy-framework' ); ?></button>
			<button type="button" class="button" id="ucpf-theme-copy" hidden><?php esc_html_e( 'Copy to clipboard', 'universal-consent-privacy-framework' ); ?></button>
			<button type="button" class="button" id="ucpf-theme-download" hidden><?php esc_html_e( 'Download .json', 'universal-consent-privacy-framework' ); ?></button>
		</p>
		<label for="ucpf-theme-pack-json"><strong><?php esc_html_e( 'Theme pack JSON', 'universal-consent-privacy-framework' ); ?></strong></label>
		<textarea id="ucpf-theme-pack-json" class="large-text code" rows="10" placeholder="{ &quot;schema&quot;: &quot;ucpf-theme/1.0&quot;, … }"></textarea>
		<p>
			<input type="file" id="ucpf-theme-pack-file" accept="application/json,.json" />
		</p>
		<p>
			<button type="button" class="button button-primary" id="ucpf-theme-import"><?php esc_html_e( 'Import theme', 'universal-consent-privacy-framework' ); ?></button>
		</p>
		<p id="ucpf-theme-pack-status" class="ucpf-wizard__status" hidden></p>
	</section>
</div>
