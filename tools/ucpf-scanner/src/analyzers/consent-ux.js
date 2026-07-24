/**
 * Consent UX analyzers (Slashgear-inspired, rewritten for UCPF).
 * Technical checks only — not a GDPR compliance determination.
 */

import { matchTrackerHost } from '../data/trackers.js';

/** Curated CMP / banner root selectors. */
export const CMP_SELECTORS = [
  { id: 'ucpf', name: 'Universal Consent & Privacy Framework', selectors: ['#ucpf-root .ucpf-banner', '#ucpf-root [data-ucpf-banner]', '#ucpf-root'] },
  { id: 'cookiebot', name: 'Cookiebot', selectors: ['#CybotCookiebotDialog', '.CybotCookiebotDialogBody'] },
  { id: 'onetrust', name: 'OneTrust', selectors: ['#onetrust-banner-sdk', '#onetrust-consent-sdk', '.ot-sdk-container'] },
  { id: 'didomi', name: 'Didomi', selectors: ['#didomi-host', '.didomi-popup-container'] },
  { id: 'usercentrics', name: 'Usercentrics', selectors: ['#usercentrics-root', '[data-testid="uc-container"]'] },
  { id: 'axeptio', name: 'Axeptio', selectors: ['#axeptio_overlay', '.axeptio_mount'] },
  { id: 'tarteaucitron', name: 'Tarteaucitron', selectors: ['#tarteaucitronRoot', '#tarteaucitronAlertBig'] },
  { id: 'quantcast', name: 'Quantcast Choice', selectors: ['.qc-cmp2-container', '#qc-cmp2-ui'] },
  { id: 'trustarc', name: 'TrustArc', selectors: ['#truste-consent-track', '.truste_box_overlay'] },
  { id: 'complianz', name: 'Complianz', selectors: ['#cmplz-cookiebanner-container', '.cmplz-cookiebanner'] },
  { id: 'cookieyes', name: 'CookieYes', selectors: ['.cky-consent-container', '#cky-consent'] },
  { id: 'termly', name: 'Termly', selectors: ['#termly-code-snippet-support', '.termly-styles'] },
  { id: 'iubenda', name: 'iubenda', selectors: ['.iubenda-cs-container', '#iubenda-cs-banner'] },
  { id: 'osano', name: 'Osano', selectors: ['.osano-cm-window', '.osano-cm-dialog'] },
];

const ACCEPT_RE = /accept\s*all|allow\s*all|agree(\s*all)?|i\s*agree|got\s*it|consent/i;
const REJECT_RE = /reject\s*all|decline(\s*all)?|refuse|necessary\s*only|essential\s*only|deny(\s*all)?|reject/i;
const AMBIGUOUS_RE = /^(ok|okay|continue|close|next|proceed|understood|fine)$/i;

/**
 * Analyze consent modal in-page (runs in browser context via page.evaluate).
 * @param {import('playwright').Page} page
 */
