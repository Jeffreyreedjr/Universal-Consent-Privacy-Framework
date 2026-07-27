<?php
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- included template; locals are not plugin globals.

$items      = isset( $logs['items'] ) && is_array( $logs['items'] ) ? $logs['items'] : array();
$page       = isset( $logs['page'] ) ? max( 1, (int) $logs['page'] ) : 1;
$pages      = isset( $logs['pages'] ) ? max( 0, (int) $logs['pages'] ) : 0;
$total      = isset( $logs['total'] ) ? max( 0, (int) $logs['total'] ) : 0;
$per_page   = isset( $logs['per_page'] ) ? max( 1, (int) $logs['per_page'] ) : 50;
$retention  = isset( $retention_days ) ? max( 1, (int) $retention_days ) : 360;
$base_url   = admin_url( 'admin.php?page=ucpf-logs' );

/**
 * Format category JSON for display.
 *
 * @param mixed $raw JSON string or array.
 * @return string
 */
$ucpf_format_cats = static function ( $raw ) {
	if ( is_string( $raw ) ) {
		$decoded = json_decode( $raw, true );
	} else {
		$decoded = $raw;
	}
	if ( ! is_array( $decoded ) ) {
		return '—';
	}
	$on = array();
	foreach ( $decoded as $key => $val ) {
		if ( ! empty( $val ) ) {
			$on[] = sanitize_key( (string) $key );
		}
	}
	return $on ? implode( ', ', $on ) : '—';
};
?>
<div class="wrap ucpf-admin">
	<h1><?php esc_html_e( 'Consent Logs', 'universal-consent-privacy-framework' ); ?></h1>
	<p class="description">
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: retention days, 2: total log count */
				__( 'Hashed consent events (UUID, action, categories, timestamps — no IP). Kept about %1$d days. Showing %2$s total.', 'universal-consent-privacy-framework' ),
				$retention,
				number_format_i18n( $total )
			)
		);
		?>
	</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:1rem;">
		<?php wp_nonce_field( 'ucpf_export_logs' ); ?>
		<input type="hidden" name="action" value="ucpf_export_logs" />
		<?php submit_button( __( 'Export CSV', 'universal-consent-privacy-framework' ), 'secondary', 'submit', false ); ?>
	</form>

	<?php if ( $pages > 1 ) : ?>
		<div class="tablenav top">
			<div class="tablenav-pages">
				<span class="displaying-num">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: current page, 2: total pages */
							__( 'Page %1$d of %2$d', 'universal-consent-privacy-framework' ),
							$page,
							$pages
						)
					);
					?>
				</span>
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%', $base_url ),
							'format'    => '',
							'current'   => $page,
							'total'     => $pages,
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
							'type'      => 'plain',
						)
					)
				);
				?>
			</div>
		</div>
	<?php endif; ?>

	<div class="ucpf-table-scroll" tabindex="0" aria-label="<?php echo esc_attr__( 'Consent logs table', 'universal-consent-privacy-framework' ); ?>">
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'ID', 'universal-consent-privacy-framework' ); ?></th>
				<th scope="col"><?php esc_html_e( 'UUID', 'universal-consent-privacy-framework' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Action', 'universal-consent-privacy-framework' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Categories', 'universal-consent-privacy-framework' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Region', 'universal-consent-privacy-framework' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Created (UTC)', 'universal-consent-privacy-framework' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( ! $items ) : ?>
			<tr>
				<td colspan="6">
					<?php esc_html_e( 'No consent logs yet. Events appear here after visitors accept, reject, or save preferences (when logging is enabled).', 'universal-consent-privacy-framework' ); ?>
				</td>
			</tr>
		<?php else : ?>
			<?php foreach ( $items as $log ) : ?>
				<tr>
					<td><?php echo esc_html( isset( $log['id'] ) ? (string) $log['id'] : '' ); ?></td>
					<td><code><?php echo esc_html( isset( $log['consent_uuid'] ) ? (string) $log['consent_uuid'] : '' ); ?></code></td>
					<td><?php echo esc_html( isset( $log['action'] ) ? (string) $log['action'] : '' ); ?></td>
					<td><?php echo esc_html( $ucpf_format_cats( isset( $log['categories'] ) ? $log['categories'] : '' ) ); ?></td>
					<td><?php echo esc_html( ! empty( $log['region'] ) ? (string) $log['region'] : '—' ); ?></td>
					<td><?php echo esc_html( isset( $log['created_at'] ) ? (string) $log['created_at'] : '' ); ?></td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>
	</div>

	<?php if ( $pages > 1 ) : ?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%', $base_url ),
							'format'    => '',
							'current'   => $page,
							'total'     => $pages,
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
							'type'      => 'plain',
						)
					)
				);
				?>
			</div>
		</div>
	<?php endif; ?>
</div>
