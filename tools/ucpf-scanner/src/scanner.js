/**
 * Playwright privacy-behavior scan engine.
 * Never stores cookie values — value hashes only when CDP provides them.
 *
 * Sessions: profile-driven (quick / standard / compliance).
 * Per page: before_banner → action → after_action → reload → after_reload.
 * Optional options.interact / recipeFile: safe widget probing (time-capped).
 */

import { chromium } from 'playwright';
import { URL } from 'node:url';
import { config } from './config.js';
import { assertSafePublicUrl } from './ssrf.js';
import { classifyValue, toUcpfCategory } from './classify.js';
import {
  filterCookieInventory,
  filterConsentLeaks,
  filterStorage,
  shouldIgnoreCookieLeak,
  shouldIgnoreUrlLeak,
  shouldOmitSignal,
  collapseSignalHost,
} from './noise.js';
import {
  analyzeConsentModal,
  detectTcf,
  buildDarkPatterns,
  computeTechnicalScore,
  enrichTrackerRows,
} from './analyzers/consent-ux.js';
import { profilesForLevel } from './profiles.js';
import {
  attachNetworkCapture,
  enrichCookiesFromCdp,
  collectStorageSurface,
  serviceWorkerBypassPass,
  proxyHeuristic,
} from './capture.js';
import { buildFindings, summarizeFindings } from './findings.js';
import { probeGpcNavigator, probeConsentModeParams, probeGpp } from './signals.js';
import { selectRepresentativePages } from './pages.js';
import { DEFAULT_SAFE_ACTIONS, loadRecipeFile, runSafeRecipe } from './recipes.js';
import { getNodeInfo } from './node-info.js';
import { compareReports } from './drift.js';
/**
 * @param {object} input
 * @param {string} input.url
 * @param {string[]} [input.paths]
 * @param {object} [input.options]
 * @param {boolean} [input.options.interact]
 * @param {'quick'|'standard'|'compliance'} [input.options.profile]
 * @param {string} [input.options.recipeFile]
 * @param {object} [input.options.baseline]
 * @param {(p: object) => void} [input.options.onProgress]
 * @param {() => boolean} [input.options.shouldCancel]
 * @param {(browser: import('playwright').Browser) => void} [input.options.onBrowser]
 */
export async function runPrivacyScan(input) {
  const baseCheck = await assertSafePublicUrl(input.url);
  if (!baseCheck.ok) {
    throw new Error(baseCheck.error);
  }

  const options = input.options && typeof input.options === 'object' ? { ...input.options } : {};
  const onProgress = typeof options.onProgress === 'function' ? options.onProgress : () => {};
  const shouldCancel = typeof options.shouldCancel === 'function' ? options.shouldCancel : () => false;
  const onBrowser = typeof options.onBrowser === 'function' ? options.onBrowser : () => {};
  delete options.onProgress;
  delete options.shouldCancel;
  delete options.onBrowser;

  const interact = !!options.interact;
  const screenshots = !!options.screenshots;
  const profileLevel =
    options.profile === 'quick' || options.profile === 'compliance' ? options.profile : 'standard';
  const sessionProfiles = profilesForLevel(profileLevel);
  const maxPages = Math.max(
    1,
    Math.min(500, Number(options.maxPages) || config.maxPagesPerScan || 100)
  );

  const base = new URL(baseCheck.url);
  const selected = selectRepresentativePages(input.paths || ['/'], profileLevel);
  const paths = normalizePaths(selected.paths, maxPages);
  const pageUrls = paths.map((p) => new URL(p, base).toString());
  const recipeActions = [
    ...DEFAULT_SAFE_ACTIONS,
    ...loadRecipeFile(options.recipeFile || process.env.UCPF_SCANNER_RECIPE || ''),
  ];

  for (const u of pageUrls) {
    const check = await assertSafePublicUrl(u);
    if (!check.ok) {
      throw new Error(`Blocked page URL: ${check.error} (${u})`);
    }
  }

  const swExtra = profileLevel !== 'quick' ? 1 : 0;
  const reportExtra = 1;
  const totalUnits = sessionProfiles.length * pageUrls.length + swExtra + reportExtra;
  let completedUnits = 0;
  /** @type {string[]} */
  const progressLog = [];
  let cancelled = false;

  /**
   * @param {object} partial
   */
  function emitProgress(partial) {
    const step = Math.min(completedUnits, totalUnits);
    let percent =
      totalUnits > 0 ? Math.max(0, Math.min(99, Math.round((step / totalUnits) * 100))) : 0;
    if (partial.phase === 'done' || partial.percent === 100) {
      percent = 100;
    } else if (partial.percent != null && Number.isFinite(Number(partial.percent))) {
      percent = Math.max(0, Math.min(100, Math.round(Number(partial.percent))));
    }
    const payload = {
      ...partial,
      percent,
      step: partial.phase === 'done' ? totalUnits : step,
      total: totalUnits,
      profile: profileLevel,
      sessions_total: sessionProfiles.length,
      pages_total: pageUrls.length,
      log: progressLog.slice(-24),
    };
    try {
      onProgress(payload);
    } catch {
      /* progress sink must never fail the scan */
    }
  }

  /**
   * @param {string} msg
   */
  function logProgress(msg) {
    const ts = new Date().toISOString().slice(11, 19);
    progressLog.push(`${ts} ${msg}`);
    if (progressLog.length > 40) {
      progressLog.shift();
    }
  }

  function checkCancel() {
    if (shouldCancel()) {
      cancelled = true;
      return true;
    }
    return false;
  }

  if (checkCancel()) {
    const err = new Error('Scan cancelled before start');
    err.name = 'ScanCancelledError';
    throw err;
  }

  logProgress(`Starting ${profileLevel} scan · ${sessionProfiles.length} session(s) · ${pageUrls.length} page(s)`);
  emitProgress({
    phase: 'launch',
    message: `Launching Chromium (${profileLevel}: ${sessionProfiles.length} sessions × ${pageUrls.length} pages)…`,
  });

  const browser = await chromium.launch({
    headless: config.headless,
  });
  try {
    onBrowser(browser);
  } catch {
    /* ignore */
  }

  /** @type {Record<string, object>} */
  const sessions = {};
  /** @type {import('playwright').BrowserContext['storageState']|null} */
  let acceptStorageState = null;

  try {
    for (let si = 0; si < sessionProfiles.length; si += 1) {
      if (checkCancel()) {
        logProgress('Cancel requested — stopping before next session');
        break;
      }
      const profile = sessionProfiles[si];
      logProgress(`Session ${si + 1}/${sessionProfiles.length}: ${profile.label}`);
      emitProgress({
        phase: 'session',
        session: profile.id,
        session_label: profile.label,
        session_index: si + 1,
        message: `Session ${si + 1}/${sessionProfiles.length}: ${profile.label}`,
      });

      const sessOpts = {
        profile,
        pageUrls,
        baseHost: base.hostname,
        interact,
        screenshots,
        recipeActions,
        storageState: null,
        sessionCount: sessionProfiles.length,
        shouldCancel: () => shouldCancel(),
        onLog: (msg) => {
          logProgress(msg);
          emitProgress({
            phase: 'session',
            session: profile.id,
            session_label: profile.label,
            session_index: si + 1,
            message: msg,
          });
        },
        onPageStart: (pageIndex, url) => {
          let path = url;
          try {
            path = new URL(url).pathname || '/';
          } catch {
            /* keep url */
          }
          const msg = `${profile.label} · page ${pageIndex + 1}/${pageUrls.length} · ${path}`;
          logProgress(msg);
          emitProgress({
            phase: 'session',
            session: profile.id,
            session_label: profile.label,
            session_index: si + 1,
            page_index: pageIndex + 1,
            page_path: path,
            page_url: url,
            message: msg,
          });
        },
        onPageDone: () => {
          completedUnits += 1;
          emitProgress({
            phase: 'session',
            session: profile.id,
            session_label: profile.label,
            session_index: si + 1,
            message: `${profile.label} · completed ${completedUnits}/${totalUnits} units`,
          });
        },
      };
      if (profile.returning && acceptStorageState) {
        sessOpts.storageState = acceptStorageState;
      }
      if (profile.consent === 'revoke' && acceptStorageState) {
        sessOpts.storageState = acceptStorageState;
      }

      try {
        sessions[profile.id] = await runSession(browser, sessOpts);
      } catch (err) {
        const msg = String((err && err.message) || err || '');
        if (
          shouldCancel() ||
          (err && err.name === 'ScanCancelledError') ||
          /Target closed|has been closed|Browser closed|Connection closed/i.test(msg)
        ) {
          cancelled = true;
          logProgress('Session interrupted by cancel');
          break;
        }
        throw err;
      }

      if (profile.id === 'accept_all' && sessions.accept_all && sessions.accept_all._storageState) {
        acceptStorageState = sessions.accept_all._storageState;
        delete sessions.accept_all._storageState;
      }

      if (checkCancel()) {
        logProgress('Cancel requested — stopping after session');
        break;
      }
    }

    // Optional SW dual-pass on homepage (standard+)
    if (!cancelled && !checkCancel() && profileLevel !== 'quick' && pageUrls[0]) {
      logProgress('Service-worker bypass pass (homepage)');
      emitProgress({
        phase: 'sw_bypass',
        message: 'Service-worker bypass pass on homepage…',
      });
      const bypassCtx = await browser.newContext({
        userAgent:
          process.env.UCPF_SCANNER_UA ||
          'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
      });
      try {
        if (!checkCancel()) {
          sessions._sw_bypass = await serviceWorkerBypassPass(
            bypassCtx,
            pageUrls[0],
            config.navigationTimeoutMs
          );
        }
      } finally {
        await bypassCtx.close().catch(() => {});
      }
      completedUnits += 1;
      emitProgress({
        phase: 'sw_bypass',
        message: 'Service-worker bypass pass done',
      });
    }
  } finally {
    try {
      onBrowser(null);
    } catch {
      /* ignore */
    }
    await browser.close().catch(() => {});
  }

  const sessionKeys = Object.keys(sessions).filter((k) => !k.startsWith('_'));
  if (cancelled || checkCancel()) {
    if (!sessionKeys.length) {
      const err = new Error('Scan cancelled');
      err.name = 'ScanCancelledError';
      throw err;
    }
    logProgress(`Building partial report (${sessionKeys.length} session(s))…`);
    emitProgress({
      phase: 'report',
      message: `Cancelled — building partial report from ${sessionKeys.length} session(s)…`,
    });
    const report = buildReport({
      site_url: base.origin + '/',
      site_host: base.hostname,
      pageUrls,
      page_tags: selected.tagged,
      sessions,
      options: { interact, screenshots, profile: profileLevel, partial: true, cancelled: true },
      baseline: options.baseline || null,
    });
    report.partial = true;
    report.cancelled = true;
    report.partial_sessions = sessionKeys;
    completedUnits = totalUnits;
    logProgress('Partial report ready');
    emitProgress({
      phase: 'done',
      percent: 100,
      message: `Cancelled — partial results (${sessionKeys.length} session(s))`,
    });
    return report;
  }

  logProgress('Building privacy report…');
  emitProgress({
    phase: 'report',
    message: 'Building privacy report…',
  });

  const report = buildReport({
    site_url: base.origin + '/',
    site_host: base.hostname,
    pageUrls,
    page_tags: selected.tagged,
    sessions,
    options: { interact, screenshots, profile: profileLevel },
    baseline: options.baseline || null,
  });

  completedUnits += 1;
  logProgress('Scan complete');
  emitProgress({
    phase: 'done',
    percent: 100,
    message: 'Scan complete — ready to import',
  });

  return report;
}

