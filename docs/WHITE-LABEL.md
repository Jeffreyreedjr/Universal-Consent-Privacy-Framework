# White-label / agency branding

Keep the plugin folder and slug as `universal-consent-privacy-framework` so **WordPress.org updates continue to work**. Prefer configuration over forking.

## Site-facing (any business)

**Privacy Consent → Banner & Branding**

| Setting | Effect |
|---------|--------|
| Business name | Legal / cookie policy pages |
| Logo URL | Consent banner header |
| Theme + accents + custom CSS | Visual identity |
| Show powered by | Preferences panel attribution |

## Agency drop-in (no fork)

Create `wp-content/ucpf-brand.php`:

```php
<?php
/**
 * Optional UCPF agency brand file.
 * Do not place secrets here that must stay out of backups if shared.
 */
return array(
	'product_name'    => 'Acme Consent',
	'menu_title'      => 'Acme Consent',
	'support_url'     => 'https://acme.example/support',
	'scanner_api_url' => 'https://scanner.acme.example',
	'default_theme'   => 'classic',
);
```

Or use filters in an MU-plugin:

```php
add_filter( 'ucpf_brand_config', function ( $cfg ) {
	$cfg['product_name'] = 'Acme Consent';
	return $cfg;
} );

add_filter( 'ucpf_product_name', function () {
	return 'Acme Consent';
} );
```

Empty site `scanner_api_url` falls back to `scanner_api_url` from brand config when set.

## Why not fork?

Changing the plugin directory/slug disconnects WordPress.org update checks. Clients then need manual zips forever. Brand via settings + `ucpf-brand.php` instead.

## Scanner branding

The Playwright companion under `tools/ucpf-scanner` is separate software. Host it on your domain; point each client’s Advanced settings (or brand drop-in) at your scanner URL and issue per-client API keys.
