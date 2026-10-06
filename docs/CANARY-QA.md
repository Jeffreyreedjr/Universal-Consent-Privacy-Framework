# Canary QA gate (before fleet push)

Run on **one** production-like site after deploying this build. Do not roll out to ~300 Plesk sites until every item passes.

**Canary site:** https://mybabybigfoot.com/  
**Canary zip:** `dist/universal-consent-privacy-framework.zip` (upload via WP Admin → Plugins → Add New → Upload)

Full caching guide (nginx skip, CF, quiet/busy TTLs): [CLOUDFLARE-CACHE.md](CLOUDFLARE-CACHE.md). Re-probe headers: `.\tools\probe-cache-headers.ps1 -BaseUrl https://mybabybigfoot.com`

---

## mybabybigfoot.com header probe (2026-08-21)

Live `?ver=` still `0.1.30-alpha.1787332008` (mtime style) — content-hash build **not** on origin until zip is uploaded. **No Cloudflare ruleset was applied** (existing zone rules left as-is).

| Request | `cf-cache-status` | Cache life signal | Result |
|--------|-------------------|-------------------|--------|
| `/` HTML (anon) | HIT | `s-maxage=30` | PASS (short HTML) |
| `/?_ucpf=1` | DYNAMIC | Bypass | PASS |
| `/` + `ucpf_consent` cookie | DYNAMIC | Bypass | PASS |
| UCPF `banner.css` / `consent.js` | HIT | `max-age=31536000` (1y) | Note — existing Cache Files TTL |
| Theme / Elementor CSS | HIT | 1y | Note |
| Elementor `uploads/.../css/` | HIT | 1y | Note |
| PNG / WebP images | HIT | 1y | PASS |
| MIME on UCPF css/js | — | `text/css` / `application/javascript` | PASS (not poisoned) |

After uploading the canary zip, expect `consent.js?ver=` like `0.1.30-alpha.SIZE.CRC32[.rev]` (plugin-side bust; no CF ruleset change required for that).

---

## Assets / cache life

- [ ] View-source or DevTools Network: `consent.js?ver=` looks like `0.x.y.SIZE.CRC32[.rev]` (size + crc change when the file content changes, even if Version header is unchanged).
- [ ] After a same-version zip overwrite (no Version bump), load any front page once from origin (or wp-admin): `?ver=` changed, response has `CDN-Cache-Control: no-store` (or CF purge ran), banner/CSS load **without** a Cloudflare dashboard flush.
- [ ] Advanced → Cloudflare: “Purge after UCPF…” checked and API token set on canary (or official CF WP plugin installed for soft-hook).
- [ ] UCPF `.css` / `.js` return `content-type: text/css` or `javascript` (never HTML soft-404). Cloudflare: Status **400–599 → TTL 0**; **do not** Ignore Query String on CSS/JS.
- [ ] Existing CF rules: Bypass `?_ucpf=` and consented HTML still win; Rocket Loader off for UCPF tags.

## Consent / overlays

- [ ] Accept All / Reject All / Customize work; network-gate still parks third-party scripts before consent.
- [ ] Elementor page with form + captcha: Security overlay appears (no long uncovered typing window).
- [ ] YouTube or Vimeo embed: Marketing/Functional cover appears until consent.
- [ ] Map embed: cover appears until Embeds consent.
- [ ] WooCommerce checkout (if present): Embeds/Security covers behave as before.
- [ ] Gravity Forms PayPal / PPCP donate form (if present): SDK parked until Embeds; buttons/hosted fields work after Accept Embeds (Marketing off OK); no empty PayPal well after cancelled reload.

## Legal pages

- [ ] Cookie Policy and Privacy Policy load with `legal.css` styling and inventory/disclosures tables.
- [ ] Pages listed in Generated Pages still detect as legal even if `_ucpf_generated_page` meta was missing (ID match).

## Scans (manual only)

- [ ] Cookie Scanner → Run Playwright / WordPress helper / Import report still works.
- [ ] Advanced → Scanner shows **Deep scan automation** retired notice — no schedule toggle / “Run scheduled scan now”.
- [ ] After load: `wp_next_scheduled( 'ucpf_scheduled_scan_start' )` is empty (WP-CLI or Query Monitor cron). Overnight traffic does not start Deep scans.
- [ ] Sites that previously had scheduled scans enabled: setting is forced off; cron hooks cleared.

## Multisite (if used)

- [ ] Network → clear site overrides / switch blogs does not copy one site’s `ucpf_settings` onto another.
- [ ] Network-activate does not leave recurring scan events behind.

## Sign-off

| Item | Tester | Date | Pass |
|------|--------|------|------|
| Assets / header probe | agent (curl) | 2026-08-21 | Partial — HTML/bypass OK; upload canary zip for content `?ver=` |
| Consent / overlays | | | |
| Legal | | | |
| Manual scans only | | | |
| Multisite (if any) | | | |
| CF ops | | | No ruleset changes (existing zone left alone) |

Only after this canary: zip/deploy to the fleet.