/**
 * @param {string[]} paths
 * @param {number} max
 */
function normalizePaths(paths, max) {
  const out = [];
  const seen = new Set();
  for (const raw of paths) {
    let p = String(raw || '/').trim() || '/';
    if (/^https?:\/\//i.test(p)) {
      try {
        p = new URL(p).pathname || '/';
      } catch {
        continue;
      }
    }
    if (!p.startsWith('/')) p = `/${p}`;
    if (isNonHtmlScanPath(p)) continue;
    if (seen.has(p)) continue;
    seen.add(p);
    out.push(p);
    if (out.length >= max) break;
  }
  if (!out.length) out.push('/');
  return out;
}

/**
 * Skip PDFs, archives, media, and other downloadable assets — Playwright
 * throws "Download is starting" and aborts the session.
 * @param {string} pathOrUrl
 */
function isNonHtmlScanPath(pathOrUrl) {
  const s = String(pathOrUrl || '').split('?')[0].split('#')[0].toLowerCase();
  if (
    /\.(pdf|docx?|xlsx?|pptx?|zip|rar|7z|gz|tgz|tar|csv|tsv|rtf|odt|ods|odp|mp[34]|m4a|wav|avi|mov|wmv|webm|mkv|exe|dmg|apk|iso|bin|dmg)(\s|$)/i.test(
      s
    )
  ) {
    return true;
  }
  if (/\/wp-content\/uploads\/.+\.(pdf|docx?|xlsx?|zip)(\s|$)/i.test(s)) {
    return true;
  }
  return false;
}

/**
 * True when Playwright failed because the URL triggered a file download.
 * @param {unknown} err
 */
function isDownloadNavigationError(err) {
  const msg = String((err && /** @type {{ message?: string }} */ (err).message) || err || '');
  return /Download is starting|net::ERR_ABORTED|download/i.test(msg);
}

/**
 * Per-session wall-clock budget so selected pages can finish when the overall
 * timeout allows. Divides UCPF_SCANNER_BROWSER_TIMEOUT_MS by real session count
 * (not a hardcoded 6), floors by page count × estimated page cost, and caps so
 * one stuck session cannot consume the whole job.
 *
 * @param {number} sessionCount
 * @param {number} pageCount
 * @returns {number}
 */
