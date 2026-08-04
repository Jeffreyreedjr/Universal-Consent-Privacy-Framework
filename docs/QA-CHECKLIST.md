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

- [ ] Bypass includes: `ucpf_consent` / `ucpf_dns`, `_ucpf`, UCPF plugin path, `/wp-content/uploads/elementor/css/`
- [ ] Cache Files: 4xx/5xx → no cache; do not Ignore Query String for CSS
- [ ] Rocket Loader off (or not rewriting UCPF gate/consent/loader tags)
- [ ] Private window → Accept All → `?_ucpf=` briefly appears; fonts/footer widgets load for allowed categories
- [ ] Decline All → gated embeds stay blocked; no half-rendered footer
- [ ] Cookied return visit: layout matches consent without Ctrl+F5
- [ ] Elementor `post-*.css` is `text/css` (BYPASS/DYNAMIC), not `text/html`
- [ ] Without Functional: Calendly `.calendly-inline-widget` shows Enable Embeds panel
- [ ] With Functional (incl. Elementor popup): Calendly initializes
- [ ] DevTools: cookied HTML shows `cf-cache-status: BYPASS` or `DYNAMIC`
- [ ] Console: no `insertBefore` NotFoundError from form-captcha-guard.js
