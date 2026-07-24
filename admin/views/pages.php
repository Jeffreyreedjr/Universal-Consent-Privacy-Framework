<?php
/**
 * Generated pages + rights request URL hub.
 *
 * @package UCPF
 */

defined( 'ABSPATH' ) || exit;

if ( ! isset( $settings ) || ! is_array( $settings ) ) {
	$settings = \UCPF\Settings::all();
}

$option_key   = \UCPF\Settings::OPTION_KEY;
$auto_refresh = ! empty( $settings['auto_refresh_cookie_policy_after_scan'] );
$gf_active    = class_exists( 'GFCommon' ) || function_exists( 'gravity_form' );
$gf_dr_id     = isset( $settings['gf_data_request_form_id'] ) ? (int) $settings['gf_data_request_form_id'] : 0;
$gf_dns_id    = isset( $settings['gf_do_not_sell_form_id'] ) ? (int) $settings['gf_do_not_sell_form_id'] : 0;
$gf_dr_sc     = isset( $settings['gf_data_request_shortcode'] ) ? (string) $settings['gf_data_request_shortcode'] : '';
$gf_dns_sc    = isset( $settings['gf_do_not_sell_shortcode'] ) ? (string) $settings['gf_do_not_sell_shortcode'] : '';
$dr_url       = isset( $settings['data_request_page_url'] ) ? (string) $settings['data_request_page_url'] : '';
$dns_url      = isset( $settings['do_not_sell_page_url'] ) ? (string) $settings['do_not_sell_page_url'] : '';
$resolved_dr  = \UCPF\Page_Generator::instance()->get_rights_url( 'data_request' );
$resolved_dns = \UCPF\Page_Generator::instance()->get_rights_url( 'do_not_sell' );

$labels = array(
	'privacy_policy'      => __( 'Privacy Policy', 'universal-consent-privacy-framework' ),
	'cookie_policy'       => __( 'Cookie Policy', 'universal-consent-privacy-framework' ),
	'consent_preferences' => __( 'Consent Preferences', 'universal-consent-privacy-framework' ),
);

$docs_rights = UCPF_PLUGIN_DIR . 'docs/RIGHTS-FORMS.md';
$docs_url    = file_exists( $docs_rights )
	? ''
	: 'https://github.com/Jeffreyreedjr/Universal-Consent-Privacy-Framework/blob/main/docs/RIGHTS-FORMS.md';
