<div class="ucpf-legal">
	<?php
	$pack = \UCPF\Jurisdiction::instance()->resolve();
	$copy = isset( $pack['copy'] ) && is_array( $pack['copy'] ) ? $pack['copy'] : array();
	$title = ! empty( $copy['dns_title'] ) ? $copy['dns_title'] : __( 'Do Not Sell or Share', 'universal-consent-privacy-framework' );
	$intro = ! empty( $copy['dns_intro'] ) ? $copy['dns_intro'] : __( 'California and certain US state privacy laws may provide the right to opt out of the sale or sharing of personal information.', 'universal-consent-privacy-framework' );
	?>
	<p class="ucpf-legal__label"><?php echo esc_html( isset( $site_name ) ? $site_name : get_bloginfo( 'name' ) ); ?></p>
	<h1 class="ucpf-legal__title"><?php echo esc_html( $title ); ?></h1>
	<p><?php echo esc_html( $intro ); ?></p>
	<?php if ( ! empty( $pack['show_limit_sensitive'] ) ) : ?>
		<p><?php esc_html_e( 'Where required (for example under the California CPRA), you may also request that we limit the use of sensitive personal information using the form below.', 'universal-consent-privacy-framework' ); ?></p>
	<?php endif; ?>
	<p><?php esc_html_e( 'Notice at collection: we collect identifiers and internet activity for security, site functionality, and — only with your choices or applicable law — analytics and advertising. This page helps support privacy rights workflows; it is not legal advice and does not guarantee compliance.', 'universal-consent-privacy-framework' ); ?></p>
	<p class="ucpf-form__notice"><?php esc_html_e( 'Add the form on this page with [ucpf_do_not_sell_form] (or your Gravity Forms embed). Set this page’s URL under Privacy Consent → Generated Pages → Rights request pages.', 'universal-consent-privacy-framework' ); ?></p>
</div>
