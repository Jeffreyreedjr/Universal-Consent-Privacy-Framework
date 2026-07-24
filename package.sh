#!/usr/bin/env bash
# Builds an installable WordPress plugin zip.
# Output: dist/universal-consent-privacy-framework.zip

set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_SLUG="universal-consent-privacy-framework"
DIST="$ROOT/dist"
STAGE="$DIST/$PLUGIN_SLUG"
ZIP="$DIST/$PLUGIN_SLUG.zip"

rm -rf "$DIST"
mkdir -p "$STAGE"

rsync -a \
  --exclude '.git' \
  --exclude '.cursor' \
  --exclude 'dist' \
  --exclude 'tests' \
  --exclude 'node_modules' \
  --exclude 'vendor' \
  --exclude '.wp-env' \
  --exclude '.wp-env.json' \
  --exclude 'AGENTS.md' \
  --exclude 'package.ps1' \
  --exclude 'package.sh' \
  --exclude '.gitignore' \
  --exclude '.gitattributes' \
  "$ROOT/" "$STAGE/"

(
  cd "$DIST"
  if command -v zip >/dev/null 2>&1; then
    zip -r "$PLUGIN_SLUG.zip" "$PLUGIN_SLUG" >/dev/null
  else
    powershell.exe -NoProfile -Command "Compress-Archive -Path '$PLUGIN_SLUG' -DestinationPath '$PLUGIN_SLUG.zip' -Force"
  fi
)

echo "Done: $ZIP"
echo "Install via Plugins → Add New → Upload Plugin"
