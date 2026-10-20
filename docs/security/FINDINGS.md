# Findings register — UCPF

Authoritative living checklist. Scanner/CI green ≠ every vulnerability gone.

States used: `CONFIRMED`, `IN_PROGRESS`, `PATCHED_NOT_VERIFIED`, `VERIFIED_LOCAL`, `BLOCKED`, `RISK_ACCEPTED_BY_OWNER`, `FALSE_POSITIVE_WITH_EVIDENCE`.

---

## UCPF-001 — Cross-tenant scanner job/report disclosure

| Field | Value |
|-------|--------|
| Severity | High — any valid API key could `GET` another tenant’s job + report |
| Status | `VERIFIED_LOCAL` (unit) |
| Component | `tools/ucpf-scanner/src/index.js`, `store.js` |
| CWE / ASVS | CWE-639; ASVS V4 Access Control |
| Root cause | Ownership enforced on cancel only |
| Fix | `canAccessJob` on GET; `listJobs(caller)` filter; 404 for foreign IDs |
| Tests | `npm run test:security` — listJobs / canAccessJob |
| Residual | Admins with `UCPF_SCANNER_ADMIN_KEYS` intentionally see all |
| Deploy | Not deployed (local only) |

- [x] Root cause and affected paths confirmed
- [x] Reproduction / equivalent evidence recorded
- [x] Code fix implemented
- [x] Ownership tests pass after fix
- [x] Legitimate list/get for own key still works (unit)
- [x] Second review (self) — cancel/delete already owned; GET aligned
- [x] Prevention test in `test:security`
- [x] Docs updated (`SCANNER-SERVER.md`, this register)
- [ ] Deployment / post-deploy verification

---

## UCPF-002 — Multisite network secret exfil via URL override

| Field | Value |
|-------|--------|
| Severity | High — site admin + network key → attacker host |
| Status | `VERIFIED_LOCAL` (pure decision helper); full WP multisite runtime still BLOCKED |
| Component | `includes/class-settings.php` |
| CWE / ASVS | CWE-522; ASVS V4 |
| Fix | `evaluate_force_network_connection_url()` / `must_force_network_connection_url()` |
| Tests | `php tests/security/connection-pairing-selftest.php` |
| Residual | Constant-provided keys with site URL still allowed (single-site recommended pattern) |

- [x] Root cause confirmed
- [x] Fix implemented
- [x] Pure-logic pairing self-test passes
- [ ] Multisite WP runtime reproduction after fix
- [x] Docs / runbook note
- [ ] Deployment verification

---

## UCPF-003 — Scanner SSRF via redirects / Chromium

| Field | Value |
|-------|--------|
| Severity | High |
| Status | `VERIFIED_LOCAL` (helper + mock redirect unit); Chromium route guard untested in headed run |
| Component | `ssrf.js`, `safe-fetch.js`, `discover.js`, `scanner.js` |
| Fix | Manual redirect validation; Playwright route abort; post-goto recheck |
| Tests | `test:security` — metadata/RFC1918 redirects via mocked `fetch` |
| Residual | DNS rebinding TOCTOU |

- [x] Fix implemented + unit tests (incl. redirect chains)
- [ ] Full Playwright SSRF integration test
- [ ] Deployment verification

---

## UCPF-004 — Default scanner bind `0.0.0.0` + first-key admin

| Field | Value |
|-------|--------|
| Severity | Medium (misconfiguration amplifier) |
| Status | `VERIFIED_LOCAL` |
| Component | `tools/ucpf-scanner/src/config.js` |
| Fix | Default host `127.0.0.1`; admin keys only from `UCPF_SCANNER_ADMIN_KEYS` |
| Breaking | Existing fleets relying on first API key for cancel-all must set `ADMIN_KEYS` |

- [x] Fix + `.env.example` + docs
- [x] `test:security` asserts default host when env unset
- [ ] Deployment verification

---

## UCPF-005 — Secrets plaintext fallback

| Field | Value |
|-------|--------|
| Severity | Medium |
| Status | `VERIFIED_LOCAL` |
| Component | `includes/class-secrets.php` |
| Fix | `seal()` returns `WP_Error`; `seal_in_array` omits key; `prepare_for_storage` surfaces `add_settings_error`; `ucpf_secrets_deny_seal` test hook |
| Tests | `php tests/security/secrets-seal-selftest.php` |
| Residual | Legacy plaintext still readable via `reveal()` until migrated |

- [x] Fix implemented
- [x] Admin-facing settings error wired
- [x] Fail-closed self-test (deny filter + normal seal round-trip)
- [ ] Deployment verification

---

## UCPF-006 — WP helper scanner redirect SSRF

| Field | Value |
|-------|--------|
| Severity | Medium |
| Status | `VERIFIED_LOCAL` (pure helper) |
| Component | `includes/class-cookie-scanner.php`, `includes/class-same-site-http.php` |
| Fix | `http_get_same_site()` + `Same_Site_Http` redirect host checks |
| Tests | `php tests/security/same-site-http-selftest.php` |