export async function analyzeConsentModal(page) {
  return page.evaluate(
    ({ cmpList, acceptReSource, rejectReSource, ambiguousReSource }) => {
      const acceptRe = new RegExp(acceptReSource, 'i');
      const rejectRe = new RegExp(rejectReSource, 'i');
      const ambiguousRe = new RegExp(ambiguousReSource, 'i');

      function visible(el) {
        if (!el || !(el instanceof Element)) return false;
        const st = window.getComputedStyle(el);
        if (st.display === 'none' || st.visibility === 'hidden' || Number(st.opacity) === 0) return false;
        const r = el.getBoundingClientRect();
        return r.width > 2 && r.height > 2;
      }

      function textOf(el) {
        return (el.innerText || el.textContent || el.getAttribute('aria-label') || el.value || '').replace(/\s+/g, ' ').trim();
      }

      let cmp = null;
      let root = null;
      for (const entry of cmpList) {
        for (const sel of entry.selectors) {
          try {
            const el = document.querySelector(sel);
            if (el && visible(el)) {
              cmp = { id: entry.id, name: entry.name, selector: sel };
              root = el;
              break;
            }
          } catch {
            /* ignore bad selectors */
          }
        }
        if (cmp) break;
      }

      // Heuristic: fixed/sticky dialog mentioning cookies/consent.
      if (!root) {
        const candidates = Array.from(document.querySelectorAll('div, section, aside, dialog')).filter((el) => {
          if (!visible(el)) return false;
          const st = window.getComputedStyle(el);
          const pos = st.position;
          if (pos !== 'fixed' && pos !== 'sticky') return false;
          const t = textOf(el).toLowerCase();
          return /cookie|consent|privacy|gdpr/.test(t) && t.length < 4000;
        });
        candidates.sort((a, b) => b.getBoundingClientRect().height - a.getBoundingClientRect().height);
        if (candidates[0]) {
          root = candidates[0];
          cmp = { id: 'heuristic', name: 'Heuristic cookie banner', selector: null };
        }
      }

      if (!root) {
        return {
          detected: false,
          cmp: null,
          buttons: [],
          checkboxes: [],
          text_sample: '',
        };
      }

      const scope = root;
      const clickables = Array.from(scope.querySelectorAll('button, a, [role="button"], input[type="button"], input[type="submit"]')).filter(visible);
      const buttons = clickables.map((el) => {
        const label = textOf(el).slice(0, 120);
        const r = el.getBoundingClientRect();
        const st = window.getComputedStyle(el);
        const fontSize = parseFloat(st.fontSize) || 0;
        const area = Math.max(0, r.width) * Math.max(0, r.height);
        let role = 'other';
        if (acceptRe.test(label)) role = 'accept';
        else if (rejectRe.test(label)) role = 'reject';
        else if (ambiguousRe.test(label)) role = 'ambiguous';
        else if (/customize|preferences|settings|manage|choose/i.test(label)) role = 'customize';
        return {
          label,
          role,
          fontSize,
          area,
          width: Math.round(r.width),
          height: Math.round(r.height),
        };
      });

      const checks = Array.from(scope.querySelectorAll('input[type="checkbox"]')).filter(visible).map((el) => ({
        name: el.name || el.id || '',
        checked: !!el.checked,
        disabled: !!el.disabled,
        label: textOf(el.closest('label') || el.parentElement || el).slice(0, 160),
      }));

      return {
        detected: true,
        cmp,
        buttons,
        checkboxes: checks,
        text_sample: textOf(scope).slice(0, 500),
        has_reject: buttons.some((b) => b.role === 'reject'),
        has_accept: buttons.some((b) => b.role === 'accept'),
        has_customize: buttons.some((b) => b.role === 'customize'),
      };
    },
    {
      cmpList: CMP_SELECTORS,
      acceptReSource: ACCEPT_RE.source,
      rejectReSource: REJECT_RE.source,
      ambiguousReSource: AMBIGUOUS_RE.source,
    }
  );
}

/**
 * Detect IAB TCF presence (informational).
 * @param {import('playwright').Page} page
 */
