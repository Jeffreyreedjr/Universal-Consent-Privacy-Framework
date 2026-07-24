/**
 * Same-origin path discovery (sitemap + homepage links).
 * Used when CLI/local scan is given only a site URL.
 */

import { URL } from 'node:url';
import { assertSafePublicUrl } from './ssrf.js';

const PRIORITY_RE = [
  { re: /(checkout|cart|payment)/i, score: 0 },
  { re: /(contact|form|enquiry|inquiry|support)/i, score: 1 },
  { re: /(shop|product|store)/i, score: 2 },
  { re: /(about|privacy|cookie|terms)/i, score: 3 },
];

/**
 * @param {string} siteUrl
 * @param {{ max?: number }} [opts]
 * @returns {Promise<string[]>} pathname list starting with /
 */
export async function discoverSitePaths(siteUrl, opts = {}) {
  const max = Math.max(1, Math.min(500, Number(opts.max) || 100));
  const check = await assertSafePublicUrl(siteUrl);
  if (!check.ok) {
    throw new Error(check.error || 'Unsafe site URL');
  }

  const base = new URL(check.url);
  const origin = base.origin;
  const host = base.hostname.toLowerCase();
  /** @type {Map<string, number>} */
  const scored = new Map();

  const add = (raw, score = 6) => {
    try {
      const u = new URL(raw, origin);
      if (u.hostname.toLowerCase() !== host) return;
      let p = u.pathname || '/';
      if (!p.startsWith('/')) p = `/${p}`;
      // Drop assets / feeds
      if (/\.(css|js|map|png|jpe?g|gif|webp|svg|ico|woff2?|ttf|eot|pdf|xml|json)$/i.test(p)) return;
      if (/\/(wp-json|feed|comments\/feed)/i.test(p)) return;
      const prev = scored.get(p);
      if (prev === undefined || score < prev) scored.set(p, score);
    } catch {
      /* ignore */
    }
  };

  add('/', 0);

  const sitemapCandidates = [
    `${origin}/sitemap.xml`,
    `${origin}/sitemap_index.xml`,
    `${origin}/wp-sitemap.xml`,
  ];

  for (const sm of sitemapCandidates) {
    const locs = await fetchSitemapLocs(sm, host, 0);
    if (locs.length) {
      for (const loc of locs) {
        add(loc, pathScore(loc));
      }
      break;
    }
  }

  try {
    const homeRes = await fetch(origin + '/', {
      redirect: 'follow',
      headers: {
        'user-agent':
          process.env.UCPF_SCANNER_UA ||
          'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
      },
      signal: AbortSignal.timeout(15000),
    });
    if (homeRes.ok) {
      const html = await homeRes.text();
      const hrefRe = /<a\s[^>]*href\s*=\s*["']([^"']+)["']/gi;
      let m;
      while ((m = hrefRe.exec(html)) !== null) {
        const href = m[1].trim();
        if (!href || href.startsWith('#') || /^(mailto:|tel:|javascript:)/i.test(href)) continue;
        add(href, pathScore(href));
      }
    }
  } catch {
    /* homepage fetch optional */
  }

  const sorted = [...scored.entries()]
    .sort((a, b) => a[1] - b[1] || a[0].localeCompare(b[0]))
    .map(([p]) => p);

  return sorted.slice(0, max);
}

/**
 * @param {string} url
 * @param {string} host
 * @param {number} depth
 * @returns {Promise<string[]>}
 */
async function fetchSitemapLocs(url, host, depth) {
  if (depth > 1) return [];
  try {
    const safe = await assertSafePublicUrl(url);
    if (!safe.ok) return [];
    const res = await fetch(safe.url, {
      redirect: 'follow',
      headers: {
        'user-agent':
          process.env.UCPF_SCANNER_UA ||
          'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
      },
      signal: AbortSignal.timeout(12000),
    });
    if (!res.ok) return [];
    const body = await res.text();
    if (!/<loc/i.test(body)) return [];

    const locs = [];
    const re = /<loc>\s*([^<\s]+)\s*<\/loc>/gi;
    let m;
    while ((m = re.exec(body)) !== null) {
      const loc = m[1].trim();
      try {
        const u = new URL(loc);
        if (u.hostname.toLowerCase() !== host) continue;
        if (depth < 1 && /sitemap/i.test(loc) && /\.xml/i.test(loc)) {
          const nested = await fetchSitemapLocs(loc, host, depth + 1);
          locs.push(...nested);
          continue;
        }
        locs.push(loc);
      } catch {
        /* ignore */
      }
    }
    return [...new Set(locs)];
  } catch {
    return [];
  }
}

/**
 * @param {string} pathOrUrl
 */
function pathScore(pathOrUrl) {
  const s = String(pathOrUrl);
  for (const row of PRIORITY_RE) {
    if (row.re.test(s)) return row.score;
  }
  return 6;
}
