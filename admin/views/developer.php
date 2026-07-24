<?php defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- included template; locals are not plugin globals. ?>
<div class="wrap ucpf-admin">
	<h1><?php esc_html_e( 'Developer API', 'universal-consent-privacy-framework' ); ?></h1>
	<p><?php esc_html_e( 'Register services with ucpf_register_service() or contribute JSON definitions to the vendor catalog.', 'universal-consent-privacy-framework' ); ?></p>
	<pre class="ucpf-admin__code">add_action('ucpf_loaded', function () {
    ucpf_register_service([
        'key' => 'example_analytics',
        'name' => 'Example Analytics',
        'category' => 'analytics',
        'script_patterns' => ['example-analytics.com/script.js'],
    ]);
});</pre>
	<p><button type="button" class="button" id="ucpf-export-registry"><?php esc_html_e( 'Export registry JSON', 'universal-consent-privacy-framework' ); ?></button></p>
	<p><label><?php esc_html_e( 'Import registry JSON', 'universal-consent-privacy-framework' ); ?><br /><textarea id="ucpf-import-json" rows="8" class="large-text code"></textarea></label></p>
	<p><button type="button" class="button" id="ucpf-import-registry"><?php esc_html_e( 'Import', 'universal-consent-privacy-framework' ); ?></button></p>
</div>
