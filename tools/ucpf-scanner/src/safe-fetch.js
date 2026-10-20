/**
 * Fetch with manual redirect following and SSRF re-validation on every hop.
 */

import { assertSafePublicUrl, assertSafeRedirect } from './ssrf.js';
import { config } from './config.js';

const DEFAULT_UA =
  process.env.UCPF_SCANNER_UA ||
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

/**
 * @param {string} rawUrl
 * @param {{ headers?: Record<string, string>, timeoutMs?: number, maxRedirects?: number }} [opts]
 * @returns {Promise<{ ok: true, response: Response, finalUrl: string } | { ok: false, error: string }>}
 */
export async function safeFetch(rawUrl, opts = {}) {
  const maxRedirects = opts.maxRedirects ?? config.maxRedirects ?? 5;
  const timeoutMs = opts.timeoutMs ?? 15000;
  let current = rawUrl;

  for (let hop = 0; hop <= maxRedirects; hop += 1) {
    const check = hop === 0 ? await assertSafePublicUrl(current) : await assertSafeRedirect(current);
    if (!check.ok) {
      return { ok: false, error: check.error || 'Blocked URL' };
    }

    let response;
    try {
      response = await fetch(check.url, {
        redirect: 'manual',
        headers: {
          'user-agent': DEFAULT_UA,
          ...(opts.headers || {}),
        },
        signal: AbortSignal.timeout(timeoutMs),
      });
    } catch (err) {
      return { ok: false, error: err?.message || 'Fetch failed' };
    }

    if (response.status >= 300 && response.status < 400) {
      const location = response.headers.get('location');
      if (!location) {
        return { ok: false, error: 'Redirect without Location' };
      }
      try {
        current = new URL(location, check.url).toString();
      } catch {
        return { ok: false, error: 'Invalid redirect Location' };
      }
      continue;
    }

    return { ok: true, response, finalUrl: check.url };
  }

  return { ok: false, error: 'Too many redirects' };
}