- [x] Fix implemented
- [x] Pure redirect-host self-test
- [ ] Full WP HTTP stack fixture
- [ ] Deployment verification

---

## UCPF-007 — Admin-configured outbound URL SSRF (WP)

| Field | Value |
|-------|--------|
| Severity | Medium |
| Status | `VERIFIED_LOCAL` (Http_Url_Guard self-test) |
| Component | `includes/class-http-url-guard.php` + sanitize/call sites |
| Fix | Block private/metadata; `UCPF_ALLOW_PRIVATE_SCANNER_URL` for loopback |
| Tests | `php tests/security/http-url-guard-selftest.php` |

- [x] Fix + self-test
- [ ] Deployment verification

---

## UCPF-008 — Privacy signature verification no-op

| Field | Value |
|-------|--------|
| Severity | Medium (when privacy API used with signatures) |
| Status | `VERIFIED_LOCAL` (unsigned / signed-without-key); sodium keypair cases skipped on this host |
| Component | `includes/class-privacy-preference-client.php` |
| Fix | Real ed25519 verify; reject signed payloads without public key; filter defaults to crypto result |
| Tests | `php tests/security/privacy-signature-selftest.php` |

- [x] Fix implemented
- [x] Self-test: unsigned OK without key; signed rejected without key
- [ ] Sodium keypair round-trip on host with libsodium (skipped locally 2026-10-20)
- [ ] Deployment verification

---

## UCPF-009 — Custom CSS `@import` / `url()` 

| Field | Value |
|-------|--------|
| Severity | Low–Medium (requires admin or theme import) |
| Status | `VERIFIED_LOCAL` |
| Component | `includes/class-theme-manager.php` |
| Tests | `php tests/security/css-sanitize-selftest.php` |

- [x] Fix + self-test

---

## UCPF-010 — Public well-known scan token

| Field | Value |
|-------|--------|
| Severity | Low |
| Status | `RISK_ACCEPTED_BY_OWNER` (by-design domain proof; scans do not require verify) |
| Component | `includes/class-agency-scanner.php` |

---

## UCPF-011 — Public REST settings/services disclosure

| Field | Value |
|-------|--------|
| Severity | Info |
| Status | `RISK_ACCEPTED_BY_OWNER` (required for banner) |
| Component | `includes/class-rest-api.php` |
| Second review (Wave 2) | `get_public_settings` returns banner layout only; `get_services_for_js` returns key/name/category/provider only — no secrets |

---

## UCPF-012 — HTTP `options.recipeFile` host filesystem read

| Field | Value |
|-------|--------|
| Severity | High — authenticated scanner API client could pass a path into `fs.readFileSync` |
| Status | `VERIFIED_LOCAL` |
| Component | `tools/ucpf-scanner/src/index.js`, `scan-options.js`, `recipes.js` |
| CWE | CWE-22 / CWE-73 |
| Fix | `sanitizeHttpScanOptions()` strips `recipeFile` / `recipe` / `recipe_file` from HTTP body; recipes only via env/CLI |
| Tests | `npm run test:security` — sanitizeHttpScanOptions |

- [x] Root cause confirmed
- [x] Fix + regression test
- [x] Docs (`.env.example`, SCANNER-SERVER)
- [ ] Deployment verification

---

## UCPF-013 — Unconditional Express `trust proxy`

| Field | Value |
|-------|--------|
| Severity | Medium — spoofable `X-Forwarded-For` skews rate limits when not behind a trusted proxy |
| Status | `VERIFIED_LOCAL` |
| Component | `tools/ucpf-scanner/src/index.js`, `config.js` |
| Fix | Trust proxy only when `UCPF_SCANNER_TRUST_PROXY=1` |
| Tests | `npm run test:security` — trustProxy defaults off |

- [x] Fix + test + docs
- [ ] Deployment verification

---

## UCPF-014 — Release workflow supply-chain residual

| Field | Value |
|-------|--------|
| Severity | Low |
| Status | `RISK_ACCEPTED_BY_OWNER` / partial mitigation |
| Component | `.github/workflows/release.yml` |
| Evidence | Tag-only trigger; `contents: write`; root has no `package-lock.json` so `npm ci` BLOCKED |
| Mitigation | Pinned `softprops/action-gh-release@v2.2.2`; `npm install --ignore-scripts` |
| Residual | Unlocked npm install at release time; branch-protection enforcement UNKNOWN |

---

## Wave 2 second-pass notes (no new High)

| Surface | Result |
|---------|--------|
| Registry import loaders | Confirmed stripped (`loader = null`) — no change |
| Theme / knowledge import | Sanitized packs; CSS sanitizer covers theme CSS |
| Public REST secrets | Not leaked (banner + service metadata only) |
| WP cancel-all | Still hits scanner; scanner requires `UCPF_SCANNER_ADMIN_KEYS` |
| consent.js prefs innerHTML | Uses `escapeHtml` for labels — no new sink |
