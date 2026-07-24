<?php
/**
 * Cookie Policy generated-page template.
 *
 * @package UCPF
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- included template; locals are not plugin globals.
?>
<div class="ucpf-legal">
	<p class="ucpf-legal__label"><?php echo esc_html( $site_name ); ?></p>
	<h1 class="ucpf-legal__title"><?php esc_html_e( 'Cookie Policy', 'universal-consent-privacy-framework' ); ?></h1>
	<p class="ucpf-legal__meta"><?php
		echo esc_html(
			sprintf(
				/* translators: %s: last updated date */
				__( 'Last updated: %s', 'universal-consent-privacy-framework' ),
				$last_updated
			)
		);
	?></p>
	<p><?php
		echo esc_html(
			sprintf(
				/* translators: %s: business name */
				__( 'This Cookie Policy explains how %s (“we”) uses cookies and similar technologies on this website.', 'universal-consent-privacy-framework' ),
				$business_name
			)
		);
	?></p>
	<p><?php esc_html_e( 'Cookies are small text files stored on your device. Similar technologies include localStorage, pixels, and embedded scripts. Essential cookies keep the site secure and working. Optional cookies (analytics, marketing, embeds) load only after you give consent through our Cookie Settings banner.', 'universal-consent-privacy-framework' ); ?></p>
	<p><?php esc_html_e( 'The inventory below is generated from a privacy scan of this site. It lists cookies and related technologies we observed, with category and purpose where known. Re-scan and refresh this page after major site changes so the list stays current. This is a technical disclosure — not a legal compliance guarantee.', 'universal-consent-privacy-framework' ); ?></p>
</div>