?>
<div class="wrap ucpf-admin">
	<h1><?php esc_html_e( 'Generated Pages', 'universal-consent-privacy-framework' ); ?></h1>

	<p><?php esc_html_e( 'Create Privacy Policy, Cookie Policy, and Consent Preferences from templates. Do Not Sell and Data Request live on your home-site pages — set their URLs below and paste a UCPF shortcode (or build a form that posts the documented API).', 'universal-consent-privacy-framework' ); ?></p>

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

		<h2><?php esc_html_e( 'Rights request pages (home site)', 'universal-consent-privacy-framework' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Point the banner, Privacy Policy, and jurisdiction links at pages you build on this site. Paste a shortcode on that page, or build Gravity Forms / custom HTML that posts the UCPF data-request API (see checklist below). This is not legal advice and does not guarantee compliance.', 'universal-consent-privacy-framework' ); ?>
			<?php if ( $docs_url ) : ?>
				<a href="<?php echo esc_url( $docs_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Full rights forms guide (GitHub)', 'universal-consent-privacy-framework' ); ?></a>
			<?php elseif ( file_exists( $docs_rights ) ) : ?>
				<?php esc_html_e( 'Full guide: docs/RIGHTS-FORMS.md in the plugin repository.', 'universal-consent-privacy-framework' ); ?>
			<?php endif; ?>
		</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="ucpf-dr-page-url"><?php esc_html_e( 'Data Request page URL', 'universal-consent-privacy-framework' ); ?></label></th>
				<td>
					<input type="url" class="large-text code" id="ucpf-dr-page-url" name="<?php echo esc_attr( $option_key ); ?>[data_request_page_url]" value="<?php echo esc_attr( $dr_url ); ?>" placeholder="https://example.com/privacy-rights/" />
					<p class="description">
						<?php esc_html_e( 'Shortcode to paste on that page:', 'universal-consent-privacy-framework' ); ?>
						<code>[ucpf_data_request_form]</code>
						<?php if ( $resolved_dr ) : ?>
							<br /><?php esc_html_e( 'Currently linked:', 'universal-consent-privacy-framework' ); ?>
							<a href="<?php echo esc_url( $resolved_dr ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $resolved_dr ); ?></a>
						<?php endif; ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ucpf-dns-page-url"><?php esc_html_e( 'Do Not Sell page URL', 'universal-consent-privacy-framework' ); ?></label></th>
				<td>
					<input type="url" class="large-text code" id="ucpf-dns-page-url" name="<?php echo esc_attr( $option_key ); ?>[do_not_sell_page_url]" value="<?php echo esc_attr( $dns_url ); ?>" placeholder="https://example.com/do-not-sell/" />
					<p class="description">
						<?php esc_html_e( 'Shortcode to paste on that page:', 'universal-consent-privacy-framework' ); ?>
						<code>[ucpf_do_not_sell_form]</code>
						<?php if ( $resolved_dns ) : ?>
							<br /><?php esc_html_e( 'Currently linked:', 'universal-consent-privacy-framework' ); ?>
							<a href="<?php echo esc_url( $resolved_dns ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $resolved_dns ); ?></a>
						<?php endif; ?>
					</p>
				</td>
			</tr>
		</table>

		<div class="ucpf-card" style="margin:1rem 0 1.5rem;padding:1rem 1.25rem;max-width:52rem;">
			<h3 style="margin-top:0;"><?php esc_html_e( 'Form build checklist', 'universal-consent-privacy-framework' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Use the built-in shortcode for full Rights Inbox + DNS enforcement, or POST JSON to /wp-json/ucpf/v1/data-request with the public nonce (X-WP-Nonce). Gravity Forms embed-only does not write to Rights Inbox unless you also call that API.', 'universal-consent-privacy-framework' ); ?></p>
			<ul>
				<li><strong><?php esc_html_e( 'Data Request', 'universal-consent-privacy-framework' ); ?></strong> —
					<?php esc_html_e( 'Required: email, request_type (access | deletion | correction | withdraw). Optional: message. Honeypot website must be empty. Built-in shortcode submits access.', 'universal-consent-privacy-framework' ); ?>
				</li>
				<li><strong><?php esc_html_e( 'Do Not Sell', 'universal-consent-privacy-framework' ); ?></strong> —
					<?php esc_html_e( 'request_type=do_not_sell; email; checkboxes opt_out_sale, opt_out_sharing, opt_out_targeted; optional limit_sensitive, scope (site|controller|selected), global_privacy_mode, message.', 'universal-consent-privacy-framework' ); ?>
				</li>
				<li><?php esc_html_e( 'Rate limit: 5 submissions per window. Spam honeypot field name: website.', 'universal-consent-privacy-framework' ); ?></li>
			</ul>
		</div>

		<h2><?php esc_html_e( 'Gravity Forms embeds (optional)', 'universal-consent-privacy-framework' ); ?></h2>
		<?php if ( ! $gf_active ) : ?>
			<p class="description"><?php esc_html_e( 'Gravity Forms is not detected. You can still save a form ID; embeds activate when GF is installed. Prefer [ucpf_data_request_form] / [ucpf_do_not_sell_form] when you need Rights Inbox.', 'universal-consent-privacy-framework' ); ?></p>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'When set, these replace the built-in markup inside the UCPF shortcodes. Embed-only GF does not hit Rights Inbox or DNS enforcement — map fields to the API or use the built-in shortcode.', 'universal-consent-privacy-framework' ); ?></p>
		<?php endif; ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="ucpf-gf-dr-id"><?php esc_html_e( 'Data Request form ID', 'universal-consent-privacy-framework' ); ?></label></th>
				<td>
					<input type="number" min="0" class="small-text" id="ucpf-gf-dr-id" name="<?php echo esc_attr( $option_key ); ?>[gf_data_request_form_id]" value="<?php echo esc_attr( (string) $gf_dr_id ); ?>" />
					<p class="description"><?php esc_html_e( 'Example: 12 → embeds [gravityform id="12" …]. Or use a custom shortcode below.', 'universal-consent-privacy-framework' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ucpf-gf-dr-sc"><?php esc_html_e( 'Data Request custom shortcode', 'universal-consent-privacy-framework' ); ?></label></th>
				<td>
					<input type="text" class="large-text code" id="ucpf-gf-dr-sc" name="<?php echo esc_attr( $option_key ); ?>[gf_data_request_shortcode]" value="<?php echo esc_attr( $gf_dr_sc ); ?>" placeholder='[gravityform id="12" title="false" description="false" ajax="true"]' />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ucpf-gf-dns-id"><?php esc_html_e( 'Do Not Sell form ID', 'universal-consent-privacy-framework' ); ?></label></th>
				<td>
					<input type="number" min="0" class="small-text" id="ucpf-gf-dns-id" name="<?php echo esc_attr( $option_key ); ?>[gf_do_not_sell_form_id]" value="<?php echo esc_attr( (string) $gf_dns_id ); ?>" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ucpf-gf-dns-sc"><?php esc_html_e( 'Do Not Sell custom shortcode', 'universal-consent-privacy-framework' ); ?></label></th>
				<td>
					<input type="text" class="large-text code" id="ucpf-gf-dns-sc" name="<?php echo esc_attr( $option_key ); ?>[gf_do_not_sell_shortcode]" value="<?php echo esc_attr( $gf_dns_sc ); ?>" placeholder='[gravityform id="15" title="false" ajax="true"]' />
				</td>
			</tr>
		</table>

		<p class="description">
			<?php esc_html_e( 'You can also place [ucpf_gravity_form id="12"] or the built-in rights shortcodes in any page content or Elementor shortcode widget.', 'universal-consent-privacy-framework' ); ?>
		</p>

		<?php submit_button( __( 'Save page & form settings', 'universal-consent-privacy-framework' ) ); ?>
	</form>
</div>
