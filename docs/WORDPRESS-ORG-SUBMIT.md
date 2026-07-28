# WordPress.org submission

Slug: `universal-consent-privacy-framework`

## Before you click Submit

1. **Create** a WordPress.org account whose username matches `Contributors:` in [`readme.txt`](../readme.txt) (currently `universalconsent`). As of prep, `https://profiles.wordpress.org/universalconsent/` returned **404** — register that username (or change `Contributors:` to your existing WP.org username before packaging).
2. Upload the plugin zip to a local WordPress site and run **Tools → Plugin Check** (install [Plugin Check (PCP)](https://wordpress.org/plugins/plugin-check/)). Target **ERROR = 0** on the packaged zip from `.\package.ps1`. Static packaging gates (name match, no GSAP, Credits, Screenshots, assets on disk) already pass.
3. Confirm `.wordpress-org/` has icons, banners, and screenshots (deployed to SVN assets after approval).

## Submit

1. Log in as that WordPress.org user.
2. Open [Add Your Plugin](https://wordpress.org/plugins/developers/add/) (requires login).
3. Upload `dist/universal-consent-privacy-framework.zip`.
4. Wait for the human review email (often 1–10 days). Reply promptly to any review questions.

## After approval

1. Add GitHub secrets `SVN_USERNAME` and `SVN_PASSWORD` for [`.github/workflows/deploy-wordpress-org.yml`](../.github/workflows/deploy-wordpress-org.yml).
2. Tag releases so the deploy workflow pushes trunk + assets from `.wordpress-org/`.
3. Watch the plugin support forum for the slug (directory users expect replies there).

## Packaging hygiene (already enforced)

- No GreenSock / GSAP in the zip (CSS motion only)
- No `phpcs.xml.dist` / `tools/` / `.wordpress-org` inside the plugin zip
- `readme.txt` title matches `Plugin Name` header (including `(Alpha)` while pre-1.0)
- Open Cookie Database attribution under `== Credits ==`
