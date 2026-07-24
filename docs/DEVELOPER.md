# Developer API

## Register a service

```php
add_action( 'ucpf_loaded', function () {
    ucpf_register_service( [
        'key'             => 'example_analytics',
        'name'            => 'Example Analytics',
        'provider'        => 'Example Inc.',
        'category'        => 'analytics',
        'treatment'       => 'consent', // necessary | consent | ignore
        'description'     => 'Measures traffic.',
        'privacy_url'     => 'https://example.com/privacy',
        'script_patterns' => [ 'example-analytics.com/script.js' ],
        'cookie_patterns' => [ '_example' ],
        'cookies'         => [
            [
                'name'      => '_example',
                'pattern'   => '_example',
                'purpose'   => 'Measures traffic.',
                'retention' => '1 year',
                'category'  => 'analytics',
                'treatment' => 'consent',
                'contexts'  => [ 'guest', 'logged_in' ],
            ],
        ],
    ] );
} );
```

## Bundled cookie database

UCPF ships a local catalog in `assets/vendor-catalog/*.json`. This is the source of truth for known cookies/services (WordPress, WooCommerce, GA4, Meta, Clarity, embeds, etc.). It grows via plugin updates — no phone-home required.

See `assets/vendor-catalog/README.md` for the schema and contribution workflow.

Site owners can override category/treatment per service in the Setup Wizard (Cookie review). Overrides are stored in `service_overrides` settings.

Per-cookie **display overrides** (`cookie_display_overrides`, also mirrored to legacy `cookie_overrides`) let you set visitor-facing `label`, `purpose`, `visibility` (`show` | `hide` | `document_only`), and optional category/treatment. Applied in `Cookie_Scanner::get_policy_inventory()` so Cookie Policy and Privacy Policy stay consistent. Saving review refreshes those pages when auto-refresh is on. `ignore` / `document_only` → public consent column “Documented only (not gated)”; `hide` omits the row.

## Markup blocking

```html
<script type="text/plain" data-ucpf-category="analytics" data-ucpf-service="google_analytics_4" data-src="https://example.com/track.js"></script>
```

## JavaScript API

```js
UCPF.hasConsent('analytics');
UCPF.acceptAll();
UCPF.on('ucpf:consent:changed', (state) => {});
```

## REST (`ucpf/v1`)

- `GET/POST /consent`
- `POST /withdraw`
- `GET /services`
- `POST /scan` — multi-URL guest (+ optional logged-in) scan
- `POST /cookies/capture` — merge live `document.cookie` names into inventory
- `POST /cookies/review` — categorize unknown cookies
- `POST /services/override` — set treatment/category for a service
- `POST /pages/generate`
- `POST /data-request` — DSAR / Do Not Sell (public nonce); see [RIGHTS-FORMS.md](RIGHTS-FORMS.md)
- `GET/POST /registry/export|import`

## Rights request pages (Data Request / Do Not Sell)

These pages are **not** auto-generated. Set external URLs under Generated Pages and paste `[ucpf_data_request_form]` / `[ucpf_do_not_sell_form]` on home-site pages, or POST the field contract in [RIGHTS-FORMS.md](RIGHTS-FORMS.md). Gravity Forms embeds alone do not feed Rights Inbox.

## Scanner contexts

The Cookie Scanner covers:

1. Guest URLs (home, recent posts/pages)
2. WooCommerce shop/cart/checkout/account/product when Woo is active
3. Optional authenticated requests (admin session cookies)
4. Live browser cookie capture from the admin UI

Unknown cookies are stored for review; necessary WP/Woo cookies default to always-allow.

## Setup Wizard

Admin → Privacy Consent → Setup Wizard guides: Visitors → Documents → Site info → Security → Scan → Statistics → Services → Cookie review → Generate pages → Enable banner/blocker.
