<?php
/**
 * Repeatable GTM container rows (Integrations + wizard).
 *
 * Expected vars:
 * - $name_prefix (string)
 * - $meta (array)
 * - $gtm_row (array, preferred) full service_ids[google_tag_manager] row including legacy id
 * - $containers (array, optional fallback)
 * - $gtm_suggestions (array, optional)
 *
 * @package UCPF
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$name_prefix     = isset( $name_prefix ) ? (string) $name_prefix : 'service_ids[google_tag_manager]';
$meta            = isset( $meta ) && is_array( $meta ) ? $meta : array();
$containers      = isset( $containers ) && is_array( $containers ) ? $containers : array();
$gtm_suggestions = isset( $gtm_suggestions ) && is_array( $gtm_suggestions ) ? $gtm_suggestions : array();

// Prefer full row so legacy `id` migrates into containers[] for display + save.
$gtm_seed = ( isset( $gtm_row ) && is_array( $gtm_row ) ) ? $gtm_row : array(
	'containers' => $containers,
	'id'         => isset( $legacy_seed_id ) ? $legacy_seed_id : '',
);
$row        = \UCPF\Tracking_Templates::normalize_gtm_row( $gtm_seed );
$containers = $row['containers'];
if ( ! $containers ) {
	$containers = array(
		\UCPF\Tracking_Templates::sanitize_gtm_container( array() ),
	);
	$containers[0]['id'] = '';
}

$legacy_id    = isset( $row['id'] ) ? $row['id'] : '';
$suggest_json = $gtm_suggestions ? wp_json_encode( array_values( $gtm_suggestions ) ) : '[]';
$purpose_opts = \UCPF\Tracking_Templates::gtm_disclosure_purpose_options();
$use_opts     = \UCPF\Tracking_Templates::gtm_disclosure_use_options();

/**
 * Render one container card (shared by live rows + template).
 *
 * @param array  $c           Container row.
 * @param int    $i           Index (or -1 for template).
 * @param string $name_prefix Field name prefix.
 * @param array  $meta        Template meta.
 * @param array  $purpose_opts Purpose checkbox map.
 * @param array  $use_opts    Use checkbox map.
 * @param bool   $is_template Whether this is the <template> clone source.
 */
