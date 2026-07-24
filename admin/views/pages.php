<?php
/**
 * Generated pages + external rights URL hub.
 *
 * @package UCPF
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- included template; locals are not plugin globals.

if ( ! isset( $settings ) || ! is_array( $settings ) ) {
	$settings = \UCPF\Settings::all();
}

$option_key   = \UCPF\Settings::OPTION_KEY;
$auto_refresh = ! empty( $settings['auto_refresh_cookie_policy_after_scan'] );
$dr_url       = isset( $settings['data_request_page_url'] ) ? (string) $settings['data_request_page_url'] : '';
$dns_url      = isset( $settings['do_not_sell_page_url'] ) ? (string) $settings['do_not_sell_page_url'] : '';
$resolved_dr  = \UCPF\Page_Generator::instance()->get_rights_url( 'data_request' );
$resolved_dns = \UCPF\Page_Generator::instance()->get_rights_url( 'do_not_sell' );

$labels = array(
	'privacy_policy'      => __( 'Privacy Policy', 'universal-consent-privacy-framework' ),
	'cookie_policy'       => __( 'Cookie Policy', 'universal-consent-privacy-framework' ),
	'consent_preferences' => __( 'Consent Preferences', 'universal-consent-privacy-framework' ),
);
?>
<div class="wrap ucpf-admin">
	<h1><?php esc_html_e( 'Generated Pages', 'universal-consent-privacy-framework' ); ?></h1>

	<p><?php esc_html_e( 'Create Privacy Policy, Cookie Policy, and Consent Preferences from templates. Data Request and Do Not Sell forms live on your separate home site — paste those page URLs below so the banner and policies can link out.', 'universal-consent-privacy-framework' ); ?></p>

	<div class="ucpf-toolbar" role="group" aria-label="<?php esc_attr_e( 'Page actions', 'universal-consent-privacy-framework' ); ?>">
		<button type="button" class="button button-primary" id="ucpf-generate-pages"><?php esc_html_e( 'Generate missing pages', 'universal-consent-privacy-framework' ); ?></button>
		<button type="button" class="button" id="ucpf-regenerate-pages"><?php esc_html_e( 'Regenerate all pages (overwrite)', 'universal-consent-privacy-framework' ); ?></button>
		<button type="button" class="button" id="ucpf-refresh-cookie-policy"><?php esc_html_e( 'Refresh Cookie Policy only', 'universal-consent-privacy-framework' ); ?></button>
	</div>
	<div id="ucpf-pages-status" class="ucpf-wizard__status" hidden></div>

	<ul>
		<?php foreach ( $labels as $key => $label ) : ?>
			<?php
			$id  = isset( $settings['generated_pages'][ $key ] ) ? (int) $settings['generated_pages'][ $key ] : 0;
			$url = $id ? get_permalink( $id ) : '';
			?>
			<li><?php echo esc_html( $label ); ?> —
				<?php if ( $url ) : ?>
					<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'universal-consent-privacy-framework' ); ?></a>
					|
					<a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>"><?php esc_html_e( 'Edit', 'universal-consent-privacy-framework' ); ?></a>
				<?php else : ?>
					<?php esc_html_e( 'Not created', 'universal-consent-privacy-framework' ); ?>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>

	<form method="post" action="options.php" style="margin-top:2rem;">
		<?php settings_fields( 'ucpf_settings_group' ); ?>
		<input type="hidden" name="<?php echo esc_attr( $option_key ); ?>[_ucpf_pages_form]" value="1" />

		<h2><?php esc_html_e( 'After cookie scan', 'universal-consent-privacy-framework' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Auto-refresh Cookie Policy', 'universal-consent-privacy-framework' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $option_key ); ?>[auto_refresh_cookie_policy_after_scan]" value="1" <?php checked( $auto_refresh ); ?> />
						<?php esc_html_e( 'When a scan finishes, create or overwrite the Cookie Policy page so inventory and last-updated stay current.', 'universal-consent-privacy-framework' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Rights request pages (external)', 'universal-consent-privacy-framework' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'These URLs are for linking only. This plugin does not collect rights-form submissions — host those forms on your home site. This is not legal advice and does not guarantee compliance.', 'universal-consent-privacy-framework' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="ucpf-dr-page-url"><?php esc_html_e( 'Data Request page URL', 'universal-consent-privacy-framework' ); ?></label></th>
				<td>
					<input type="url" class="large-text code" id="ucpf-dr-page-url" name="<?php echo esc_attr( $option_key ); ?>[data_request_page_url]" value="<?php echo esc_attr( $dr_url ); ?>" placeholder="https://example.com/privacy-rights/" />
					<?php if ( $resolved_dr ) : ?>
						<p class="description"><?php esc_html_e( 'Resolved link:', 'universal-consent-privacy-framework' ); ?> <a href="<?php echo esc_url( $resolved_dr ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $resolved_dr ); ?></a></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ucpf-dns-page-url"><?php esc_html_e( 'Do Not Sell page URL', 'universal-consent-privacy-framework' ); ?></label></th>
				<td>
					<input type="url" class="large-text code" id="ucpf-dns-page-url" name="<?php echo esc_attr( $option_key ); ?>[do_not_sell_page_url]" value="<?php echo esc_attr( $dns_url ); ?>" placeholder="https://example.com/do-not-sell/" />
					<?php if ( $resolved_dns ) : ?>
						<p class="description"><?php esc_html_e( 'Resolved link:', 'universal-consent-privacy-framework' ); ?> <a href="<?php echo esc_url( $resolved_dns ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $resolved_dns ); ?></a></p>
					<?php endif; ?>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Save rights page URLs', 'universal-consent-privacy-framework' ) ); ?>
	</form>
</div>
