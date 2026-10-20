# Verification — UCPF security hardening

## Environment

| Field | Value |
|-------|--------|
| Work type | Local working tree only (no commit/push by agent) |
| Branch | `security/hardening` (no branch ops in Wave 2) |
| Production changed | **No** |
| Baseline HEAD | `6ef35253b3fa196e2dd10c974b6e0978bbe4baa9` |
| Verification host | Windows local (PowerShell), 2026-10-20 |

## Commands

```bash
# Scanner security unit tests
cd tools/ucpf-scanner
npm run test:security

# Scanner classify baseline
npm run test:classify

# PHP self-tests
php tests/security/http-url-guard-selftest.php
php tests/security/css-sanitize-selftest.php
php tests/security/connection-pairing-selftest.php
php tests/security/same-site-http-selftest.php
php tests/security/privacy-signature-selftest.php
php tests/security/secrets-seal-selftest.php
php tests/security/ui-safe-href-selftest.php

# Consent durability (root)
npm run test:consent-durability
```

## Tooling notes

| Tool | Local (2026-10-20) | CI |
|------|--------------------|-----|
| Gitleaks | NOT RUN (CLI not installed) | `.github/workflows/security.yml` |
| OSV-Scanner | NOT RUN (CLI not installed) | security.yml |
| Semgrep | NOT RUN (CLI not installed) | Not in CI |
| ZAP / WPScan / CodeQL | NOT RUN / BLOCKED | See COVERAGE.md |
| Branch protection requires checks | UNKNOWN / BLOCKED | Needs repo admin |

## Results (Wave 2 retest)

| Check | Result | Notes |
|-------|--------|-------|
| `npm run test:security` | PASS | 13 cases incl. recipeFile strip + trustProxy |
| `http-url-guard-selftest.php` | PASS | |
| `css-sanitize-selftest.php` | PASS | |
| `connection-pairing-selftest.php` | PASS | |
| `same-site-http-selftest.php` | PASS | |
| `privacy-signature-selftest.php` | PASS | Sodium keypair cases skipped (no libsodium) |
| `secrets-seal-selftest.php` | PASS | |
| `test:consent-durability` | PASS | |
| `test:classify` | PASS (Wave 1) | Re-run if needed |
| `npm run build` (admin UI) | PASS | wp-scripts compiled successfully |
| `.\package.ps1` plugin zip | PASS | `dist/universal-consent-privacy-framework.zip` (~1.07 MB) |
| Zip contains hardened PHP | PASS | http-url-guard, same-site-http, secrets, settings, cookie-scanner, privacy-preference-client, admin/build/index.js |
| Zip SHA-256 | `9e5495e813cb6e139ef8519b3d1bca038c2caf245f19d263651807de09cf1f12` | Wave 2 artifact (superseded below) |
| Git commit / push | NOT DONE | Per maintainer |
| Production deploy | NOT DONE | Unchanged |

## Results (UI hardening)

| Check | Result | Notes |
|-------|--------|-------|
| `ui-safe-href-selftest.php` | PASS | Rejects `javascript:` / `data:` / protocol-relative |
| `npm run build` (admin UI) | PASS | Dashboard/Button safe hrefs |
| `.\package.ps1` plugin zip | PASS | Includes `includes/ui-safe.php`, hardened `consent.js` / `admin.js` |
| Zip SHA-256 | `4cf0433feb88433dd12fe1e8bf2137723fe323b02433807cb5a6cf2bbc22c6a9` | Local artifact only |

UI sinks hardened: public prefs DOM rendering (no `innerHTML` string build), admin status HTML text-only for strings, scanner badge via `createElement`, logo/external page URLs http(s)-only, secret-field show/hide, stop-scan confirm, `rel=noopener noreferrer` on blank targets.

## WordPress plugin rebuild notes

- Hardening lives in PHP under `includes/` (shipped in zip). Scanner companion under `tools/` is **excluded** from the WordPress.org-style zip by design.
- Admin React rebuild: `npm run build` → `admin/build/index.js` (included in zip).
- Installable artifact: `dist/universal-consent-privacy-framework.zip` — upload via Plugins → Add New → Upload (or replace existing folder first if corrupted).