export async function detectTcf(page) {
  return page.evaluate(async () => {
    const hasTcfApi = typeof window.__tcfapi === 'function';
    const hasCmp = typeof window.__cmp === 'function';
    const hasLocator = !!document.getElementById('__tcfapiLocator');
    const cookies = (document.cookie || '').split(';').map((p) => p.trim().split('=')[0]);
    const euconsent = cookies.includes('euconsent-v2') || cookies.includes('euconsent');

    /** @type {object|null} */
    let ping = null;
    /** @type {object|null} */
    let tcDataSummary = null;
    if (hasTcfApi) {
      try {
        ping = await new Promise((resolve) => {
          let done = false;
          const t = setTimeout(() => {
            if (!done) {
              done = true;
              resolve(null);
            }
          }, 800);
          try {
            window.__tcfapi('ping', 2, (res) => {
              if (done) return;
              done = true;
              clearTimeout(t);
              resolve(res || null);
            });
          } catch {
            clearTimeout(t);
            resolve(null);
          }
        });
      } catch {
        ping = null;
      }
      try {
        tcDataSummary = await new Promise((resolve) => {
          let done = false;
          const t = setTimeout(() => {
            if (!done) {
              done = true;
              resolve(null);
            }
          }, 800);
          try {
            window.__tcfapi('getTCData', 2, (data, success) => {
              if (done) return;
              done = true;
              clearTimeout(t);
              if (!success || !data) {
                resolve(null);
                return;
              }
              resolve({
                gdprApplies: !!data.gdprApplies,
                eventStatus: data.eventStatus || '',
                cmpId: data.cmpId || null,
                cmpVersion: data.cmpVersion || null,
                tcfPolicyVersion: data.tcfPolicyVersion || null,
                // Do not export full purpose/vendor bitfields (size + PII-adjacent).
              });
            });
          } catch {
            clearTimeout(t);
            resolve(null);
          }
        });
      } catch {
        tcDataSummary = null;
      }
    }

    return {
      detected: hasTcfApi || hasCmp || hasLocator || euconsent,
      has_tcfapi: hasTcfApi,
      has_cmp_v1: hasCmp,
      has_locator: hasLocator,
      has_euconsent_cookie: euconsent,
      ping: ping
        ? {
            cmpLoaded: !!ping.cmpLoaded,
            gdprApplies: !!ping.gdprApplies,
            cmpStatus: ping.cmpStatus || '',
            displayStatus: ping.displayStatus || '',
            apiVersion: ping.apiVersion || '',
          }
        : null,
      tc_data: tcDataSummary,
      note: 'TCF probe is observational — not a determination of lawful IAB TCF configuration.',
    };
  });
}

/**
 * Build dark-pattern issues from modal analysis + consent leak signals.
 * @param {object} opts
 */
export function buildDarkPatterns(opts) {
  const issues = [];
  const modal = opts.modal || {};
  const leaks = Array.isArray(opts.consent_leaks) ? opts.consent_leaks : [];
  const beforeCookies = Array.isArray(opts.before_banner_nonessential) ? opts.before_banner_nonessential : [];

  if (!modal.detected) {
    issues.push({
      type: 'no-banner-detected',
      severity: 'warning',
      description: 'No consent banner/modal was detected on the first page. Site may lack a CMP or uses an unrecognized pattern.',
    });
  } else {
    if (!modal.has_reject) {
      issues.push({
        type: 'no-reject-button',
        severity: 'critical',
        description: 'No clear Reject / Essential-only control found on the first layer of the consent UI.',
      });
    }
    const accepts = (modal.buttons || []).filter((b) => b.role === 'accept');
    const rejects = (modal.buttons || []).filter((b) => b.role === 'reject');
    if (accepts.length && rejects.length) {
      const maxAccept = Math.max(...accepts.map((b) => b.area || 0));
      const maxReject = Math.max(...rejects.map((b) => b.area || 0));
      if (maxAccept > 0 && maxReject > 0 && maxAccept >= maxReject * 1.8) {
        issues.push({
          type: 'asymmetric-prominence',
          severity: 'warning',
          description: 'Accept control appears significantly larger than Reject (visual asymmetry).',
        });
      }
      const maxAf = Math.max(...accepts.map((b) => b.fontSize || 0));
      const maxRf = Math.max(...rejects.map((b) => b.fontSize || 0));
      if (maxAf > 0 && maxRf > 0 && maxAf >= maxRf + 2) {
        issues.push({
          type: 'nudging',
          severity: 'warning',
          description: 'Accept button font size is larger than Reject (possible nudging).',
        });
      }
    }
    const ambiguous = (modal.buttons || []).filter((b) => b.role === 'ambiguous');
    if (ambiguous.length && !modal.has_reject) {
      issues.push({
        type: 'misleading-wording',
        severity: 'critical',
        description: `Ambiguous primary control label(s): ${ambiguous.map((b) => b.label).join(', ')}.`,
      });
    } else if (ambiguous.length) {
      issues.push({
        type: 'misleading-wording',
        severity: 'warning',
        description: `Ambiguous control label(s): ${ambiguous.map((b) => b.label).join(', ')}.`,
      });
    }
    const preticked = (modal.checkboxes || []).filter((c) => c.checked && !c.disabled);
    if (preticked.length) {
      issues.push({
        type: 'pre-ticked',
        severity: 'critical',
        description: `Pre-ticked checkbox(es) in the consent UI (${preticked.length}).`,
      });
    }
    if (modal.has_customize && modal.has_accept && !modal.has_reject) {
      issues.push({
        type: 'buried-reject',
        severity: 'critical',
        description: 'Customize/preferences is available but Reject is not on the first layer (possible buried refusal).',
      });
    }
    const sample = (modal.text_sample || '').toLowerCase();
    if (sample && !/(withdraw|change|manage|preference)/.test(sample)) {
      issues.push({
        type: 'missing-info',
        severity: 'warning',
        description: 'Banner text may omit clear mention of withdrawing or managing consent later.',
      });
    }
  }

  if (beforeCookies.length || leaks.length) {
    issues.push({
      type: 'auto-consent',
      severity: 'critical',
      description: `Non-essential signals observed before consent or after reject (${beforeCookies.length} pre-consent cookie hint(s), ${leaks.length} consent leak(s)).`,
    });
  }

  return issues;
}

