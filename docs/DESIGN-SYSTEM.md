# Design system (2026)

Tokens live under `#ucpf-root` (public) and `.ucpf-admin` / `.ucpf-shell` (admin) as `--ucpf-*` / `--ucpf-admin-*`.

## Typography

Self-hosted **Plus Jakarta Sans** (OFL) in `assets/fonts/` — no CDN / no phone-home.

## Presets (public)

- `classic` (**default**) — black / white / `#135629`
- `studio_neon`, `studio_ocean`, `studio_light`

## WCAG targets

- Body text ≥ **4.5:1** against surfaces
- UI chrome / icons ≥ **3:1**
- Focus rings: `--ucpf-focus-ring` / `--ucpf-admin-focus` (≥ 3:1), visible on `:focus-visible`
- Interactive controls expose **default / hover / active / focus-visible / disabled**
- Respect `prefers-reduced-motion` (CSS + GSAP `matchMedia`)

## Admin components

- `.ucpf-shell` — sidebar + main
- `.ucpf-card` / `.ucpf-bento` — dashboard cards
- `.ucpf-btn--primary` / `--ghost`
- Status: `.ucpf-health__item--ok|warn|fail`

## Motion

- Admin dashboard: GSAP stagger (bundled in `admin/build`)
- Public banner: `public/js/lib/gsap.min.js` + `consent-motion.js` after consent boot

Overrides: Banner & Branding settings or filter `ucpf_theme_tokens`.
