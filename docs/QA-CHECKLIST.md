# QA Checklist

Default theme: **classic**. Local-first; remote registry **off**.

## Banner (public)

- [ ] Banner shows for new visitor (**classic** + Plus Jakarta Sans)
- [ ] Reject All and Accept All same visual tier (`.ucpf-btn--primary-tier`)
- [ ] ESC rejects optional cookies
- [ ] Logo appears when Logo URL is set
- [ ] Powered-by respects toggle
- [ ] `:focus-visible` rings on buttons, toggles, FAB
- [ ] Hover / active states distinct from default
- [ ] `prefers-reduced-motion: reduce` disables CSS entrance motion
- [ ] Accept/Reject still works if motion script fails to load (boot + consent.js)
- [ ] Accept All → close tab → reopen same site: banner stays closed; `ucpf_consent` present with Max-Age (not Session)
- [ ] Clear only cookies (leave localStorage): reload restores from backup and rewrites cookie; banner stays closed
- [ ] After Accept, reload URL briefly includes `?_ucpf=` and `#ucpf_c=` (all browsers)
- [ ] Safari / Chrome Back-Forward Cache: Accept → navigate away → Back: banner stays closed
- [ ] Device matrix smoke: iOS Safari, Android Chrome, Mac Safari, Brave Shields (see GETTING-STARTED “Banner returns every session”)

## Admin shell

- [ ] All UCPF screens show dark sidebar nav with current page `aria-current`
- [ ] Keyboard: Tab reaches nav links and primary actions; focus ring visible
- [ ] Dashboard React mount loads (or “Loading…” then content)
- [ ] Health list statuses announced (badge `aria-label`)
- [ ] Scanner chips use `aria-pressed` + hover/active styles
- [ ] Wizard nav buttons keyboard operable

## Branding / privacy

- [ ] `wp-content/ucpf-brand.php` renames product in shell brand label
- [ ] Remote registry default off
- [ ] No phone-home on fresh install

## Cloudflare / CDN (when the site is proxied)

Operator rules: [CLOUDFLARE-CACHE.md](CLOUDFLARE-CACHE.md). Advanced Settings shows the same Bypass list.

- [ ] Cloudflare Free stack in order: Cache Files (4xx TTL 0; CSS/JS not year-long), HTML 60–120s, admin Bypass, UCPF plugin + `?_ucpf=` Bypass, consented HTML Bypass last (`ucpf_consent`/`ucpf_dns` and not static extensions)
- [ ] Cache Files: images/media year TTL; CSS/JS hours-scale microcache; 4xx/5xx → no cache; do not Ignore Query String for CSS/JS
- [ ] Rocket Loader off (or not rewriting UCPF gate/consent/loader tags)
- [ ] Private window → Accept All → `?_ucpf=` briefly appears; consented assets load
- [ ] Decline All → gated embeds stay blocked; theme/builder CSS still loads (`text/css`, not `text/html`)
- [ ] After UCPF or Elementor CSS clear: open an Elementor page in a private window **without** accepting cookies and **without** `?_ucpf=` / query strings — View Source includes `elementor-post-{pageId}-css` (layout styled). Maps may still show Embeds guards (expected).
- [ ] If a page looks unstyled until Accept All: purge Hummingbird Page Cache (or origin page cache) for that clean URL; do not treat `?_ucpf=` reload as “consent unlocks CSS”
- [ ] Cookied return visit: layout matches consent without Ctrl+F5
- [ ] After plugin zip upload with Bypass in place: layout does not require a CF purge
- [ ] Without Functional: Calendly `.calendly-inline-widget` shows Enable Embeds panel; Network shows `assets.calendly.com` / calendly event iframes canceled or parked (expected)
- [ ] With Functional (incl. Elementor popup): Calendly initializes
- [ ] Gravity Forms PayPal Checkout / PPCP (`gravityformsppcp`) or PayPal SDK donate form: fresh opt-in visitor — `www.paypal.com/sdk/js` is parked (`type="text/plain"` / cancelled) until Embeds accepted
- [ ] Same GF PayPal form: Accept Embeds only (Marketing off) — PayPal buttons + Braintree hosted-fields render and stay clickable; `gform_post_render` recovery if Accept did not hard-reload
- [ ] Same: Accept All — clean init (no empty PayPal button well); scanner/inventory can list PayPal from SDK / `gravityformsppcp` hosts even when PayPal sends `disableSetCookie` (no classic `l7_az` Set-Cookie)
- [ ] Regression: Woo checkout Embeds+Security overlay still works; Stripe/Square still functional; maps/YouTube dual-require unchanged; GF captcha still reinits on Security consent
- [ ] Hummingbird (or AO/Rocket/LSC) Asset Optimization on: console has no `jQuery(...).ready is not a function` from `hummingbird-assets` / combined bundles; jQuery + The Plus + Mailchimp Woo pixel are not smashed into one file
- [ ] Hummingbird does not delay/combine `gform_paypal_sdk` / `paypal.com/sdk` / `gravityformsppcp` past consent unlock
- [ ] After UCPF zip overwrite: Hummingbird AO cache cleared (or rebuild once); layout still styled
- [ ] DevTools: cookied HTML shows `cf-cache-status: BYPASS` or `DYNAMIC`; images from uploads can `HIT`; CSS is `text/css` with a short Age
- [ ] Console: no `insertBefore` NotFoundError from form-captcha-guard.js

