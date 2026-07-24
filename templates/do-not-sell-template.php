<?php
/**
 * Do Not Sell generated-page template (link target / intro only).
 *
 * @package UCPF
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- included template; locals are not plugin globals.
?>
<div class="ucpf-legal">
	<?php
	$pack  = \UCPF\Jurisdiction::instance()->resolve();
	$copy  = isset( $pack['copy'] ) && is_array( $pack['copy'] ) ? $pack['copy'] : array();
	$title = ! empty( $copy['dns_title'] ) ? $copy['dns_title'] : __( 'Do Not Sell or Share', 'universal-consent-privacy-framework' );
	$intro = ! empty( $copy['dns_intro'] ) ? $copy['dns_intro'] : __( 'California and certain US state privacy laws may provide the right to opt out of the sale or sharing of personal information.', 'universal-consent-privacy-framework' );
	?>
	<p class="ucpf-legal__label"><?php echo esc_html( isset( $site_name ) ? $site_name : get_bloginfo( 'name' ) ); ?></p>
	<h1 class="ucpf-legal__title"><?php echo esc_html( $title ); ?></h1>
	<p><?php echo esc_html( $intro ); ?></p>
	<p><?php esc_html_e( 'Use the Do Not Sell / privacy rights form on the page URL configured under Generated Pages. That form is hosted externally — this plugin only links to it.', 'universal-consent-privacy-framework' ); ?></p>
</div>
