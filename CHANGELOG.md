# Changelog

All notable changes to Universal Consent & Privacy Framework are documented here.

## [Unreleased]

### Added
- Multisite **Network Admin** connection settings (`ucpf_network_settings`): shared Scanner API URL/key, Privacy Preference API, and agency registry defaults. Sites inherit when Advanced fields are blank; filled site fields override. Promote-from-site + clear-overrides tools for existing installs.
- Elementor Video **open-inline** fallback: when park/restore and Elementor `runReadyTrigger` leave `.elementor-wrapper.elementor-open-inline` empty, inject/restore `iframe.elementor-video` from `data-settings` (`youtube_url` / `vimeo_url`). Background inject and existing video handling unchanged.
- Mapster WP Maps / MapLibre: catalog patterns park `mapster-wp-maps*` scripts server-side; embed guard covers `.mapster-wp-maps` containers; post-consent one-shot force-refire + canvas hydrate so maps leave the loader after Marketing+Embeds.
- MapLibre CDN hosts (`unpkg` / `jsdelivr` / demotiles) classified as Embeds in the network gate.
- Elementor update resilience: after plugin / theme / UCPF updates, clear Elementor CSS cache (rebuild on enqueue / next view), queue Cloudflare purge on request shutdown (prefix for `uploads/elementor/css`), and show a dismissible admin notice. Setting: **Clear Elementor CSS cache after updates** (Advanced → Cloudflare; default on). Missing CSS files self-heal on `elementor/css-file/before_enqueue`.

### Fixed
- Accept All “Page Unresponsive” after reload (`?_ucpf=…#ucpf_c=…`): Mapster `forceMapster` cleared refire flags and re-cloned every map script until the tab locked. Force-refire is one-shot; clones are never re-cloned; handoff boot no longer re-fires `accepted_all` plus a second full loader scan.
- Accept All hard-reload path no longer sync-activates every parked script before navigation; consent change handlers coalesce and skip heavy hydrate while reload is pending.
- Elementor/Cloudflare update path avoids WP-Cron / `spawn_cron` (unreliable with external cron runners). Cache clear stays sync; CF purge runs on shutdown / admin fallback.
- Elementor open-inline YouTube: Accept / Enable Marketing+Embeds left a blank player, then hard-refresh stacked **two** `iframe.elementor-video` nodes (Elementor + UCPF). Prefer restoring Elementor’s parked URL; only create a marked fallback after ~1.2s if still empty; dedupe so one player remains.
- Elementor open-inline YouTube/Vimeo: overlay cleared on Accept but the player stayed blank. Video hydrate no longer skips when `leaveBuildersAlone` is on (default); restore now covers network-gate–parked iframes (not only `data-ucpf-parked`); dead iframes are replaced so YouTube remounts.
- Mapster Premium (MapLibre): linking-script could run before consent, leave a naked spinner, and never remount after Accept — now parked, guarded, and force-refired like WP Go Maps.

## [0.1.29-alpha] — 2026-08-11

### Added
- Optimizer exclusions integration: keep UCPF assets out of Hummingbird, Autoptimize, WP Rocket, and LiteSpeed minify/combine/delay pipelines.

### Fixed
- Network gate: ignore invalid `setAttribute` names (empty or URL-as-name from Hummingbird combine) so consent/captcha activation cannot abort.
- Gravity Forms captchas: reinit after Security consent and on boot/pageshow so new tabs / navigation races recover widgets.
- Gravity Forms contact forms: detect `.gfield--type-captcha` / `.gform_wrapper` so the Security consent overlay attaches even when the widget host is empty or AJAX-replaced.

## [0.1.28-alpha] — 2026-08-06

### Fixed
- UserWay accessibility toolbar: fully hands-off — never collect as embed cover, never re-park on Reject, un-park legacy gated scripts on refresh. Consent-guard `filter` / opacity no longer apply to `body`/`html` or UserWay nodes (that was relocating the fixed icon, often top-right).

