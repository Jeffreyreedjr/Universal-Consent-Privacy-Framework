<?php defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- included template; locals are not plugin globals. ?>
<div class="wrap ucpf-admin">
	<h1><?php esc_html_e( 'Consent Logs', 'universal-consent-privacy-framework' ); ?></h1>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'ucpf_export_logs' ); ?>
		<input type="hidden" name="action" value="ucpf_export_logs" />
		<?php submit_button( __( 'Export CSV', 'universal-consent-privacy-framework' ), 'secondary' ); ?>
	</form>
	<div class="ucpf-table-scroll">
	<table class="widefat striped">
		<thead><tr><th>ID</th><th><?php esc_html_e( 'UUID', 'universal-consent-privacy-framework' ); ?></th><th><?php esc_html_e( 'Action', 'universal-consent-privacy-framework' ); ?></th><th><?php esc_html_e( 'Created', 'universal-consent-privacy-framework' ); ?></th></tr></thead>
		<tbody>
		<?php foreach ( $logs['items'] as $log ) : ?>
			<tr>
				<td><?php echo esc_html( $log['id'] ); ?></td>
				<td><code><?php echo esc_html( $log['consent_uuid'] ); ?></code></td>
				<td><?php echo esc_html( $log['action'] ); ?></td>
				<td><?php echo esc_html( $log['created_at'] ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</div>
</div>