/**
 * Technical score 0–100 across four ~25pt buckets.
 * @param {object} opts
 */
export function computeTechnicalScore(opts) {
  const issues = Array.isArray(opts.dark_patterns) ? opts.dark_patterns : [];
  const modal = opts.modal || {};
  const leaks = Array.isArray(opts.consent_leaks) ? opts.consent_leaks : [];

  const has = (type) => issues.some((i) => i.type === type);
  const crit = (type) => issues.some((i) => i.type === type && i.severity === 'critical');

  let consentValidity = 25;
  if (crit('pre-ticked')) consentValidity -= 12;
  if (crit('misleading-wording')) consentValidity -= 8;
  if (has('misleading-wording') && !crit('misleading-wording')) consentValidity -= 4;
  if (has('missing-info')) consentValidity -= 4;
  if (!modal.detected) consentValidity -= 6;
  consentValidity = Math.max(0, consentValidity);

  let easyRefusal = 25;
  if (crit('no-reject-button')) easyRefusal -= 15;
  if (crit('buried-reject')) easyRefusal -= 12;
  if (has('asymmetric-prominence')) easyRefusal -= 5;
  if (has('nudging')) easyRefusal -= 3;
  if (has('click-asymmetry')) easyRefusal -= 10;
  easyRefusal = Math.max(0, easyRefusal);

  let transparency = 25;
  if (!modal.has_customize && modal.detected) transparency -= 6;
  if (has('missing-info')) transparency -= 8;
  if (!modal.detected) transparency -= 10;
  if (modal.detected && modal.text_sample && /(third.?part|analytics|marketing|duration|expir)/i.test(modal.text_sample)) {
    transparency = Math.min(25, transparency + 2);
  }
  transparency = Math.max(0, Math.min(25, transparency));

  let cookieBehavior = 25;
  if (crit('auto-consent') || leaks.length) cookieBehavior -= Math.min(18, 6 + leaks.length * 2);
  if (has('auto-consent') && !leaks.length) cookieBehavior -= 8;
  cookieBehavior = Math.max(0, cookieBehavior);

  const total = consentValidity + easyRefusal + transparency + cookieBehavior;
  let grade = 'F';
  if (total >= 90) grade = 'A';
  else if (total >= 75) grade = 'B';
  else if (total >= 55) grade = 'C';
  else if (total >= 35) grade = 'D';

  return {
    total,
    grade,
    breakdown: {
      consent_validity: consentValidity,
      easy_refusal: easyRefusal,
      transparency,
      cookie_behavior: cookieBehavior,
    },
    disclaimer:
      'Technical automated checks only — not a GDPR compliance determination or legal audit.',
  };
}

/**
 * Tag requests with tracker DB when classifier left them thin.
 * @param {object[]} rows
 */
export function enrichTrackerRows(rows) {
  return (rows || []).map((row) => {
    const host = row.host || row.url || row.name || '';
    const hit = matchTrackerHost(host);
    if (!hit) return row;
    return {
      ...row,
      provider: row.provider || hit.provider,
      category: row.category && row.category !== 'unclassified' ? row.category : hit.category,
      tracker_db: true,
    };
  });
}
