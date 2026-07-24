<?php
/**
 * Script registry admin view.
 *
 * @package UCPF
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- included template; locals are not plugin globals.
?>
<div class="wrap ucpf-admin">
	<h1><?php esc_html_e( 'Script Registry', 'universal-consent-privacy-framework' ); ?></h1>
	<p><?php esc_html_e( 'Local metadata catalog of common services. Configure GA / GTM / Pixel / Clarity IDs under Integrations.', 'universal-consent-privacy-framework' ); ?></p>
	<p>
		<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=ucpf-integrations' ) ); ?>">
			<?php esc_html_e( 'Configure tracking tags', 'universal-consent-privacy-framework' ); ?>
		</a>
	</p>
	<div class="ucpf-table-scroll">
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Service', 'universal-consent-privacy-framework' ); ?></th>
				<th><?php esc_html_e( 'Category', 'universal-consent-privacy-framework' ); ?></th>
				<th><?php esc_html_e( 'Provider', 'universal-consent-privacy-framework' ); ?></th>
				<th><?php esc_html_e( 'Source', 'universal-consent-privacy-framework' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $services as $service ) : ?>
			<tr>
				<td><code><?php echo esc_html( $service['key'] ); ?></code> — <?php echo esc_html( $service['name'] ); ?></td>
				<td><?php echo esc_html( $service['category'] ); ?></td>
				<td><?php echo esc_html( $service['provider'] ); ?></td>
				<td><?php echo esc_html( isset( $service['source'] ) ? $service['source'] : 'core' ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</div>

	<h2><?php esc_html_e( 'Import / export', 'universal-consent-privacy-framework' ); ?></h2>
	<p>
		<button type="button" class="button" id="ucpf-export-registry"><?php esc_html_e( 'Export registry JSON', 'universal-consent-privacy-framework' ); ?></button>
	</p>
	<p>
		<textarea id="ucpf-import-json" class="large-text code" rows="6" placeholder='{"services":[...]}'></textarea><br />
		<button type="button" class="button" id="ucpf-import-registry"><?php esc_html_e( 'Import registry JSON', 'universal-consent-privacy-framework' ); ?></button>
	</p>
</div>
