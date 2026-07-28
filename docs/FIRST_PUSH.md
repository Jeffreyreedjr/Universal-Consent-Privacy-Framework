# First public GitHub push checklist

Use this once when publishing the repo.

1. Create GitHub repo matching Plugin URI: `Jeffreyreedjr/Universal-Consent-Privacy-Framework` (or update the plugin header URI).
2. Confirm ignored: `.env`, `tools/ucpf-scanner/report*.json`, `.cursor/`, `dist/`, `node_modules/`
3. Confirm no personal paths or client domains in tracked docs
4. `git add` → review `git status` → commit
5. `git push -u origin main`
6. Tag `v0.1.7-alpha` (or current `0.x.y-alpha`) when ready for a Release (triggers `.github/workflows/release.yml`)
7. After WordPress.org approval, add `SVN_USERNAME` / `SVN_PASSWORD` secrets for deploy workflow
8. Fill `.wordpress-org/` with icons, banners, and screenshots before directory submission (see that folder’s README)

See [RELEASING.md](RELEASING.md) for ongoing releases.
