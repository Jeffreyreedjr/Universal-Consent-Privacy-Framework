/**
 * Privacy signal probes (GPC, GCM params, GPP stub). Observational only.
 */

/**
 * @param {import('playwright').Page} page
 */
export async function probeGpcNavigator(page) {
  try {
    return await page.evaluate(() => ({
      navigator_gpc:
        typeof navigator.globalPrivacyControl === 'boolean' ? navigator.globalPrivacyControl : null,
    }));
  } catch {
    return { navigator_gpc: null };
  }
}

/**
 * Scan collected request URLs for Google Consent Mode evidence params.
 * @param {string[]} requestKeys
 */
export function probeConsentModeParams(requestKeys) {
  /** @type {object[]} */
  const hits = [];
  for (const raw of requestKeys || []) {
    const s = String(raw);
    if (!/google-analytics|googletagmanager|g\/collect|doubleclick/i.test(s)) continue;
    // Keys are host+path without query — look for known path markers only
    if (/g\/collect|\/collect/i.test(s)) {
      hits.push({
        request: s,
        note: 'Collect endpoint observed. gcs/gcd/dma live in query strings — inspect live Network when validating Consent Mode; this scan stores path keys only.',
      });
    }
  }
  return {
    observed_collect_endpoints: hits.length,
    samples: hits.slice(0, 10),
    disclaimer:
      'Observational only — presence of collect endpoints or gcs/gcd/dma does not prove lawful Consent Mode configuration.',
  };
}

/**
 * Lightweight GPP probe.
 * @param {import('playwright').Page} page
 */
export async function probeGpp(page) {
  try {
    return await page.evaluate(() => {
      const has = typeof window.__gpp === 'function';
      return {
        detected: has,
        note: has
          ? 'window.__gpp present — decode sections manually for jurisdiction coverage.'
          : 'No __gpp API detected.',
      };
    });
  } catch {
    return { detected: false };
  }
}
