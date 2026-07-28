# Vendor catalog

Bundled cookie and service definitions that ship with the plugin zip. This is UCPF’s local “cookie database” — **no phone-home**, **no hosted Docker/DB required**.

Edit JSON under this folder and ship updates in plugin releases. Site Cookie review overrides stay site-local; **fleet-wide defaults live here**.

## Classification conventions (UCPF categories)

| Catalog `category` | Admin label | Typical treatment |
|--------------------|-------------|-------------------|
| `necessary` | Essential / Necessary | `necessary` (always allow) |
| `preferences` | Preferences | `consent` |
| `analytics` | Analytics | `consent` |
| `marketing` | Marketing | `consent` |
| `functional` | Embeds & Widgets | `consent` |
| `security` | Security | `necessary` or `consent` |

Current fleet defaults (keep in sync when reviewing a reference site):

- **YouTube** (`maps.json`): service + player cookies (`YSC`, `VISITOR_*`, `__Secure-Y*`) → `marketing` / `consent`, `default_blocking: true`
- **PayPal checkout cookies** (`l7_az`, `sc_f`, `KHcl0EuY7AKSMgfvHl7J5E7hPtK`) → `necessary` / `necessary` (payment facility); PayPal **scripts/iframes** stay `functional` / `consent` + blocked until consent
- **WooCommerce Order Attribution** (`sbjs_*`) → `analytics` / `consent`, blocked until analytics consent
- **Calendly** session cookies → `functional` / `consent` (embeds)
- **Magnite `c`** → `marketing` / `consent` (host-context required for short name)

## Public Cookie Policy display

- Property-/site-specific **cookie names** collapse to catalog patterns on the Cookie Policy (e.g. `_ga_TK6Q39VV4F` → `_ga_*`, `_hjSession_1234567` → `_hjSession_*`, `_gcl_au` → `_gcl_*`). Blocking still matches the real observed names.
- **Integration IDs** (GTM container, Measurement ID, Meta/TikTok pixel ID, Clarity project ID, Hotjar site ID, LinkedIn partner ID) stay in Integrations admin only — they are not cookie names and are never published as Cookie Policy rows.
- Admin Cookie review and Playwright findings keep raw observed names for operators.

## Scanning

1. Prefer **Playwright scan** (Scanner API or local CLI + import) from Cookie Scanner — inventories cookies (incl. HttpOnly), storage, scripts, iframes, beacons across no-consent / reject / accept.
2. Or run the built-in WordPress helper for a lighter inventory pass (does not verify blocking).
3. Review unknowns; refresh Cookie Policy; re-verify with Playwright after changing treatments.

See `tools/ucpf-scanner/README.md` and `docs/GETTING-STARTED.md`.

## Fleet growth (agency)

- Prefer expanding these JSON files over applying `local_*` stubs on every site.
- Scan export (`GET /ucpf/v1/scan/export`) is for human merge into this folder.
- Known vendor hosts (PayPal, YouTube, UserWay, …) are skipped by Catalog Suggestions — merge patterns here instead.
- Noise hosts (`example.invalid`, font CDNs, `cdnjs`) belong in `data/noise-filters.json` (mirrored under `tools/ucpf-scanner/rules/`), not this catalog.
- Site knowledge log + GitHub hub pull: see `docs/COOKIE-KNOWLEDGE-HUB.md` (opt-in remote registry URL).
- After editing catalog JSON: `.\package.ps1` and deploy the zip so sites pick up new defaults (existing site overrides still win).
