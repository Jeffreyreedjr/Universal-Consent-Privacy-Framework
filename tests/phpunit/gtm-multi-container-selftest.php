<?php
/**
 * Lightweight GTM multi-container contract checks (no WP bootstrap).
 * Run: php tests/phpunit/gtm-multi-container-selftest.php
 *
 * @package UCPF
 */

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return is_string( $str ) ? trim( strip_tags( $str ) ) : '';
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return $value;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text ) {
		return $text;
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $str ) {
		return is_string( $str ) ? trim( $str ) : '';
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once dirname( __DIR__, 2 ) . '/includes/class-tracking-templates.php';

use UCPF\Tracking_Templates;

$failed = 0;
function ucpf_gtm_assert( $cond, $msg ) {
	global $failed;
	if ( ! $cond ) {
		echo "FAIL: $msg\n";
		$failed++;
	} else {
		echo "ok: $msg\n";
	}
}

// Legacy id → containers.
$row = Tracking_Templates::normalize_gtm_row(
	array(
		'enabled' => true,
		'id'      => 'gtm-abc123',
	)
);
ucpf_gtm_assert( ! empty( $row['containers'][0]['id'] ) && 'GTM-ABC123' === $row['containers'][0]['id'], 'legacy id migrates into containers[0]' );
ucpf_gtm_assert( 'GTM-ABC123' === $row['id'], 'legacy id mirrored on row.id' );

// Wizard posted IDs without enabled must not force disabled.
$posted = Tracking_Templates::sanitize_posted_service_ids(
	array(
		'google_tag_manager' => array(
			'id'         => 'GTM-ABC123',
			'containers' => array(
				array( 'id' => 'GTM-ABC123', 'label' => 'Main', 'data_layer' => 'dataLayer' ),
			),
		),
	)
);
ucpf_gtm_assert( ! array_key_exists( 'enabled', $posted['google_tag_manager'] ), 'posted IDs omit enabled key' );

$merged = Tracking_Templates::merge_service_ids(
	$posted,
	array(
		'google_tag_manager' => array(
			'enabled'    => true,
			'id'         => 'GTM-ABC123',
			'containers' => array(
				array( 'id' => 'GTM-ABC123', 'label' => '', 'data_layer' => 'dataLayer' ),
			),
		),
	)
);
ucpf_gtm_assert( ! empty( $merged['google_tag_manager']['enabled'] ), 'wizard merge keeps enabled from selected_statistics path' );

// Empty containers post without id keeps previous containers.
$merged2 = Tracking_Templates::merge_service_ids(
	array(
		'google_tag_manager' => array(
			'containers' => array(
				array( 'id' => '', 'label' => '', 'data_layer' => 'dataLayer' ),
			),
		),
	),
	array(
		'google_tag_manager' => array(
			'enabled'    => true,
			'id'         => 'GTM-KEEPME',
			'containers' => array(
				array( 'id' => 'GTM-KEEPME', 'label' => 'Keep', 'data_layer' => 'dataLayer' ),
			),
		),
	)
);
ucpf_gtm_assert(
	! empty( $merged2['google_tag_manager']['containers'][0]['id'] ) &&
	'GTM-KEEPME' === $merged2['google_tag_manager']['containers'][0]['id'],
	'empty posted containers preserve previous'
);

// Multi-container sanitize + dedupe.
$multi = Tracking_Templates::sanitize_gtm_containers(
	array(
		array( 'id' => 'GTM-ONE', 'label' => 'A' ),
		array( 'id' => 'GTM-TWO', 'label' => 'B' ),
		array( 'id' => 'GTM-ONE', 'label' => 'dup' ),
	)
);
ucpf_gtm_assert( 2 === count( $multi ), 'dedupe containers by id' );
ucpf_gtm_assert( 'GTM-ONE' === $multi[0]['id'] && 'GTM-TWO' === $multi[1]['id'], 'container order preserved' );

// dataLayer must stay camelCase (not sanitize_key → datalayer).
$dl_row = Tracking_Templates::sanitize_gtm_container(
	array(
		'id'         => 'GTM-DLTEST',
		'data_layer' => 'dataLayer',
	)
);
ucpf_gtm_assert( 'dataLayer' === $dl_row['data_layer'], 'dataLayer stays camelCase' );
ucpf_gtm_assert( 'dataLayer' === Tracking_Templates::sanitize_gtm_data_layer( 'datalayer-bad!' ), 'invalid dataLayer falls back to dataLayer' );

// Extract GTM from pasted URL/snippet.
ucpf_gtm_assert(
	'GTM-ABC123' === Tracking_Templates::normalize_gtm_container_id( 'https://www.googletagmanager.com/gtm.js?id=GTM-ABC123' ),
	'extract GTM id from gtm.js URL'
);

// Legacy id prepended when containers exist but omit it.
$half = Tracking_Templates::normalize_gtm_row(
	array(
		'enabled'    => true,
		'id'         => 'GTM-LEGACY',
		'containers' => array(
			array( 'id' => 'GTM-NEW', 'label' => 'New', 'data_layer' => 'dataLayer' ),
		),
	)
);
ucpf_gtm_assert( 'GTM-LEGACY' === $half['containers'][0]['id'], 'legacy id prepended when missing from containers' );
ucpf_gtm_assert( 'GTM-NEW' === $half['containers'][1]['id'], 'existing container kept after legacy prepend' );

// Integrations sanitize: empty posted containers must not wipe legacy-only current.
$integrations = Tracking_Templates::sanitize_service_ids(
	array(
		'google_tag_manager' => array(
			'enabled'    => '1',
			'id'         => '',
			'containers' => array(
				array( 'id' => '', 'label' => '', 'data_layer' => 'dataLayer' ),
			),
		),
	),
	array(
		'google_tag_manager' => array(
			'enabled' => true,
			'id'      => 'GTM-OLDONLY',
		),
	)
);
ucpf_gtm_assert(
	! empty( $integrations['google_tag_manager']['containers'][0]['id'] ) &&
	'GTM-OLDONLY' === $integrations['google_tag_manager']['containers'][0]['id'],
	'Integrations empty POST preserves legacy-only id'
);

// Integrations sanitize: missing containers key keeps prior.
$integrations2 = Tracking_Templates::sanitize_service_ids(
	array(
		'google_tag_manager' => array(
			'enabled' => '1',
			'code'    => '',
		),
	),
	array(
		'google_tag_manager' => array(
			'enabled'    => true,
			'id'         => 'GTM-KEEP',
			'containers' => array(
				array( 'id' => 'GTM-KEEP', 'label' => '', 'data_layer' => 'dataLayer' ),
			),
		),
	)
);
ucpf_gtm_assert(
	'GTM-KEEP' === $integrations2['google_tag_manager']['containers'][0]['id'],
	'Integrations missing containers key preserves prior'
);

// Integrations sanitize: two valid containers both kept.
$integrations3 = Tracking_Templates::sanitize_service_ids(
	array(
		'google_tag_manager' => array(
			'enabled'    => '1',
			'id'         => 'GTM-A',
			'containers' => array(
				array( 'id' => 'GTM-A', 'label' => 'A', 'data_layer' => 'dataLayer' ),
				array( 'id' => 'GTM-B', 'label' => 'B', 'data_layer' => 'dataLayer' ),
			),
		),
	),
	array()
);
ucpf_gtm_assert( 2 === count( $integrations3['google_tag_manager']['containers'] ), 'Integrations keeps two containers' );
ucpf_gtm_assert(
	'GTM-A' === $integrations3['google_tag_manager']['containers'][0]['id'] &&
	'GTM-B' === $integrations3['google_tag_manager']['containers'][1]['id'],
	'Integrations container order + ids'
);
ucpf_gtm_assert( 'dataLayer' === $integrations3['google_tag_manager']['containers'][0]['data_layer'], 'Integrations save keeps dataLayer camelCase' );

// containers_json is the preferred Integrations POST path.
$from_json = Tracking_Templates::sanitize_gtm_service_row_for_integrations(
	array(
		'enabled'         => '1',
		'id'              => '',
		'containers_json' => wp_json_encode(
			array(
				array(
					'id'         => 'GTM-JSON1',
					'label'      => 'One',
					'data_layer' => 'dataLayer',
				),
				array(
					'id'         => 'https://www.googletagmanager.com/gtm.js?id=GTM-JSON2',
					'label'      => 'Two',
					'data_layer' => 'dataLayer',
				),
			)
		),
	),
	array()
);
ucpf_gtm_assert( ! empty( $from_json['enabled'] ), 'containers_json path keeps enabled' );
ucpf_gtm_assert( 2 === count( $from_json['containers'] ), 'containers_json saves two containers' );
ucpf_gtm_assert( 'GTM-JSON1' === $from_json['containers'][0]['id'], 'containers_json first id' );
ucpf_gtm_assert( 'GTM-JSON2' === $from_json['containers'][1]['id'], 'containers_json extracts second id from URL' );
ucpf_gtm_assert( 'GTM-JSON1' === $from_json['id'], 'containers_json mirrors first id' );

// Empty JSON with prior legacy must not wipe.
$json_preserve = Tracking_Templates::sanitize_gtm_service_row_for_integrations(
	array(
		'enabled'         => '1',
		'containers_json' => '[]',
	),
	array(
		'enabled' => true,
		'id'      => 'GTM-STILLHERE',
	)
);
ucpf_gtm_assert(
	'GTM-STILLHERE' === $json_preserve['containers'][0]['id'],
	'empty containers_json preserves legacy'
);

// Empty JSON must not wipe when nested containers still posted (GT- IDs).
$nested_after_empty_json = Tracking_Templates::sanitize_gtm_service_row_for_integrations(
	array(
		'enabled'         => '1',
		'containers_json' => '[]',
		'containers'      => array(
			array(
				'id'         => 'GT-M393MCT9',
				'label'      => 'test',
				'data_layer' => 'dataLayer',
			),
			array(
				'id'         => 'GT-M393MCT92',
				'label'      => 'test2',
				'data_layer' => 'dataLayer',
			),
		),
	),
	array()
);
ucpf_gtm_assert( ! empty( $nested_after_empty_json['enabled'] ), 'nested fallback keeps enabled' );
ucpf_gtm_assert( 2 === count( $nested_after_empty_json['containers'] ), 'empty JSON falls back to nested GT- rows' );
ucpf_gtm_assert( 'GT-M393MCT9' === $nested_after_empty_json['containers'][0]['id'], 'nested GT- first id' );
ucpf_gtm_assert( 'GT-M393MCT92' === $nested_after_empty_json['containers'][1]['id'], 'nested GT- second id' );

// GT- Google Tag IDs must persist (users often paste GT- into GTM multi-list).
$gt_tag = Tracking_Templates::sanitize_gtm_service_row_for_integrations(
	array(
		'enabled'         => '1',
		'containers_json' => wp_json_encode(
			array(
				array(
					'id'         => 'GT-M393MCT9',
					'label'      => 'GT-M393MCT9',
					'data_layer' => 'dataLayer',
				),
				array(
					'id'         => 'GT-M393MCT9',
					'label'      => 'dup',
					'data_layer' => 'dataLayer',
				),
				array(
					'id'         => 'GTM-ABC123',
					'label'      => 'Container',
					'data_layer' => 'dataLayer',
				),
			)
		),
	),
	array()
);
ucpf_gtm_assert( ! empty( $gt_tag['enabled'] ), 'GT- path keeps enabled' );
ucpf_gtm_assert( 2 === count( $gt_tag['containers'] ), 'GT- + GTM saved; duplicate GT- deduped' );
ucpf_gtm_assert( 'GT-M393MCT9' === $gt_tag['containers'][0]['id'], 'GT- ID persisted' );
ucpf_gtm_assert( 'GTM-ABC123' === $gt_tag['containers'][1]['id'], 'GTM- ID persisted alongside GT-' );
ucpf_gtm_assert( 'GT-M393MCT9' === $gt_tag['id'], 'legacy id mirrors first GT-' );
ucpf_gtm_assert( Tracking_Templates::is_gtag_id( 'GT-M393MCT9' ), 'GT- detected as gtag id' );
ucpf_gtm_assert( Tracking_Templates::is_gtm_container_id( 'GTM-ABC123' ), 'GTM- detected as container id' );

// Configured tags for policy (not disclosure-gated) + fingerprint + display label.
$map_a = array( 'google_tag_manager' => $gt_tag );
$map_b = array(
	'google_tag_manager' => Tracking_Templates::sanitize_gtm_service_row_for_integrations(
		array(
			'enabled'         => '1',
			'containers_json' => wp_json_encode(
				array(
					array(
						'id'         => 'GT-M393MCT9',
						'label'      => 'test',
						'data_layer' => 'dataLayer',
					),
					array(
						'id'         => 'GT-M393MCT92',
						'label'      => 'test2',
						'data_layer' => 'dataLayer',
					),
				)
			),
		),
		array()
	),
);
$configured = Tracking_Templates::gtm_configured_tags_for_policy( $map_b );
ucpf_gtm_assert( 2 === count( $configured ), 'configured tags lists both without disclosures' );
ucpf_gtm_assert( 'GT-M393MCT9' === $configured[0]['id'], 'configured tags first id' );
ucpf_gtm_assert( 'test' === $configured[0]['label'], 'configured tags first label' );
ucpf_gtm_assert( 'GT-M393MCT92' === $configured[1]['id'], 'configured tags second id' );
ucpf_gtm_assert(
	Tracking_Templates::policy_relevant_fingerprint( $map_a ) !== Tracking_Templates::policy_relevant_fingerprint( $map_b ),
	'fingerprint changes when multi GT- IDs change'
);
ucpf_gtm_assert( Tracking_Templates::gtm_row_has_gtag_ids( $map_b['google_tag_manager'] ), 'gtm row reports gtag ids' );
ucpf_gtm_assert(
	'test (GT-M393MCT9)' === Tracking_Templates::gtm_policy_display_label( 'test', 'GT-M393MCT9' ),
	'policy display label combines label + id'
);
$empty_disc = Tracking_Templates::gtm_disclosures_for_policy( $map_b );
ucpf_gtm_assert( 0 === count( $empty_disc ), 'empty disclosures omit partner table but configured tags still list' );

if ( $failed ) {
	fwrite( STDERR, "\n$failed assertion(s) failed\n" );
	exit( 1 );
}
echo "\nAll GTM multi-container assertions passed.\n";
exit( 0 );
