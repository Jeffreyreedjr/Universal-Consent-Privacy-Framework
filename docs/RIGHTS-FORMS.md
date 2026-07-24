# Rights forms (external)

UCPF on client WordPress sites **does not** collect DSAR / Do Not Sell form submissions.

## What this plugin does

1. **Link out** — under **Generated Pages**, set `data_request_page_url` and `do_not_sell_page_url` to pages on your home site (or any external URL).
2. Banner, Privacy Policy, and jurisdiction packs use those URLs for “privacy rights” / Do Not Sell links.
3. Shortcodes `[ucpf_data_request_form]` / `[ucpf_do_not_sell_form]` only render a **link** to the configured URL (legacy compatibility). They do not POST to this site.

## What it does not do

- No local Rights Inbox
- No `POST /wp-json/ucpf/v1/data-request`
- No Gravity Forms → inbox wiring

Host and process rights forms on your home site / CRM. GPC and consent banner enforcement still run on this WordPress install.

This is not legal advice and does not guarantee compliance.
