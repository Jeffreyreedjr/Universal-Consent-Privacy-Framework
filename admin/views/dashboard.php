<?php
/**
 * Admin dashboard — React mount + shell.
 *
 * @package UCPF
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- included template; locals are not plugin globals.

$ucpf_shell_current      = 'dashboard';
$ucpf_shell_hide_heading = true;
$ucpf_shell_title        = __( 'Privacy Consent Dashboard', 'universal-consent-privacy-framework' );
$ucpf_shell_lede         = '';

ob_start();
?>
	<div id="ucpf-admin-root" class="ucpf-admin" aria-busy="true">
		<p class="ucpf-shell__lede"><?php esc_html_e( 'Loading dashboard…', 'universal-consent-privacy-framework' ); ?></p>
	</div>
	<noscript>
		<p><?php esc_html_e( 'Enable JavaScript to use the interactive dashboard, or open the Setup Wizard from the menu.', 'universal-consent-privacy-framework' ); ?></p>
	</noscript>
<?php
$ucpf_shell_body = ob_get_clean();
include UCPF_PLUGIN_DIR . 'admin/views/partials/shell.php';
