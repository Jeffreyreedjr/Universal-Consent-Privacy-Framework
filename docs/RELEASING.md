# Releasing UCPF

Trusted updates without a phone-home updater:

1. **WordPress.org** — primary (Dashboard updates via WordPress core)
2. **GitHub Releases** — secondary (zip + SHA256)

## Version scheme

UCPF is **pre-1.0 / Alpha** until a deliberate stable release.

- Current line: `0.1.0-alpha`, then `0.1.1-alpha`, `0.2.0-alpha`, etc.
- Historical `1.4.x` tags were development iterations, not a stable 1.x product line.
- Prefer `0.x.y-alpha` (or later `-beta`) in the plugin header and `UCPF_VERSION` until ready for `1.0.0`.

## Version bump checklist

1. `universal-consent-privacy-framework.php` — header `Version` + `UCPF_VERSION` (and Alpha in Plugin Name / Description while pre-1.0)
2. `readme.txt` — `Stable tag` + changelog section
3. `CHANGELOG.md` — user-facing notes
4. Ensure no secrets, `.env`, or `tools/ucpf-scanner/report*.json` are staged

## Build zip

Requires Node 20+ (builds `admin/build`). Source of truth: `package.ps1` only.

```powershell
npm install
.\package.ps1
# → dist/universal-consent-privacy-framework.zip
```

The GitHub Release workflow (`.github/workflows/release.yml`) runs the same on tag `v*`, verifies `admin/build/index.js` is inside the zip, and attaches `SHA256SUMS.txt`.

Compute checksum (PowerShell):

```powershell
Get-FileHash dist\universal-consent-privacy-framework.zip -Algorithm SHA256
```

## GitHub Release

1. Merge to `main`
2. Tag: `git tag -a v0.1.0-alpha -m "v0.1.0-alpha"` && `git push origin v0.1.0-alpha`
3. Workflow `.github/workflows/release.yml` builds the zip, publishes a Release, and writes SHA256 into the notes
4. Until WordPress.org is approved, distribute that Release zip

## WordPress.org

**Slug:** `universal-consent-privacy-framework`

1. Submit the plugin for directory review (one-time human process)
2. Store SVN credentials as GitHub secrets: `SVN_USERNAME`, `SVN_PASSWORD`
3. After approval, tag releases: `.github/workflows/deploy-wordpress-org.yml` deploys via [10up/action-wordpress-plugin-deploy](https://github.com/10up/action-wordpress-plugin-deploy)
4. Assets live in `.wordpress-org/` (icons, banners, screenshots)

`.distignore` excludes `tools/`, tests, CI, and internal docs from the directory zip. The Playwright scanner stays on GitHub as companion software.

## Security notes for each release

- Confirm scanner auth still requires keys for non-loopback
- Confirm remote registry default is **off**
- No remote executable code loaders
- Admin/REST still use capabilities + nonces
- Publish SHA256 of the zip in the GitHub Release body

## Pre-push hygiene

- [ ] No client scan reports
- [ ] No `.env` files
- [ ] No personal filesystem paths in docs
- [ ] Plugin URI matches the public GitHub repo
