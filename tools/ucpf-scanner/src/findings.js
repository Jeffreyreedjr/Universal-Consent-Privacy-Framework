/**
 * Consent differential findings — compare inventory across sessions.
 */

import { normalizeRequestKey } from './hash.js';
import { shouldIgnoreCookieLeak, shouldIgnoreUrlLeak } from './noise.js';

/** Fail findings for CI / pass UI */
export const FAIL_FINDINGS = [
  'incorrectly_loaded_before_consent',
  'still_loaded_after_reject',
  'still_loaded_after_dns',
  'still_loaded_after_gpc',
  'category_mismatch',
];

const TRACKING_RE =
  /google-analytics|googletagmanager|g\/collect|doubleclick|facebook|hotjar|clarity|cloudflareinsights|segment\.|mixpanel|tiktok|linkedin|snapchat|bing\.com\/bat|pinterest\.com\/ct|twitter\.com|t\.co\/i\/adsct/i;

/**
 * @param {Record<string, object>} sessions sessionName → session payload
 * @param {object[]} cookies classified cookie rows with contexts
 * @returns {object[]}
 */
export function buildFindings(sessions, cookies) {
  /** @type {object[]} */
  const findings = [];
  const no = sessions.no_consent || {};
  const rej = sessions.reject_all || {};
  const acc = sessions.accept_all || {};
  const revoke = sessions.revoke || {};
  const dns = sessions.dns_opt_out || {};
  const gpc = sessions.gpc_on || {};

  const cookieByName = new Map();
  for (const c of cookies || []) {
    if (c?.name) cookieByName.set(String(c.name).toLowerCase(), c);
  }

  const reqNo = new Set((no.requests || []).map(normalizeRequestKey));
  const reqRej = new Set((rej.requests || []).map(normalizeRequestKey));
  const reqAcc = new Set((acc.requests || []).map(normalizeRequestKey));
  const reqRev = new Set((revoke.requests || []).map(normalizeRequestKey));
  const reqDns = new Set((dns.requests || []).map(normalizeRequestKey));
  const reqGpc = new Set((gpc.requests || []).map(normalizeRequestKey));

  const namesNo = new Set((no.cookies || []).map((c) => String(c.name || '').toLowerCase()).filter(Boolean));
  const namesRej = new Set((rej.cookies || []).map((c) => String(c.name || '').toLowerCase()).filter(Boolean));
  const namesAcc = new Set((acc.cookies || []).map((c) => String(c.name || '').toLowerCase()).filter(Boolean));
  const namesRev = new Set((revoke.cookies || []).map((c) => String(c.name || '').toLowerCase()).filter(Boolean));
  const namesDns = new Set((dns.cookies || []).map((c) => String(c.name || '').toLowerCase()).filter(Boolean));
  const namesGpc = new Set((gpc.cookies || []).map((c) => String(c.name || '').toLowerCase()).filter(Boolean));

  const push = (row) => {
    if (!row || !row.name) return;
    findings.push(row);
  };

  for (const [name, meta] of cookieByName) {
    if (shouldIgnoreCookieLeak(meta.name)) continue;
    const treatment = meta.treatment || '';
    const category = meta.category || '';
    const consentRequired =
      treatment === 'consent' ||
      (category && category !== 'necessary' && category !== 'unclassified' && treatment !== 'necessary');
    const inNo = namesNo.has(name);
    const inRej = namesRej.has(name);
    const inAcc = namesAcc.has(name);
    const inRev = namesRev.has(name);
    const inDns = namesDns.has(name);
    const inGpc = namesGpc.has(name);

    if (consentRequired && inNo) {
      push({
        type: 'cookie',
        name: meta.name,
        provider: meta.provider || '',
        category,
        finding: 'incorrectly_loaded_before_consent',
        severity: 'high',
        sessions: ['no_consent'],
        reason: 'Consent-required cookie present before any consent action.',
      });
    }
    if (consentRequired && inNo && inRej) {
      push({
        type: 'cookie',
        name: meta.name,
        provider: meta.provider || '',
        category,
        finding: 'still_loaded_after_reject',
        severity: 'high',
        sessions: ['no_consent', 'reject_all'],
        reason: 'Consent-required cookie still present after reject_all.',
      });
    }
    if (consentRequired && Object.keys(dns).length && inDns) {
      push({
        type: 'cookie',
        name: meta.name,
        provider: meta.provider || '',
        category,
        finding: 'still_loaded_after_dns',
        severity: 'high',
        sessions: ['dns_opt_out'],
        reason: 'Consent-required cookie still present under DNS opt-out cookie.',
      });
    }
    if (consentRequired && Object.keys(gpc).length && inGpc) {
      push({
        type: 'cookie',
        name: meta.name,
        provider: meta.provider || '',
        category,
        finding: 'still_loaded_after_gpc',
        severity: 'high',
        sessions: ['gpc_on'],
        reason: 'Consent-required cookie still present with GPC enabled.',
      });
    }
    if (consentRequired && inAcc && !inNo) {
      push({
        type: 'cookie',
        name: meta.name,
        provider: meta.provider || '',
        category,
        finding: 'correctly_loaded_after_accept',
        severity: 'info',
        sessions: ['accept_all'],
        reason: 'Appeared after accept_all and not in no_consent.',
      });
    }
    if (!consentRequired && category === 'necessary' && inNo) {
      push({
        type: 'cookie',
        name: meta.name,
        provider: meta.provider || '',
        category,
        finding: 'blocked_before_consent',
        severity: 'info',
        sessions: ['no_consent'],
        reason: 'Necessary/classified cookie present pre-consent (expected when necessary).',
      });
    }
    if (consentRequired && inAcc && Object.keys(revoke).length && !inRev) {
      push({
        type: 'cookie',
        name: meta.name,
        provider: meta.provider || '',
        category,
        finding: 'removed_after_revocation',
        severity: 'info',
        sessions: ['accept_all', 'revoke'],
        reason: 'Absent after revoke session following accept.',
      });
    }
    if (consentRequired && inAcc && Object.keys(revoke).length && inRev) {
      push({
        type: 'cookie',
        name: meta.name,
        provider: meta.provider || '',
        category,
        finding: 'still_loaded_after_reject',
        severity: 'high',
        sessions: ['revoke'],
        reason: 'Still present after revoke session.',
      });
    }
  }

  const trackHost = (key, setNo, setRej, setAcc) => {
    if (shouldIgnoreUrlLeak(key)) return;
    const inNo = setNo.has(key);
    const inRej = setRej.has(key);
    const inAcc = setAcc.has(key);
    const looksTracking = TRACKING_RE.test(key);
    if (!looksTracking) return;
    if (inNo) {
      push({
        type: 'request',
        name: key,
        provider: '',
        category: 'analytics',
        finding: 'incorrectly_loaded_before_consent',
        severity: 'high',
        sessions: ['no_consent'],
        reason: 'Tracking-like request observed before consent.',
      });
    }
    if (inNo && inRej) {
      push({
        type: 'request',
        name: key,
        provider: '',
        category: 'analytics',
        finding: 'still_loaded_after_reject',
        severity: 'high',
        sessions: ['no_consent', 'reject_all'],
        reason: 'Tracking-like request still present after reject_all.',
      });
    }
    if (inAcc && !inNo) {
      push({
        type: 'request',
        name: key,
        provider: '',
        category: 'analytics',
        finding: 'correctly_loaded_after_accept',
        severity: 'info',
        sessions: ['accept_all'],
        reason: 'Tracking-like request appeared after accept only.',
      });
    }
  };

  const allKeys = new Set([...reqNo, ...reqRej, ...reqAcc, ...reqRev, ...reqDns, ...reqGpc]);
  for (const key of allKeys) {
    trackHost(key, reqNo, reqRej, reqAcc);
    if (shouldIgnoreUrlLeak(key) || !TRACKING_RE.test(key)) continue;
    if (Object.keys(dns).length && reqDns.has(key)) {
      push({
        type: 'request',
        name: key,
        provider: '',
        category: 'analytics',
        finding: 'still_loaded_after_dns',
        severity: 'high',
        sessions: ['dns_opt_out'],
        reason: 'Tracking-like request still present under DNS opt-out cookie.',
      });
    }
    if (Object.keys(gpc).length && reqGpc.has(key)) {
      push({
        type: 'request',
        name: key,
        provider: '',
        category: 'analytics',
        finding: 'still_loaded_after_gpc',
        severity: 'high',
        sessions: ['gpc_on'],
        reason: 'Tracking-like request still present with GPC enabled.',
      });
    }
  }

  // Dedupe by type|name|finding
  const seen = new Set();
  return findings.filter((row) => {
    const k = `${row.type}|${row.name}|${row.finding}`;
    if (seen.has(k)) return false;
    seen.add(k);
    return true;
  });
}

/**
 * Summarize findings for CLI / UI.
 * @param {object[]} findings
 */
export function summarizeFindings(findings) {
  const list = findings || [];
  const fails = list.filter((f) => FAIL_FINDINGS.includes(f.finding));
  const info = list.filter((f) => !fails.includes(f));
  return {
    total: list.length,
    fail: fails.length,
    info: info.length,
    pass: fails.length === 0,
  };
}
