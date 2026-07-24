/**
 * Safe interaction recipes — never purchase, never submit forms by default.
 */

import fs from 'node:fs';

/**
 * Default safe probes (time-capped by caller).
 */
export const DEFAULT_SAFE_ACTIONS = [
  { action: 'scroll', note: 'lazy-load' },
  { action: 'click', selector: '[aria-haspopup="dialog"], .ucpf-fab, #userwayAccessibilityIcon', optional: true },
  { action: 'focus', selector: 'form input, form textarea', optional: true },
  { action: 'click', selector: 'video, .wp-block-video, .elementor-widget-video', optional: true },
  { action: 'click', selector: '.wpgmza-map, .gm-style, [class*="map"]', optional: true },
];

/**
 * Load optional JSON recipe file.
 * @param {string} filePath
 * @returns {object[]}
 */
export function loadRecipeFile(filePath) {
  if (!filePath || !fs.existsSync(filePath)) return [];
  try {
    const raw = JSON.parse(fs.readFileSync(filePath, 'utf8'));
    const list = Array.isArray(raw) ? raw : raw.interactions || [];
    return list.filter((row) => row && row.action);
  } catch {
    return [];
  }
}

/**
 * @param {import('playwright').Page} page
 * @param {object[]} actions
 * @param {number} budgetMs
 */
export async function runSafeRecipe(page, actions, budgetMs) {
  const deadline = Date.now() + Math.max(500, budgetMs || 8000);
  const ran = [];
  for (const step of actions || []) {
    if (Date.now() > deadline) break;
    const action = step.action;
    try {
      if (action === 'scroll') {
        await page.evaluate(() => window.scrollBy(0, Math.min(900, window.innerHeight)));
        ran.push({ action: 'scroll', ok: true });
      } else if (action === 'focus' && step.selector) {
        const loc = page.locator(step.selector).first();
        if (await loc.isVisible({ timeout: 400 })) {
          await loc.focus({ timeout: 500 });
          ran.push({ action: 'focus', selector: step.selector, ok: true });
        }
      } else if (action === 'click' && step.selector) {
        // Never click purchase / submit / logout
        if (/submit|purchase|buy|checkout|logout|delete|remove|add-to-cart/i.test(step.selector)) {
          ran.push({ action: 'click', selector: step.selector, ok: false, skipped: 'unsafe_selector' });
          continue;
        }
        const loc = page.locator(step.selector).first();
        if (await loc.isVisible({ timeout: 400 })) {
          await loc.click({ timeout: 800, trial: false });
          ran.push({ action: 'click', selector: step.selector, ok: true });
          await page.waitForTimeout(300);
        }
      }
    } catch (err) {
      ran.push({ action, selector: step.selector || '', ok: false, error: String(err.message || err) });
    }
  }
  return ran;
}