$ucpf_render_gtm_card = static function ( $c, $i, $name_prefix, $meta, $purpose_opts, $use_opts, $is_template = false ) {
	$disc = isset( $c['disclosure'] ) && is_array( $c['disclosure'] ) ? $c['disclosure'] : \UCPF\Tracking_Templates::empty_gtm_disclosure();
	$purposes = isset( $disc['purposes'] ) && is_array( $disc['purposes'] ) ? $disc['purposes'] : array();
	$uses     = isset( $disc['uses'] ) && is_array( $disc['uses'] ) ? $disc['uses'] : array();
	$has_disc = \UCPF\Tracking_Templates::gtm_disclosure_has_content( $disc );
	$base     = $is_template ? '' : $name_prefix . '[containers][' . (int) $i . ']';
	$name_attr = static function ( $field ) use ( $is_template, $base ) {
		$field_attr = ' data-ucpf-gtm-field="' . esc_attr( $field ) . '"';
		if ( $is_template ) {
			return 'data-name="' . esc_attr( $field ) . '"' . $field_attr;
		}
		return 'name="' . esc_attr( $base . '[' . $field . ']' ) . '"' . $field_attr;
	};
	$disc_name = static function ( $field, $is_array = false ) use ( $is_template, $base ) {
		$suffix     = $is_array ? '[]' : '';
		$field_key  = 'disclosure.' . $field . ( $is_array ? '[]' : '' );
		$field_attr = ' data-ucpf-gtm-field="' . esc_attr( $field_key ) . '"';
		if ( $is_template ) {
			return 'data-name="' . esc_attr( $field_key ) . '"' . $field_attr;
		}
		return 'name="' . esc_attr( $base . '[disclosure][' . $field . ']' . $suffix ) . '"' . $field_attr;
	};
	?>
	<div class="ucpf-gtm-containers__item" data-ucpf-gtm-row>
		<div class="ucpf-gtm-containers__item-main">
			<div class="ucpf-gtm-containers__field ucpf-gtm-containers__field--label">
				<label><?php esc_html_e( 'Label (optional)', 'universal-consent-privacy-framework' ); ?></label>
				<input
					type="text"
					class="regular-text"
					<?php echo $name_attr( 'label' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					value="<?php echo esc_attr( isset( $c['label'] ) ? $c['label'] : '' ); ?>"
					placeholder="<?php esc_attr_e( 'e.g. Main site, GTM4WP', 'universal-consent-privacy-framework' ); ?>"
					autocomplete="off"
				/>
			</div>
			<div class="ucpf-gtm-containers__field ucpf-gtm-containers__field--id">
				<label><?php esc_html_e( 'Container / Tag ID', 'universal-consent-privacy-framework' ); ?></label>
				<input
					type="text"
					class="regular-text code"
					<?php echo $name_attr( 'id' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					value="<?php echo esc_attr( isset( $c['id'] ) ? $c['id'] : '' ); ?>"
					placeholder="<?php echo esc_attr( isset( $meta['placeholder'] ) ? $meta['placeholder'] : 'GTM-XXXXXXX' ); ?>"
					autocomplete="off"
					data-ucpf-gtm-id
				/>
			</div>
			<div class="ucpf-gtm-containers__field ucpf-gtm-containers__field--datalayer">
				<label><?php esc_html_e( 'dataLayer', 'universal-consent-privacy-framework' ); ?></label>
				<input
					type="text"
					class="regular-text code"
					<?php echo $name_attr( 'data_layer' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					value="<?php echo esc_attr( isset( $c['data_layer'] ) && $c['data_layer'] ? $c['data_layer'] : 'dataLayer' ); ?>"
					placeholder="dataLayer"
					autocomplete="off"
				/>
			</div>
			<div class="ucpf-gtm-containers__field ucpf-gtm-containers__field--actions">
				<button type="button" class="button-link-delete ucpf-gtm-remove-row" data-ucpf-gtm-remove aria-label="<?php esc_attr_e( 'Remove container', 'universal-consent-privacy-framework' ); ?>">&times;</button>
			</div>
		</div>

		<details class="ucpf-gtm-disclosure" <?php echo $has_disc ? 'open' : ''; ?>>
			<summary><?php esc_html_e( 'What’s inside this container (for Cookie / Privacy Policy)', 'universal-consent-privacy-framework' ); ?></summary>
			<p class="description"><?php esc_html_e( 'Paste what the ad team or digital partner tells you for this site only. Different sites have different platforms — leave blank when you do not have answers yet. Saving refreshes generated legal pages.', 'universal-consent-privacy-framework' ); ?></p>

			<div class="ucpf-gtm-disclosure__grid">
				<div class="ucpf-gtm-disclosure__field ucpf-gtm-disclosure__field--wide">
					<label><?php esc_html_e( 'Platforms, pixels, scripts, or tags in this container', 'universal-consent-privacy-framework' ); ?></label>
					<textarea
						rows="2"
						class="large-text"
						<?php echo $disc_name( 'platforms' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						placeholder="<?php esc_attr_e( 'e.g. names of ad platforms, pixels, or tags the partner listed', 'universal-consent-privacy-framework' ); ?>"
					><?php echo esc_textarea( isset( $disc['platforms'] ) ? $disc['platforms'] : '' ); ?></textarea>
				</div>

				<div class="ucpf-gtm-disclosure__field">
					<label><?php esc_html_e( 'Cookie / identifier duration', 'universal-consent-privacy-framework' ); ?></label>
					<input
						type="text"
						class="regular-text"
						<?php echo $disc_name( 'cookie_duration' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						value="<?php echo esc_attr( isset( $disc['cookie_duration'] ) ? $disc['cookie_duration'] : '' ); ?>"
						placeholder="<?php esc_attr_e( 'e.g. 30 days from first ad engagement', 'universal-consent-privacy-framework' ); ?>"
						autocomplete="off"
					/>
				</div>

				<div class="ucpf-gtm-disclosure__field">
					<label><?php esc_html_e( 'Third parties / recipients', 'universal-consent-privacy-framework' ); ?></label>
					<input
						type="text"
						class="regular-text"
						<?php echo $disc_name( 'recipients' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						value="<?php echo esc_attr( isset( $disc['recipients'] ) ? $disc['recipients'] : '' ); ?>"
						placeholder="<?php esc_attr_e( 'Who receives or processes the data', 'universal-consent-privacy-framework' ); ?>"
						autocomplete="off"
					/>
				</div>

				<div class="ucpf-gtm-disclosure__field">
					<span class="ucpf-gtm-disclosure__legend"><?php esc_html_e( 'Purpose', 'universal-consent-privacy-framework' ); ?></span>
					<div class="ucpf-gtm-disclosure__checks">
						<?php foreach ( $purpose_opts as $pkey => $plabel ) : ?>
							<label>
								<input
									type="checkbox"
									value="<?php echo esc_attr( $pkey ); ?>"
									<?php echo $disc_name( 'purposes', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<?php checked( in_array( $pkey, $purposes, true ) ); ?>
								/>
								<?php echo esc_html( $plabel ); ?>
							</label>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="ucpf-gtm-disclosure__field">
					<span class="ucpf-gtm-disclosure__legend"><?php esc_html_e( 'Use of information', 'universal-consent-privacy-framework' ); ?></span>
					<div class="ucpf-gtm-disclosure__checks">
						<?php foreach ( $use_opts as $ukey => $ulabel ) : ?>
							<label>
								<input
									type="checkbox"
									value="<?php echo esc_attr( $ukey ); ?>"
									<?php echo $disc_name( 'uses', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<?php checked( in_array( $ukey, $uses, true ) ); ?>
								/>
								<?php echo esc_html( $ulabel ); ?>
							</label>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="ucpf-gtm-disclosure__field ucpf-gtm-disclosure__field--wide">
					<label><?php esc_html_e( 'Visitor information collected (as described by the partner)', 'universal-consent-privacy-framework' ); ?></label>
					<textarea
						rows="2"
						class="large-text"
						<?php echo $disc_name( 'visitor_info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						placeholder="<?php esc_attr_e( 'e.g. view-through / click-through conversions, device identifiers, page activity', 'universal-consent-privacy-framework' ); ?>"
					><?php echo esc_textarea( isset( $disc['visitor_info'] ) ? $disc['visitor_info'] : '' ); ?></textarea>
				</div>

				<div class="ucpf-gtm-disclosure__field ucpf-gtm-disclosure__field--wide">
					<label><?php esc_html_e( 'Notes (optional)', 'universal-consent-privacy-framework' ); ?></label>
					<textarea
						rows="2"
						class="large-text"
						<?php echo $disc_name( 'notes' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						placeholder="<?php esc_attr_e( 'Anything else needed for this site’s policy wording', 'universal-consent-privacy-framework' ); ?>"
					><?php echo esc_textarea( isset( $disc['notes'] ) ? $disc['notes'] : '' ); ?></textarea>
				</div>
			</div>
		</details>
	</div>
	<?php
};
?>
<div class="ucpf-gtm-containers" data-ucpf-gtm-containers data-ucpf-gtm-suggestions="<?php echo esc_attr( $suggest_json ); ?>" data-ucpf-gtm-name-prefix="<?php echo esc_attr( $name_prefix . '[containers]' ); ?>">
	<?php if ( $gtm_suggestions ) : ?>
		<div class="notice notice-info inline ucpf-gtm-containers__scan-notice">
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of GTM container IDs */
						_n(
							'Your last privacy scan found %d GTM container ID not yet in this list.',
							'Your last privacy scan found %d GTM container IDs not yet in this list.',
							count( $gtm_suggestions ),
							'universal-consent-privacy-framework'
						),
						count( $gtm_suggestions )
					)
				);
				?>
				<button type="button" class="button button-secondary ucpf-gtm-add-suggested" data-ucpf-gtm-add-suggested>
					<?php esc_html_e( 'Add all to list', 'universal-consent-privacy-framework' ); ?>
				</button>
				<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'ucpf_dismiss_gtm_suggestions', '1' ), 'ucpf_dismiss_gtm_suggestions' ) ); ?>" class="button-link">
					<?php esc_html_e( 'Dismiss', 'universal-consent-privacy-framework' ); ?>
				</a>
			</p>
		</div>
	<?php endif; ?>

	<p class="description ucpf-gtm-containers__lede"><?php echo esc_html( isset( $meta['help'] ) ? $meta['help'] : '' ); ?></p>

	<input type="hidden" name="<?php echo esc_attr( $name_prefix ); ?>[id]" value="<?php echo esc_attr( $legacy_id ); ?>" data-ucpf-gtm-legacy-id />
	<input
		type="hidden"
		name="<?php echo esc_attr( $name_prefix ); ?>[containers_json]"
		value=""
		data-ucpf-gtm-containers-json
		autocomplete="off"
	/>

	<div class="ucpf-gtm-containers__list" data-ucpf-gtm-rows>
		<?php foreach ( $containers as $i => $c ) : ?>
			<?php $ucpf_render_gtm_card( $c, $i, $name_prefix, $meta, $purpose_opts, $use_opts, false ); ?>
		<?php endforeach; ?>
	</div>

	<p>
		<button type="button" class="button button-secondary ucpf-gtm-add-row" data-ucpf-gtm-add>
			<?php esc_html_e( 'Add container', 'universal-consent-privacy-framework' ); ?>
		</button>
	</p>

	<template data-ucpf-gtm-row-template>
		<?php $ucpf_render_gtm_card( \UCPF\Tracking_Templates::sanitize_gtm_container( array() ), -1, $name_prefix, $meta, $purpose_opts, $use_opts, true ); ?>
	</template>
</div>
