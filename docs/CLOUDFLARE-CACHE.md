# Cloudflare Cache Rules for UCPF (any WordPress site)

Long-TTL **Cache Files** rules that cache by extension (`.css` / `.js`) can poison **any** theme, builder, or plugin asset when origin briefly returns HTML (soft 404, maintenance page, plugin zip upload, PHP fatals). The browser then reports:

`Refused to apply style… MIME type ('text/html')`

…and the layout looks broken until you purge.

UCPF also reshapes **HTML** from the `ucpf_consent` cookie. After Accept / Decline / Save it navigates with `?_ucpf=<timestamp>` so WordPress re-renders for that consent mix. Caching **one HTML shell for every visitor** (or ignoring `?_ucpf=` / plugin `?ver=`) causes footers and gated sections to look wrong until a hard refresh.

**Deploy note:** the network gate loads as an external `src=` script (not inlined into HTML). External `src` can 404 for one request during zip replace; it cannot poison the HTML document with a partial JS payload.

Stylesheets and layout webfonts (Typekit / Google Fonts / Font Awesome) are never consent-gated. You still need the Bypass / Cache Files rules below so deploys cannot year-cache an HTML soft-404 as `.css`/`.js`.

## Elementor `post-*.css`

Elementor serves generated CSS from:

`/wp-content/uploads/elementor/css/post-*.css?ver=<filemtime>`

After plugin/theme updates, Elementor clears and rebuilds those files. If Cloudflare **Cache Files** (or default `.css` caching) already stored a soft-404 **HTML** body for that URL — or an old CSS body while **Ignore Query String** collapses `?ver=` — the layout stays broken until you purge.

UCPF never consent-gates these stylesheets. The fix is at Cloudflare (and optional purge API), not Accept All.

### Exclusion from Cache Everything is not enough

A Cache Everything rule that uses `not …/elementor/css/` only skips **that** rule. Cloudflare still caches `.css` by default. You need an **explicit Bypass** (or short-TTL microcache) rule for Elementor CSS so those URLs are not stored at the edge.

### Preferred: Bypass (no edge store)

Put this so it **wins** over Cache Files / year-TTL rules (usually last if last-match wins):

```
(http.request.uri.path contains "/wp-content/uploads/elementor/css/")
```

**Cache eligibility → Bypass cache.**

`cf-cache-status` should be `BYPASS` / `DYNAMIC`. Origin always wins after Elementor regenerates.

### Alternative: microcache (short Edge TTL)

If you want a little edge help without year-long poison, use **Eligible for cache** with a short TTL and **never** cache errors:

| Setting | Value |
|--------|--------|
| Match | `(http.request.uri.path contains "/wp-content/uploads/elementor/css/")` |
| Cache eligibility | Eligible for cache |
| Edge TTL | Override origin → **60–300 seconds** (microcache) |
| Status Code TTL | **200–299** → same short TTL; **400–599** → **0 / no-cache** |
| Cache key | **Do not** Ignore Query String (keep `?ver=` in the key) |

Poisoned HTML-as-CSS then expires in minutes instead of a year. Still purge once after a bad deploy if visitors hit during the window.

### HTML after Accept All (`?_ucpf=` / `ucpf_consent`)

Consent reshapes HTML. Do **not** Cache Everything one shell for every visitor:

```
(http.request.uri.query contains "_ucpf") or (http.cookie contains "ucpf_consent") or (http.cookie contains "ucpf_dns")
```

→ **Bypass cache** (preferred).

Optional HTML microcache for **anonymous** first visits only (no consent cookie): Edge TTL 30–120s is fine; cookied / `_ucpf` traffic must still Bypass.

### Quick check in DevTools

1. Open `post-*.css` → Response headers: `content-type: text/css` (not `text/html`).
2. `cf-cache-status`: `BYPASS` / `DYNAMIC`, or `HIT` only with a short Age under your microcache TTL.
3. After Elementor regen, `?ver=` should change; with Ignore Query String off, that is a new cache key.

When **Clear Elementor CSS cache after updates** is on, UCPF clears Elementor’s CSS on update and queues a Cloudflare purge on shutdown (no WP-Cron). That does not replace Bypass / microcache rules — without them, Cache Files can re-poison on the next miss.

## Bypass expression (site-wide)

Add these clauses to your Cloudflare **Bypass cache** rule (or create one). Put **Bypass last** if you use “last matching rule wins,” so it overrides Cache Files / Cache Everything.

```
(http.request.uri.path contains "/wp-content/plugins/universal-consent-privacy-framework/") or (http.request.uri.path contains "/wp-content/uploads/") or (ends_with(http.request.uri.path, ".css")) or (ends_with(http.request.uri.path, ".js")) or (http.request.uri.query contains "_ucpf") or (http.cookie contains "ucpf_consent") or (http.cookie contains "ucpf_dns")
```

| Clause | Why (any site) |
|--------|----------------|
| `ucpf_consent` / `ucpf_dns` cookies | Returning visitors get origin HTML shaped for their consent |
| query `_ucpf` | Accept / Decline / Save reload cache-bust |
| UCPF plugin path | Honor `?ver=` when Ignore Query String would pin stale consent.js / banner.css |
| **`/wp-content/uploads/`** | Builder CSS, optimized images metadata, generated CSS (Elementor, Oxygen, Bricks, Divi, Smush WebP paths, etc.) — regenerates and must not year-cache HTML-as-CSS |
| **all `.css` / `.js`** | Stops Cache Files from keeping a soft-404 HTML body as a stylesheet/script for a year after deploys |

