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

	<section class="ucpf-panel">
		<div class="ucpf-registry-toolbar">
			<div class="ucpf-registry-filter">
				<label class="screen-reader-text" for="ucpf-registry-search"><?php esc_html_e( 'Filter services', 'universal-consent-privacy-framework' ); ?></label>
				<input type="search" id="ucpf-registry-search" placeholder="<?php echo esc_attr__( 'Filter by name, key, category, or provider…', 'universal-consent-privacy-framework' ); ?>" autocomplete="off" />
			</div>
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=ucpf-integrations' ) ); ?>">
				<?php esc_html_e( 'Configure tracking tags', 'universal-consent-privacy-framework' ); ?>
			</a>
		</div>
		<div class="ucpf-table-scroll">
			<table class="widefat striped ucpf-registry-table" id="ucpf-registry-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Service', 'universal-consent-privacy-framework' ); ?></th>
						<th><?php esc_html_e( 'Category', 'universal-consent-privacy-framework' ); ?></th>
						<th><?php esc_html_e( 'Provider', 'universal-consent-privacy-framework' ); ?></th>
						<th><?php esc_html_e( 'Source', 'universal-consent-privacy-framework' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $services as $service ) :
					$cat    = isset( $service['category'] ) ? (string) $service['category'] : '';
					$source = isset( $service['source'] ) ? (string) $service['source'] : 'core';
					$hay    = strtolower(
						( isset( $service['key'] ) ? $service['key'] : '' ) . ' ' .
						( isset( $service['name'] ) ? $service['name'] : '' ) . ' ' .
						$cat . ' ' .
						( isset( $service['provider'] ) ? $service['provider'] : '' ) . ' ' .
						$source
					);
					?>
					<tr data-ucpf-filter="<?php echo esc_attr( $hay ); ?>">
						<td>
							<div class="ucpf-service-cell">
								<code class="ucpf-slug"><?php echo esc_html( $service['key'] ); ?></code>
								<span class="ucpf-service-name"><?php echo esc_html( $service['name'] ); ?></span>
							</div>
						</td>
						<td><span class="ucpf-cat ucpf-cat--<?php echo esc_attr( sanitize_key( $cat ) ); ?>"><?php echo esc_html( $cat ); ?></span></td>
						<td><?php echo esc_html( $service['provider'] ); ?></td>
						<td><?php echo esc_html( $source ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<p class="description" id="ucpf-registry-empty" hidden><?php esc_html_e( 'No services match that filter.', 'universal-consent-privacy-framework' ); ?></p>
	</section>

	<section class="ucpf-panel">
		<h2 class="ucpf-panel__title"><?php esc_html_e( 'Import / export', 'universal-consent-privacy-framework' ); ?></h2>
		<p class="ucpf-panel__lede"><?php esc_html_e( 'Export the local catalog or import a JSON pack for agency / fleet use.', 'universal-consent-privacy-framework' ); ?></p>
		<p>
			<button type="button" class="button" id="ucpf-export-registry"><?php esc_html_e( 'Export registry JSON', 'universal-consent-privacy-framework' ); ?></button>
		</p>
		<p>
			<textarea id="ucpf-import-json" class="large-text code" rows="6" placeholder='{"services":[...]}'></textarea><br />
			<button type="button" class="button button-primary" id="ucpf-import-registry"><?php esc_html_e( 'Import registry JSON', 'universal-consent-privacy-framework' ); ?></button>
		</p>
	</section>
</div>
