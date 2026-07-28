# Agency cookie knowledge hub

Grow a shared cookie description registry across fleet sites **without** CookieDatabase.org, **without** a hosted cookie DB, and **without** re-shipping the plugin zip for every new cookie.

**Not a legal determination.** Metadata catalogs help detection and copy — they do not guarantee GDPR/CCPA compliance.

## Decision (locked)

| Do | Don't |
|----|--------|
| Git / CDN `registry.json` as the fleet hub | Host a global writeable cookie database for the world |
| Opt-in pull on each WP site | Phone-home or silent sync |
| Scrubbed export → merge tool → commit | POST contribution packs from WordPress |
| Bundled OCD + vendor catalog offline | Call cookiedatabase.org |

## Layers

| Layer | Role |
|-------|------|
| Open Cookie Database (MIT) | Bundled baseline — refresh with `tools/build-ocd.ps1` |
| `assets/vendor-catalog/` | Services + script patterns + blocking (plugin releases) |
| Site option `ucpf_knowledge_entries` | Local log of cookies you reviewed / scanned |
| Your GitHub `registry.json` | Fleet hub — sites opt-in pull via Advanced → Agency knowledge hub |

## Dual gate (easy to miss)

Remote pull only runs when **all** of these are true:

1. **Registry mode** = `agency` (or `community` with double opt-in)
2. **Enable remote metadata sync** checked
3. **Raw `registry.json` URL** set (GitHub raw / CDN)

Optional wp-config for fleets:

```php
define( 'UCPF_REGISTRY_MODE', 'agency' );
```

Community mode never activates unless Remote registry is also enabled.

## Workflow (300-site friendly)

1. Scan a site → Cookie Review / Cookie Lookup → knowledge entries accumulate locally.
2. **Cookie Scanner → Export knowledge pack** (`GET /ucpf/v1/knowledge/export`). Cookies are grouped by provider when possible.
3. Collect exports from many sites, then merge:

```powershell
.\tools\merge-knowledge-hub.ps1 `
  -Inputs .\exports\site-a.json,.\exports\site-b.json `
  -Base .\docs\examples\agency-registry\registry.json `
  -Out .\registry.json
```

- Dedupes cookies by name/pattern
- Merges `services[]` by `key`
- Refuses to overwrite **core** vendor-catalog keys unless you pass `-Force`

4. Commit / publish `registry.json` (GitHub raw URL or your CDN).
5. On each fleet site: **Advanced → Agency knowledge hub** → mode Agency → enable → paste URL → **Refresh registry now**.
6. Cache refreshes about daily. Use Refresh after you push. Status / errors show under the hub block.

Sample skeleton: [`docs/examples/agency-registry/registry.json`](examples/agency-registry/registry.json).

## Schema

```json
{
  "schema": "ucpf-registry-catalog/1.0",
  "services": [
    {
      "key": "example_service",
      "name": "Example",
      "provider": "Example Co",
      "category": "analytics",
      "treatment": "consent",
      "script_patterns": ["example.com"],
      "cookie_patterns": ["ex_*"],
      "cookies": [
        {
          "name": "ex_id",
          "pattern": "ex_id",
          "purpose": "Visitor id for analytics.",
          "category": "analytics",
          "treatment": "consent"
        }
      ],
      "default_blocking": true
    }
  ]
}
```

## Admin tools

- **Cookie lookup** — vendor catalog → site knowledge (exact + `*` patterns) → Open Cookie Database.
- **Import knowledge pack** — merge another site’s export into this site’s log (patterns preserved).
- **Contribute cookie knowledge** — scrubbed anonymized pack download + GitHub issue (manual attach; no phone-home). Schema `ucpf-cookie-knowledge-contribution/1.0`.
- **Refresh registry now** — clears the daily cache and re-fetches the hub URL.

## Public community contributions

Fleet and contribution packs are **anonymized** (no `site_url`, no first-party hosts). Admins must confirm the checkbox each time; opening GitHub is a browser navigation they initiate — the plugin does not POST metadata.

## Fleet checklist (~300 sites)

- [ ] One private Git repo (or CDN) for `registry.json`
- [ ] Prefer `UCPF_REGISTRY_MODE=agency` in wp-config on fleet sites
- [ ] Enable sync + paste the **same** raw URL (or shard cohorts across hubs if needed)
- [ ] After hub push: spot-check Refresh on one site, then rely on daily cache
- [ ] Keep scanner API keys per-site (separate from the knowledge hub)
- [ ] Never treat hub data as a compliance certificate

## Rules

- Never store cookie **values**.
- Do not scrape or call cookiedatabase.org (CC BY-NC-ND).
- Credit Open Cookie Database when OCD text is shown.
- Remote hub entries are never auto-`necessary`.
- No remote executable code — catalogs are data/rules only.
- Not a legal compliance determination.
