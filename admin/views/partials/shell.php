<?php
/**
 * Shared admin shell (nav + main).
 *
 * Expects: $ucpf_shell_title, $ucpf_shell_lede (optional), $ucpf_shell_current (slug).
 * Content is captured via $ucpf_shell_content callback or buffered HTML in $ucpf_shell_body.
 *
 * @package UCPF
 */

defined( 'ABSPATH' ) || exit;

$product = \UCPF\Brand::product_name();
$current = isset( $ucpf_shell_current ) ? (string) $ucpf_shell_current : 'dashboard';
$title   = isset( $ucpf_shell_title ) ? (string) $ucpf_shell_title : $product;
$lede    = isset( $ucpf_shell_lede ) ? (string) $ucpf_shell_lede : '';

$nav = array(
	'dashboard'    => array( __( 'Dashboard', 'universal-consent-privacy-framework' ), admin_url( 'admin.php?page=ucpf-dashboard' ) ),
	'wizard'       => array( __( 'Setup Wizard', 'universal-consent-privacy-framework' ), admin_url( 'admin.php?page=ucpf-wizard' ) ),
	'banner'       => array( __( 'Banner & Branding', 'universal-consent-privacy-framework' ), admin_url( 'admin.php?page=ucpf-banner' ) ),
	'registry'     => array( __( 'Script Registry', 'universal-consent-privacy-framework' ), admin_url( 'admin.php?page=ucpf-registry' ) ),
	'scanner'      => array( __( 'Cookie Scanner', 'universal-consent-privacy-framework' ), admin_url( 'admin.php?page=ucpf-scanner' ) ),
	'pages'        => array( __( 'Generated Pages', 'universal-consent-privacy-framework' ), admin_url( 'admin.php?page=ucpf-pages' ) ),
	'rights'       => array( __( 'Rights Inbox', 'universal-consent-privacy-framework' ), admin_url( 'admin.php?page=ucpf-rights' ) ),
	'logs'         => array( __( 'Consent Logs', 'universal-consent-privacy-framework' ), admin_url( 'admin.php?page=ucpf-logs' ) ),
	'integrations' => array( __( 'Integrations', 'universal-consent-privacy-framework' ), admin_url( 'admin.php?page=ucpf-integrations' ) ),
	'developer'    => array( __( 'Developer API', 'universal-consent-privacy-framework' ), admin_url( 'admin.php?page=ucpf-developer' ) ),
	'advanced'     => array( __( 'Advanced', 'universal-consent-privacy-framework' ), admin_url( 'admin.php?page=ucpf-advanced' ) ),
);
?>
<div class="wrap ucpf-admin">
	<div class="ucpf-shell">
		<nav class="ucpf-shell__nav" aria-label="<?php echo esc_attr( $product ); ?>">
			<p class="ucpf-shell__brand"><?php echo esc_html( $product ); ?></p>
			<ul class="ucpf-shell__nav-list">
				<?php foreach ( $nav as $slug => $item ) : ?>
					<li>
						<a
							class="ucpf-shell__nav-link<?php echo $current === $slug ? ' is-active' : ''; ?>"
							href="<?php echo esc_url( $item[1] ); ?>"
							<?php echo $current === $slug ? ' aria-current="page"' : ''; ?>
						>
							<?php echo esc_html( $item[0] ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</nav>
		<div class="ucpf-shell__main">
			<?php if ( empty( $ucpf_shell_hide_heading ) ) : ?>
				<h1 class="ucpf-shell__title"><?php echo esc_html( $title ); ?></h1>
				<?php if ( $lede ) : ?>
					<p class="ucpf-shell__lede"><?php echo esc_html( $lede ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
			<?php
			if ( isset( $ucpf_shell_body ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled from escaped view buffers.
				echo $ucpf_shell_body;
			}
			?>
		</div>
	</div>
</div>
