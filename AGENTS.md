# UCPF — Agent Instructions

## Purpose
Build and maintain the Universal Consent & Privacy Framework WordPress plugin. Strict GDPR defaults. Never claim guaranteed compliance.

## Rules
- Namespace: `UCPF\`, text domain: `universal-consent-privacy-framework`
- Never phone home; remote registry off by default
- Never load remote executable code
- Reject All === Accept All button tier (`.ucpf-btn--primary-tier`)
- ESC on banner/prefs = reject (essential only)
- Use `--ucpf-*` CSS tokens under `#ucpf-root`
- Update shim + REST + JS when changing consent logic
- Phase gates: complete exit criteria before next phase

## Category mapping (UCPF → WP Consent API)
- necessary → functional (always allow)
- preferences → preferences
- analytics → statistics
- marketing → marketing
- functional → preferences
- security → security (extended)

## Key hooks
- `ucpf_loaded`, `ucpf_consent_saved`, `ucpf_consent_withdrawn`
- `ucpf_register_service`, `ucpf_consent_categories`, `ucpf_service_registry`
- `ucpf_should_block_script`, `ucpf_should_block_iframe`
- `ucpf_scan_noise_filters`, `ucpf_is_cookie_scan_noise`
- `ucpf_brand_config`, `ucpf_product_name`, `ucpf_theme_tokens`

## Coding standards
- `ABSPATH` guards, sanitize/validate/escape, `$wpdb->prepare`, nonces, capabilities

## Cookie descriptions
- Primary: UCPF service catalog in Script_Registry
- Fallback: bundled Open Cookie Database snapshot (`data/open-cookie-database.min.json`) — attribution to jkwakman/Open-Cookie-Database; offline only; not a compliance guarantee
- Refresh snapshot: `.\tools\build-ocd.ps1`

## Scanner
- Deep scan: `tools/ucpf-scanner` (Playwright) — inventory + technical consent score / dark-pattern checks (not a legal determination)
- Path discovery: sitemap + homepage links + WP content; depth presets Quick/Standard/Deep
- Ideas inspired by Slashgear gdpr-cookie-scanner (MIT) and FAZ-style local discovery — rewritten for UCPF; do not vendor those codebases
