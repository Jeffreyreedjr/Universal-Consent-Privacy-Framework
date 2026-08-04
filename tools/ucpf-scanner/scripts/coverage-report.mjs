/**
 * Fleet coverage report: inventory vs fingerprints vs plugin-map vs catalog.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const repo = path.join(root, '..', '..');
const fps = JSON.parse(fs.readFileSync(path.join(root, 'rules', 'plugin-fingerprints.json'), 'utf8'));
const inv = JSON.parse(fs.readFileSync(path.join(root, 'data', 'fleet-inventory-normalized.json'), 'utf8'));
const pluginMap = JSON.parse(
  fs.readFileSync(path.join(repo, 'assets', 'vendor-catalog', 'plugin-map.json'), 'utf8')
);

const inventory = Array.isArray(inv) ? inv : inv.items || inv.plugins || [];
const fpsBySlug = new Map((fps.fingerprints || []).map((f) => [String(f.slug).toLowerCase(), f]));

const catalogDir = path.join(repo, 'assets', 'vendor-catalog');
const catalogKeys = new Set();
for (const file of fs.readdirSync(catalogDir)) {
  if (!file.endsWith('.json') || file === 'plugin-map.json' || file === 'README.md') continue;
  try {
    const data = JSON.parse(fs.readFileSync(path.join(catalogDir, file), 'utf8'));
    for (const s of data.services || []) {
      if (s.key) catalogKeys.add(s.key);
    }
  } catch {
    /* skip */
  }
}

let exact = 0;
let family = 0;
let adminIgnore = 0;
let needsResearch = 0;
const unmapped = [];
const inferred = [];
const missingServiceKey = [];
const mapMissingFp = [];
const fpMissingCatalog = [];
const slugConflicts = [];
const treatmentConflicts = [];

const seenSlugs = new Map();

for (const fp of fps.fingerprints || []) {
  if (!fp.service_key && fp.treatment !== 'ignore' && fp.importance !== 'ignore') {
    if (fp.treatment === 'consent' && fp.privacy_impact === 'high') {
      missingServiceKey.push(fp.slug);
    }
  }
  if (fp.service_key && !catalogKeys.has(fp.service_key)) {
    // Label/necessary transactional often in catalog — track missing for consent-gated
    if (fp.treatment === 'consent' && fp.importance === 'non_essential') {
      fpMissingCatalog.push(`${fp.slug} → ${fp.service_key}`);
    }
  }
  if (String(fp.notes || '').includes('inferred_slug')) {
    inferred.push(fp.slug);
  }
  const prev = seenSlugs.get(fp.slug);
  if (prev) {
    slugConflicts.push(fp.slug);
    if (prev.treatment !== fp.treatment || prev.consent_category !== fp.consent_category) {
      treatmentConflicts.push(fp.slug);
    }
  } else {
    seenSlugs.set(fp.slug, fp);
  }
}

for (const row of inventory) {
  const name = row.name || row.plugin || '';
  const slug = (row.fingerprint_slug || row.slug_guess || '').toLowerCase();
  const fp = slug ? fpsBySlug.get(slug) : null;
  if (!fp) {
    unmapped.push(name || slug || '(blank)');
    continue;
  }
  exact++;
  if (fp.treatment === 'ignore' || fp.importance === 'ignore') adminIgnore++;
  if (String(fp.notes || '').includes('family:')) family++;
  if (String(fp.notes || '').includes('needs_research')) needsResearch++;
}

// plugin-map entries without fingerprint
const mapValues = { ...(pluginMap.map || {}), ...(pluginMap.slug_hints || {}) };
for (const [k, v] of Object.entries(pluginMap.map || {})) {
  const slug = k.includes('/') ? k.split('/')[0] : k;
  if (!fpsBySlug.has(slug.toLowerCase()) && !fpsBySlug.has(String(v).toLowerCase())) {
    // try service key match
    const byService = [...fpsBySlug.values()].some((f) => f.service_key === v);
    if (!byService) mapMissingFp.push(`${k} → ${v}`);
  }
}

const report = `# Fleet coverage report

Generated: ${new Date().toISOString()}

## Summary

| Metric | Count |
|--------|------:|
| Fingerprint records | ${(fps.fingerprints || []).length} |
| Normalized fleet inventory rows | ${inventory.length} |
| Exact fingerprint link | ${exact} |
| Family-tagged fingerprints (notes) | ${family} |
| Admin/ignore among matched inventory | ${adminIgnore} |
| Needs research (notes) | ${needsResearch} |
| Completely unmapped inventory | ${unmapped.length} |
| Inferred slugs | ${inferred.length} |
| Consent fingerprints missing service_key (high impact) | ${missingServiceKey.length} |
| plugin-map without fingerprint | ${mapMissingFp.length} |
| Consent fp service_key missing catalog | ${fpMissingCatalog.length} |
| Duplicate slug keys | ${slugConflicts.length} |
| Treatment conflicts on duplicate slug | ${treatmentConflicts.length} |
| Vendor catalog service keys | ${catalogKeys.size} |

## Unmapped inventory

${unmapped.length ? unmapped.map((u) => `- ${u}`).join('\n') : '_None_'}

## Inferred slugs (sample / full)

${inferred.map((s) => `- ${s}`).join('\n') || '_None_'}

## High-impact consent fingerprints missing service_key

${missingServiceKey.map((s) => `- ${s}`).join('\n') || '_None_'}

## plugin-map without matching fingerprint

${mapMissingFp.slice(0, 80).map((s) => `- ${s}`).join('\n') || '_None_'}
${mapMissingFp.length > 80 ? `\n… and ${mapMissingFp.length - 80} more` : ''}

## Consent service_key missing vendor-catalog entry

${fpMissingCatalog.slice(0, 80).map((s) => `- ${s}`).join('\n') || '_None_'}
${fpMissingCatalog.length > 80 ? `\n… and ${fpMissingCatalog.length - 80} more` : ''}

## Notes

- Plugin identity and service behavior are separate layers; Elementor "necessary" does not make YouTube necessary.
- Arrays on fingerprints may be empty in this pass; slug/treatment/consent_category/notes are required coverage.
`;

const outDir = path.join(root, 'reports');
fs.mkdirSync(outDir, { recursive: true });
fs.writeFileSync(path.join(outDir, 'fleet-coverage.md'), report);
fs.writeFileSync(
  path.join(outDir, 'fleet-coverage.json'),
  JSON.stringify(
    {
      fingerprints: (fps.fingerprints || []).length,
      inventory: inventory.length,
      exact,
      unmapped,
      inferred,
      missingServiceKey,
      mapMissingFp,
      fpMissingCatalog,
      slugConflicts,
      treatmentConflicts,
    },
    null,
    2
  )
);
console.log(report.split('\n').slice(0, 30).join('\n'));
console.log('Wrote', path.join(outDir, 'fleet-coverage.md'));
