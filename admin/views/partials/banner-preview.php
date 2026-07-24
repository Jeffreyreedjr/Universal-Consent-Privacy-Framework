<?php
/**
 * Banner preview partial.
 *
 * @package UCPF
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- included template; locals are not plugin globals.

\UCPF\Theme_Manager::instance()->enqueue_admin_preview_styles();

$layout = \UCPF\Settings::get( 'banner_layout' );
if ( ! in_array( $layout, array( 'bar', 'modal', 'corner' ), true ) ) {
	$layout = 'bar';
}
$theme = \UCPF\Theme_Manager::instance()->resolve_preset( \UCPF\Settings::get( 'banner_theme' ) );
?>
<div class="ucpf-admin__preview" id="ucpf-banner-preview">
	<p class="description" style="position:absolute;top:0.5rem;left:0.75rem;z-index:2;margin:0;color:#ccc;">
		<?php esc_html_e( 'Preview updates when you change layout or theme (save to apply on the site).', 'universal-consent-privacy-framework' ); ?>
	</p>
	<div id="ucpf-root" class="ucpf-theme-<?php echo esc_attr( $theme ); ?>">
		<div
			class="ucpf-banner ucpf-banner--<?php echo esc_attr( $layout ); ?> ucpf-banner--visible"
			id="ucpf-banner-preview-el"
			role="img"
			aria-label="<?php esc_attr_e( 'Cookie banner preview', 'universal-consent-privacy-framework' ); ?>"
			data-ucpf-layout="<?php echo esc_attr( $layout ); ?>"
		>
			<div class="ucpf-modal__overlay" <?php echo 'modal' === $layout ? '' : 'hidden'; ?>></div>
			<div class="ucpf-banner__panel" tabindex="-1">
				<div class="ucpf-banner__inner">
					<div class="ucpf-banner__content">
						<p class="ucpf-banner__label"><?php esc_html_e( 'Cookies', 'universal-consent-privacy-framework' ); ?></p>
						<p class="ucpf-banner__text">
							<?php esc_html_e( 'We use essential cookies for security and optional cookies based on your choices.', 'universal-consent-privacy-framework' ); ?>
						</p>
					</div>
					<div class="ucpf-banner__actions">
						<span class="ucpf-btn ucpf-btn--pill ucpf-btn--ghost" aria-hidden="true"><?php esc_html_e( 'Customize', 'universal-consent-privacy-framework' ); ?></span>
						<span class="ucpf-btn ucpf-btn--pill ucpf-btn--primary-tier ucpf-btn--outline" aria-hidden="true"><?php esc_html_e( 'Reject All', 'universal-consent-privacy-framework' ); ?></span>
						<span class="ucpf-btn ucpf-btn--pill ucpf-btn--primary-tier ucpf-btn--fill" aria-hidden="true"><?php esc_html_e( 'Accept All', 'universal-consent-privacy-framework' ); ?></span>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
