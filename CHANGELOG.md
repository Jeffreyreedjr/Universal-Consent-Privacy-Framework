# Changelog

All notable changes to Universal Consent & Privacy Framework are documented here.

## [1.3.0] — 2026-07-23

### Added
- Privacy-behavior scanner 1.2 workstream: capture v2, consent differential findings (schema `ucpf-playwright-scan/2.0`), smart crawl + recipes, GPC/GCM/GPP observational probes, CLI CI exit codes (`0/1/2/3`) and `--profile quick|standard|compliance`
- Agency federation foundations: scanner node metadata, drift compare, domain well-known token, baseline storage
- Community registry foundations: `registry_mode` / `UCPF_REGISTRY_MODE`, contribution sanitization, signed-catalog validation stubs (still off by default)
- **Cross-site privacy enforcement:** `Privacy_State` (Sec-GPC + local DNS cookie), Do Not Sell scopes + Global Privacy Mode, optional Privacy Preference API (off by default), HMAC identity, vendor suppression hooks, signed policy cache / fail-closed marketing
- Docs: `docs/PRIVACY-BEHAVIOR-SCANNER.md`, `docs/PRIVACY-PREFERENCE-ENFORCEMENT.md`
- Scanner admin: pass/fail differential findings UI

### Changed
- Deep scan reports prefer `findings[]` while keeping legacy `consent_leaks` import
- Do Not Sell requests now enforce local opt-out (not database-only)
- Banner “Powered by” removed
## [1.2.0] — 2026-07-23

### Added
- 2026 admin design system: shell nav, tokens, WCAG focus/hover/active states, self-hosted Plus Jakarta Sans
- React dashboard (`admin/src` → `admin/build`) with GSAP card entrances
- Public consent motion via vendored GSAP (`consent-motion.js`), reduced-motion safe

### Changed
- All admin screens wrapped in shared shell chrome
- Scanner chips expose `aria-pressed`

## [1.1.0] — 2026-07-23

### Added
- Open-source / GitHub readiness: README, CONTRIBUTING, SECURITY, CODE_OF_CONDUCT, RELEASING docs
- White-label branding: Banner & Branding UI (business name, logo, accents, powered-by), `wp-content/ucpf-brand.php` + `ucpf_brand_config` / `ucpf_product_name` filters
- Classic theme preset (neutral rename of prior agency default)
- WordPress.org packaging helpers: `.distignore`, `.wordpress-org/` assets placeholders, release + deploy workflows
- Scanner noise filters (earlier 1.0.38) remain; scanner auth hardened for public hosts

### Changed
- Default scanner API URL is empty (local CLI import or self-hosted URL)
- npm package renamed to `ucpf-scanner`
- Agency-specific defaults and docs scrubbed for public use

### Security
- Scanner denies unauthenticated non-loopback requests unless API keys are set
- `UCPF_SCANNER_ALLOW_LOCAL=1` only permits unauthenticated access from loopback

## [1.0.38] — 2026-07-23

- Global scan noise filters (`data/noise-filters.json`) for Defender lockouts, WAF noise, logged-in WP cookies

## [1.0.37] — 2026-07

- Playwright scanner power upgrade (discovery, CMP/dark patterns, technical score)

## [1.0.36] — 2026-07

- Bundled Open Cookie Database enrichment

## [1.0.0] — Initial

- Initial public feature set (consent banner, scanner, registry, policies)