If bypassing every `.css`/`.js` is too broad for your CDN plan, use at least:

```
(http.request.uri.path contains "/wp-content/uploads/") or (http.request.uri.path contains "/wp-content/themes/") or (http.request.uri.path contains "/wp-content/plugins/")
```

…and still apply the Cache Files rules below.

Anonymous first visits (no consent cookie) can still hit Cache Everything for **HTML**. Images / video / fonts can stay on a long Cache Files rule.

## Cache Files (static) — required for any long-TTL setup

If you long-cache by file extension for ~1 year:

1. **Status 400–599 → Bypass / no cache (TTL 0).**  
   Without this, a missing `.css` that returns an HTML 404 sticks for a year on every kind of site.
2. **Do not Ignore Query String** for CSS or JS (or carve out `/wp-content/`). WordPress `?ver=` and builder cache-busters must change the cache key.
3. Prefer **images / media only** on the year TTL rule; keep CSS/JS on Bypass or a short TTL (hours–days).
4. After plugin/theme uploads or builder CSS regen, purge once if Bypass was missing when poison was stored (`/wp-content/uploads/`, or Purge Everything if unsure).

## Plugin updates and page caches

On update, UCPF bumps its own `?ver=` asset stamps by default and does not clear LiteSpeed / Rocket / Autoptimize / similar stacks (those plugins can delete or rename optimized CSS bundles). If Bypass / status TTLs were incomplete when HTML-as-CSS was stored, purge once after upgrading.

When **Elementor** is active and **Clear Elementor CSS cache after updates** is enabled (Advanced → CDN / Cloudflare; default on), UCPF clears Elementor’s CSS cache on update (files rebuild on enqueue / next view) and queues a Cloudflare purge on **request shutdown** (no WP-Cron). Missing CSS files also self-heal when Elementor enqueues them.

If the front end still shows `MIME type ('text/html')` on Elementor CSS: the edge still has a poisoned soft-404 — enable the purge API token (or Purge Everything once) and confirm Bypass **or** short-TTL microcache covers `/wp-content/uploads/elementor/css/` (see section above). Without that, Cache Files can re-poison on the next deploy.

## Settings checklist

| Setting | Guidance |
|--------|----------|
| **Rocket Loader** | Off site-wide, or never rewrite UCPF tags (`data-cfasync="false"` is set on gate / consent / loader). |
| **Auto Minify JS** | Prefer off while troubleshooting broken layouts. |
| **Cache Everything** | OK for anonymous HTML only if Bypass wins for consent cookies / `_ucpf` / CSS+JS rules above. |
| **Browser Cache TTL** | Aggressive for images/media; do **not** force a long browser TTL on HTML. |
| **Purge** | After major plugin/theme uploads if Bypass was incomplete when HTML-as-CSS was stored. |

Do **not** add Transform Rules that strip `_ucpf` from the query string.

## Validation

1. Private window → Accept All → URL briefly has `?_ucpf=` then strips → consented assets load.
2. Decline All → gated embeds stay blocked; layout CSS still loads (theme/builder must not be consent-gated).
3. Return visit with consent cookie: layout matches without Ctrl+F5.
4. DevTools → Network: theme/plugin/builder `.css` is `text/css` with `cf-cache-status: BYPASS` or `DYNAMIC` (not `text/html`).
5. Cookied HTML shows `BYPASS` / `DYNAMIC`. Images can still HIT with long TTL.
6. After a plugin zip upload: with the rules above, upload should not require a full cache dump.

Also see Advanced Settings → **CDN / Cloudflare assets** and [DEVELOPER.md](DEVELOPER.md) § Front-end asset versions / CDN.

## Automatic purge API (optional)

When Bypass rules are incomplete or HTML is still sticky after updates, enable **Automatic Cloudflare purge API** under Advanced Settings → CDN / Cloudflare assets:

1. Create an API Token in Cloudflare with **Zone → Cache Purge** and **Zone → Zone → Read** for this site’s zone.
2. Paste the **domain** (e.g. `example.com`) and API token into UCPF (Wizard → Visitors or Advanced → Cloudflare); enable purge on updates. UCPF resolves the Zone ID automatically via the Cloudflare API.
3. On any plugin/theme update, UCPF activation/upgrade, theme switch, or Elementor CSS clear, UCPF queues **one** edge purge on **request shutdown** (and retries on the next `admin_init` if needed). No WP-Cron / `spawn_cron`. Each purge attempts a **prefix** clear for `/wp-content/uploads/elementor/css/` (when the plan/token allows), then `purge_everything`.
4. Hard limit: at most one Cloudflare API purge every **10 minutes** (manual button included).
5. This does **not** clear Autoptimize / LiteSpeed / Rocket on the origin — only the Cloudflare edge. With Elementor present, UCPF can also clear Elementor’s CSS cache so `post-*.css` rebuilds on enqueue / next page view (Advanced setting, default on).

Last purge status appears on the same settings screen. Leave the token field blank when saving other settings to keep the existing token.

## After a plugin zip upload (per-URL HTML cache)

**Cache public HTML** stores each URL separately. After you replace UCPF, Cloudflare may still have the previous HTML for a page until that URL is requested again (MISS), the optional purge API runs, or you **Purge Everything** once. Visiting pages “warms” the cache — that is Cloudflare’s model.

During zip extract, WordPress may still mark UCPF active. Current builds **bail out soft** if core files are missing/truncated (site stays up without the banner for a few seconds) instead of white-screening the whole front end.
