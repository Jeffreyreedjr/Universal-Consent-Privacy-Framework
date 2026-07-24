# Rights request forms (Data Request & Do Not Sell)

UCPF does **not** auto-generate Data Request or Do Not Sell WordPress pages. Build those pages on the home site, paste a shortcode (or a custom form that posts the API), then set the page URLs under **Privacy Consent → Generated Pages → Rights request pages**.

This document is a technical field/API contract for agencies and developers. It is **not legal advice** and does not guarantee compliance with GDPR, CPRA, or any other law.

Related: [PRIVACY-PREFERENCE-ENFORCEMENT.md](PRIVACY-PREFERENCE-ENFORCEMENT.md) (DNS cookie, GPC, vendor suppress).

---

## Recommended setup (Option A — built-in shortcodes)

1. Create two normal pages on the site (Elementor / block editor / classic).
2. Paste:
   - Data Request: `[ucpf_data_request_form]`
   - Do Not Sell: `[ucpf_do_not_sell_form]`
3. Publish and copy each permalink.
4. Paste the URLs into **Generated Pages → Rights request pages** and save.
5. Banner, Privacy Policy links, and jurisdiction JS (`doNotSellUrl` / `dataRequestUrl`) resolve those URLs via `Page_Generator::get_rights_url()`.

Optional intro copy templates (not auto-published):

- `templates/data-request-page-template.php`
- `templates/do-not-sell-template.php`

The built-in forms POST to `POST /wp-json/ucpf/v1/data-request` with the public `X-WP-Nonce` from the front-end config. Submissions land in **Rights Inbox** and (for DNS) enforce opt-out on the site.

Require `enable_data_request_forms` = true (wizard / settings).

---

## REST endpoint

| Item | Value |
|------|--------|
| Method / path | `POST /wp-json/ucpf/v1/data-request` |
| Auth | Public nonce: header `X-WP-Nonce: {ucpfConfig.nonce}` |
| Body | JSON |
| Rate limit | 5 requests per window (key `dsar`) |
| Honeypot | If `website` is non-empty → rejected as spam |

Example:

```http
POST /wp-json/ucpf/v1/data-request
Content-Type: application/json
X-WP-Nonce: <front-end nonce>

{
  "request_type": "access",
  "email": "visitor@example.com",
  "message": "Optional note",
  "website": ""
}
```

Valid `request_type` values: `access`, `deletion`, `correction`, `withdraw`, `do_not_sell`.

---

## Data Request (DSAR) field contract

| Field | Required | Type | Notes |
|-------|----------|------|--------|
| `email` | Yes | string (email) | Sanitized; hashed (HMAC) in storage — plaintext not kept in the row |
| `request_type` | Yes | enum | `access` \| `deletion` \| `correction` \| `withdraw` |
| `message` | No | string | Free text |
| `website` | No | string | **Honeypot** — must be empty |

### What happens after submit

| Type | Behavior |
|------|----------|
| `access` / `deletion` | Rights Inbox row; WordPress privacy export/erase request when `wp_create_user_request` exists |
| `correction` | Rights Inbox row (manual follow-up) |
| `withdraw` | Rights Inbox + `Consent_Manager::withdraw_consent()` |

**Built-in shortcode note:** `[ucpf_data_request_form]` currently posts `request_type=access` only. Other types need a custom UI or REST client that sets `request_type`.

---

## Do Not Sell / Share / Limit Use field contract

| Field | Required | Type | Notes |
|-------|----------|------|--------|
| `email` | Yes | string (email) | Same storage rules as DSAR |
| `request_type` | Yes | const | Must be `do_not_sell` |
| `opt_out_sale` | No* | bool | Default **true** if omitted |
| `opt_out_sharing` | No* | bool | Default **true** if omitted |
| `opt_out_targeted` | No* | bool | Default **true** if omitted |
| `limit_sensitive` | No | bool | Show when jurisdiction pack has `show_limit_sensitive` (e.g. CPRA) |
| `scope` | No | enum | `site` (default) \| `controller` \| `selected` |
| `global_privacy_mode` | No | bool | Also block all nonessential categories on this site |
| `message` | No | string | Free text |
| `website` | No | string | Honeypot — must be empty |