function computeSessionBudgetMs(sessionCount, pageCount) {
  const sessions = Math.max(1, Math.floor(Number(sessionCount) || 1));
  const pages = Math.max(1, Math.floor(Number(pageCount) || 1));
  const totalMs = Math.max(60000, Number(config.browserTimeoutMs) || 600000);
  const equalShare = Math.floor(totalMs / sessions);
  const navMs = Math.max(5000, Number(config.navigationTimeoutMs) || 25000);
  const settleMs = Math.max(1000, Number(config.settleMs) || 4000);
  const gapMs = Math.max(0, Number(config.pageGapMs) || 1500);
  // Typical page: goto + settle + consent work + reload + settle + gap (nav often under max).
  const perPageMs = Math.floor(navMs * 0.55) + settleMs * 2.5 + gapMs + 6000;
  const pageFloor = Math.ceil(pages * perPageMs);
  const minBudget = 90000;
  const hardCap = Math.min(Math.max(pageFloor * 2, equalShare), 45 * 60 * 1000);
  return Math.min(hardCap, Math.max(equalShare, pageFloor, minBudget));
}

/**
 * @param {import('playwright').Browser} browser
 * @param {{ profile: import('./profiles.js').SessionProfile, pageUrls: string[], baseHost: string, interact: boolean, screenshots?: boolean, recipeActions?: object[], storageState?: object|null, sessionCount?: number, shouldCancel?: () => boolean, onLog?: (msg: string) => void, onPageStart?: (pageIndex: number, url: string) => void, onPageDone?: (pageIndex: number, url: string) => void }} opts
 */
async function runSession(browser, opts) {
  const profile = opts.profile;
  const sessionName = profile.id;
  /** @type {Record<string, string>} */
  const extraHeaders = {};
  if (profile.gpc === true) {
    extraHeaders['Sec-GPC'] = '1';
  }

  const contextOpts = {
    userAgent:
      process.env.UCPF_SCANNER_UA ||
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
    ignoreHTTPSErrors: false,
    javaScriptEnabled: true,
    locale: 'en-US',
    extraHTTPHeaders: Object.keys(extraHeaders).length ? extraHeaders : undefined,
  };
  if (opts.storageState) {
    contextOpts.storageState = opts.storageState;
  }

  const context = await browser.newContext(contextOpts);

  if (profile.dns === true && opts.baseHost) {
    const dnsPayload = {
      v: 1,
      sale: false,
      sharing: false,
      targeted_advertising: false,
      profiling: false,
      nonessential_tracking: false,
      limit_sensitive: true,
      scope: 'site',
      policy_version: 0,
      effective_at: Math.floor(Date.now() / 1000),
    };
    const host = String(opts.baseHost).replace(/^\./, '');
    try {
      await context.addCookies([
        {
          name: 'ucpf_dns',
          value: JSON.stringify(dnsPayload),
          domain: host,
          path: '/',
          httpOnly: false,
          secure: true,
          sameSite: 'Lax',
        },
      ]);
    } catch {
      try {
        await context.addCookies([
          {
            name: 'ucpf_dns',
            value: JSON.stringify(dnsPayload),
            url: `https://${host}/`,
          },
        ]);
      } catch {
        /* cookie inject best-effort */
      }
    }
  }

  if (profile.gpc === true) {
    await context.addInitScript(() => {
      try {
        Object.defineProperty(Navigator.prototype, 'globalPrivacyControl', {
          get: () => true,
          configurable: true,
        });
      } catch {
        /* ignore */
      }
    });
  } else if (profile.gpc === false) {
    await context.addInitScript(() => {
      try {
        Object.defineProperty(Navigator.prototype, 'globalPrivacyControl', {
          get: () => false,
          configurable: true,
        });
      } catch {
        /* ignore */
      }
    });
  }

  /** @type {Map<string, object>} */
  const requestMap = new Map();
  const scripts = new Set();
  const iframes = new Set();
  const beacons = new Set();
  const pixels = new Set();
  const fonts = new Set();
  const media = new Set();
  const websockets = new Set();
  /** @type {Map<string, object>} name|domain|path → cookie attrs from jar + Set-Cookie */
  const cookieEvents = new Map();
  /** @type {Array<object>} */
  const cookiePhases = [];

  const page = await context.newPage();
  page.setDefaultNavigationTimeout(config.navigationTimeoutMs);
  page.setDefaultTimeout(config.navigationTimeoutMs);

  const buckets = { requests: requestMap, scripts, beacons, pixels, websockets, fonts, media };
  const cdpNet = await attachNetworkCapture(context, page, buckets);
  await attachSetCookieListener(page, cookieEvents);

  const sessionBudgetMs = computeSessionBudgetMs(
    opts.sessionCount != null ? opts.sessionCount : 6,
    (opts.pageUrls && opts.pageUrls.length) || 1
  );
  const sessionDeadline = Date.now() + sessionBudgetMs;
  const interactBudgetMs = Math.min(12000, Math.floor(config.settleMs * 2.5));
  /** @type {object|null} */
  let consentModal = null;
  /** @type {object|null} */
  let tcf = null;
  /** @type {object|null} */
  let gpcProbe = null;
  /** @type {object|null} */
  let gppProbe = null;
  /** @type {object[]} */
  let recipeLog = [];
  /** @type {Record<string, string>} */
  const shotData = {};
  let truncatedByBudget = false;

  try {
    let pageIndex = 0;
    for (const url of opts.pageUrls) {
      if (Date.now() > sessionDeadline) {
        truncatedByBudget = true;
        break;
      }
      if (typeof opts.shouldCancel === 'function' && opts.shouldCancel()) {
        break;
      }
      const safe = await assertSafePublicUrl(url);
      if (!safe.ok) continue;
      if (isNonHtmlScanPath(safe.url)) {
        if (typeof opts.onLog === 'function') {
          try {
            opts.onLog(`Skipping download/non-HTML URL: ${safe.url}`);
          } catch {
            /* ignore */
          }
        }
        pageIndex += 1;
        continue;
      }

      if (typeof opts.onPageStart === 'function') {
        try {
          opts.onPageStart(pageIndex, safe.url);
        } catch {
          /* ignore */
        }
      }

      try {
        await page.goto(safe.url, { waitUntil: 'domcontentloaded', timeout: config.navigationTimeoutMs });
      } catch (err) {
        if (typeof opts.shouldCancel === 'function' && opts.shouldCancel()) {
          break;
        }
        // Browser closed mid-nav (cancel) — stop session with what we have.
        if (err && /Target closed|has been closed|Browser closed/i.test(String(err.message || err))) {
          break;
        }
        // PDF / attachment URLs — skip this page; do not fail the whole session.
        if (isDownloadNavigationError(err)) {
          if (typeof opts.onLog === 'function') {
            try {
              opts.onLog(`Skipping download navigation: ${safe.url}`);
            } catch {
              /* ignore */
            }
          }
          if (typeof opts.onPageDone === 'function') {
            try {
              opts.onPageDone(pageIndex, safe.url);
            } catch {
              /* ignore */
            }
          }
          pageIndex += 1;
          continue;
        }
        throw err;
      }
      if (typeof opts.shouldCancel === 'function' && opts.shouldCancel()) {
        break;
      }
      await page.waitForTimeout(Math.min(1500, config.settleMs));

      const before = await snapshotCookies(context, cookieEvents);
      cookiePhases.push({ page: safe.url, phase: 'before_banner', cookies: before });

      if ((sessionName === 'no_consent' || sessionName === 'gpc_on' || sessionName === 'gpc_off') && pageIndex === 0) {
        try {
          consentModal = await analyzeConsentModal(page);
        } catch {
          consentModal = { detected: false, error: 'modal_analysis_failed' };
        }
        try {
          tcf = await detectTcf(page);
        } catch {
          tcf = { detected: false };
        }
        try {
          gpcProbe = await probeGpcNavigator(page);
        } catch {
          gpcProbe = { navigator_gpc: null };
        }
        try {
          gppProbe = await probeGpp(page);
        } catch {
          gppProbe = { detected: false };
        }
        if (opts.screenshots && consentModal && consentModal.detected) {
          try {
            shotData.banner = (await page.screenshot({ type: 'jpeg', quality: 55, fullPage: false })).toString(
              'base64'
            );
          } catch {
            /* ignore */
          }
        }
      }

      await applyConsentAction(page, profile);

      if (profile.consent === 'reject' && opts.screenshots && pageIndex === 0) {
        try {
          shotData.after_reject = (await page.screenshot({ type: 'jpeg', quality: 55, fullPage: false })).toString(
            'base64'
          );
        } catch {
          /* ignore */
        }
      } else if (profile.consent === 'accept' && opts.screenshots && pageIndex === 0) {
        try {
          shotData.after_accept = (await page.screenshot({ type: 'jpeg', quality: 55, fullPage: false })).toString(
            'base64'
          );
        } catch {
          /* ignore */
        }
      }

      await page.waitForTimeout(Math.min(1200, config.settleMs));
      const afterAction = await snapshotCookies(context, cookieEvents);
      cookiePhases.push({ page: safe.url, phase: 'after_action', cookies: afterAction });

      try {
        await page.reload({ waitUntil: 'domcontentloaded', timeout: config.navigationTimeoutMs });
        await page.waitForTimeout(Math.min(1500, config.settleMs));
      } catch {
        /* reload may fail on some CF pages — keep going */
      }

      const afterReload = await snapshotCookies(context, cookieEvents);
      cookiePhases.push({ page: safe.url, phase: 'after_reload', cookies: afterReload });

      await scrollPage(page);
      await page.waitForTimeout(Math.min(config.settleMs, 3000));

      const isDns = profile.dns === true;
      const shouldInteract =
        opts.interact ||
        profile.consent === 'accept' ||
        profile.consent === 'reject' ||
        isDns ||
        (opts.interact && profile.gpc === true);
      if (
        shouldInteract &&
        (profile.consent === 'accept' || profile.consent === 'reject' || isDns || profile.gpc === true)
      ) {
        if (opts.interact && profile.consent === 'accept') {
          await heavyInteractions(page, interactBudgetMs);
        } else {
          await lightInteractions(page);
        }
        if (opts.recipeActions && opts.recipeActions.length && pageIndex === 0) {
          recipeLog = await runSafeRecipe(page, opts.recipeActions, Math.min(8000, interactBudgetMs));
        }
      }

      const frameSrcs = await page.$$eval('iframe[src]', (nodes) =>
        nodes.map((n) => n.getAttribute('src')).filter(Boolean)
      );
      frameSrcs.forEach((src) => {
        try {
          if (shouldOmitSignal(src)) return;
          const abs = new URL(src, url).toString().split('?')[0];
          if (shouldOmitSignal(abs)) return;
          const collapsed = collapseSignalHost(abs);
          iframes.add(collapsed || abs);
        } catch {
          /* ignore */
        }
      });
      if (typeof opts.onPageDone === 'function') {
        try {
          opts.onPageDone(pageIndex, safe.url);
        } catch {
          /* ignore */
        }
      }
      pageIndex += 1;
      const gap = Number(process.env.UCPF_SCANNER_PAGE_GAP_MS || config.pageGapMs || 1500);
      if (gap > 0 && pageIndex < opts.pageUrls.length) {
        await page.waitForTimeout(gap);
      }
    }

    if (truncatedByBudget && typeof opts.onLog === 'function') {
      const totalPages = opts.pageUrls.length;
      try {
        opts.onLog(
          `${profile.label} · stopped at page ${pageIndex}/${totalPages} (session time budget)`
        );
      } catch {
        /* ignore */
      }
    }

    await enrichCookiesFromCdp(cdpNet, cookieEvents);

    const jarCookies = await context.cookies();
    for (const c of jarCookies) {
      mergeCookieEvent(cookieEvents, stripCookieValue(c), 'jar');
    }

    const storage = await collectStorageSurface(page);

    /** @type {object} */
    const result = {
      session: sessionName,
      profile_label: profile.label,
      gpc: profile.gpc === true,
      dns: profile.dns === true,
      cookies: [...cookieEvents.values()].map((c) => ({
        name: c.name,
        domain: c.domain || '',
        path: c.path || '/',
        expires: c.expires,
        httpOnly: !!c.httpOnly,
        secure: !!c.secure,
        sameSite: c.sameSite || '',
        sources: c.sources || [],
        partitioned: !!c.partitioned,
        partitionKey: c.partitionKey || undefined,
        value_hash: c.value_hash || undefined,
      })),
      cookie_phases: cookiePhases,
      localStorage: storage.localStorage,
      sessionStorage: storage.sessionStorage,
      indexedDB: storage.indexedDB,
      cacheStorage: storage.cacheStorage,
      serviceWorkers: storage.serviceWorkers,
      sharedStorage: !!storage.sharedStorage,
      cookieStore: !!storage.cookieStore,
      requests: [...requestMap.keys()],
      request_details: [...requestMap.values()].slice(0, 400),
      scripts: [...scripts],
      iframes: [...iframes],
      beacons: [...beacons],
      pixels: [...pixels],
      fonts: [...fonts],
      media: [...media],
      websockets: [...websockets],
      consent_modal: consentModal,
      tcf,
      gpc_probe: gpcProbe,
      gpp: gppProbe,
      recipe: recipeLog,
      screenshots: shotData,
    };

    if (sessionName === 'accept_all') {
      try {
        result._storageState = await context.storageState();
      } catch {
        /* ignore */
      }
    }

    return result;
  } finally {
    await context.close();
  }
}