## [0.1.27-alpha] — 2026-08-06

### Fixed
- UserWay Accessibility: catalog + migration treat as **necessary** (never blocked). Preferences previously mapped into the Embeds network gate, so the ADA toolbar waited on consent. Script blocker and network-gate allowlist `cdn.userway.org` / `userway.org`.

## [0.1.26-alpha] — 2026-08-06

### Fixed
- Elementor YouTube/Vimeo widgets: empty `.elementor-video` shells measured ~0px before an iframe existed, so the absolute consent panel painted invisible (blank white + nearby carousel dots). Apply a **video-only** 16:9 / `min-height: 12rem` fallback and retry size-lock on refresh. Forms, maps, Calendly, and captcha covers unchanged.

## [0.1.25-alpha] — 2026-08-06

### Fixed
- Consent guards must never wrap `<body>` / `<html>` / `<head>` (or `#ucpf-root`). Gated Calendly (and similar) scripts under `<body>` were promoting the document as the host, which reparented the entire page into a “Form / widget blocked” overlay and broke Elementor sticky / MutationObserver / Gravity Forms.
- Prefer `.calendly-inline-widget` (or Elementor widget box) as the overlay host; return null for bare scripts instead of `parentElement` when that is body.
- On load, unwrap any body trapped inside a legacy guard and strip chrome attributes.

## [0.1.24-alpha] — 2026-08-06

### Fixed
- Plugin Check: translators comment on admin scan notice; escape scanner finding counts; sanitize wizard/advanced GET/POST inputs; print network-gate via `wp_enqueue_script` / `wp_print_scripts`; align `readme.txt` Stable tag.

Same ship as 0.1.23-alpha (Amelia first-party form overlay, Maps one-shot restore).

## [0.1.23-alpha] — 2026-08-06

### Fixed
- Amelia Booking: first-party form (Gravity Forms model) — never park `/ameliabooking/`; Security captcha overlay on `#amelia-container` only; migrate stale registry/overrides to necessary.
- Google Maps: stop `ucpf_r` force-reload loops; one-shot parked iframe restore (no Elementor re-boot spam when map is live).

Same ship otherwise as 0.1.22-alpha (scanner path fail-fast, self-hosted video allow).

## [0.1.22-alpha] — 2026-08-06

### Fixed
- Playwright multi-page scans: admin syncs checkbox DOM before start, fails fast when Scanner API accepts fewer paths than sent (redeploy `tools/ucpf-scanner` required — plugin zip does not include the scanner). Poll responses expose `paths` / `paths_count`; Active Scan prefers WordPress-sent page counts when remote progress under-reports.
- Elementor Google Maps embeds (`maps.google.com` iframes): force-reload after Marketing+Embeds consent (handles empty `src` and blank live frames).
- Self-hosted / same-origin videos: no longer default Elementor video widgets to YouTube covers. HTML5 `<video>`, Elementor `video_type: hosted`, and same-host iframes load without Marketing+Embeds; third-party YouTube/Vimeo embeds stay gated.
- Amelia Booking: catalog patterns, network-gate classification, consent cover on `#amelia-container`, and post-consent captcha refire so booking/payment can run after Functional (+ Security for captcha).

### Changed
- Cookie Scanner UI notes that Playwright needs both the plugin and a redeployed Scanner API for multi-page jobs.
- Scanner API `/health` version bumped to `1.5.1`; `GET /v1/scans/:id` returns `paths`, `paths_count`, `exactPaths`.

## [0.1.21-alpha] — 2026-08-04

