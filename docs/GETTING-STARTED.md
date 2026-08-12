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

Change profile later under **Advanced Settings**. Behind Cloudflare, enable **geo pack routing** (Advanced) so US visitors get the US privacy baseline and EEA/UK get strict GDPR — see [JURISDICTION-PACKS.md](JURISDICTION-PACKS.md). If you long-cache HTML or use Ignore Query String, add the Cache Rules in [CLOUDFLARE-CACHE.md](CLOUDFLARE-CACHE.md) (also summarized under Advanced → CDN / Cloudflare assets).

### Performance plugins (Hummingbird, Autoptimize, WP Rocket, LiteSpeed)

Do **not** minify, combine, defer, or delay UCPF assets. The consent gate must load early and uncombined. UCPF auto-registers exclusions when those plugins are present; still verify in the optimizer UI:

- Path: `universal-consent-privacy-framework`
- Handles: `ucpf-network-gate`, `ucpf-consent`, `ucpf-consent-motion`, `ucpf-loader`, `ucpf-form-captcha-guard`, `ucpf-legal`, `ucpf-banner`

Elementor `post-*.css` 404s (unstyled until hard refresh) are builder/CDN cache issues — UCPF does not consent-gate stylesheets. After plugin/theme/UCPF updates, UCPF clears Elementor’s CSS cache by default so files rebuild on the next front-end view; if the layout is still broken, use Elementor → Regenerate CSS & Data and purge Cloudflare (Bypass `/wp-content/uploads/elementor/css/` — see [CLOUDFLARE-CACHE.md](CLOUDFLARE-CACHE.md)).

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
3. Open each site’s dashboard → **Integrations** for that site’s measurement IDs; **Cookie Scanner** to scan that site’s `home_url` (inventory stays per-site).
4. Network-activate provisions tables/cron for existing blogs and for new sites (`wp_initialize_site`). New sites inherit network connection settings automatically via empty site fields.
5. Consent cookies use WordPress `COOKIEPATH` so subdirectory blogs do not share `ucpf_consent`. Avoid a network-wide `COOKIE_DOMAIN` if sites must keep separate consent (subdomain fleets are safest with host-only cookies).
6. Resolve order for connection keys: `UCPF_SCANNER_API_*` wp-config constants → site override → network setting → brand default. Constants still win if set.

## Next

- Developer hooks: [DEVELOPER.md](DEVELOPER.md)
- Releases & WordPress.org: [RELEASING.md](RELEASING.md)
