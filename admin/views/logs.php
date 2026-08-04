<?php
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- included template; locals are not plugin globals.

$items      = isset( $logs['items'] ) && is_array( $logs['items'] ) ? $logs['items'] : array();
$page       = isset( $logs['page'] ) ? max( 1, (int) $logs['page'] ) : 1;
$pages      = isset( $logs['pages'] ) ? max( 0, (int) $logs['pages'] ) : 0;
$total      = isset( $logs['total'] ) ? max( 0, (int) $logs['total'] ) : 0;
$per_page   = isset( $logs['per_page'] ) ? max( 1, (int) $logs['per_page'] ) : 50;
$retention  = isset( $retention_days ) ? max( 1, (int) $retention_days ) : 360;
$filters    = isset( $logs['filters'] ) && is_array( $logs['filters'] ) ? $logs['filters'] : array();
$view       = isset( $logs['view'] ) ? sanitize_key( (string) $logs['view'] ) : 'events';
if ( 'visitors' !== $view ) {
	$view = 'events';
}

$filter_uuid      = isset( $filters['uuid'] ) ? (string) $filters['uuid'] : '';
$filter_action    = isset( $filters['action'] ) ? (string) $filters['action'] : '';
$filter_date_from = isset( $filters['date_from'] ) ? (string) $filters['date_from'] : '';
$filter_date_to   = isset( $filters['date_to'] ) ? (string) $filters['date_to'] : '';

$base_args = array(
	'page' => 'ucpf-logs',
	'view' => $view,
);
if ( '' !== $filter_uuid ) {
	$base_args['uuid'] = $filter_uuid;
}
if ( '' !== $filter_action ) {
	$base_args['log_action'] = $filter_action;
}
if ( '' !== $filter_date_from ) {
	$base_args['date_from'] = $filter_date_from;
}
if ( '' !== $filter_date_to ) {
	$base_args['date_to'] = $filter_date_to;
}
$base_url = add_query_arg( $base_args, admin_url( 'admin.php' ) );

$events_url = add_query_arg(
	array_merge(
		$base_args,
		array(
			'view'  => 'events',
			'paged' => false,
		)
	),
	admin_url( 'admin.php' )
);
$visitors_url = add_query_arg(
	array_merge(
		$base_args,
		array(
			'view'  => 'visitors',
			'paged' => false,
		)
	),
	admin_url( 'admin.php' )
);

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

