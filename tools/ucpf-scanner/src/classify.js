/**
 * Classification against classification.json + plugin-fingerprints.json (+ plugin-paths fallback).
 * Layer 1: host/network/DOM/global service signals.
 * Layer 2: plugin/theme path identity (never inherits to embedded third parties).
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RULES_PATH = path.join(__dirname, '..', 'rules', 'classification.json');
const PLUGIN_PATHS = path.join(__dirname, '..', 'rules', 'plugin-paths.json');
const FINGERPRINTS_PATH = path.join(__dirname, '..', 'rules', 'plugin-fingerprints.json');
const SUSPICION_PATH = path.join(__dirname, '..', 'rules', 'suspicion.json');

/** @type {Array<object>} */
let rules = [];
/** @type {{ plugins: Record<string, object>, themes: Record<string, object> }} */
let pathMap = { plugins: {}, themes: {} };
/** @type {object[]} */
let fingerprints = [];
/** @type {Map<string, object>} */
let fingerprintsBySlug = new Map();
/** @type {{ allowlist: string[], rules: object[], gate_needles: string[] }} */
let suspicion = { allowlist: [], rules: [], gate_needles: [] };

/**
 * @param {object} fp
 */
function fpToResult(fp, ruleLabel) {
  const category = fp.consent_category || fp.category || 'unclassified';
  const treatment = fp.treatment || (category === 'necessary' ? 'necessary' : 'consent');
  return {
    category: toUcpfCategory(category === 'maps' ? 'functional' : category),
    provider: fp.name || fp.slug || '',
    treatment,
    importance: fp.importance || importanceFrom(category, treatment),
    matched: true,
    rule: ruleLabel || `fingerprint:${fp.slug}`,
    note: fp.notes || '',
    service_key: fp.service_key || null,
    slug: fp.slug || '',
    layer: 'fingerprint',
  };
}

export function loadRules() {
  rules = JSON.parse(fs.readFileSync(RULES_PATH, 'utf8'));
  pathMap = JSON.parse(fs.readFileSync(PLUGIN_PATHS, 'utf8'));
  try {
    const raw = JSON.parse(fs.readFileSync(FINGERPRINTS_PATH, 'utf8'));
    fingerprints = Array.isArray(raw.fingerprints) ? raw.fingerprints : [];
  } catch {
    fingerprints = [];
  }
  try {
    const rawSus = JSON.parse(fs.readFileSync(SUSPICION_PATH, 'utf8'));
    suspicion = {
      allowlist: Array.isArray(rawSus.allowlist) ? rawSus.allowlist : [],
      rules: Array.isArray(rawSus.rules) ? rawSus.rules : [],
      gate_needles: Array.isArray(rawSus.gate_needles) ? rawSus.gate_needles : [],
    };
  } catch {
    suspicion = { allowlist: [], rules: [], gate_needles: [] };
  }
  fingerprintsBySlug = new Map();
  for (const fp of fingerprints) {
    if (fp && fp.slug) {
      fingerprintsBySlug.set(String(fp.slug).toLowerCase(), fp);
    }
  }
  // Keep pathMap enriched from fingerprints when plugin-paths lacks a slug.
  for (const fp of fingerprints) {
    if (!fp?.slug) continue;
    const slug = String(fp.slug).toLowerCase();
    if (!pathMap.plugins[slug] && fp.category !== 'maps' && !String(fp.notes || '').includes('service-only')) {
      pathMap.plugins[slug] = {
        provider: fp.name || slug,
        category: fp.consent_category || fp.category || 'unclassified',
        treatment: fp.treatment || 'consent',
        importance: fp.importance || 'unclassified',
        note: fp.notes || '',
        service_key: fp.service_key || undefined,
      };
    }
  }
  return rules;
}

/**
 * Filename / path segment for suspicion (ignore query string).
 * @param {string} value
 */
function suspicionPath(value) {
  let v = String(value || '').toLowerCase();
  try {
    if (/^https?:\/\//i.test(v)) {
      const u = new URL(v);
      v = u.pathname || v;
    }
  } catch {
    /* keep */
  }
  const q = v.indexOf('?');
  if (q >= 0) v = v.slice(0, q);
  return v;
}

