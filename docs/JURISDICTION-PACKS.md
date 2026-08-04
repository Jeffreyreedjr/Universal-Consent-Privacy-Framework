# Jurisdiction packs

Local JSON packs under `assets/jurisdiction-packs/` drive consent model, banner copy, GPC defaults, and privacy-choices UX. They **help support** privacy workflows across GDPR, US state privacy laws, LGPD, and Quebec Law 25. They are **not** a compliance guarantee or legal advice.

## Settings

- **compliance_mode** — default pack id when geo routing is off, or for countries that do not map to a pack (`strict_gdpr`, `us_baseline`, `global_balanced`, …).
- **geo_jurisdiction_routing** — optional; **auto-enables and stays on when Cloudflare is detected** (request headers, proxy detect, or last scan `cloudflare_proxied`). Filter `ucpf_auto_enable_geo_on_cloudflare` to disable auto-on. Also uses filter `ucpf_visitor_region`. No paid GeoIP SaaS.
- Filter **`ucpf_jurisdiction_pack_id`** — override resolved pack per request.
- Filter **`ucpf_visitor_region`** — inject a region code from your edge (e.g. `US-CA`, `CA-QC`). Cloudflare’s bare `CA` means **Canada**, never California.

## Geo matrix (when routing is on)

| Visitor country | Pack | Behavior (summary) |
|---|---|---|
| **US** | `us_baseline` | Banner + Accept/Reject/Customize; optional cookies gated until choice; Do Not Sell/Share + GPC `sale_share`. Covers comprehensive US state privacy laws **without** IP-state detection. |
| **EEA / UK / CH** (and other `strict_gdpr` regions) | `strict_gdpr` | Strict opt-in GDPR/ePrivacy-style UX |
| **BR** | `br_lgpd` | LGPD-oriented pack |
| **Unknown** / missing header / Tor (`XX`, `T1`) | `strict_gdpr` | Fail closed |
| Other known countries | Admin default pack | Keep `compliance_mode` |

State-specific packs (`us_california`, `us_colorado`, …) remain for copy or agency-injected `US-XX` regions. They are **not** selected from Cloudflare country alone.

## Recommended defaults

Advanced Settings → “Apply recommended defaults (strict GDPR)” sets strict GDPR as the default pack, **enables geo routing**, GPC nonessential (for GDPR default), local catalog, Reject All on, remote registry off.

## Rights pages

Set external Data Request / Do Not Sell URLs under Generated Pages. This plugin does not host a local Rights Inbox (forms live on the home site). US packs expect a Do Not Sell / privacy choices URL when `dns_required` is true.
