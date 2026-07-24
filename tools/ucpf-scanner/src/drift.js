/**
 * Drift / regression vs previous report (agency 1.3).
 */

import { normalizeRequestKey } from './hash.js';

/**
 * @param {object} previous
 * @param {object} current
 */
export function compareReports(previous, current) {
  /** @type {object[]} */
  const alerts = [];
  if (!previous || !current) {
    return { alerts, note: 'No previous baseline — first scan.' };
  }

  const prevHosts = new Set(
    (previous.requests || []).map((r) => (typeof r === 'string' ? r : r.host || r.key || '')).filter(Boolean)
  );
  const curHosts = new Set(
    (current.requests || []).map((r) => (typeof r === 'string' ? r : r.host || r.key || '')).filter(Boolean)
  );

  for (const h of curHosts) {
    const key = normalizeRequestKey(h);
    let found = false;
    for (const p of prevHosts) {
      if (normalizeRequestKey(p) === key) {
        found = true;
        break;
      }
    }
    if (!found && /google-analytics|facebook|doubleclick|tiktok|hotjar/i.test(key)) {
      alerts.push({
        type: 'new_third_party_host',
        name: key,
        severity: 'high',
        reason: 'New tracking-like host vs baseline.',
      });
    } else if (!found) {
      alerts.push({
        type: 'new_request_key',
        name: key,
        severity: 'medium',
        reason: 'New request key vs baseline.',
      });
    }
  }

  const prevCookies = new Set((previous.cookies || []).map((c) => String(c.name || '').toLowerCase()));
  for (const c of current.cookies || []) {
    const n = String(c.name || '').toLowerCase();
    if (n && !prevCookies.has(n)) {
      alerts.push({
        type: 'new_cookie',
        name: c.name,
        severity: 'medium',
        reason: 'Cookie not in previous baseline.',
      });
    }
  }

  const prevFails = (previous.findings_summary && previous.findings_summary.fail) || 0;
  const curFails = (current.findings_summary && current.findings_summary.fail) || 0;
  if (curFails > prevFails) {
    alerts.push({
      type: 'findings_regression',
      name: 'findings_fail_count',
      severity: 'high',
      reason: `Fail findings increased (${prevFails} → ${curFails}).`,
    });
  }

  const prevLeaks = Array.isArray(previous.consent_leaks) ? previous.consent_leaks.length : 0;
  const curLeaks = Array.isArray(current.consent_leaks) ? current.consent_leaks.length : 0;
  if (curLeaks > prevLeaks) {
    alerts.push({
      type: 'consent_leak_regression',
      name: 'consent_leaks',
      severity: 'high',
      reason: `Consent leaks increased (${prevLeaks} → ${curLeaks}).`,
    });
  }

  return {
    alerts: alerts.slice(0, 100),
    note: 'Technical drift only — not a legal determination.',
  };
}
