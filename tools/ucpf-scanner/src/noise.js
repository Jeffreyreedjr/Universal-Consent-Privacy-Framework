/**
 * Apply noise filters so inventories stay clean (lockouts, ephemeral WAF, admin probes).
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const FILTERS_PATH = path.join(__dirname, '..', 'rules', 'noise-filters.json');

/** @type {object|null} */
let filters = null;

export function loadNoiseFilters() {
  filters = JSON.parse(fs.readFileSync(FILTERS_PATH, 'utf8'));
  return filters;
}

function getFilters() {
  if (!filters) loadNoiseFilters();
  return filters;
}

/**
 * Glob-ish: * → .* (case-insensitive full match).
 * @param {string} name
 * @param {string} pattern
 */
export function nameMatchesPattern(name, pattern) {
  const n = String(name || '');
  const p = String(pattern || '');
  if (!n || !p) return false;
  if (p.includes('*')) {
    const re = new RegExp('^' + p.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*') + '$', 'i');
    return re.test(n);
  }
  return n.toLowerCase() === p.toLowerCase();
}

/**
 * @param {string} name
 * @returns {{ omit: boolean, reason: string }}
 */
export function shouldOmitCookie(name) {
  const f = getFilters();
  for (const row of f.cookie_omit || []) {
    if (nameMatchesPattern(name, row.pattern)) {
      return { omit: true, reason: row.reason || 'noise' };
    }
  }
  for (const row of f.cookie_omit_regex || []) {
    try {
      if (new RegExp(row.regex, 'i').test(String(name || ''))) {
        return { omit: true, reason: row.reason || 'noise' };
      }
    } catch {
      /* bad regex */
    }
  }
  return { omit: false, reason: '' };
}

/**
 * @param {string} name
 */
export function shouldIgnoreCookieLeak(name) {
  const f = getFilters();
  if (shouldOmitCookie(name).omit) return true;
  for (const row of f.leak_ignore_cookies || []) {
    if (nameMatchesPattern(name, row.pattern)) return true;
  }
  return false;
}

/**
 * @param {string} urlOrHost
 */
export function shouldIgnoreUrlLeak(urlOrHost) {
  const v = String(urlOrHost || '').toLowerCase();
  if (!v) return false;
  const f = getFilters();
  for (const row of f.leak_ignore_hosts || []) {
    const h = String(row.host || '').toLowerCase();
    if (h && v.includes(h)) return true;
  }
  for (const sub of f.leak_ignore_url_substrings || []) {
    if (sub && v.includes(String(sub).toLowerCase())) return true;
  }
  return false;
}

/**
 * @param {string} key
 */
export function shouldOmitStorageKey(key) {
  const f = getFilters();
  for (const row of f.storage_omit || []) {
    if (String(row.key || '').toLowerCase() === String(key || '').toLowerCase()) {
      return true;
    }
  }
  return false;
}

/**
 * Filter cookie rows; dedupe by name (prefer httpOnly / richer domain).
 * @param {object[]} cookies
 */
export function filterCookieInventory(cookies) {
  const byName = new Map();
  const omitted = [];
  for (const c of cookies || []) {
    if (!c || !c.name) continue;
    const check = shouldOmitCookie(c.name);
    if (check.omit) {
      omitted.push({ name: c.name, reason: check.reason });
      continue;
    }
    const key = String(c.name).toLowerCase();
    const prev = byName.get(key);
    if (!prev) {
      byName.set(key, { ...c });
      continue;
    }
    // Merge contexts / prefer httpOnly and non-empty domain.
    const merged = { ...prev };
    const ctxA = Array.isArray(prev.contexts) ? prev.contexts : [];
    const ctxB = Array.isArray(c.contexts) ? c.contexts : [];
    merged.contexts = [...new Set([...ctxA, ...ctxB])];
    if (!merged.domain && c.domain) merged.domain = c.domain;
    if (c.httpOnly) merged.httpOnly = true;
    if (c.secure) merged.secure = true;
    byName.set(key, merged);
  }
  return {
    cookies: [...byName.values()],
    omitted,
  };
}

/**
 * @param {object[]} leaks
 */
export function filterConsentLeaks(leaks) {
  return (leaks || []).filter((row) => {
    if (!row || typeof row !== 'object') return false;
    const type = row.type || '';
    const name = row.name || '';
    if (type === 'cookie' && shouldIgnoreCookieLeak(name)) return false;
    if (type !== 'cookie' && shouldIgnoreUrlLeak(name)) return false;
    return true;
  });
}

/**
 * @param {object[]} storage
 */
export function filterStorage(storage) {
  return (storage || []).filter((row) => row && row.key && !shouldOmitStorageKey(row.key));
}
