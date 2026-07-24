/**
 * Scanner configuration (env overrides).
 */

export const config = {
  host: process.env.UCPF_SCANNER_HOST || '0.0.0.0',
  port: Number(process.env.UCPF_SCANNER_PORT || 3847),
  apiKeys: (process.env.UCPF_SCANNER_API_KEYS || process.env.UCPF_SCANNER_API_KEY || '')
    .split(',')
    .map((k) => k.trim())
    .filter(Boolean),
  /** Allow unauthenticated local use only when no keys configured and bind is loopback-ish. */
  allowUnauthenticatedLocal: process.env.UCPF_SCANNER_ALLOW_LOCAL === '1',
  maxPagesPerScan: Number(process.env.UCPF_SCANNER_MAX_PAGES || 100),
  maxConcurrentScans: Number(process.env.UCPF_SCANNER_MAX_CONCURRENT || 2),
  maxRedirects: Number(process.env.UCPF_SCANNER_MAX_REDIRECTS || 5),
  navigationTimeoutMs: Number(process.env.UCPF_SCANNER_NAV_TIMEOUT_MS || 25000),
  browserTimeoutMs: Number(process.env.UCPF_SCANNER_BROWSER_TIMEOUT_MS || 600000),
  settleMs: Number(process.env.UCPF_SCANNER_SETTLE_MS || 4000),
  /** Delay between pages (ms) — helps avoid Defender / CF lockouts. */
  pageGapMs: Number(process.env.UCPF_SCANNER_PAGE_GAP_MS || 1500),
  rateLimitWindowMs: Number(process.env.UCPF_SCANNER_RATE_WINDOW_MS || 60000),
  rateLimitMax: Number(process.env.UCPF_SCANNER_RATE_MAX || 10),
  /** Auto-delete completed job reports after this many ms. */
  reportTtlMs: Number(process.env.UCPF_SCANNER_REPORT_TTL_MS || 3600000),
  headless: process.env.UCPF_SCANNER_HEADED !== '1',
};
