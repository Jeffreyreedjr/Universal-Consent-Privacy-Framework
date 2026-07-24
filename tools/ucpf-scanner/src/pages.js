/**
 * Representative page selection + feature tagging.
 */

const FEATURE_RULES = [
  { feature: 'checkout', re: /(checkout|cart|payment|basket)/i, priority: 'high' },
  { feature: 'form', re: /(contact|form|enquiry|inquiry|support|register)/i, priority: 'high' },
  { feature: 'google_maps', re: /(location|map|store-locator|find-us)/i, priority: 'high' },
  { feature: 'shop', re: /(shop|product|store|woocommerce)/i, priority: 'high' },
  { feature: 'video', re: /(video|watch|media|youtube|vimeo)/i, priority: 'medium' },
  { feature: 'privacy', re: /(privacy|cookie|consent|gdpr|ccpa)/i, priority: 'medium' },
  { feature: 'account', re: /(account|login|my-account|signin)/i, priority: 'medium' },
  { feature: 'blog', re: /(blog|news|stories|article)/i, priority: 'low' },
];

/**
 * @param {string} path
 */
export function tagPath(path) {
  const p = String(path || '/');
  /** @type {string[]} */
  const features = [];
  let priority = 'low';
  for (const rule of FEATURE_RULES) {
    if (rule.re.test(p)) {
      features.push(rule.feature);
      if (rule.priority === 'high') priority = 'high';
      else if (rule.priority === 'medium' && priority !== 'high') priority = 'medium';
    }
  }
  if (p === '/' || p === '') {
    features.push('home');
    priority = 'high';
  }
  return { page: p, features, priority };
}

/**
 * Pick representative paths for scan levels.
 * @param {string[]} paths
 * @param {'quick'|'standard'|'compliance'} level
 */
export function selectRepresentativePages(paths, level) {
  const tagged = [...new Set(paths || ['/'])].map(tagPath);
  const limit = level === 'quick' ? 8 : level === 'compliance' ? 80 : 40;

  const score = (t) => {
    if (t.priority === 'high') return 0;
    if (t.priority === 'medium') return 1;
    return 2;
  };

  tagged.sort((a, b) => score(a) - score(b) || a.page.localeCompare(b.page));

  // Ensure diversity of features
  /** @type {string[]} */
  const out = [];
  const seenFeat = new Set();
  for (const t of tagged) {
    if (out.length >= limit) break;
    const novel = t.features.some((f) => !seenFeat.has(f));
    if (novel || out.length < Math.min(6, limit)) {
      out.push(t.page);
      t.features.forEach((f) => seenFeat.add(f));
    }
  }
  for (const t of tagged) {
    if (out.length >= limit) break;
    if (!out.includes(t.page)) out.push(t.page);
  }
  if (!out.includes('/')) out.unshift('/');
  return { paths: out.slice(0, limit), tagged: tagged.filter((t) => out.includes(t.page)) };
}