### Fixed
- Setup Wizard Visitors step: Save and Continue advances reliably (`formnovalidate`, step redirect hint, Enter in Cloudflare fields no longer submits Save/stay).
- Wizard ↔ Advanced settings sync: scanner URL/key and Cloudflare token use the same option keys, URL normalization, and encrypted secret storage; empty password fields keep existing secrets.
- Playwright deep scan: honor every admin-selected page end-to-end — WP no longer truncates curated paths by depth `maxPages` / `MAX_SERVER_URLS`; scanner API no longer slices jobs to `UCPF_SCANNER_MAX_PAGES` when `exactPaths` is set (that env alone could force a 1-page scan). Admin awaits selection persist and reports accepted `paths_count`.
- Microsoft Clarity disclosures: Privacy Policy text aligned with Microsoft’s sample (heatmaps/session replay, first-/third-party cookies, purposes, Microsoft Privacy Statement link) while stating Clarity waits for Analytics consent under GDPR-style packs — not “by using this site you agree”. Optional footer shortcode `[ucpf_clarity_disclosure]`.
- Google Maps / map widgets: after consent, activate Maps/Mapbox APIs before dependents; refire WP Go Maps / Mapster / Elementor Google Maps; park first-party map plugin scripts until Marketing+Embeds (same pattern as GTM4WP+Vimeo). Broader restore of gated scripts/iframes so maps and other embeds do not stay blank.
- Third-party form embeds (Jobber Client Hub / work-request, Typeform, Jotform, etc.): show Enable Marketing & Embeds overlay when gated; re-park scripts/iframes if builders restore `src`; Jobber added to vendor catalog; Marketing + Embeds always required together for third-party iframes (payment processors excepted).
- Embed consent overlays keep the original media-box size (no forced 14–22rem shells); glass cover fills that box; Jobber/HTML widgets decorate the Elementor host.
- Elementor background video after consent: do not nest the player in `.elementor-background-video-embed` (often `width:0`). Inject a cover-sized iframe on `.elementor-background-video-container` and repair already-hydrated zero-width iframes.
- Cloudflare Advanced save: fixed memory-exhaustion loop (sanitize discarded Zone ID writes → endless resolve/re-seal). Zone resolve runs once on shutdown; Settings::update bypasses form sanitize.
- Service detection: stop marking every Gravity SMTP connector as active (catalog name lists); only primary/backup/enabled connectors; prune stale ESP selections; ignore competing CMPs and sanitize_key junk chips.
- Cloudflare purge admin redirect used wrong page slug (`ucpf-settings-advanced`); now returns to Advanced → Cloudflare.

### Added
- Setup Wizard Visitors / Advanced → Cloudflare: domain + API token (Zone ID resolved automatically). Advanced Settings split into General / Scanner / Privacy / Cloudflare / Data tabs.
- API secrets (scanner / privacy / Cloudflare) encrypted at rest; admin fields never echo stored values; empty = keep; optional `UCPF_*` wp-config overrides.

## [0.1.20-alpha] — 2026-08-04

### Fixed
- Post-consent embeds: preserve Vimeo unlisted privacy hash (`vimeo.com/{id}/{hash}` → `player.vimeo.com/video/{id}?h=`). Hydration only fills empty shells and prefers an existing iframe `src`/`data-src` over rebuilt URLs. YouTube rebuilds keep `list` / `start` / `si`. Deferred iframe restore stays verbatim.

## [0.1.19-alpha] — 2026-08-04

### Fixed
- Vimeo / YouTube post-consent: same-origin JS is no longer blanket-skipped by the network gate (that left `gtm4wp-vimeo.js` running while `player.vimeo.com/api/player.js` stayed blocked → `Vimeo is not defined`). Gate GTM4WP vimeo/youtube helpers with the player APIs; loader re-fires those helpers after the API `load` event and on consent apply.

## [0.1.18-alpha] — 2026-08-04

### Fixed
- Frequent zip updates no longer call full-site page-cache / Autoptimize / Rocket / LiteSpeed purges on activate, migration, every plugin upgrade, or Elementor CSS clear. Those nukes deleted optimized CSS while Cloudflare Cache Files could pin a soft-404 — unique to UCPF because other plugins do not flush the whole stack on every bump. Updates now only bump UCPF asset `?ver=` stamps.