## Animations (GSAP / Lottie)

- [ ] Fresh visit → Accept All (`?_ucpf=` reload): GSAP animations run; console has no `gsap is not defined`
- [ ] Fresh visit → Embeds/Functional only (Marketing off): GSAP CDN and Lottie still load and animate
- [ ] Elementor HTML widget with `cdn.jsdelivr.net/npm/gsap@…/dist/gsap.min.js` + ScrollTrigger + inline init: scroll pin/scrub works without plugin interference
- [ ] Same-page Save (no reload, toggles changed): maps still hydrate; GSAP CDN left untouched
- [ ] DotLottie / lottie-player elements resume after consent (`play()`) when previously parked
- [ ] Regression: Smart Slider hero still loads (no customElements re-define errors)

## Site media (Smush / CDN)

- [ ] Pre-consent (Reject All or no choice yet): Smush lazy images on `*.assetcdn.net` (or mirrored `/wp-content/uploads/` CDN URLs) swap off `data:` placeholders and load — not stuck until Accept All
- [ ] Tracker pixels still blocked pre-consent (e.g. `facebook.com/tr`, `bat.bing.com` — not treated as site media)
- [ ] Regression: map embeds still gated (Google Maps / MapMe lazy iframe parks until Marketing + Embeds)

## Maps (Google / Mapbox / OSM / Mapster)

- [ ] Accept All: Google Maps widget renders (`.gm-style` or live iframe with height)
- [ ] Accept All: Mapster / MapLibre canvas appears (loader hidden, tiles/canvas visible)
- [ ] Accept All: only one `mapster-*-ucpf-refire` script (no duplicate clones / nested MapLibre canvases)
- [ ] Accept All: Leaflet/OSM map tiles load (`.leaflet-container canvas` or tile images)
- [ ] Embeds + Marketing both granted: WP Google Maps / Elementor Google Maps refire after API
- [ ] Intermittent miss: map hydrates on delayed retry (≤4s) without manual refresh
- [ ] Regression: Accept All does not freeze tab (Mapster force-refire stays one-shot)
- [ ] Regression: Reject All re-parks map iframes and scripts
- [ ] Reject All: MapMe lazy iframe (`data-src=viewer.mapme.com`, placeholder `data:` src) — no network to mapme; iframe parked / consent overlay
- [ ] Accept All: MapMe / MapHub iframe map restores once and renders
- [ ] Generic lazy iframe: third-party URL in `data-src` with `data:image` placeholder blocked pre-consent (Marketing + Embeds required)

## Videos (Elementor YouTube / Vimeo)

- [ ] Accept All: Elementor background YouTube plays; container is not left `elementor-loading` / `elementor-invisible` (has `ucpf-bg-video-ready`)
- [ ] Embeds + Marketing: open-inline Elementor video mounts a single player (no stacked iframes)
- [ ] Reject optional: background / embed videos stay covered or parked

## Integrations — Google Tag Manager (multi-container)

- [ ] Legacy site with single `GTM-…` in Integrations: opens with one pre-filled row; saves unchanged
- [ ] Add 2+ containers (labels optional); save; reload Integrations — all rows persist; Label/Container fields readable without horizontal scroll
- [ ] Accept All with analytics consent: Network shows one `gtm.js?id=GTM-…` request per saved container
- [ ] Privacy scan on page with multiple GTM snippets: Integrations shows “scan found N containers” + **Add all to list**
- [ ] **Add all to list** merges IDs without enabling GTM unless already enabled; duplicates deduped on save
- [ ] Setup Wizard step 7 (Statistics): GTM uses same multi-row UI; wizard save matches Integrations
- [ ] Open **What’s inside this container**; enter free-text platforms + duration (e.g. “30 days…”) + purposes/recipients for that site; save → Cookie Policy shows Duration + GTM disclosure table; Privacy Policy names those partner details (not another site’s vendors)
- [ ] Enable GA4 only → Cookie Policy lists `_ga` / `_gid` with catalog durations without waiting for a scan
- [ ] Change a GTM ID or disclosure → Generated Pages “Last updated” bumps; success notice appears; **Refresh policies for current tags** works manually