/**
 * @param {import('playwright').Page} page
 * @param {import('./profiles.js').SessionProfile} profile
 */
async function applyConsentAction(page, profile) {
  const mode = profile.consent;
  if (mode === 'none') return false;
  if (mode === 'reject') return clickConsent(page, 'reject');
  if (mode === 'accept') return clickConsent(page, 'accept');
  if (mode === 'revoke') {
    // Prefer prefs / withdraw; fall back to reject
    const opened = await clickConsent(page, 'customize');
    if (opened) {
      await clickConsent(page, 'reject');
      return true;
    }
    return clickConsent(page, 'reject');
  }
  if (mode === 'analytics' || mode === 'functional') {
    await clickConsent(page, 'customize');
    try {
      const sel =
        mode === 'analytics'
          ? '#ucpf-root [data-ucpf-category="analytics"] input, #ucpf-root input[name*="analytics" i]'
          : '#ucpf-root [data-ucpf-category="preferences"] input, #ucpf-root [data-ucpf-category="functional"] input, #ucpf-root input[name*="preference" i]';
      const boxes = page.locator(sel);
      const n = await boxes.count();
      for (let i = 0; i < Math.min(n, 6); i += 1) {
        const box = boxes.nth(i);
        if (await box.isVisible({ timeout: 300 })) {
          await box.check({ force: true }).catch(() => box.click({ force: true }));
        }
      }
      await page
        .locator('#ucpf-root [data-ucpf-action="save"], #ucpf-root button:has-text("Save")')
        .first()
        .click({ timeout: 1500 })
        .catch(() => {});
      await page.waitForTimeout(600);
      return true;
    } catch {
      return clickConsent(page, 'accept');
    }
  }
  return false;
}