\* Omitting a checkbox in a custom form is treated as opted-out (`true`). Send explicit `false` to leave that flag off.

### Side effects (local site)

- Sets DNS preference state / `ucpf_dns` cookie path (see enforcement doc)
- Withdraws optional consent / rejects nonessential as configured
- Queues vendor suppression where connectors exist
- Often marks the Rights Inbox row completed when locally enforced
- Front-end may call `UCPF.rejectAll()` when `local_enforced` is true in the response

Cross-site / multi-property enforcement needs GPC and/or an optional agency privacy API — UCPF does not set cross-domain tracking cookies.

---

## Option B — Gravity Forms

Admin settings (Generated Pages):

- `gf_data_request_form_id` / `gf_data_request_shortcode`
- `gf_do_not_sell_form_id` / `gf_do_not_sell_shortcode`

When set, `[ucpf_data_request_form]` / `[ucpf_do_not_sell_form]` embed that GF markup instead of the built-in form.

**Important:** Embed-only Gravity Forms **do not** call `/ucpf/v1/data-request`. They will **not**:

- Create Rights Inbox rows
- Set DNS / withdraw consent
- Trigger vendor suppress

To use GF and still feed UCPF:

1. Prefer the built-in shortcode for enforcement paths, **or**
2. Add a custom `gform_after_submission` (or webhook) that POSTs the JSON contract above with a valid public nonce, **or**
3. Keep GF for lead capture only and also place `[ucpf_do_not_sell_form]` for the legal opt-out path

### Suggested GF field map (names → JSON)

| GF purpose | Suggested admin label | JSON key | Input type |
|------------|----------------------|----------|------------|
| Email | Email | `email` | Email |
| Request type | Request type | `request_type` | Hidden/select (`access`, … / `do_not_sell`) |
| Sale opt-out | Opt out of sale | `opt_out_sale` | Checkbox → bool |
| Sharing opt-out | Opt out of sharing | `opt_out_sharing` | Checkbox → bool |
| Targeted ads | Opt out of targeted advertising | `opt_out_targeted` | Checkbox → bool |
| Limit SPI | Limit sensitive PI | `limit_sensitive` | Checkbox → bool |
| Scope | Apply to | `scope` | Radio: site / controller / selected |
| Global block | Block all nonessential | `global_privacy_mode` | Checkbox → bool |
| Message | Message | `message` | Paragraph |
| Honeypot | (hidden) | `website` | Single line, hidden, empty |

There is **no** automatic GF field-name bridge in this release.

---

## Option C — Custom HTML / JS

Mirror the built-in form: collect the fields above, `JSON.stringify`, POST to `config.restUrl + 'data-request'` with `X-WP-Nonce: config.nonce` (same object as banner consent.js). Checkbox fields must be booleans in JSON, not `"on"` strings.

Reference: `public/js/consent.js` (handler on `.ucpf-data-request-form`).

---

## URL resolution order

`Page_Generator::get_rights_url( $key )`:

1. Setting `data_request_page_url` or `do_not_sell_page_url` if non-empty
2. Else legacy `generated_pages[key]` permalink (sites that generated these pages before 1.4.5)
3. Else empty (links omitted)

---

## Admin checklist (support)

- [ ] Home-site pages exist and are published
- [ ] Shortcode or API-backed form present
- [ ] URLs saved under Generated Pages
- [ ] Test submit as logged-out visitor → Rights Inbox row
- [ ] For DNS: after submit, nonessential tags stay blocked; `local_enforced` / reject path works
- [ ] Privacy Policy / banner link opens the correct URL
- [ ] Counsel reviewed copy — not a compliance guarantee
