# UCPF Privacy-behavior Scanner

Optional companion to the [Universal Consent & Privacy Framework](../../README.md) WordPress plugin. Playwright-based **privacy-behavior** scans: compare cookies, storage, and network across consent states (not cookie-name inventory alone).

**Not a legal compliance guarantee.** HTTPS JSON API and CLI only — never loads remote executable code into WordPress. Default is local-first (no phone-home).

See [docs/PRIVACY-BEHAVIOR-SCANNER.md](../../docs/PRIVACY-BEHAVIOR-SCANNER.md) for architecture, findings vocabulary, and scan levels.

## What it collects (schema `ucpf-playwright-scan/2.0`)

- Cookies (attributes + optional CDP **value hash** — never raw values), partitions/CHIPS when available
- Consent differential `findings[]` + `findings_summary` (pass/fail)
- Profiles: `--profile quick|standard|compliance` (GPC, revoke, category-only on compliance)
- Storage surface: local/session/IDB/Cache/SW (+ Shared Storage / Cookie Store flags)
- Network + initiator (CDP); SW dual-pass observational note
- Noise filters, CMP/TCF/dark-pattern heuristics, observational GPC/GCM/GPP
- Optional `--recipe`, `--baseline` (drift), `--interact`, screenshots

## Quick start (local CLI)

Requires Node 20+.

```bash
cd tools/ucpf-scanner
npm install
npx playwright install chromium
npm run scan -- --url https://example.com/ --profile standard --out report.json
echo Exit: $?   # 0 pass · 1 violation · 2 incomplete · 3 error
```

Import `report-*.json` in WordPress: **Privacy Consent → Cookie Scanner → Import scan JSON**.

## Self-hosted / agency API

**Full server guide:** [docs/SCANNER-SERVER.md](../../docs/SCANNER-SERVER.md) (VPS install, systemd, TLS, WordPress wiring).

```bash
cp .env.example .env
# Set UCPF_SCANNER_API_KEYS=long-random-secret
npm start
# Default listen: http://0.0.0.0:3847 — put HTTPS in front for production
```

- `GET /health`, `GET /v1/node` — node registration metadata
- `POST /v1/scans` — start job (auth)
- `POST /v1/drift` — compare baselines
- `POST /v1/verify-domain` — `/.well-known/ucpf-scan-token` challenge (no redirect follow)

Auth: header `X-UCPF-Scanner-Key` or `Authorization: Bearer …`. Required off-loopback. SSRF protections block private IPs.

## WordPress wiring

1. **Local:** import JSON (no API).
2. **Self-hosted:** Advanced → Scanner API URL + key ([SCANNER-SERVER.md](../../docs/SCANNER-SERVER.md)).
3. **Registry mode:** `local` (default) | `agency` | `community` | `disabled` — community requires Remote registry enabled (double gate).

## Package

npm name: `ucpf-scanner` (private companion tool).