/**
 * Capture Set-Cookie response headers (names + attributes only).
 * @param {import('playwright').Page} page
 * @param {Map<string, object>} cookieEvents
 */
async function attachSetCookieListener(page, cookieEvents) {
  page.on('response', async (response) => {
    try {
      let lines = [];
      if (typeof response.headerValues === 'function') {
        lines = await response.headerValues('set-cookie');
      }
      if (!lines.length) {
        const h = response.headers();
        if (h['set-cookie']) lines = [h['set-cookie']];
      }
      for (const line of lines) {
        const parsed = parseSetCookieLine(line, response.url());
        if (parsed) mergeCookieEvent(cookieEvents, parsed, 'set-cookie');
      }
    } catch {
      /* ignore */
    }
  });

  // CDP extra-info often preserves multi Set-Cookie better than collapsed headers.
  try {
    const cdp = await page.context().newCDPSession(page);
    await cdp.send('Network.enable');
    cdp.on('Network.responseReceivedExtraInfo', (event) => {
      try {
        const headers = event.headers || {};
        const raw = headers['set-cookie'] || headers['Set-Cookie'];
        if (!raw) return;
        const lines = Array.isArray(raw) ? raw : String(raw).split(/\n/).filter(Boolean);
        for (const line of lines) {
          const parsed = parseSetCookieLine(line, '');
          if (parsed) mergeCookieEvent(cookieEvents, parsed, 'cdp-set-cookie');
        }
      } catch {
        /* ignore */
      }
    });
  } catch {
    /* CDP optional */
  }
}

/**
 * @param {string} line
 * @param {string} responseUrl
 */
function parseSetCookieLine(line, responseUrl) {
  const raw = String(line || '').trim();
  if (!raw) return null;
  const first = raw.split(';')[0];
  const eq = first.indexOf('=');
  if (eq <= 0) return null;
  const name = first.slice(0, eq).trim();
  if (!name || name.toLowerCase() === 'expires') return null;

  const attrs = raw.toLowerCase();
  let domain = '';
  let path = '/';
  const domainMatch = raw.match(/;\s*domain=([^;]+)/i);
  const pathMatch = raw.match(/;\s*path=([^;]+)/i);
  if (domainMatch) domain = domainMatch[1].trim();
  if (pathMatch) path = pathMatch[1].trim() || '/';
  if (!domain && responseUrl) {
    try {
      domain = new URL(responseUrl).hostname;
    } catch {
      domain = '';
    }
  }

  return {
    name,
    domain,
    path,
    expires: -1,
    httpOnly: attrs.includes('httponly'),
    secure: attrs.includes('secure'),
    sameSite: /samesite=none/i.test(raw) ? 'None' : /samesite=strict/i.test(raw) ? 'Strict' : /samesite=lax/i.test(raw) ? 'Lax' : '',
  };
}

/**
 * @param {Map<string, object>} map
 * @param {object} cookie
 * @param {string} source
 */
function mergeCookieEvent(map, cookie, source) {
  const key = `${cookie.name}|${cookie.domain || ''}|${cookie.path || '/'}`;
  const prev = map.get(key) || {
    name: cookie.name,
    domain: cookie.domain || '',
    path: cookie.path || '/',
    expires: cookie.expires,
    httpOnly: !!cookie.httpOnly,
    secure: !!cookie.secure,
    sameSite: cookie.sameSite || '',
    sources: [],
  };
  if (cookie.httpOnly) prev.httpOnly = true;
  if (cookie.secure) prev.secure = true;
  if (cookie.sameSite) prev.sameSite = cookie.sameSite;
  if (cookie.expires != null && cookie.expires !== -1) prev.expires = cookie.expires;
  if (source && !prev.sources.includes(source)) prev.sources.push(source);
  map.set(key, prev);
}

/**
 * @param {import('playwright').BrowserContext} context
 * @param {Map<string, object>} cookieEvents
 */
async function snapshotCookies(context, cookieEvents) {
  const jar = await context.cookies();
  for (const c of jar) {
    mergeCookieEvent(cookieEvents, stripCookieValue(c), 'jar');
  }
  return jar.map(stripCookieValue);
}

/** @param {import('playwright').Cookie} c */
function stripCookieValue(c) {
  return {
    name: c.name,
    domain: c.domain,
    path: c.path,
    expires: c.expires,
    httpOnly: c.httpOnly,
    secure: c.secure,
    sameSite: c.sameSite,
  };
}

/**
 * @param {import('playwright').Page} page
 */
async function collectStorage(page) {
  try {
    return await page.evaluate(async () => {
      const localStorage = Object.keys(window.localStorage || {});
      const sessionStorage = Object.keys(window.sessionStorage || {});
      let indexedDBNames = [];
      try {
        if (window.indexedDB && typeof window.indexedDB.databases === 'function') {
          const dbs = await window.indexedDB.databases();
          indexedDBNames = (dbs || []).map((d) => d.name).filter(Boolean);
        }
      } catch (e) {
        indexedDBNames = [];
      }
      return { localStorage, sessionStorage, indexedDB: indexedDBNames };
    });
  } catch {
    return { localStorage: [], sessionStorage: [], indexedDB: [] };
  }
}

/**
 * Optional heavy-pass storage APIs (names only).
 * @param {import('playwright').Page} page
 */
async function collectCacheAndServiceWorkers(page) {
  try {
    return await page.evaluate(async () => {
      let cacheStorage = [];
      try {
        if (window.caches && typeof window.caches.keys === 'function') {
          cacheStorage = await window.caches.keys();
        }
      } catch (e) {
        cacheStorage = [];
      }
      let serviceWorkers = [];
      try {
        if (navigator.serviceWorker && navigator.serviceWorker.getRegistrations) {
          const regs = await navigator.serviceWorker.getRegistrations();
          serviceWorkers = (regs || []).map((r) => r.scope).filter(Boolean);
        }
      } catch (e) {
        serviceWorkers = [];
      }
      return { cacheStorage, serviceWorkers };
    });
  } catch {
    return { cacheStorage: [], serviceWorkers: [] };
  }
}

/**
 * @param {import('playwright').Page} page
 * @param {'accept'|'reject'|'customize'} mode
 */
