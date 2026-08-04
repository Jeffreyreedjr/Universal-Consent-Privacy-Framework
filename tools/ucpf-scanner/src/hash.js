import { createHash } from 'node:crypto';

/**
 * Hash a cookie/storage value for private reports. Never put raw values in shared data.
 * @param {string} value
 * @returns {string}
 */
export function hashValue(value) {
  const s = String(value ?? '');
  if (!s) return '';
  return createHash('sha256').update(s, 'utf8').digest('hex').slice(0, 16);
}

/**
 * Normalize a URL/host for set comparison (drop query/hash, lowercase host).
 * @param {string} raw
 * @returns {string}
 */
export function normalizeRequestKey(raw) {
  try {
    const u = new URL(raw, 'https://example.invalid');
    const host = u.hostname.toLowerCase().replace(/^www\./, '');
    const path = (u.pathname || '/').replace(/\/+$/, '') || '/';
    return `${host}${path}`;
  } catch {
    return String(raw || '')
      .split('?')[0]
      .toLowerCase();
  }
}
