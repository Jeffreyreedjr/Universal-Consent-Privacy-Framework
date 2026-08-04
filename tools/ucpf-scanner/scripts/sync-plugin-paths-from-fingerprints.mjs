/**
 * Sync plugin-paths.json plugins{} from plugin-fingerprints.json (compat layer).
 * Themes left untouched. Service-only / maps-as-service rows skipped.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const fpsPath = path.join(root, 'rules', 'plugin-fingerprints.json');
const pathsPath = path.join(root, 'rules', 'plugin-paths.json');

const fps = JSON.parse(fs.readFileSync(fpsPath, 'utf8'));
const pathMap = JSON.parse(fs.readFileSync(pathsPath, 'utf8'));
if (!pathMap.plugins) pathMap.plugins = {};
if (!pathMap.themes) pathMap.themes = {};

let added = 0;
let updated = 0;

for (const fp of fps.fingerprints || []) {
  if (!fp?.slug) continue;
  const notes = String(fp.notes || '');
  if (notes.includes('service-only') || notes.includes('service_only')) continue;
  // Skip pure CDN service keys that are not WP plugin folders.
  if (
    [
      'mapbox',
      'google_maps',
      'openstreetmap',
      'leaflet',
      'appnexus',
      'microsoft_advertising',
      'google_docs',
      'wistia',
      'spotify',
      'soundcloud',
      'bing_maps',
      'here_maps',
      'arcgis',
      'tomtom',
      'youtube',
      'vimeo',
    ].includes(fp.slug) &&
    !notes.includes('wordpress_plugin')
  ) {
    // Still allow if name looks like a WP plugin slug with hyphens and not service_key===slug only
    if (fp.service_key === fp.slug && !(fp.slug.includes('-') && fp.slug.length > 12)) {
      continue;
    }
  }

  const category = fp.consent_category || fp.category || 'unclassified';
  const entry = {
    provider: fp.name || fp.slug,
    category: category === 'maps' ? 'functional' : category === 'email_marketing' ? 'marketing' : category,
    treatment: fp.treatment === 'label' ? 'necessary' : fp.treatment || 'consent',
    importance: fp.importance || 'unclassified',
  };
  if (fp.notes) entry.note = fp.notes;
  if (fp.service_key) entry.service_key = fp.service_key;

  if (!pathMap.plugins[fp.slug]) {
    pathMap.plugins[fp.slug] = entry;
    added++;
  } else {
    // Align captcha / map treatments when fingerprint is authoritative.
    const prev = pathMap.plugins[fp.slug];
    if (
      fp.service_key === 'cloudflare_turnstile' ||
      fp.service_key === 'recaptcha' ||
      fp.service_key === 'hcaptcha' ||
      fp.consent_category === 'security' ||
      fp.consent_category === 'functional'
    ) {
      pathMap.plugins[fp.slug] = { ...prev, ...entry };
      updated++;
    }
  }
}

fs.writeFileSync(pathsPath, JSON.stringify(pathMap, null, 2) + '\n');
console.log(JSON.stringify({ added, updated, totalPlugins: Object.keys(pathMap.plugins).length }));
