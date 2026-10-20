# Security runbook — UCPF

## Secure development checklist

1. New REST/admin-post routes: capability check + nonce; object-level authz when IDs are involved.
2. Outbound URLs: use `Settings::normalize_outbound_url()` / `Http_Url_Guard::assert_safe_outbound()`.
3. Secrets: never log tokens; prefer `UCPF_*` wp-config constants in production.
4. Scanner API changes: enforce `canAccessJob` on every job read/mutate path; add `npm run test:security` case.
5. Do not treat Plugin Check / lint / scanners as proof of absence of vulns.

## Scanner fleet operations

- Bind default is `127.0.0.1`. Set `UCPF_SCANNER_HOST=0.0.0.0` only behind TLS proxy + firewall.
- Set distinct per-tenant keys in `UCPF_SCANNER_API_KEYS`.
- Set `UCPF_SCANNER_ADMIN_KEYS` for cancel-all / cross-tenant visibility (**required**; no first-key fallback).
- Set `UCPF_SCANNER_TRUST_PROXY=1` only when a trusted reverse proxy sets `X-Forwarded-For`.
- Playwright recipes: `UCPF_SCANNER_RECIPE` or CLI `--recipe` only — never via HTTP `options.recipeFile`.
- Rotate a leaked key: remove from env, restart Node, revoke any mirrored WP options/constants.

## WordPress secrets

- Prefer `UCPF_SCANNER_API_KEY`, `UCPF_PRIVACY_API_KEY`, `UCPF_CLOUDFLARE_API_TOKEN` in `wp-config.php`.
- Loopback scanner URL: define `UCPF_ALLOW_PRIVATE_SCANNER_URL` as true.
- Privacy signatures: define `UCPF_PRIVACY_PUBLIC_KEY` (hex or base64 ed25519 public key).
- Salt change invalidates sealed option blobs — re-enter secrets or use constants.

## Multisite

- Network connection URL + key are paired: a site cannot override only the URL while inheriting the network key.
- To use a different scanner endpoint per site, set **both** site URL and site API key (or site-specific constants).

## Incident response (high level)

1. Contain: rotate API keys/tokens; disable remote scanner URL if abused.
2. Preserve evidence: export audit logs / scanner logs before purge.
3. Assess: which tenants’ scan reports or keys were exposed.
4. Recover: redeploy patched plugin/scanner; verify with commands in `VERIFICATION.md`.
5. Removing a secret from a file does **not** revoke it — rotate at the provider.

## Release / rollback

1. Run verification commands; update `VERIFICATION.md` results honestly.
2. Ship plugin zip / scanner deploy separately (scanner is not in WP.org zip).
3. Rollback of code must not restore old sealed secrets that were rotated — re-apply rotation.
4. Production verification remains a separate authorized step.

## Monitoring

- Scanner `/health` queue depth and failed jobs
- WP debug log for seal failures (redacted)
- Unusual cancel-all usage (admin key only)
