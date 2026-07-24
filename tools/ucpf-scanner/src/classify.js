/**
 * Classification against rules/classification.json + plugin-paths.json.
 * Every match includes category, treatment, and importance (required | non_essential | unclassified).
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RULES_PATH = path.join(__dirname, '..', 'rules', 'classification.json');
const PLUGIN_PATHS = path.join(__dirname, '..', 'rules', 'plugin-paths.json');

/** @type {Array<object>} */
let rules = [];
/** @type {{ plugins: Record<string, object>, themes: Record<string, object> }} */
let pathMap = { plugins: {}, themes: {} };

export function loadRules() {
  rules = JSON.parse(fs.readFileSync(RULES_PATH, 'utf8'));
  pathMap = JSON.parse(fs.readFileSync(PLUGIN_PATHS, 'utf8'));
  return rules;
}

/**
 * Map scanner category → UCPF consent category.
 * advertising → marketing
 */
export function toUcpfCategory(category) {
  if (category === 'advertising') return 'marketing';
  if (category === 'unclassified') return 'unclassified';
  return category || 'unclassified';
}

function importanceFrom(category, treatment, explicit) {
  if (explicit) return explicit;
  if (treatment === 'necessary' || category === 'necessary') return 'required';
  if (treatment === 'ignore') return 'ignore';
  if (category === 'unclassified') return 'unclassified';
  return 'non_essential';
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
    const meta = pathMap.plugins[slug];
    if (meta) {
      return {
        category: meta.category || 'unclassified',
        provider: meta.provider || slug,
        treatment: meta.treatment || 'consent',
        importance: meta.importance || importanceFrom(meta.category, meta.treatment),
        matched: true,
        rule: `plugin:${slug}`,
        note: meta.note || '',
      };
    }
    // Unknown plugin slug — still label provider from folder name, leave unclassified for review.
    return {
      category: 'unclassified',
      provider: slug.replace(/-/g, ' '),
      treatment: 'consent',
      importance: 'unclassified',
      matched: true,
      rule: `plugin:${slug}`,
      note: 'Plugin asset observed; category needs review.',
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
    };
  }

  return null;
}

/**
 * @param {string} value
 * @param {'cookie'|'script_host'|'request_host'|'iframe_host'|'storage_key'} type
 */
export function classifyValue(value, type = 'cookie') {
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

  // URL/path-based plugin detection first for scripts/requests/iframes.
  if (type !== 'cookie' && type !== 'storage_key') {
    const fromPath = classifyPluginPath(v);
    if (fromPath) return fromPath;
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
      ruleType !== type
    ) {
      continue;
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
        const category = rule.category || 'unclassified';
        const treatment = rule.treatment || (category === 'necessary' ? 'necessary' : 'consent');
        return {
          category,
          provider: rule.provider || '',
          treatment,
          importance: importanceFrom(category, treatment, rule.importance),
          matched: true,
          rule: needle,
          note: rule.note || '',
        };
      }
    }
  }

  // First-party same-origin path without plugin folder — still unclassified for cookies;
  // for hosts that are clearly the site itself, mark necessary CDN-less first party lightly.
  return {
    category: 'unclassified',
    provider: '',
    treatment: 'consent',
    importance: 'unclassified',
    matched: false,
  };
}

loadRules();
