/**
 * CI exit codes for ucpf-scan.
 * 0 = passed, 1 = policy violation, 2 = incomplete, 3 = scanner error
 */

export const EXIT = {
  PASS: 0,
  VIOLATION: 1,
  INCOMPLETE: 2,
  ERROR: 3,
};

/**
 * @param {object} report
 * @returns {number}
 */
export function exitCodeForReport(report) {
  if (!report || typeof report !== 'object') return EXIT.ERROR;
  if (report.incomplete) return EXIT.INCOMPLETE;
  const summary = report.findings_summary || {};
  if (typeof summary.fail === 'number' && summary.fail > 0) return EXIT.VIOLATION;
  const findings = report.findings || [];
  const fails = findings.filter((f) =>
    [
      'incorrectly_loaded_before_consent',
      'still_loaded_after_reject',
      'still_loaded_after_dns',
      'still_loaded_after_gpc',
      'category_mismatch',
    ].includes(f.finding)
  );
  if (fails.length) return EXIT.VIOLATION;
  // Legacy: consent_leaks still count as violations for CI
  if (Array.isArray(report.consent_leaks) && report.consent_leaks.length > 0) return EXIT.VIOLATION;
  return EXIT.PASS;
}
