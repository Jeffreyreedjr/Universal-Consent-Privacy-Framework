# Cloudflare Cache Rules for UCPF

UCPF reshapes **HTML** from the `ucpf_consent` cookie (fonts, maps, embeds, captchas, footer widgets). After Accept / Decline / Save it navigates with `?_ucpf=<timestamp>` so WordPress re-renders for that consent mix. Long-term caching of static scripts/CSS is fine; caching **one HTML shell for every visitor** (or ignoring `?_ucpf=` / plugin `?ver=`) causes footers and sections to look broken until a hard refresh.

Add these clauses to your existing Cloudflare **Bypass cache** rule (or create one). With Cache Rules, put **Bypass last** if you use “last matching rule wins” (typical), so it overrides Cache Everything / Cache Files for matched requests.

## Bypass expression

```
(http.request.uri.path contains "/wp-content/plugins/universal-consent-privacy-framework/") or (http.request.uri.path contains "/wp-content/uploads/elementor/css/") or (http.request.uri.query contains "_ucpf") or (http.cookie contains "ucpf_consent") or (http.cookie contains "ucpf_dns")
```

| Clause | Why |
|--------|-----|
| `ucpf_consent` cookie | Returning visitors get origin HTML shaped for their consent |
| `ucpf_dns` cookie | Do Not Sell / privacy override |
| query `_ucpf` | Accept / Decline / Save reload cache-bust |
| UCPF plugin path | Honor `?ver=` when Ignore Query String would pin stale consent.js / CSS |
| `uploads/elementor/css/` | Elementor post CSS regenerates after edits/updates; year-caching can store HTML (soft 404) as `.css` → MIME `text/html` and a broken layout until purge |

Anonymous first visits (no consent cookie) can still hit Cache Everything for HTML. Images and other statics can stay on a long **Cache Files** rule.

## Cache Files (static) — do not poison assets

If you long-cache by file extension (css/js/images) for ~1 year:

- Status **400–599** → **no cache** (or TTL 0). Otherwise a missing `.css` that returns HTML 404 can stick for a year.
- Do **not** use zone **Ignore Query String** for CSS (or carve out uploads). Elementor and WordPress `?ver=` must change the cache key.
- Prefer Bypass (above) for `uploads/elementor/css/` rather than 1-year TTL on that path.

## Settings checklist

| Setting | Guidance |
|--------|----------|
| **Rocket Loader** | Off site-wide, or never rewrite UCPF tags. The plugin sets `data-cfasync="false"` on gate / consent / loader. |
| **Auto Minify JS** | Prefer off while troubleshooting broken sections. |
| **Cache Everything** | OK for anonymous HTML only if Bypass overrides when consent / Elementor CSS / `_ucpf` match. |
| **Browser Cache TTL** | Aggressive for static assets; do **not** force a long browser TTL on HTML documents. |
| **Purge** | After Elementor CSS regen or major plugin updates, purge `uploads/elementor/` once if Bypass was missing when poison was stored. |

Do **not** add Transform Rules that strip `_ucpf` from the query string.

UCPF also flushes **origin** / common page caches when plugins, themes, or Elementor CSS clear — it does not call the Cloudflare API.

## Validation

1. Private window → Accept All → URL briefly has `?_ucpf=` then strips → fonts / footer widgets that need Functional or Marketing load.
2. Decline All → same bust → gated embeds stay blocked / placeholders; no half-rendered footer junk.
3. Return visit with an existing cookie: layout matches consent without Ctrl+F5.
4. DevTools → Network: HTML for cookied requests shows `cf-cache-status: BYPASS` or `DYNAMIC`. Elementor `post-*.css` is `text/css` (BYPASS/DYNAMIC), not `text/html`.
5. Images still HIT with long TTL.

Also see Advanced Settings → **CDN / Cloudflare assets** and [DEVELOPER.md](DEVELOPER.md) § Front-end asset versions / CDN.