/**
 * Generic tracking-like path heuristics (URL only — not script body).
 * @param {string} value
 * @returns {object|null}
 */
export function classifySuspicion(value) {
  const pathOnly = suspicionPath(value);
  if (!pathOnly) return null;
  for (const a of suspicion.allowlist || []) {
    if (a && pathOnly.includes(String(a).toLowerCase())) {
      return null;
    }
  }
  for (const rule of suspicion.rules || []) {
    for (const m of rule.match || []) {
      const needle = String(m).toLowerCase();
      if (!needle || !pathOnly.includes(needle)) continue;
      const category = toUcpfCategory(rule.category || 'marketing');
      const treatment = rule.treatment || 'consent';
      return {
        category,
        provider: rule.provider || 'Suspected tracker',
        treatment,
        importance: importanceFrom(category, treatment, 'non_essential'),
        matched: true,
        rule: `suspicion:${needle}`,
        note: rule.note || '',
        suspicion: rule.suspicion || 'high',
        suggested_category: category,
        status: 'needs_review',
        layer: 'suspicion',
      };
    }
  }
  return null;
}

/**
 * Needles for runtime gates (PHP mirrors this list).
 * @returns {string[]}
 */
export function getSuspicionGateNeedles() {
  return [...(suspicion.gate_needles || [])];
}

/**
 * Map scanner category → UCPF consent category.
 */
export function toUcpfCategory(category) {
  if (category === 'advertising' || category === 'email_marketing') return 'marketing';
  if (category === 'maps' || category === 'transactional_email') {
    return category === 'maps' ? 'functional' : 'necessary';
  }
  if (category === 'unclassified') return 'unclassified';
  return category || 'unclassified';
}

function importanceFrom(category, treatment, explicit) {
  if (explicit) return explicit;
  if (treatment === 'necessary' || treatment === 'label' || category === 'necessary') return 'required';
  if (treatment === 'ignore' || category === 'ignore') return 'ignore';
  if (category === 'unclassified') return 'unclassified';
  return 'non_essential';
}

/**
 * Match fingerprint signals against a URL or free-text signal bag.
 * @param {string} value
 * @param {{ globals?: string[], selectors?: string[], cookies?: string[], storage?: string[] }} [extra]
 */