async function clickConsent(page, mode) {
  if (mode === 'customize') {
    const customizeSelectors = [
      '#ucpf-root [data-ucpf-action="customize"]',
      '#ucpf-root [data-ucpf-action="preferences"]',
      '#ucpf-root button:has-text("Customize")',
      '#ucpf-root button:has-text("Preferences")',
      '#ucpf-root button:has-text("Manage")',
    ];
    for (const sel of customizeSelectors) {
      try {
        const el = page.locator(sel).first();
        if (await el.isVisible({ timeout: 600 })) {
          await el.click({ timeout: 2000 });
          await page.waitForTimeout(500);
          return true;
        }
      } catch {
        /* try next */
      }
    }
    try {
      const btn = page.getByRole('button', { name: /customize|preferences|manage cookies|cookie settings/i }).first();
      if (await btn.isVisible({ timeout: 600 })) {
        await btn.click({ timeout: 2000 });
        await page.waitForTimeout(500);
        return true;
      }
    } catch {
      /* ignore */
    }
    return false;
  }

  const ucpfSelectors =
    mode === 'accept'
      ? [
          '#ucpf-root [data-ucpf-action="accept_all"]',
          '#ucpf-root button.ucpf-btn--fill.ucpf-btn--primary-tier',
          '#ucpf-root .ucpf-btn--fill',
        ]
      : [
          '#ucpf-root [data-ucpf-action="reject_all"]',
          '#ucpf-root button.ucpf-btn--outline.ucpf-btn--primary-tier',
          '#ucpf-root .ucpf-btn--outline',
        ];

  for (const sel of ucpfSelectors) {
    try {
      const el = page.locator(sel).first();
      if (await el.isVisible({ timeout: 800 })) {
        await el.click({ timeout: 2000 });
        await page.waitForTimeout(800);
        return true;
      }
    } catch {
      /* try next */
    }
  }

  const texts =
    mode === 'accept'
      ? [/accept all/i, /allow all/i, /agree/i]
      : [/reject all/i, /decline/i, /necessary only/i, /essential only/i];

  for (const re of texts) {
    try {
      const btn = page.getByRole('button', { name: re }).first();
      if (await btn.isVisible({ timeout: 600 })) {
        await btn.click({ timeout: 2000 });
        await page.waitForTimeout(800);
        return true;
      }
    } catch {
      /* next */
    }
  }
  return false;
}

/** @param {import('playwright').Page} page */
async function scrollPage(page) {
  try {
    await page.evaluate(async () => {
      const step = Math.max(200, Math.floor(window.innerHeight * 0.8));
      for (let y = 0; y < document.body.scrollHeight; y += step) {
        window.scrollTo(0, y);
        await new Promise((r) => setTimeout(r, 120));
      }
      window.scrollTo(0, 0);
    });
  } catch {
    /* ignore */
  }
}

/** @param {import('playwright').Page} page */
async function lightInteractions(page) {
  try {
    const input = page.locator('input[type="email"], input[type="text"], textarea').first();
    if (await input.isVisible({ timeout: 500 })) {
      await input.focus();
    }
  } catch {
    /* ignore */
  }
  try {
    await page.locator('iframe[src*="tawk"], iframe[src*="maps"], iframe[src*="youtube"], .ucpf-iframe-placeholder').first().click({
      timeout: 500,
      force: true,
    });
  } catch {
    /* ignore */
  }
}

/**
 * Time-capped widget probing. Never submits forms.
 * @param {import('playwright').Page} page
 * @param {number} budgetMs
 */
async function heavyInteractions(page, budgetMs) {
  const deadline = Date.now() + budgetMs;
  const stillTime = () => Date.now() < deadline;

  await lightInteractions(page);
  if (!stillTime()) return;

  const clickFirst = async (selector) => {
    if (!stillTime()) return;
    try {
      const el = page.locator(selector).first();
      if (await el.isVisible({ timeout: 400 })) {
        await el.click({ timeout: 800, force: true });
        await page.waitForTimeout(400);
      }
    } catch {
      /* ignore */
    }
  };

  // Maps / embeds / placeholders
  await clickFirst('iframe[src*="google.com/maps"], iframe[src*="maps.google"], .wpgmza_map, .ucpf-iframe-placeholder');
  if (!stillTime()) return;

  // Video / music (play controls — no navigation away)
  await clickFirst('video, button[aria-label*="Play" i], .mejs-play, .elementor-custom-embed-play');
  if (!stillTime()) return;

  // Accessibility widgets
  await clickFirst('#userwayAccessibilityIcon, .userway_buttons_wrapper, [class*="userway"], [id*="userway"]');
  if (!stillTime()) return;

  // Common popup dismiss (close only)
  await clickFirst('[aria-label="Close"], .dialog-close, .popup-close, button.close, .elementor-popup-modal .dialog-close-button');
  if (!stillTime()) return;

  // Focus a form control — never submit
  try {
    const formControl = page.locator('form input:not([type="hidden"]):not([type="submit"]), form textarea, form select').first();
    if (await formControl.isVisible({ timeout: 400 })) {
      await formControl.focus();
      await page.waitForTimeout(300);
    }
  } catch {
    /* ignore */
  }
}

/**
 * @param {{ site_url: string, site_host: string, pageUrls: string[], page_tags?: object[], sessions: Record<string, object>, options?: object, baseline?: object|null }} data
 */