## [0.1.17-alpha] — 2026-08-04

### Fixed
- Site-wide (any theme/builder): layout webfonts (Google Fonts, Adobe Typekit, Font Awesome) are never consent-gated — gating them left every site looking broken until Embeds. Same-origin theme/plugin CSS is never gated. Deferred stylesheets use an inert `data:` href instead of `href=""` (empty href made browsers load the HTML document as CSS → MIME `text/html`). Cloudflare Cache Files guidance remains in `docs/CLOUDFLARE-CACHE.md` for HTML-as-CSS poison after deploys.

## [0.1.16-alpha] — 2026-08-04

### Changed
- Video consent overlays (YouTube / Vimeo / Elementor): Enable always grants **Marketing and Embeds**; empty builder shells hydrate after consent (incl. Shorts URLs) on enable and on return visits.
- Scanner consent differential: post-revoke leftover cookies are **cleanup warnings** (`retained_after_revoke`), not FAIL. Summary shows “Blocking OK” when only jar leftovers remain; red FAIL reserved for pre-consent / reject / GPC / DNS leaks.

## [0.1.15-alpha] — 2026-08-03

### Fixed
- Elementor Motion Effects / fade-ins: surface-guard MutationObserver no longer watches `class` (Elementor toggles classes on every entrance animation). That re-scan loop was breaking sticky/fade timelines (`stop`/`start` undefined) and thrashing Cloudflare Turnstile widgets.

## [0.1.14-alpha] — 2026-08-03

### Fixed
- Emergency restore: front-end `consent.js`, `form-captcha-guard.js`, and `loader.js` reverted to the last known-good **0.1.10-alpha** builds. 0.1.11–0.1.13 Calendly/seamless/loading changes could freeze the entire browser on Accept/Enable.

## [0.1.13-alpha] — 2026-08-03

### Fixed
- Hotfix: Accept / Enable no longer freezes the browser. Removed seamless in-place activate and loading-panel DOM churn that feedback-looped with MutationObserver. All consent actions hard-reload again.

## [0.1.12-alpha] — 2026-08-03

### Changed
- Hybrid consent UX: banner Accept All / Reject All / Save Preferences still hard-reload with `?_ucpf=` (worldwide-safe PHP re-render)
- Enable Security / Embeds from a surface guard **inside any modal/dialog** skips reload and activates gated scripts in place so the popup stays open

### Added
- Generic modal detection (`dialog`, `role=dialog`, `aria-modal`, conservative `.modal`/`.popup` overlays) via `UCPF.isInModal`
- Scroll restore after consent reload; Calendly/captcha loading-until-ready panels; ~5 minute slow-network embed retries (Starlink / 3G)

## [0.1.11-alpha] — 2026-08-03

### Fixed
- Embeds & Widgets enable path: activate deferred scripts with onload, inject Calendly `widget.js` when Elementor dropped the gated tag, re-init inline widgets up to ~12s, retry after reload via session flag when navigation was cancelled

## [0.1.10-alpha] — 2026-08-03

### Added
- Cloudflare Bypass clause for `/wp-content/uploads/elementor/css/` (avoids year-caching soft-404 HTML as `post-*.css`); Cache Files 4xx/5xx no-cache guidance
- `ucpf_flush_site_caches()` on any plugin/theme upgrade, theme switch, and Elementor CSS clear (origin / page-cache hooks only — no Cloudflare API)
- Calendly surface guard (`.calendly-inline-widget` / Elementor popups) + re-init after Functional consent

### Fixed
- form-captcha-guard `insertBefore` NotFoundError when refreshing panels on Elementor in-place shells
- Network-gate: treat all `calendly.com` URLs as Functional (not only paths containing `widget`)

## [0.1.9-alpha] — 2026-07-31

### Added
- Cloudflare Cache Rules operator guide (`docs/CLOUDFLARE-CACHE.md`): Bypass for `ucpf_consent` / `ucpf_dns` / `?_ucpf=` / plugin path; paste-ready expression in Advanced Settings + QA checklist

