/**
 * Session profiles for privacy-behavior scans.
 */

/** @typedef {{ id: string, label: string, consent: 'none'|'reject'|'accept'|'analytics'|'functional'|'revoke', gpc?: boolean, dns?: boolean, reuseAccept?: boolean, returning?: boolean, expired?: boolean }} SessionProfile */

/** Core triad — always run */
export const PROFILE_CORE = /** @type {SessionProfile[]} */ ([
  { id: 'no_consent', label: 'Fresh visitor', consent: 'none' },
  { id: 'reject_all', label: 'Reject all', consent: 'reject' },
  { id: 'accept_all', label: 'Accept all', consent: 'accept' },
]);

/** Extended differential — Standard / Compliance QA */
export const PROFILE_EXTENDED = /** @type {SessionProfile[]} */ ([
  { id: 'analytics_only', label: 'Analytics only', consent: 'analytics' },
  { id: 'functional_only', label: 'Functional only', consent: 'functional' },
  { id: 'revoke', label: 'Revoke after accept', consent: 'revoke', reuseAccept: true },
  { id: 'returning_accept', label: 'Returning visitor (accepted)', consent: 'accept', returning: true },
  { id: 'gpc_on', label: 'GPC enabled', consent: 'none', gpc: true },
  { id: 'gpc_off', label: 'GPC baseline', consent: 'none', gpc: false },
  { id: 'dns_opt_out', label: 'DNS opt-out', consent: 'none', dns: true },
]);

/**
 * @param {'quick'|'standard'|'compliance'} level
 * @returns {SessionProfile[]}
 */
export function profilesForLevel(level) {
  if (level === 'quick') {
    return PROFILE_CORE.slice(0, 2); // fresh + reject
  }
  if (level === 'compliance') {
    return [...PROFILE_CORE, ...PROFILE_EXTENDED];
  }
  // standard: core + revoke + GPC on + DNS opt-out
  return [
    ...PROFILE_CORE,
    { id: 'revoke', label: 'Revoke after accept', consent: 'revoke', reuseAccept: true },
    { id: 'gpc_on', label: 'GPC enabled', consent: 'none', gpc: true },
    { id: 'dns_opt_out', label: 'DNS opt-out', consent: 'none', dns: true },
  ];
}
