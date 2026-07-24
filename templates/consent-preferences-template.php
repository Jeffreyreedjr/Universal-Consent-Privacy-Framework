<div class="ucpf-legal">
	<p class="ucpf-legal__label"><?php echo esc_html( isset( $site_name ) ? $site_name : get_bloginfo( 'name' ) ); ?></p>
	<h1 class="ucpf-legal__title"><?php esc_html_e( 'Consent Preferences', 'universal-consent-privacy-framework' ); ?></h1>
	<p><?php esc_html_e( 'Manage your cookie and tracking preferences at any time.', 'universal-consent-privacy-framework' ); ?></p>
	<?php
	$cookie_policy_url = \UCPF\Page_Generator::instance()->get_page_url( 'cookie_policy' );
	if ( $cookie_policy_url ) :
		?>
		<p><a href="<?php echo esc_url( $cookie_policy_url ); ?>"><?php esc_html_e( 'Read our Cookie Policy', 'universal-consent-privacy-framework' ); ?></a></p>
	<?php endif; ?>
</div>