### Changed
- Version bump so live zip updates run migrations and bust front-end `?ver=` (avoids stale consent.js / banner.css after overwrite)

## [0.1.8-alpha] — 2026-07-29

### Added
- Geo pack routing matrix: US → `us_baseline` (all states; banner + gated optional cookies + DNS/GPC sale-share); EEA/UK/CH → `strict_gdpr`; Brazil → LGPD; unknown → fail closed
- Geo routing **auto-enables and locks on** when Cloudflare is detected (headers, proxy, or last scan)
- Admin dashboard: attention-first Install health (score hero, warn/fail action cards, quick actions, collapsible passing checks)
- Shared admin panel cards across Banner, Registry, Pages, Integrations, Developer, Advanced
- Catalog: WordPress Download Manager `__wpdm_client` (necessary); Matomo `_pk_id/_pk_ref/_pk_ses/_pk_cvar/_pk_hsr` (analytics); Cookie Law Info / CookieYes preference cookies (disclosure)

### Changed
- `us_baseline` uses opt-in consent mechanics with US Privacy Choices copy; GPC `sale_share`
- California pack regions: `US-CA` only (Cloudflare `CA` is Canada)
- Visitor banner copy: removed “helps support privacy compliance / not legal advice” disclaimers from packs
- Script Registry filter; Integrations card layout; Generated Pages list + scrollable resolved URLs

### Fixed
- Brave/GPC: Embeds & Widgets (`functional`) no longer blocked by GPC-only nonessential enforcement (checkout overlays)
- Brave Shields: consent URL-hash handoff for Enable/Accept across reload

## [0.1.7-alpha] — 2026-07-28

### Added
- Agency knowledge hub UI (mode + enable + URL), last sync status, Refresh registry now
- `tools/merge-knowledge-hub.ps1` to merge site exports into Git `registry.json`
- Sample hub at `docs/examples/agency-registry/registry.json`; COOKIE-KNOWLEDGE-HUB.md fleet checklist
- Cookie Scanner verify-blocking loop: re-verify CTA, remediation links on consent leaks, leak/score delta vs prior Playwright import
- Consent surface guard: CAPTCHA forms (Security), maps / MapLibre (Functional), and YouTube/Vimeo embeds show theme-matched blocking notices + category CTAs; panels inherit `bannerTheme` tokens and custom accent overrides (keeps services consent-gated; no silent force-on after Reject All)
- Agency plugin fingerprint DB (`plugin-fingerprints.json`, 600+ records) with service-vs-plugin layers; Mapbox/maps, Mailchimp marketing vs transactional, payments, shipping, CRM, ads, docs/media embeds; fleet coverage report + classifier acceptance fixtures
- Vendor catalog: Mapbox, AppNexus/Xandr, Microsoft Advertising, Google Docs, Wistia/Spotify/SoundCloud, chat widgets; `fleet-services.json` stubs for fleet consent keys
- Guard overlays (forms/maps): `ucpfConfig.themeTokens` + live `#ucpf-root` copy so panels match active banner theme and Banner & Branding custom colors; button styles hardened against theme/Elementor overrides
- Consent surface guard UX: centered standard card for captcha, maps, video, and checkout; copy uses Embeds & Widgets / Marketing labels consistently
- Consent surface guard: detect builder video shells (Elementor `data-settings` youtube/vimeo, Gutenberg, Divi, WPBakery, Beaver, Bricks) even when the iframe is not injected yet
- WooCommerce checkout guard: requires Embeds & Widgets (`functional`) for PayPal/Stripe/Square payment widgets; category copy updated; network-gate treats those payment hosts as functional
- Shipping catalog (`shipping.json`): Shippo, UPS, USPS, FedEx, DHL, EasyPost, ShipStation, Printful, Avalara, TaxJar → Embeds & Widgets consent + network gate; fingerprints/classification/plugin-map aligned; checkout overlay copy includes shipping/address validation

