/**
 * SSRF protection: block private/link-local/metadata targets.
 *
 * Agency / sandbox hardening notes:
 * - Prefer non-root container users when self-hosting workers
 * - Do not follow redirects on domain-verification fetches (see index.js)
 * - Rate-limit via auth.js; disposable workers per job when scaling
 * - Never scan arbitrary user-supplied IPs without assertSafePublicUrl
 */

import dns from 'node:dns/promises';
import net from 'node:net';
import { URL } from 'node:url';

const BLOCKED_HOSTNAMES = new Set([
  'localhost',
  'metadata.google.internal',
  'metadata.google',
  'instance-data',
]);

/**
 * @param {string} ip
 * @returns {boolean}
 */
export function isPrivateOrBlockedIp(ip) {
  if (!ip) return true;
  const normalized = ip.toLowerCase().replace(/^::ffff:/, '');

  if (normalized === '::1' || normalized === '0.0.0.0') return true;

  // IPv4
  if (net.isIPv4(normalized)) {
    const parts = normalized.split('.').map(Number);
    const [a, b] = parts;
    if (a === 10) return true;
    if (a === 127) return true;
    if (a === 0) return true;
    if (a === 169 && b === 254) return true; // link-local + AWS metadata
    if (a === 172 && b >= 16 && b <= 31) return true;
    if (a === 192 && b === 168) return true;
    if (a === 100 && b >= 64 && b <= 127) return true; // CGNAT
    return false;
  }

  // IPv6 private / ULA / link-local
  if (net.isIPv6(normalized)) {
    if (normalized.startsWith('fc') || normalized.startsWith('fd')) return true; // ULA
    if (normalized.startsWith('fe80')) return true;
    if (normalized.startsWith('ff')) return true; // multicast
    return false;
  }

  return true;
}

/**
 * Validate URL string before navigation. Resolves DNS and checks IPs.
 * @param {string} rawUrl
 * @param {{ maxRedirects?: number }} [opts]
 * @returns {Promise<{ ok: true, url: string, hostname: string } | { ok: false, error: string }>}
 */
export async function assertSafePublicUrl(rawUrl, opts = {}) {
  let parsed;
  try {
    parsed = new URL(rawUrl);
  } catch {
    return { ok: false, error: 'Invalid URL' };
  }

  if (!['http:', 'https:'].includes(parsed.protocol)) {
    return { ok: false, error: 'Only http/https URLs are allowed' };
  }

  const hostname = parsed.hostname.toLowerCase();
  if (!hostname || BLOCKED_HOSTNAMES.has(hostname)) {
    return { ok: false, error: 'Blocked hostname' };
  }

  if (hostname.endsWith('.local') || hostname.endsWith('.internal') || hostname.endsWith('.localhost')) {
    return { ok: false, error: 'Blocked hostname suffix' };
  }

  // Literal IP in URL
  if (net.isIP(hostname)) {
    if (isPrivateOrBlockedIp(hostname)) {
      return { ok: false, error: 'Private or blocked IP address' };
    }
  } else {
    let records;
    try {
      records = await dns.lookup(hostname, { all: true, verbatim: true });
    } catch {
      return { ok: false, error: 'DNS resolution failed' };
    }
    if (!records.length) {
      return { ok: false, error: 'DNS resolution returned no addresses' };
    }
    for (const rec of records) {
      if (isPrivateOrBlockedIp(rec.address)) {
        return { ok: false, error: `Resolved to blocked address ${rec.address}` };
      }
    }
  }

  // Block cloud metadata hostnames even if somehow public DNS
  if (hostname === '169.254.169.254' || hostname.includes('metadata')) {
    if (hostname.includes('metadata') && !hostname.includes('.')) {
      return { ok: false, error: 'Blocked metadata host' };
    }
  }

  void opts.maxRedirects;
  return { ok: true, url: parsed.toString(), hostname };
}

/**
 * Re-validate after a redirect location.
 * @param {string} location
 */
export async function assertSafeRedirect(location) {
  return assertSafePublicUrl(location);
}