function buildReport(data) {
  const cookieMap = new Map();
  const storageKeys = new Map();
  const requestHosts = new Map();
  const requestUrls = new Map();
  const scriptUrls = new Map();
  const iframeUrls = new Map();
  const beaconUrls = new Map();
  const pixelUrls = new Map();
  const services = new Map();
  /** @type {Array<object>} */
  const allPhases = [];
  /** @type {string[]} */
  const proxyLabels = [];

  const sessionEntries = Object.entries(data.sessions).filter(([k]) => !k.startsWith('_'));

  for (const [sessionName, sess] of sessionEntries) {
    for (const phase of sess.cookie_phases || []) {
      allPhases.push({
        session: sessionName,
        page: phase.page,
        phase: phase.phase,
        cookie_names: (phase.cookies || []).map((c) => c.name),
        cookie_count: (phase.cookies || []).length,
      });
      for (const c of phase.cookies || []) {
        mergeCookieIntoMap(cookieMap, c, sessionName, phase.phase, []);
      }
    }

    for (const c of sess.cookies || []) {
      mergeCookieIntoMap(cookieMap, c, sessionName, null, c.sources || []);
    }

    for (const k of sess.localStorage || []) {
      mergeKey(storageKeys, k, 'localStorage', sessionName);
    }
    for (const k of sess.sessionStorage || []) {
      mergeKey(storageKeys, k, 'sessionStorage', sessionName);
    }
    for (const k of sess.indexedDB || []) {
      mergeKey(storageKeys, k, 'indexedDB', sessionName);
    }
    for (const k of sess.cacheStorage || []) {
      mergeKey(storageKeys, k, 'cacheStorage', sessionName);
    }
    for (const k of sess.serviceWorkers || []) {
      mergeKey(storageKeys, k, 'serviceWorker', sessionName);
    }

    collectHosts(requestHosts, sess.requests, sessionName);
    collectUrls(
      requestUrls,
      (sess.requests || []).map((r) => (String(r).includes('://') ? r : `https://${r}`)),
      sessionName
    );
    collectUrls(scriptUrls, sess.scripts, sessionName);
    collectUrls(iframeUrls, sess.iframes, sessionName);
    collectUrls(beaconUrls, sess.beacons, sessionName);
    collectUrls(pixelUrls, sess.pixels, sessionName);

    for (const det of sess.request_details || []) {
      const labels = proxyHeuristic(det.host, det.path, data.site_host);
      for (const lab of labels) {
        proxyLabels.push(`${lab}:${det.key}`);
      }
    }
  }

  const cookiesRaw = [...cookieMap.values()].map((c) => {
    const cls = classifyValue(c.name, 'cookie');
    const category = cls.matched ? cls.category : 'unclassified';
    const treatment = cls.treatment || (category === 'necessary' ? 'necessary' : 'consent');
    const importance =
      cls.importance ||
      (category === 'unclassified' ? 'unclassified' : category === 'necessary' ? 'required' : 'non_essential');
    if (cls.provider) {
      services.set(cls.provider, {
        provider: cls.provider,
        category,
        treatment,
        importance,
        type: 'cookie',
      });
    }
    return {
      name: c.name,
      domain: c.domain,
      path: c.path,
      expires: c.expires,
      httpOnly: c.httpOnly,
      secure: c.secure,
      sameSite: c.sameSite,
      contexts: c.contexts,
      phases: c.phases,
      sources: c.sources,
      pre_consent: c.contexts.includes('no_consent'),
      post_accept: c.contexts.includes('accept_all'),
      provider: cls.provider || '',
      category,
      treatment,
      importance,
      ucpf_category: category === 'unclassified' ? 'unclassified' : toUcpfCategory(category),
      status: category === 'unclassified' ? 'needs_review' : 'classified',
      note: cls.note || '',
    };
  });

  const { cookies, omitted: noise_omitted } = filterCookieInventory(cookiesRaw);

  const classifyUrlList = (map, type) =>
    [...map.values()]
      .filter((row) => {
        const raw = row.url || row.host || row.key || '';
        return raw && !shouldOmitSignal(raw) && !shouldOmitSignal(safeHost(raw));
      })
      .map((row) => {
      const host = safeHost(row.url || row.host || row.key);
      let cls = classifyValue(row.url || row.host || row.key, type);
      if (!cls.matched && data.site_host && host && host.replace(/^www\./, '') === data.site_host.replace(/^www\./, '')) {
        cls = {
          category: 'necessary',
          provider: 'First-party site',
          treatment: 'necessary',
          importance: 'required',
          matched: true,
          rule: 'first-party',
          note: 'Same-site asset/request.',
        };
      }
      const category = cls.matched ? cls.category : 'unclassified';
      const treatment = cls.treatment || (category === 'necessary' ? 'necessary' : 'consent');
      const importance =
        cls.importance ||
        (category === 'unclassified' ? 'unclassified' : category === 'necessary' ? 'required' : 'non_essential');
      if (cls.provider) {
        services.set(cls.provider, {
          provider: cls.provider,
          category,
          treatment,
          importance,
          type,
        });
      }
      return {
        ...row,
        host,
        url: host || row.url,
        provider: cls.provider || '',
        category,
        treatment,
        importance,
        status: cls.matched && category !== 'unclassified' ? 'classified' : 'needs_review',
        note: cls.note || '',
      };
    });

  const classifiedRequests = enrichTrackerRows(classifyUrlList(requestHosts, 'script_host'));
  const classifiedScripts = enrichTrackerRows(classifyUrlList(scriptUrls, 'script_host'));
  const classifiedIframes = enrichTrackerRows(classifyUrlList(iframeUrls, 'script_host'));
  const classifiedBeacons = enrichTrackerRows(classifyUrlList(beaconUrls, 'script_host'));
  const classifiedPixels = enrichTrackerRows(classifyUrlList(pixelUrls, 'script_host'));
  const classifiedRequestUrls = enrichTrackerRows(classifyUrlList(requestUrls, 'script_host'));

  const sessionMap = Object.fromEntries(sessionEntries);
  const request_diffs = buildRequestDiffs(sessionMap);
  const consent_leaks = filterConsentLeaks(
    buildConsentLeaks({
      cookies,
      requests: classifiedRequests,
      scripts: classifiedScripts,
      iframes: classifiedIframes,
      beacons: classifiedBeacons,
      pixels: classifiedPixels,
    })
  );

  const findings = buildFindings(sessionMap, cookies);
  const findings_summary = summarizeFindings(findings);

  const noConsent = sessionMap.no_consent || sessionMap.gpc_on || {};
  const modal = noConsent.consent_modal || { detected: false };
  const tcf = noConsent.tcf || { detected: false };
  const beforeNonEssential = [];
  for (const phase of noConsent.cookie_phases || []) {
    if (phase.phase !== 'before_banner') continue;
    for (const c of phase.cookies || []) {
      const cls = classifyValue(c.name || '', 'cookie');
      const category = toUcpfCategory(cls.category);
      if (category && category !== 'necessary' && category !== 'unclassified') {
        beforeNonEssential.push(c.name);
      }
    }
  }

  const dark_patterns = buildDarkPatterns({
    modal,
    consent_leaks,
    before_banner_nonessential: beforeNonEssential,
  });
  const compliance_score = computeTechnicalScore({
    dark_patterns,
    modal,
    consent_leaks,
  });

  const allRequestKeys = sessionEntries.flatMap(([, s]) => s.requests || []);
  const consent_mode = probeConsentModeParams(allRequestKeys);
  const gpc_session = sessionMap.gpc_on || sessionMap.no_consent || {};
  const privacy_signals = {
    gpc: {
      header_profile_ran: !!sessionMap.gpc_on,
      navigator: gpc_session.gpc_probe || null,
    },
    gpp: gpc_session.gpp || noConsent.gpp || { detected: false },
    consent_mode,
    disclaimer:
      'Observational privacy signals only — not proof of lawful configuration or compliance.',
  };

  const screenshots = {
    ...(noConsent.screenshots || {}),
    ...((sessionMap.reject_all && sessionMap.reject_all.screenshots) || {}),
    ...((sessionMap.accept_all && sessionMap.accept_all.screenshots) || {}),
  };
  const shotOut = Object.keys(screenshots).length ? screenshots : undefined;

  const drift = data.baseline
    ? compareReports(data.baseline, {
        requests: classifiedRequests.map((r) => r.host || r.key || r.url),
        cookies,
        findings_summary,
        consent_leaks,
      })
    : { alerts: [], note: 'No baseline provided.' };

  const uniqueProxy = [...new Set(proxyLabels)].slice(0, 40).map((s) => {
    const [label, ...rest] = s.split(':');
    return { label, key: rest.join(':') };
  });

  return {
    schema: 'ucpf-playwright-scan/2.0',
    site_url: data.site_url,
    scanned_at: new Date().toISOString(),
    pages: data.pageUrls,
    page_tags: data.page_tags || [],
    scanner_node: getNodeInfo(),
    options: {
      interact: !!(data.options && data.options.interact),
      screenshots: !!(data.options && data.options.screenshots),
      profile: (data.options && data.options.profile) || 'standard',
    },
    sessions: Object.fromEntries(
      sessionEntries.map(([k, v]) => [
        k,
        {
          cookie_count: (v.cookies || []).length,
          request_count: (v.requests || []).length,
          script_count: (v.scripts || []).length,
          profile_label: v.profile_label || k,
          gpc: !!v.gpc,
        },
      ])
    ),
    cookies,
    cookie_phases: allPhases,
    storage: filterStorage([...storageKeys.values()]),
    noise_omitted: noise_omitted.slice(0, 50),
    requests: classifiedRequests,
    request_urls: classifiedRequestUrls.slice(0, 200),
    request_diffs,
    scripts: classifiedScripts,
    iframes: classifiedIframes,
    beacons: classifiedBeacons,
    pixels: classifiedPixels,
    consent_leaks,
    findings,
    findings_summary,
    privacy_signals,
    proxy_heuristics: uniqueProxy,
    sw_bypass: data.sessions._sw_bypass || null,
    drift,
    detected_services: [...services.values()],
    cmp: modal.cmp || null,
    consent_modal: {
      detected: !!modal.detected,
      has_accept: !!modal.has_accept,
      has_reject: !!modal.has_reject,
      has_customize: !!modal.has_customize,
      button_count: Array.isArray(modal.buttons) ? modal.buttons.length : 0,
      checkbox_count: Array.isArray(modal.checkboxes) ? modal.checkboxes.length : 0,
    },
    tcf,
    dark_patterns,
    compliance_score,
    screenshots: shotOut,
    notice:
      'Technical privacy-behavior scan only — not a guarantee of full detection or legal compliance. findings[] compare consent states. Unknown items remain Unclassified until reviewed. compliance_score and dark_patterns are automated technical checks, not a GDPR determination.',
  };
}

