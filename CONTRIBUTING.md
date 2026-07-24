# Contributing to UCPF

Thanks for helping grow an open, turnkey privacy toolkit. By contributing you agree your work is licensed under **GPL-2.0-or-later**.

## First push / local hygiene

Before opening a PR or pushing to a public remote:

- Do not commit `.env`, API keys, or `tools/ucpf-scanner/report*.json`
- Do not include personal filesystem paths or client site domains in docs/examples — use `example.com`
- Run `.\package.ps1` only for release artifacts; `dist/` is gitignored

## Development setup

1. Clone the repo into `wp-content/plugins/` or use a local WP (e.g. wp-env).
2. From the plugin root: `npm install` then `npm run build` (React admin dashboard + GSAP bundle under `admin/build/`).
3. Activate **Universal Consent & Privacy Framework**.
4. Optional scanner: see `tools/ucpf-scanner/README.md`.

`.\package.ps1` runs `npm run build` automatically when Node is available.

### Admin UI source

| Path | Role |
|------|------|
| `admin/src/` | React dashboard (edit here) |
| `admin/css/` | Design system / shell styles |
| `assets/fonts/` | Self-hosted Plus Jakarta Sans |
| `public/js/consent-motion.js` | Banner GSAP (after consent.js) |

Coding standards (also in `AGENTS.md`):

- Namespace `UCPF\`, text domain `universal-consent-privacy-framework`
- `ABSPATH` guards, sanitize/validate/escape, prepared SQL, nonces, capabilities
- Never phone home; never load remote executable code
- Reject All === Accept All button tier; ESC = reject
- CSS tokens `--ucpf-*` under `#ucpf-root`
- Do not claim guaranteed legal compliance

## Good first contributions

| Area | Path |
|------|------|
| Scan noise filters | `data/noise-filters.json` (+ mirror `tools/ucpf-scanner/rules/`) |
| Vendor catalog | `assets/vendor-catalog/` |
| Open Cookie Database rebuild | `tools/build-ocd.ps1` |
| Scanner classify rules | `tools/ucpf-scanner/src/` |
| Translations | `languages/` |
| Docs / examples | `docs/`, `README.md` |

## Pull requests

1. Branch from `main`
2. Keep PRs focused
3. Update `CHANGELOG.md` under Unreleased when user-facing
4. Ensure PHP files parse (`php -l` / CI)
5. Fill the PR template checklist

## Security issues

Do **not** open a public issue for vulnerabilities. See [SECURITY.md](SECURITY.md).

## Code of conduct

[CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md)
