/**
 * Sanitize scan options from untrusted HTTP clients.
 */

/**
 * Remove filesystem / operator-only keys from HTTP-supplied options.
 * Recipes may only come from UCPF_SCANNER_RECIPE env or CLI --recipe.
 *
 * @param {Record<string, unknown>} options
 * @returns {Record<string, unknown>}
 */
export function sanitizeHttpScanOptions(options) {
  if (!options || typeof options !== 'object') return {};
  const out = { ...options };
  delete out.recipeFile;
  delete out.recipe;
  delete out.recipe_file;
  return out;
}
