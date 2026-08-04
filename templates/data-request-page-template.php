<?php
/**
 * Optional intro copy for an external Data Request / privacy rights page.
 * Forms are hosted on your home site — set data_request_page_url under Generated Pages.
 *
 * @package UCPF
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- included template; locals are not plugin globals.
?>
<div class="ucpf-legal">
	<p class="ucpf-legal__label"><?php echo esc_html( isset( $site_name ) ? $site_name : get_bloginfo( 'name' ) ); ?></p>
	<h1 class="ucpf-legal__title"><?php esc_html_e( 'Privacy Rights Requests', 'universal-consent-privacy-framework' ); ?></h1>
	<p><?php esc_html_e( 'Submit a request to access, correct, delete, or export your personal data on the privacy rights page linked from this site. Requests are handled off this WordPress install.', 'universal-consent-privacy-framework' ); ?></p>
</div>