export function classifyByFingerprintSignals(value, extra = {}) {
  const v = String(value || '').toLowerCase();
  const globals = (extra.globals || []).map((g) => String(g).toLowerCase());
  const selectors = (extra.selectors || []).map((s) => String(s).toLowerCase());
  const cookies = (extra.cookies || []).map((c) => String(c));
  const storage = (extra.storage || []).map((s) => String(s));

  for (const fp of fingerprints) {
    const domains = fp.third_party_domains || [];
    for (const d of domains) {
      if (d && v.includes(String(d).toLowerCase())) {
        return fpToResult(fp, `fp-domain:${d}`);
      }
    }
    const nets = fp.network_patterns || [];
    for (const n of nets) {
      if (n && v.includes(String(n).toLowerCase())) {
        return fpToResult(fp, `fp-network:${n}`);
      }
    }
    const iframes = fp.iframe_patterns || [];
    for (const i of iframes) {
      if (i && v.includes(String(i).toLowerCase())) {
        return fpToResult(fp, `fp-iframe:${i}`);
      }
    }
    const scripts = fp.script_paths || [];
    for (const s of scripts) {
      if (s && v.includes(String(s).toLowerCase())) {
        return fpToResult(fp, `fp-script:${s}`);
      }
    }
    for (const g of fp.known_globals || []) {
      if (g && globals.includes(String(g).toLowerCase())) {
        return fpToResult(fp, `fp-global:${g}`);
      }
    }
    for (const sel of fp.dom_selectors || []) {
      const needle = String(sel).toLowerCase();
      if (
        needle &&
        (selectors.some((s) => s.includes(needle.replace(/^[.#]/, ''))) || v.includes(needle.replace(/^[.#]/, '')))
      ) {
        return fpToResult(fp, `fp-dom:${sel}`);
      }
    }
    for (const c of fp.cookies || []) {
      if (c && cookies.includes(c)) {
        return fpToResult(fp, `fp-cookie:${c}`);
      }
    }
    for (const s of fp.local_storage || []) {
      if (s && storage.includes(s)) {
        return fpToResult(fp, `fp-storage:${s}`);
      }
    }
    for (const s of fp.session_storage || []) {
      if (s && storage.includes(s)) {
        return fpToResult(fp, `fp-session:${s}`);
      }
    }
  }
  return null;
}

/**
 * Classify from /wp-content/plugins/{slug}/ or themes/{slug}/ in a URL.
 * @param {string} value
 */
export function classifyPluginPath(value) {
  const v = String(value || '');
  const pluginMatch = v.match(/\/wp-content\/plugins\/([^/]+)\//i);
  if (pluginMatch) {
    const slug = pluginMatch[1].toLowerCase();
    const fp = fingerprintsBySlug.get(slug);
    if (fp) {
      const r = fpToResult(fp, `plugin:${slug}`);
      r.layer = 'plugin';
      return r;
    }
    const meta = pathMap.plugins[slug];
    if (meta) {
      return {
        category: toUcpfCategory(meta.category || 'unclassified'),
        provider: meta.provider || slug,
        treatment: meta.treatment || 'consent',
        importance: meta.importance || importanceFrom(meta.category, meta.treatment),
        matched: true,
        rule: `plugin:${slug}`,
        note: meta.note || '',
        service_key: meta.service_key || null,
        slug,
        layer: 'plugin',
      };
    }
    return {
      category: 'unclassified',
      provider: slug.replace(/-/g, ' '),
      treatment: 'consent',
      importance: 'unclassified',
      matched: true,
      rule: `plugin:${slug}`,
      note: 'Plugin asset observed; category needs review.',
      slug,
      layer: 'plugin',
    };
  }

  const themeMatch = v.match(/\/wp-content\/themes\/([^/]+)\//i);
  if (themeMatch) {
    const slug = themeMatch[1].toLowerCase();
    const meta = pathMap.themes[slug];
    if (meta) {
      return {
        category: meta.category || 'necessary',
        provider: meta.provider || slug,
        treatment: meta.treatment || 'necessary',
        importance: meta.importance || 'required',
        matched: true,
        rule: `theme:${slug}`,
        note: meta.note || '',
        layer: 'theme',
      };
    }
    return {
      category: 'necessary',
      provider: slug.replace(/-/g, ' ') + ' theme',
      treatment: 'necessary',
      importance: 'required',
      matched: true,
      rule: `theme:${slug}`,
      note: 'Theme asset — treated as necessary for rendering.',
      layer: 'theme',
    };
  }

  if (/\/wp-includes\//i.test(v)) {
    return {
      category: 'necessary',
      provider: 'WordPress Core',
      treatment: 'necessary',
      importance: 'required',
      matched: true,
      rule: 'wp-includes',
      note: '',
      layer: 'core',
    };
  }

  return null;
}

/**
 * @param {string} value
 * @param {'cookie'|'script_host'|'request_host'|'iframe_host'|'storage_key'|'beacon'} type
 * @param {{ globals?: string[], selectors?: string[], cookies?: string[], storage?: string[] }} [extra]
 */
export function classifyValue(value, type = 'cookie', extra = {}) {
  const v = String(value || '');
  if (!v) {
    return {
      category: 'unclassified',
      provider: '',
      treatment: 'consent',
      importance: 'unclassified',
      matched: false,
    };
  }

  // 1) Host / URL classification rules (CDN third parties) — before plugin path so
  //    Elementor-hosted / Custom HTML third parties classify as the service.
  if (type !== 'cookie' && type !== 'storage_key') {
    const byFp = classifyByFingerprintSignals(v, extra);
    // Prefer host rules for known CDNs when both could match; try rules first.
  }

  for (const rule of rules) {
    const ruleType = rule.type || 'cookie';
    if (type === 'cookie' && ruleType !== 'cookie') continue;
    if (type === 'storage_key' && ruleType !== 'cookie' && ruleType !== 'storage_key') continue;
    if (type !== 'cookie' && type !== 'storage_key' && ruleType === 'cookie') continue;
    if (
      type !== 'cookie' &&
      type !== 'storage_key' &&
      ruleType !== 'script_host' &&
      ruleType !== 'request_host' &&
      ruleType !== 'iframe_host' &&
      ruleType !== 'beacon' &&
      ruleType !== type
    ) {
      // Allow script_host rules to match request/iframe/beacon too.
      if (!(ruleType === 'script_host' && type !== 'cookie' && type !== 'storage_key')) {
        continue;
      }
    }

    for (const m of rule.match || []) {
      const needle = String(m);
      let hit = false;
      if (rule.prefix) {
        hit = v.startsWith(needle) || v.toLowerCase().startsWith(needle.toLowerCase());
      } else if (type === 'cookie') {
        hit = v === needle || (needle.endsWith('_') && v.startsWith(needle));
      } else {
        hit = v.toLowerCase().includes(needle.toLowerCase());
      }
      if (hit) {
        const category = toUcpfCategory(rule.category || 'unclassified');
        const treatment = rule.treatment || (category === 'necessary' ? 'necessary' : 'consent');
        return {
          category,
          provider: rule.provider || '',
          treatment,
          importance: importanceFrom(category, treatment, rule.importance),
          matched: true,
          rule: needle,
          note: rule.note || '',
          layer: 'host',
        };
      }
    }
  }

  // 2) Fingerprint domain/network/global/DOM signals.
  if (type !== 'cookie' || (extra.globals && extra.globals.length) || (extra.selectors && extra.selectors.length)) {
    const byFp = classifyByFingerprintSignals(v, extra);
    if (byFp) return byFp;
  }

  // 3) Plugin / theme path identity.
  if (type !== 'cookie' && type !== 'storage_key') {
    const fromPath = classifyPluginPath(v);
    if (fromPath) {
      // Unknown / unclassified plugin assets: try suspicion before accepting weak plugin label.
      if (fromPath.category === 'unclassified' || fromPath.importance === 'unclassified') {
        const sus = classifySuspicion(v);
        if (sus) {
          return {
            ...sus,
            provider: fromPath.provider ? `${fromPath.provider} (suspected tracking)` : sus.provider,
            slug: fromPath.slug || '',
            note: [fromPath.note, sus.note].filter(Boolean).join(' '),
          };
        }
      }
      return fromPath;
    }
  }

  // 4) Generic tracking path/filename suspicion (site-agnostic long tail).
  if (type !== 'cookie' && type !== 'storage_key') {
    const sus = classifySuspicion(v);
    if (sus) return sus;
  }

  return {
    category: 'unclassified',
    provider: '',
    treatment: 'consent',
    importance: 'unclassified',
    matched: false,
  };
}

/**
 * Multi-match stack for acceptance tests / rich inventory.
 * @param {object} signals
 */
export function classifySignals(signals = {}) {
  const hits = [];
  const urls = [].concat(signals.urls || [], signals.scripts || [], signals.iframes || [], signals.beacons || []);
  const seen = new Set();
  for (const url of urls) {
    const r = classifyValue(url, 'script_host', {
      globals: signals.globals || [],
      selectors: signals.selectors || [],
      cookies: signals.cookies || [],
      storage: signals.storage || [],
    });
    if (r.matched) {
      const key = `${r.provider}|${r.category}|${r.rule}`;
      if (!seen.has(key)) {
        seen.add(key);
        hits.push(r);
      }
    }
  }
  if (signals.globals || signals.selectors) {
    const r = classifyByFingerprintSignals('', {
      globals: signals.globals || [],
      selectors: signals.selectors || [],
      cookies: signals.cookies || [],
      storage: signals.storage || [],
    });
    if (r && r.matched) {
      const key = `${r.provider}|${r.category}|${r.rule}`;
      if (!seen.has(key)) hits.push(r);
    }
  }
  return hits;
}

export function getFingerprints() {
  return fingerprints;
}

loadRules();