$ucpf_actions = array(
	'accept_all'       => __( 'Accept all', 'universal-consent-privacy-framework' ),
	'reject_all'       => __( 'Reject all', 'universal-consent-privacy-framework' ),
	'save_preferences' => __( 'Save preferences', 'universal-consent-privacy-framework' ),
	'withdraw'         => __( 'Withdraw', 'universal-consent-privacy-framework' ),
);
?>
<div class="wrap ucpf-admin">
	<h1><?php esc_html_e( 'Consent Logs', 'universal-consent-privacy-framework' ); ?></h1>
	<p class="description">
		<?php
		if ( 'visitors' === $view ) {
			echo esc_html(
				sprintf(
					/* translators: 1: retention days, 2: visitor count */
					__( 'Privacy-minimized consent events (UUID, action, categories, timestamps — no IP). Rapid identical clicks are collapsed; real preference changes are kept. Retained about %1$d days. Showing %2$s visitors.', 'universal-consent-privacy-framework' ),
					$retention,
					number_format_i18n( $total )
				)
			);
		} else {
			echo esc_html(
				sprintf(
					/* translators: 1: retention days, 2: event count */
					__( 'Privacy-minimized consent events (UUID, action, categories, timestamps — no IP). Rapid identical clicks are collapsed; real preference changes are kept. Retained about %1$d days. Showing %2$s events.', 'universal-consent-privacy-framework' ),
					$retention,
					number_format_i18n( $total )
				)
			);
		}
		?>
	</p>

	<nav class="nav-tab-wrapper ucpf-logs-tabs" aria-label="<?php echo esc_attr__( 'Consent log views', 'universal-consent-privacy-framework' ); ?>">
		<a href="<?php echo esc_url( $events_url ); ?>" class="nav-tab <?php echo 'events' === $view ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Events', 'universal-consent-privacy-framework' ); ?>
		</a>
		<a href="<?php echo esc_url( $visitors_url ); ?>" class="nav-tab <?php echo 'visitors' === $view ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Visitors', 'universal-consent-privacy-framework' ); ?>
		</a>
	</nav>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="ucpf-logs-filters">
		<input type="hidden" name="page" value="ucpf-logs" />
		<input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>" />
		<label>
			<span class="screen-reader-text"><?php esc_html_e( 'UUID', 'universal-consent-privacy-framework' ); ?></span>
			<input type="search" name="uuid" value="<?php echo esc_attr( $filter_uuid ); ?>" placeholder="<?php echo esc_attr__( 'UUID (prefix or exact)', 'universal-consent-privacy-framework' ); ?>" class="regular-text" />
		</label>
		<label>
			<span class="screen-reader-text"><?php esc_html_e( 'Action', 'universal-consent-privacy-framework' ); ?></span>
			<select name="log_action">
				<option value=""><?php esc_html_e( 'All actions', 'universal-consent-privacy-framework' ); ?></option>
				<?php foreach ( $ucpf_actions as $action_key => $action_label ) : ?>
					<option value="<?php echo esc_attr( $action_key ); ?>" <?php selected( $filter_action, $action_key ); ?>>
						<?php echo esc_html( $action_label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>
		<label>
			<span><?php esc_html_e( 'From', 'universal-consent-privacy-framework' ); ?></span>
			<input type="date" name="date_from" value="<?php echo esc_attr( $filter_date_from ); ?>" />
		</label>
		<label>
			<span><?php esc_html_e( 'To', 'universal-consent-privacy-framework' ); ?></span>
			<input type="date" name="date_to" value="<?php echo esc_attr( $filter_date_to ); ?>" />
		</label>
		<?php submit_button( __( 'Filter', 'universal-consent-privacy-framework' ), 'secondary', '', false ); ?>
		<?php if ( $filter_uuid || $filter_action || $filter_date_from || $filter_date_to ) : ?>
			<a class="button button-link" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ucpf-logs', 'view' => $view ), admin_url( 'admin.php' ) ) ); ?>">
				<?php esc_html_e( 'Clear', 'universal-consent-privacy-framework' ); ?>
			</a>
		<?php endif; ?>
	</form>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ucpf-logs-export">
		<?php wp_nonce_field( 'ucpf_export_logs' ); ?>
		<input type="hidden" name="action" value="ucpf_export_logs" />
		<input type="hidden" name="uuid" value="<?php echo esc_attr( $filter_uuid ); ?>" />
		<input type="hidden" name="log_action" value="<?php echo esc_attr( $filter_action ); ?>" />
		<input type="hidden" name="date_from" value="<?php echo esc_attr( $filter_date_from ); ?>" />
		<input type="hidden" name="date_to" value="<?php echo esc_attr( $filter_date_to ); ?>" />
		<?php submit_button( __( 'Export CSV', 'universal-consent-privacy-framework' ), 'secondary', 'submit', false ); ?>
		<span class="description"><?php esc_html_e( 'Exports matching events (up to 5,000), including categories and region.', 'universal-consent-privacy-framework' ); ?></span>
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
	<?php if ( 'visitors' === $view ) : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'UUID', 'universal-consent-privacy-framework' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Latest action', 'universal-consent-privacy-framework' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Categories', 'universal-consent-privacy-framework' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Region', 'universal-consent-privacy-framework' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Events', 'universal-consent-privacy-framework' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last seen (UTC)', 'universal-consent-privacy-framework' ); ?></th>
					<th scope="col"><?php esc_html_e( 'History', 'universal-consent-privacy-framework' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( ! $items ) : ?>
				<tr>
					<td colspan="7">
						<?php esc_html_e( 'No visitors match these filters.', 'universal-consent-privacy-framework' ); ?>
					</td>
				</tr>
			<?php else : ?>
				<?php foreach ( $items as $log ) : ?>
					<?php
					$uuid = isset( $log['consent_uuid'] ) ? (string) $log['consent_uuid'] : '';
					$history_url = add_query_arg(
						array(
							'page' => 'ucpf-logs',
							'view' => 'events',
							'uuid' => $uuid,
						),
						admin_url( 'admin.php' )
					);
					?>
					<tr>
						<td><code><?php echo esc_html( $uuid ); ?></code></td>
						<td><?php echo esc_html( isset( $log['action'] ) ? (string) $log['action'] : '' ); ?></td>
						<td><?php echo esc_html( $ucpf_format_cats( isset( $log['categories'] ) ? $log['categories'] : '' ) ); ?></td>
						<td><?php echo esc_html( ! empty( $log['region'] ) ? (string) $log['region'] : '—' ); ?></td>
						<td><?php echo esc_html( isset( $log['event_count'] ) ? number_format_i18n( (int) $log['event_count'] ) : '1' ); ?></td>
						<td><?php echo esc_html( isset( $log['created_at'] ) ? (string) $log['created_at'] : '' ); ?></td>
						<td>
							<a href="<?php echo esc_url( $history_url ); ?>">
								<?php esc_html_e( 'View history', 'universal-consent-privacy-framework' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
	<?php else : ?>
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
	<?php endif; ?>
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