function mergeCookieIntoMap(cookieMap, c, sessionName, phase, sources) {
  const key = `${c.name}|${c.domain || ''}|${c.path || '/'}`;
  const prev = cookieMap.get(key) || {
    name: c.name,
    domain: c.domain || '',
    path: c.path || '/',
    expires: c.expires,
    httpOnly: !!c.httpOnly,
    secure: !!c.secure,
    sameSite: c.sameSite || '',
    contexts: [],
    phases: [],
    sources: [],
  };
  if (!prev.contexts.includes(sessionName)) prev.contexts.push(sessionName);
  if (phase && !prev.phases.includes(phase)) prev.phases.push(phase);
  for (const s of sources || []) {
    if (s && !prev.sources.includes(s)) prev.sources.push(s);
  }
  if (c.httpOnly) prev.httpOnly = true;
  if (c.secure) prev.secure = true;
  cookieMap.set(key, prev);
}

/**
 * URL/host sets present in each consent state for diffing.
 * @param {Record<string, object>} sessions
 */
function buildRequestDiffs(sessions) {
  const sets = {};
  const names = Object.keys(sessions || {});
  for (const name of names) {
    const list = (sessions[name] && sessions[name].requests) || [];
    sets[name] = new Set(list.map(String));
  }
  // Ensure core triad keys exist even if a session was skipped.
  for (const name of ['no_consent', 'reject_all', 'accept_all']) {
    if (!sets[name]) sets[name] = new Set();
  }
  const onlyIn = (a, b, c) => [...sets[a]].filter((x) => !sets[b].has(x) && !sets[c].has(x));
  const inBoth = (a, b) => [...sets[a]].filter((x) => sets[b].has(x));

  return {
    only_no_consent: onlyIn('no_consent', 'reject_all', 'accept_all').slice(0, 100),
    only_reject_all: onlyIn('reject_all', 'no_consent', 'accept_all').slice(0, 100),
    only_accept_all: onlyIn('accept_all', 'no_consent', 'reject_all').slice(0, 100),
    shared_no_consent_and_reject_all: inBoth('no_consent', 'reject_all').slice(0, 150),
  };
}

/**
 * Flag consent-required inventory appearing in both no_consent and reject_all.
 * @param {object} lists
 */
function buildConsentLeaks(lists) {
  /** @type {Array<object>} */
  const leaks = [];
  const pushLeak = (row) => {
    leaks.push(row);
  };

  const consider = (item, type, label) => {
    const treatment = item.treatment || '';
    const importance = item.importance || '';
    const category = item.category || '';
    const isConsentRequired =
      treatment === 'consent' ||
      importance === 'non_essential' ||
      (category && category !== 'necessary' && category !== 'unclassified' && treatment !== 'necessary');
    if (!isConsentRequired) return;
    const ctx = item.contexts || [];
    if (ctx.includes('no_consent') && ctx.includes('reject_all')) {
      pushLeak({
        type,
        name: label,
        provider: item.provider || '',
        category,
        treatment: treatment || 'consent',
        importance: importance || 'non_essential',
        contexts: ctx,
        severity: 'high',
        reason: 'Consent-required signal observed in both no_consent and reject_all sessions.',
      });
    }
  };

  for (const c of lists.cookies || []) {
    if (shouldIgnoreCookieLeak(c.name)) continue;
    consider(c, 'cookie', c.name);
  }
  for (const r of lists.requests || []) {
    const label = r.host || r.url;
    if (shouldIgnoreUrlLeak(label)) continue;
    consider(r, 'request', label);
  }
  for (const s of lists.scripts || []) {
    const label = s.url || s.host;
    if (shouldIgnoreUrlLeak(label)) continue;
    consider(s, 'script', label);
  }
  for (const f of lists.iframes || []) {
    const label = f.url || f.host;
    if (shouldIgnoreUrlLeak(label)) continue;
    consider(f, 'iframe', label);
  }
  for (const b of lists.beacons || []) {
    const label = b.url || b.host;
    if (shouldIgnoreUrlLeak(label)) continue;
    consider(b, 'beacon', label);
  }
  for (const p of lists.pixels || []) {
    const label = p.url || p.host;
    if (shouldIgnoreUrlLeak(label)) continue;
    consider(p, 'pixel', label);
  }

  // Dedupe by type+name+provider
  const seen = new Set();
  return leaks.filter((row) => {
    const key = `${row.type}|${row.name}|${row.provider}`;
    if (seen.has(key)) return false;
    seen.add(key);
    return true;
  });
}

function mergeKey(map, key, kind, sessionName) {
  const prev = map.get(`${kind}|${key}`) || { key, kind, contexts: [] };
  if (!prev.contexts.includes(sessionName)) prev.contexts.push(sessionName);
  map.set(`${kind}|${key}`, prev);
}

function collectHosts(map, list, sessionName) {
  for (const item of list || []) {
    const raw = String(item || '').trim();
    if (!raw || shouldOmitSignal(raw)) continue;
    const host = collapseSignalHost(raw.split('/')[0]);
    if (!host || shouldOmitSignal(host)) continue;
    const prev = map.get(host) || { host, url: host, contexts: [] };
    if (!prev.contexts.includes(sessionName)) prev.contexts.push(sessionName);
    map.set(host, prev);
  }
}

function collectUrls(map, list, sessionName) {
  for (const url of list || []) {
    if (!url || shouldOmitSignal(url)) continue;
    const collapsedHost = collapseSignalHost(url);
    if (!collapsedHost || shouldOmitSignal(collapsedHost)) continue;
    // Prefer collapsed parent host as key when ephemeral workers were rewritten.
    let key = String(url);
    try {
      const u = new URL(url.includes('://') ? url : `https://${url}`);
      if (collapseSignalHost(u.hostname) !== u.hostname.toLowerCase()) {
        key = collapsedHost;
      }
    } catch {
      key = collapsedHost || key;
    }
    const prev = map.get(key) || { url: key, contexts: [] };
    if (!prev.contexts.includes(sessionName)) prev.contexts.push(sessionName);
    map.set(key, prev);
  }
}

function safeHost(value) {
  try {
    if (String(value).includes('://')) {
      return collapseSignalHost(new URL(value).hostname) || new URL(value).hostname;
    }
  } catch {
    /* ignore */
  }
  return collapseSignalHost(String(value).split('/')[0]) || String(value).split('/')[0];
}
