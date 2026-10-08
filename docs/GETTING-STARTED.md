# Getting started

Turnkey path: install plugin → brand → scan → review → go live.

## 1. Install

**From zip / GitHub Release**

1. Download `universal-consent-privacy-framework.zip`.
2. WordPress → Plugins → Add New → Upload Plugin → Activate.

**From this repo**

```powershell
.\package.ps1
# Upload dist/universal-consent-privacy-framework.zip
```

**From WordPress.org** (when listed): search “Universal Consent & Privacy Framework” and install — updates arrive in the Dashboard.

## 2. Setup Wizard

**Privacy Consent → Setup Wizard**

1. Visitors (jurisdiction pack) and documents.
2. Website information + **site profile** (Basic / WP login / WooCommerce) — seeds scan pages.
3. Scanner API (optional), Website Scan, statistics & services.
4. Review unknown cookies (drift queue — defaults to consent until classified).
5. Generate Cookie Policy / Privacy pages; enable the banner and go live.

Change profile later under **Advanced Settings**. Behind Cloudflare, enable **geo pack routing** (Advanced) so US visitors get the US privacy baseline and EEA/UK get strict GDPR — see [JURISDICTION-PACKS.md](JURISDICTION-PACKS.md). For CDN + **origin nginx/Plesk page cache** (skip `ucpf_consent` / `ucpf_dns` / `_ucpf`), Redis Object Cache / OPcache on zip deploy, quiet vs busy TTLs, and full Cache Rules detail, see [CLOUDFLARE-CACHE.md](CLOUDFLARE-CACHE.md) (also summarized under Advanced → CDN / Cloudflare assets).

### Performance plugins (Hummingbird, Autoptimize, WP Rocket, LiteSpeed)

Do **not** minify, combine, defer, or delay UCPF assets. The consent gate must load early and uncombined. UCPF auto-registers exclusions when those plugins are present; still verify in the optimizer UI:

- Path: `universal-consent-privacy-framework`
- Handles: `ucpf-network-gate`, `ucpf-consent`, `ucpf-consent-motion`, `ucpf-loader`, `ucpf-form-captcha-guard`, `ucpf-legal`, `ucpf-banner`

UCPF also fleet-excludes **layout-critical** scripts that break when combined/delayed (Hummingbird canary: `jQuery(...).ready is not a function` when The Plus + Mailchimp Woo pixel land in one AO file):

- jQuery / jQuery Migrate (`jquery-core`, `jquery-migrate`, `jquery.min.js`, …)
- Elementor frontend (`elementor-frontend`, `elementor-pro-frontend`, `elementor/assets/js`, `elementor(-pro)/assets/lib`, `e-sticky`, `wp-i18n`, `wp-hooks`)
- The Plus Addons (`the-plus-addons`, `theplus`, `pt-plus`, …)
- Mailchimp for WooCommerce pixel / SMS (`mailchimp-woocommerce-pixel`, `mailchimp-woocommerce_sms`, …)

Those handles get optimizer exclusion list entries plus `data-no-optimize` / `data-no-defer` / `data-cfasync="false"` on the script tag. On UCPF zip activate/upgrade, UCPF clears Hummingbird Asset Optimization (minify) cache when the HB API is present so old combined hashes are not served forever. Extend via `ucpf_optimizer_exclusion_needles`.

**Calendly:** network requests to `assets.calendly.com` / `calendly.com/…` (including event paths like `/30min/?embed_domain=…`) are **canceled/parked until Embeds (Functional)** — that is consent gating, not a broken stylesheet.

Elementor `post-*.css` issues are builder/CDN/page-cache problems — UCPF does **not** consent-gate stylesheets. After plugin/theme/UCPF updates, UCPF clears Elementor’s CSS cache by default, heals the **current page** CSS on enqueue, and purges origin **HTML page caches** (Hummingbird Page Cache, Rocket, LiteSpeed, etc.) so clean URLs are not stuck without `elementor-post-{ID}.css`.

