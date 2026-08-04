/**
 * Capture helpers — network with initiators, storage surface, CDP cookie enrich.
 */

import { hashValue, normalizeRequestKey } from './hash.js';

/**
 * Attach rich network listeners (requests + optional CDP initiator).
 * @param {import('playwright').BrowserContext} context
 * @param {import('playwright').Page} page
 * @param {{ requests: Map<string, object>, scripts: Set<string>, beacons: Set<string>, pixels: Set<string>, websockets: Set<string>, fonts: Set<string>, media: Set<string> }} buckets
 */
export async function attachNetworkCapture(context, page, buckets) {
  context.on('request', (req) => {
    try {
      const url = req.url();
      if (!url || url.startsWith('data:') || url.startsWith('blob:')) return;
      const u = new URL(url);
      const key = normalizeRequestKey(url);
      const rt = req.resourceType();
      const method = req.method();
      const initiator = req.headers()['referer'] || '';
      const prev = buckets.requests.get(key) || {
        key,
        host: u.hostname,
        path: u.pathname,
        types: [],
        methods: [],
        count: 0,
      };
      prev.count += 1;
      if (!prev.types.includes(rt)) prev.types.push(rt);
      if (!prev.methods.includes(method)) prev.methods.push(method);
      if (initiator && !prev.referer) prev.referer = initiator.split('?')[0];
      buckets.requests.set(key, prev);

      if (rt === 'script') buckets.scripts.add(url.split('?')[0]);
      if (rt === 'image' && (url.includes('pixel') || url.includes('/tr') || url.includes('collect'))) {
        buckets.pixels.add(url.split('?')[0]);
      }
      if (url.includes('sendBeacon') || /\/(collect|g\/collect|beacon)/i.test(url)) {
        buckets.beacons.add(url.split('?')[0]);
      }
      if (rt === 'font' || /\.(woff2?|ttf|otf)(\?|$)/i.test(url)) buckets.fonts.add(url.split('?')[0]);
      if (rt === 'media' || /\.(mp4|webm|m3u8)(\?|$)/i.test(url)) buckets.media.add(url.split('?')[0]);
      if (rt === 'websocket') buckets.websockets.add(url.split('?')[0]);
    } catch {
      /* ignore */
    }
  });

  // CDP initiator / stack when available
  try {
    const cdp = await context.newCDPSession(page);
    await cdp.send('Network.enable');
    cdp.on('Network.requestWillBeSent', (params) => {
      try {
        const url = params.request?.url;
        if (!url) return;
        const key = normalizeRequestKey(url);
        const row = buckets.requests.get(key);
        if (!row) return;
        const init = params.initiator || {};
        if (init.type) row.initiator_type = init.type;
        if (init.url) row.initiator_url = String(init.url).split('?')[0];
        if (Array.isArray(init.stack?.callFrames) && init.stack.callFrames[0]) {
          const f = init.stack.callFrames[0];
          row.initiator_frame = `${f.url || ''}:${f.lineNumber || 0}`;
        }
        buckets.requests.set(key, row);
      } catch {
        /* ignore */
      }
    });
    return cdp;
  } catch {
    return null;
  }
}

/**
 * Enrich cookie map via CDP Network.getAllCookies (partition fields when present).
 * Values are hashed only.
 * @param {import('playwright').CDPSession|null} cdp
 * @param {Map<string, object>} cookieEvents
 */
export async function enrichCookiesFromCdp(cdp, cookieEvents) {
  if (!cdp) return;
  try {
    const { cookies } = await cdp.send('Network.getAllCookies');
    for (const c of cookies || []) {
      const key = `${c.name}|${c.domain || ''}|${c.path || '/'}`;
      const prev = cookieEvents.get(key) || {
        name: c.name,
        domain: c.domain || '',
        path: c.path || '/',
        expires: c.expires,
        httpOnly: !!c.httpOnly,
        secure: !!c.secure,
        sameSite: c.sameSite || '',
        sources: [],
      };
      prev.httpOnly = prev.httpOnly || !!c.httpOnly;
      prev.secure = prev.secure || !!c.secure;
      if (c.sameSite) prev.sameSite = c.sameSite;
      if (c.expires != null) prev.expires = c.expires;
      if (c.partitionKey) {
        prev.partitionKey =
          typeof c.partitionKey === 'string' ? c.partitionKey : JSON.stringify(c.partitionKey);
        prev.partitioned = true;
      }
      if (c.priority) prev.priority = c.priority;
      if (c.value != null && c.value !== '') {
        prev.value_hash = hashValue(c.value);
      }
      if (!prev.sources.includes('cdp')) prev.sources.push('cdp');
      cookieEvents.set(key, prev);
    }
  } catch {
    /* CDP optional */
  }
}

