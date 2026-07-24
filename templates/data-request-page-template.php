<?php
/**
 * Optional intro copy for a home-site Data Request / privacy rights page.
 * Not auto-generated — paste [ucpf_data_request_form] on your page and set
 * data_request_page_url under Generated Pages.
 *
 * @package UCPF
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="ucpf-legal">
	<p class="ucpf-legal__label"><?php echo esc_html( isset( $site_name ) ? $site_name : get_bloginfo( 'name' ) ); ?></p>
	<h1 class="ucpf-legal__title"><?php esc_html_e( 'Privacy Rights Requests', 'universal-consent-privacy-framework' ); ?></h1>
	<p><?php esc_html_e( 'Submit a request to access, correct, delete, or export your personal data. We will review requests according to applicable law.', 'universal-consent-privacy-framework' ); ?></p>
	<p class="ucpf-form__notice"><?php esc_html_e( 'Add the form with [ucpf_data_request_form] (or your Gravity Forms embed). Set this page’s URL under Privacy Consent → Generated Pages → Rights request pages.', 'universal-consent-privacy-framework' ); ?></p>
</div>