**False “needs consent for styles”:** Accept All reloads with `?_ucpf=`, which bypasses page cache. If the clean URL was cached without the page CSS link, layout looks broken until Accept — purge Hummingbird Page Cache (or nginx/Plesk page cache) for that URL, confirm View Source has `id="elementor-post-{ID}-css"` **without** accepting cookies. Then Elementor → Regenerate CSS & Data if needed; purge Cloudflare only for soft-404 MIME `text/html` on CSS (short TTL or Bypass **only** `/wp-content/uploads/elementor/css/` — see [CLOUDFLARE-CACHE.md](CLOUDFLARE-CACHE.md)). Do not Bypass all CSS/JS/uploads.

### AI-generated website images (Privacy Policy)

**Privacy Consent → Generated Pages → AI image disclosure**

- **Off (default)** — no AI section in the generated Privacy Policy.
- **On** — adds an optional “AI-generated and enhanced images” section (menu photos, weekly updates, promotional visuals).

Enable this only on sites that actually publish AI-generated or AI-enhanced images. Saving the setting regenerates the Privacy Policy when one already exists; use **Refresh Privacy Policy only** to rebuild the template without changing other options.

**New York note:** NY’s Synthetic Performer Disclosure Law (GBL §396-b, effective June 9, 2026) requires a **conspicuous disclosure in the advertisement itself** when a commercial ad features an AI-generated human likeness that is not a real identifiable person. A Privacy Policy paragraph is good transparency for on-site content (menus, blog images) but **does not satisfy** that in-ad rule. Have counsel review wording before you rely on it.

Agencies can replace the default paragraphs with `ucpf_privacy_ai_disclosure_html` or adjust template variables via `ucpf_policy_template_variables`.

## 3. Branding

**Privacy Consent → Banner & Branding**

- Business name, logo URL
- Theme: Classic (default), Studio Neon / Ocean / Light
- Accent colors + custom CSS
- Optional “Powered by …” on preferences

Agency product rename / default scanner: [WHITE-LABEL.md](WHITE-LABEL.md).

## 4. Deep privacy-behavior scan (optional)

Full architecture: [PRIVACY-BEHAVIOR-SCANNER.md](PRIVACY-BEHAVIOR-SCANNER.md).

### Local CLI (recommended for most sites)

```bash
cd tools/ucpf-scanner
npm install
npx playwright install chromium
npm run scan -- --url https://yoursite.example/ --profile standard --out report.json
# Exit: 0 pass · 1 violation · 2 incomplete · 3 error
```

Then **Cookie Scanner → Import scan JSON** (pass/fail findings UI).

### Hybrid modes

| Mode | Config |
|------|--------|
| Local CLI | Leave Scanner API URL blank; import JSON |
| Agency | Advanced → Scanner API URL + key |
| Community | Later — `registry_mode=community` + Remote registry (double opt-in; off by default) |

### Self-hosted API (server)

Full walkthrough: **[SCANNER-SERVER.md](SCANNER-SERVER.md)** (install, `.env`, systemd, HTTPS proxy, WordPress Advanced settings).

Short version:

1. On the server: `cd tools/ucpf-scanner` → `npm install` → `npx playwright install chromium`
2. Copy `.env.example` → `.env`, set `UCPF_SCANNER_API_KEYS`
3. `npm start` (bind localhost) behind HTTPS reverse proxy
4. WP **Advanced → Scanner API URL + key**

Scanner auth: keys required for remote clients; `UCPF_SCANNER_ALLOW_LOCAL=1` only allows unauthenticated **loopback**.

## 5. After scan

1. Cookie Review — assign categories to unknowns.
2. Confirm Reject All / Accept All / ESC = reject behavior on the front end.
3. CAPTCHA forms need **Security**; maps / videos / PayPal·Stripe·Square checkout widgets need **Embeds & Widgets** (`functional`); YouTube embeds need **Marketing** — after Reject All those surfaces show a theme-matched blocking notice until the category is enabled.
4. Refresh Cookie Policy if auto-refresh is enabled.

## Privacy posture

