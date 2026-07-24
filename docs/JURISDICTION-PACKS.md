# Jurisdiction packs

Local JSON packs under `assets/jurisdiction-packs/` drive consent model, banner copy, GPC defaults, and privacy-choices UX. They **help support** privacy workflows across GDPR, California CPRA, other US state laws, LGPD, and Quebec Law 25. They are **not** a compliance guarantee or legal advice.

## Settings

- **compliance_mode** — default pack id (`strict_gdpr`, `us_baseline`, `us_california`, …).
- **geo_jurisdiction_routing** — optional; uses Cloudflare `CF-IPCountry` and filter `ucpf_visitor_region`.
- Filter **`ucpf_jurisdiction_pack_id`** — override resolved pack per request.

## Recommended defaults

Advanced Settings → “Apply recommended defaults (strict GDPR)” sets strict GDPR, GPC nonessential, local catalog, Reject All on, remote registry off.

## Rights Inbox

Admin → Rights Inbox lists `data_requests` with status, notes, processor checklist, and verification flag.
