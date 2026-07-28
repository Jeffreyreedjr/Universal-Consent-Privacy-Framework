# WordPress.org directory assets

Required files for the plugin directory listing and SVN `assets/` folder. Also shown on the GitHub README.

| File | Size | Role |
|------|------|------|
| `icon-128x128.png` | 128×128 | Directory icon |
| `icon-256x256.png` | 256×256 | Directory icon (hi-res) |
| `banner-772x250.png` | 772×250 | Directory header |
| `banner-1544x500.png` | 1544×500 | Directory header (retina) |
| `screenshot-1.png` | — | Banner & Branding admin (`readme.txt` Screenshots #1) |
| `screenshot-2.png` | — | Front-end consent banner (#2) |
| `screenshot-3.png` | — | Cookie Scanner (#3) |

## Deploy

- Kept in git; **excluded from the plugin zip** (`.distignore`).
- After WP.org approval, [`.github/workflows/deploy-wordpress-org.yml`](../.github/workflows/deploy-wordpress-org.yml) pushes these via `ASSETS_DIR=.wordpress-org`.
- Matching copies for the installed plugin live under [`assets/branding/`](../assets/branding/) (menu icon).

## GitHub

README embeds `banner-772x250.png`, `icon-256x256.png`, and the three screenshots. For the repository **Social preview** image (optional): GitHub → Settings → General → Social preview → upload `banner-1544x500.png`.