- Plugin does **not** phone home.
- Remote registry is **off** by default.
- Never loads remote executable code into WordPress.
- Technical scans are inventories — not a legal determination.

## Multisite

UCPF keeps **banner, consent, inventory, and logs per-site**. Scanner / Privacy Preference / agency registry **connection** settings can be shared once under **Network Admin → Privacy Consent**.

1. **Network Admin → Privacy Consent** — set Scanner API URL/key, Privacy API, and registry defaults. Sites inherit when their Advanced fields are blank; filled site fields override.
2. Existing installs: use **“Use this site’s settings as network defaults”**, then optionally **Clear site overrides** so every blog inherits (banner/scans untouched).
3. Open each site’s dashboard → **Integrations** for that site’s measurement IDs; **Cookie Scanner** to scan that site’s `home_url` (inventory stays per-site). When the ad team sends tag/disclosure answers for a site, enter them under GTM **What’s inside this container** (site-specific) and save so Cookie/Privacy refresh — see **Google tags and GTM partner answers** below.
4. Network-activate provisions tables/cron for existing blogs and for new sites (`wp_initialize_site`). New sites inherit network connection settings automatically via empty site fields.
5. Consent cookies use WordPress `COOKIEPATH` so subdirectory blogs do not share `ucpf_consent`. Avoid a network-wide `COOKIE_DOMAIN` if sites must keep separate consent (subdomain fleets are safest with host-only cookies).
6. Resolve order for connection keys: `UCPF_SCANNER_API_*` wp-config constants → site override → network setting → brand default. Constants still win if set.

### Banner returns every session (any site / device)

Consent is written to **cookie (Max-Age + Expires) + localStorage + sessionStorage + IndexedDB**, then restored from any surviving layer (with URL `#ucpf_c=` handoff on reload). Full site-data wipe or “block all cookies + all storage” will correctly re-prompt — everything short of that should hold.

If the banner still reappears after Accept / Reject / Save:

1. Confirm `ucpf_consent` in Application → Cookies (Path matches the site, Max-Age ≈ configured days, not session-only).
2. Check `localStorage` / `sessionStorage` key `ucpf_consent_backup` (or `ucpf_consent_backup_*` on multisite).
3. Private / “clear cookies on exit” browsers: expect a fresh banner after the jar is wiped.
4. Brave Shields / strict tracking protection: reload should briefly show `?_ucpf=` and `#ucpf_c=`; if Shields block storage, handoff still restores once.
5. Safari bfcache: leaving and returning via Back should keep the banner closed when consent is still valid.
6. CDN / page cache must skip or vary HTML on `ucpf_consent` — see [CLOUDFLARE-CACHE.md](CLOUDFLARE-CACHE.md). Sticky anon HTML can look like “banner every visit” even when the cookie is fine.
7. Cookie lifetime in settings must be ≥ 1 day (plugin floors 0 / empty to 180 days).

**Manual device matrix:** iOS Safari (normal + private), Android Chrome, Mac Safari, Mac/Windows Chrome, Brave (Shields up), “clear cookies on exit”.

### Google tags and GTM partner answers (agency workflow)

When a client’s ad team or digital partner updates tags, revise that **site’s** policies — each site is different.

1. **Integrations** → enable GA4 / GTM and enter measurement / container IDs (add multiple GTM containers if needed).
2. Under each GTM container, open **What’s inside this container** and enter the partner’s answers for **this site only** (platforms/tags, cookie duration, purposes, recipients, use of data, notes). Do not assume another site’s vendors apply.
3. **Save tracking tags** — Cookie and Privacy Policy pages refresh automatically when those pages already exist. Or use **Refresh policies for current tags**.
4. Run a **Deep privacy scan** when the container may load tags that were not disclosed, then review unknowns in Cookie Review.
5. Spot-check the public Cookie Policy (Duration column + GTM disclosure table) and Privacy Policy (Google + site-specific GTM sections).

## Next

- Developer hooks: [DEVELOPER.md](DEVELOPER.md)
- Releases & WordPress.org: [RELEASING.md](RELEASING.md)
