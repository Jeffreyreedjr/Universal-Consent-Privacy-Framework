# Design system (2026)

Tokens live under `#ucpf-root` (public) and `.ucpf-admin` / `.ucpf-shell` (admin) as `--ucpf-*` / `--ucpf-admin-*`. Legal pages use `--ucpf-legal-*` on `.ucpf-legal-page` / `.ucpf-legal-shell`.

## Typography

Self-hosted **Plus Jakarta Sans** (OFL) in `assets/fonts/` — no CDN / no phone-home.

## Presets (public)

- `classic` (**default**) — black / white / WCAG blue `#0b5cad` (hover `#094a8c`, active `#073a6e`)
- `studio_light` — light surfaces + same blue accent scale
- `studio_neon` — intentional neon green accent (branded preset)
- `studio_ocean` — warm / teal accent preset

## Shared WCAG targets

| Role | Public (`--ucpf-*`) | Admin (`--ucpf-admin-*`) | Legal (`--ucpf-legal-*`) |
|------|---------------------|-------------------------|-------------------------|
| Accent | `#0b5cad` | `#0b5cad` | `#0b5cad` |
| Hover | `#094a8c` | `#094a8c` | `#094a8c` |
| Active | `#073a6e` | `#073a6e` | — |
| On-accent | `#ffffff` | `#ffffff` | `#ffffff` on CTAs |
| Focus ring | `#b45309` (≥ 3:1) | `#b45309` | `#b45309` |

- Body text ≥ **4.5:1** against surfaces
- UI chrome / icons ≥ **3:1**
- Focus rings via `--ucpf-focus-ring` / `--ucpf-admin-focus` / `--ucpf-legal-focus`, visible on `:focus-visible`
- Interactive controls expose **default / hover / active / focus-visible / disabled**
- Respect `prefers-reduced-motion` (CSS + GSAP `matchMedia`)

Banner & Branding `accent_color` injects `--ucpf-accent` + derived hover/active and mirrors onto legal accents so custom themes cannot leave orphan green hover states.

## Admin components

- `.ucpf-shell` — sidebar + main
- `.ucpf-card` / `.ucpf-bento` — dashboard cards
- `.ucpf-btn--primary` / `--ghost`
- Status: `.ucpf-health__item--ok|warn|fail` (ok uses blue, not green)

## Motion

- Admin dashboard: GSAP stagger (bundled in `admin/build`)
- Public banner: `public/js/lib/gsap.min.js` + `consent-motion.js` after consent boot

Overrides: Banner & Branding settings, theme pack import/export, or filter `ucpf_theme_tokens`.
