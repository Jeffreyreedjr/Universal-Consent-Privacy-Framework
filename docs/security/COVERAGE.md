# Security coverage register — UCPF

Standards used as aids (not certification): **OWASP ASVS 4.0** (web/API L1-oriented), **OWASP Top 10:2021**, **CWE** classes cited per finding. Partial review ≠ certification.

## Tools

| Tool | Version / mode | Selected? | Why / limits |
|------|----------------|-----------|--------------|
| Manual code review | — | Yes | Business logic / multisite / ownership |
| Node `test:security` | project script | Yes | Scanner authz + SSRF unit checks |
| PHP security self-tests | `tests/security/*` | Yes | URL guard + CSS sanitizer without full WP |
| Gitleaks | CI Action | Yes | Local CLI NOT RUN 2026-10-20 (not installed); CI on PR/push |
| OSV-Scanner | CI Action | Yes | Local CLI NOT RUN 2026-10-20; CI scans package manifests |
| Semgrep | Optional local | NOT RUN | Not installed locally; not in CI |
| Project tests | consent-durability, classify, test:security, PHP self-tests | Yes | Baseline + Wave 1/2 regressions |
| ZAP / WPScan | — | NOT RUN | No authorized staging WordPress URL |
| Trivy | — | N/A | No container/IaC artifacts |
| CodeQL | — | BLOCKED | GH Advanced Security eligibility unknown |
| Schemathesis | — | N/A | No OpenAPI schema for `ucpf/v1` |
| Burp / Snyk | — | NOT RUN | Not provisioned |
| Branch protection | GitHub settings | UNKNOWN/BLOCKED | CI YAML ≠ merge gate without admin verify |

## Control families

| Family | Applicable | Component | Evidence / test | Gap |
|--------|------------|-----------|-----------------|-----|
| Authentication | Yes | WP core + scanner API keys | Scanner timing-safe key compare; WP cookies/nonces | Scanner has no MFA (API key model) |
| Authorization | Yes | REST `manage_options`; scanner `key_fp` | Ownership on GET/list/cancel | Custom caps not introduced |
| Session | Partial | WP sessions; consent cookie | Consent nonce on POST | — |
| Injection / XSS | Yes | Templates, admin views | Escaping review; CSS sanitizer tests | Continuous PHPCS not in CI |
| SSRF | Yes | Scanner + WP outbound | safe-fetch, route guard, Http_Url_Guard | DNS rebinding residual |
| Secrets / crypto | Yes | `Secrets`, privacy signatures | Fail-closed seal; ed25519 verify | Salt-bound key derivation |
| Data leakage | Yes | Public REST, logs, exports | Minimized consent logs | Public settings intentional |
| CSRF / CORS | Yes | WP REST nonces | Public consent uses `wp_rest` nonce | — |
| Business logic | Yes | Consent, scan queue | Queue ownership tests | Race on WP rate limit residual |
| Dependencies | Yes | npm root + scanner | OSV-Scanner CI + Dependabot | PHP = WP core |
| CI / supply chain | Yes | GitHub Actions | contents:read lint; pin checkout@v4 | Release workflow secrets ops |
| Availability | Partial | Scanner rate limit / queue | Per-key caps | Distributed throttle N/A |

## Untested / residual

- Authenticated dynamic scan of a live WordPress admin (ZAP)
- Production Cloudflare / scanner fleet configuration drift
- Full PHPUnit under wp-env in CI
- Privacy signature sodium keypair round-trip on hosts without libsodium
- Root npm lockfile / `npm ci` for release workflow
- DNS rebinding TOCTOU on scanner navigate
