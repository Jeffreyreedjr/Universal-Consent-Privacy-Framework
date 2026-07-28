# Changelog

All notable changes to Universal Consent & Privacy Framework are documented here.

## [0.1.7-alpha] — 2026-07-28

### Added
- Agency knowledge hub UI (mode + enable + URL), last sync status, Refresh registry now
- `tools/merge-knowledge-hub.ps1` to merge site exports into Git `registry.json`
- Sample hub at `docs/examples/agency-registry/registry.json`; COOKIE-KNOWLEDGE-HUB.md fleet checklist
- Cookie Scanner verify-blocking loop: re-verify CTA, remediation links on consent leaks, leak/score delta vs prior Playwright import

### Changed
- Knowledge export groups cookies by provider; import/match support `*` patterns
- Explicit no hosted cookie DB — Git/CDN opt-in pull only
- Vendor catalog: YouTube service/cookies → `marketing` + consent; PayPal `l7_az` / `sc_f` / `KHcl0EuY7AKSMgfvHl7J5E7hPtK` → `necessary`; Calendly session cookies → `functional`
- Banner default copy mentions withdraw/manage consent via Cookie Settings
- Scanner UI clarifies Playwright (verify) vs WordPress helper (inventory); gates Playwright run on Scanner API
- Cookie Policy collapses property-/site-specific tracker cookies (`_ga_*`, `_gcl_*`, Hotjar `_hj*_*`, …) to catalog patterns; Integration IDs remain admin-only
- Replace GreenSock GSAP with CSS motion (GPL-compatible for WordPress.org)
- WordPress.org directory assets (icons, banners, screenshots) + Screenshots section
- Open Cookie Database offline snapshot attribution in `readme.txt` Credits
- `readme.txt` plugin title matches header `Plugin Name` (…(Alpha)) for Plugin Check `mismatched_plugin_name`
- Catalog-driven consent gate: network gate blocks analytics/marketing/functional/security (scripts + stylesheets) from full vendor catalog; Adobe Fonts/Typekit and builder-injected tags included
- Vendor catalog: ActiveCampaign, ConvertKit, Drip, GetResponse, MailerLite, Font Awesome kits
- Reject All / withdraw: neutralize all gated scripts+stylesheets, then reload so enqueued Google Tag (`GT-…&ver=`) and fonts do not keep loading
- Save Preferences: same hard reload after cookie write so nitpicked categories (e.g. Security only) match Accept/Reject behavior for scripts and fonts
- Accept All: hard reload after cookie write so WordPress-enqueued Google Tag / fonts activate (same path as Reject / Save)
- Setup Wizard: Scanner API URL/key step before Website Scan (Playwright ready check + WordPress helper fallback)
- Setup Wizard Generate pages: Data Request and Do Not Sell / Share page URL fields (saved with Continue)

## [0.1.6-alpha] — 2026-07-28

### Added
- Shared scanner waiting queue (`UCPF_SCANNER_MAX_QUEUE`), per-API-key running/queued caps, job ownership on cancel, durable job store (SQLite/JSON)
- Admin queue position while waiting; emergency reset-all (confirm + admin only)
- Scheduled scan stagger + busy/queue backoff; longer poll window for queue wait

### Fixed
- WordPress no longer calls cancel-all when the scanner is busy (was killing every tenant on a shared host)

### Changed
- Companion `ucpf-scanner` **1.4.0**; docs for 300+ site sizing / multi-node cohorts

## [0.1.5-alpha] — 2026-07-28

### Fixed
- Policy / scan inventory no longer lists dozens of ephemeral `*.w.hcaptcha.com` workers or `about:blank` iframes
- Unclassified hosts for hCaptcha, Google Fonts, Adobe Fonts, UserWay, Jotform, and Cloudflare Turnstile are classified and deduped

### Changed
- Noise filters gain `signal_omit_*` / `signal_host_collapse`; Playwright classification + plugin-path map expanded; Jotform added to vendor catalog

## [0.1.0-alpha] — 2026-07-27

### Changed
- **Versioning reset:** project is pre-1.0 / Alpha. Prior `1.4.x` builds were development iterations, not a stable 1.x release line.
- Plugin header shows **(Alpha)** and an explicit non-production description.
- Migrations still apply when upgrading from stored `1.x` DB versions onto `0.x-alpha`.

## [1.4.17] — 2026-07-27

### Added
- Manual **Contribute cookie knowledge** on Cookie Scanner: scrubbed pack (`GET /ucpf/v1/knowledge/contribute`, no site URL) + GitHub issue helper — WordPress does not upload
- GitHub issue template `cookie-knowledge.yml`

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