/**
 * Broader storage surface (names/keys only).
 * @param {import('playwright').Page} page
 */
export async function collectStorageSurface(page) {
  try {
    return await page.evaluate(async () => {
      const localStorage = Object.keys(window.localStorage || {});
      const sessionStorage = Object.keys(window.sessionStorage || {});
      let indexedDBNames = [];
      try {
        if (window.indexedDB && typeof indexedDB.databases === 'function') {
          const dbs = await indexedDB.databases();
          indexedDBNames = (dbs || []).map((d) => d.name).filter(Boolean);
        }
      } catch {
        indexedDBNames = [];
      }
      let cacheStorage = [];
      try {
        if (window.caches?.keys) cacheStorage = await caches.keys();
      } catch {
        cacheStorage = [];
      }
      let serviceWorkers = [];
      try {
        if (navigator.serviceWorker?.getRegistrations) {
          const regs = await navigator.serviceWorker.getRegistrations();
          serviceWorkers = (regs || []).map((r) => r.scope).filter(Boolean);
        }
      } catch {
        serviceWorkers = [];
      }
      let sharedStorage = false;
      try {
        sharedStorage = !!(window.sharedStorage || navigator.sharedStorage);
      } catch {
        sharedStorage = false;
      }
      let cookieStore = false;
      try {
        cookieStore = typeof window.cookieStore !== 'undefined';
      } catch {
        cookieStore = false;
      }
      return {
        localStorage,
        sessionStorage,
        indexedDB: indexedDBNames,
        cacheStorage,
        serviceWorkers,
        sharedStorage,
        cookieStore,
      };
    });
  } catch {
    return {
      localStorage: [],
      sessionStorage: [],
      indexedDB: [],
      cacheStorage: [],
      serviceWorkers: [],
      sharedStorage: false,
      cookieStore: false,
    };
  }
}

/**
 * Second pass with service workers bypassed (Playwright routing limitation).
 * @param {import('playwright').BrowserContext} context
 * @param {string} url
 * @param {number} timeoutMs
 * @returns {Promise<{ requests: string[], note: string }>}
 */
export async function serviceWorkerBypassPass(context, url, timeoutMs) {
  const page = await context.newPage();
  const seen = new Set();
  try {
    await page.route('**/*', async (route) => {
      // Continue normally but mark we are in bypass observation context
      await route.continue();
    });
    page.on('request', (req) => {
      try {
        const u = req.url();
        if (u && !u.startsWith('data:')) seen.add(normalizeRequestKey(u));
      } catch {
        /* ignore */
      }
    });
    // Unregister SWs before load when possible
    await page.addInitScript(() => {
      try {
        if (navigator.serviceWorker?.getRegistrations) {
          navigator.serviceWorker.getRegistrations().then((regs) => {
            regs.forEach((r) => r.unregister());
          });
        }
      } catch {
        /* ignore */
      }
    });
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: timeoutMs });
    await page.waitForTimeout(1500);
    return {
      requests: [...seen],
      note: 'SW-bypass observational pass — compare to primary session requests. Not a guarantee all SW traffic is visible.',
    };
  } catch (err) {
    return { requests: [...seen], note: `SW bypass incomplete: ${err.message || err}` };
  } finally {
    await page.close().catch(() => {});
  }
}

/**
 * Heuristic labels for first-party proxy / CNAME-style paths.
 * @param {string} host
 * @param {string} path
 * @param {string} siteHost
 */
export function proxyHeuristic(host, path, siteHost) {
  const h = (host || '').toLowerCase().replace(/^www\./, '');
  const site = (siteHost || '').toLowerCase().replace(/^www\./, '');
  const p = (path || '').toLowerCase();
  const labels = [];
  if (h === site || h.endsWith('.' + site)) {
    if (/\/(collect|g\/collect|event|track|pixel|analytics|gtm|metrics)/i.test(p)) {
      labels.push('possible_server_side_tracking');
      labels.push('first_party_proxy_unknown');
    }
  }
  if (/google-analytics|googletagmanager|facebook\.net|connect\.facebook|tiktok|doubleclick/i.test(h)) {
    /* third-party known */
  } else if (/\/(g\/collect|collect\?v=)/i.test(p) && h === site) {
    labels.push('confirmed_vendor_proxy');
  }
  return labels;
}