### Changed
- Credits: original creator/developer attributed as Jeffrey Reed Jr. (plugin header Author, readme.txt Credits + Contributors, README)
- Front/admin asset `?ver=` uses filemtime + `ucpf_assets_rev` so zip updates with the same alpha Version no longer leave Cloudflare/browsers on stale consent.js / banner.css; Advanced → CDN note for Ignore Query String
- Consent surface guard: provider-first captcha detection (reCAPTCHA / hCaptcha / Turnstile / Friendly Captcha URLs + attrs) plus Formidable/Quform/HappyForms/Jet/Kadence/Divi/Woo wrappers and a form-wide heuristic so Security overlays attach on unknown themes
- Admin sidebar uses Dashicons `dashicons-shield` (custom menu PNG abandoned — unreliable in WP admin)
- Scanner classification: Turnstile/reCAPTCHA → security/consent (not necessary); Vimeo/Google Fonts → functional; network-gate no longer treats Stripe JS as marketing
- Companion `ucpf-scanner` **1.5.0**
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
- Legal pages: hide theme/WordPress duplicate page titles (CSS + main-loop filter); force UCPF h1/h2/h3 sizes with !important — site header/theme chrome untouched
- Preference toggles: lock width/height under `#ucpf-root` with !important so theme `button` rules cannot stretch them into tall rectangles
- Network gate: blocked `fetch` aborts (or returns 1×1 PNG for raster) so MapLibre does not log “image could not be decoded”; expand map tile hosts as functional
- WP Consent sync: soft-stub missing `wc_order_attribution.setOrderTracking` so Woo’s consent listener does not throw on non-shop pages
- Branding assets: ship `assets/branding/` icons in the plugin zip; GitHub README shows `.wordpress-org/` banner + screenshots; deploy `ASSETS_DIR=.wordpress-org`; WP admin menu uses transparent `menu-icon.png` (20px slot) — not SVG or the solid 128px brand tile
- Consent logs: collapse identical UUID+action+categories within 5s; lock banner actions while a save is in-flight; Visitors view (latest + event count) with UUID/action/date filters; CSV includes categories/region; withdraw + banner shim reuse existing UUID
- Site profiles (Basic / WP login / WooCommerce): wizard + Advanced Settings; seeds scan URLs and optional logged-in homepage; Woo pack button on Cookie Scanner; unknown-cookie drift queue (New badge, consent default, Scanner review link)
- Multisite per-site CMP: `COOKIEPATH` consent cookies + blog-suffixed storage backup; network activate/deactivate + `wp_initialize_site` provisioning; Integrations/Scanner notices; uninstall loops blogs when delete-on-uninstall is enabled

### Fixed
- Plugin Check: sanitize wizard `site_profile` POST with `sanitize_key` before profile whitelist; consent-log queries prepare each WHERE value separately (avoids nested prepare / LIKE `%` corruption), `esc_sql` table in-scope, documented DirectDB disables for identifier interpolation
- Remove unused `ucpf_network_defaults` stub (settings remain intentionally per-blog)
- Safari/iOS: checkout Embeds & Widgets guard no longer sticks after “Enable & continue” — sessionStorage consent bridge + longer WebKit reload delay so the choice survives the post-save reload; guard re-checks on `pageshow` (bfcache)
- Checkout: one combined Security + Embeds & Widgets panel (no stacked CAPTCHA + payment overlays); “Enable required cookies & continue” grants both
- Scanner skips PDF/download URLs (no more `page.goto: Download is starting` abort); companion `ucpf-scanner` **1.5.1**
- Chrome/Brave/Mac checkout: consent events dispatch on `document` + `window`; navigation uses cache-busted `location.assign` (same-URL replace was a no-op); unlock Enable/Accept if nav cancelled; capture-phase Enable click so Woo does not swallow it

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
