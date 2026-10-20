# Project and threat model — UCPF

Living document. Does not claim complete coverage or certification.

## Stack (verified from local source)

| Item | Value |
|------|--------|
| Product | Universal Consent & Privacy Framework (WordPress plugin) |
| Version | `0.1.45-alpha` (`readme.txt` / `UCPF_VERSION`) |
| PHP | ≥ 7.4 |
| WordPress | ≥ 6.3 (tested up to 7.1) |
| Companion | `tools/ucpf-scanner` (Node ≥ 20, Playwright) — not in WordPress.org zip |
| Package managers | npm (root admin UI + scanner); no Composer |
| Hosting assumptions | Customer WordPress; optional self-hosted scanner behind TLS |

## In scope

- WordPress plugin: REST, admin-post, options, consent logs, scanners, imports/exports
- Companion Playwright scanner HTTP API and SSRF controls
- Multisite network connection settings inheritance
- CI workflows and dependency update automation in this repository

## Out of scope / N/A (with evidence)

| Profile | Evidence |
|---------|----------|
| Mobile / MASVS | No mobile app artifacts |
| Payments / Stripe | No payment webhooks or ledgers |
| Containers / IaC | No Dockerfile / Terraform in repo |
| Cloudflare Workers | CF used only as Cache Purge API client |
| AI / RAG / agents | None |

## Assets

1. Scanner / privacy / Cloudflare API credentials
2. Consent audit rows (`user_id`, session hash, CF country — no raw IP/email by design)
3. Scan reports (site inventories, tracker findings)
4. Network-shared connection settings (multisite agencies)
5. Site integrity of consent enforcement (script blocking)

## Actors

- Anonymous visitors (public REST consent/settings)
- Site administrators (`manage_options`)
- Network administrators (`manage_network_options`)
- Operators of a shared Scanner API (multi-tenant keys)
- External attackers targeting SSRF, secret theft, or cross-tenant report access

## Trust boundaries

```
Visitor Browser ──public REST──► WordPress Plugin
Site Admin ──manage_options──► WordPress Plugin
Network Admin ──network settings──► WordPress Plugin
WordPress ──Bearer key──► Scanner API ──Playwright──► Target site
WordPress ──Bearer key──► Privacy Preference API
WordPress ──API token──► api.cloudflare.com
```

## Security invariants

1. Non-admin scanner API keys can only read/cancel their own jobs.
2. Multisite sites cannot point a network-inherited API key at an attacker URL.
3. Outbound admin-configured URLs and scan navigations must not hit private/metadata hosts (unless explicitly allowed for loopback scanners).
4. API secrets are never written plaintext when crypto is available; seal failure fails closed.
5. Privacy policy signatures are cryptographically verified when a public key is configured; signed payloads without a key are rejected.

## Assumptions

- Local source may differ from production deployments.
- Scanner exposed without keys/TLS is operator misconfiguration (see `SECURITY.md` out of scope).
- WordPress core authn/authz and salt quality are prerequisites.
