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

1. Business / contact details for legal pages.
2. Run a cookie scan (guest crawl and/or import deep scan).
3. Select services; review unknown cookies.
4. Generate Cookie Policy / Privacy pages.
5. Enable the banner and go live.

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

### Self-hosted API

1. Configure `tools/ucpf-scanner/.env` with `UCPF_SCANNER_API_KEYS`.
2. `npm start` behind HTTPS.
3. WP **Advanced → Scanner API URL + key** (or `UCPF_SCANNER_API_URL` / `UCPF_SCANNER_API_KEY` in `wp-config.php`).

Scanner auth: keys required for remote clients; `UCPF_SCANNER_ALLOW_LOCAL=1` only allows unauthenticated **loopback**.

## 5. After scan

1. Cookie Review — assign categories to unknowns.
2. Confirm Reject All / Accept All / ESC = reject behavior on the front end.
3. Refresh Cookie Policy if auto-refresh is enabled.

## Privacy posture

- Plugin does **not** phone home.
- Remote registry is **off** by default.
- Never loads remote executable code into WordPress.
- Technical scans are inventories — not a legal determination.

## Next

- Developer hooks: [DEVELOPER.md](DEVELOPER.md)
- Releases & WordPress.org: [RELEASING.md](RELEASING.md)
