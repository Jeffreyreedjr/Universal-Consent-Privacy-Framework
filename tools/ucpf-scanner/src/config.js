/**
 * Scanner configuration (env overrides).
 * Loads `.env` from cwd or package root when present (does not override existing env).
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

function loadEnvFile() {
	const candidates = [
		path.join( process.cwd(), '.env' ),
		path.join( path.dirname( fileURLToPath( import.meta.url ) ), '..', '.env' ),
	];
	for ( const file of candidates ) {
		if ( ! fs.existsSync( file ) ) {
			continue;
		}
		const text = fs.readFileSync( file, 'utf8' );
		for ( const line of text.split( '\n' ) ) {
			const trimmed = line.trim();
			if ( ! trimmed || trimmed.startsWith( '#' ) ) {
				continue;
			}
			const eq = trimmed.indexOf( '=' );
			if ( eq <= 0 ) {
				continue;
			}
			const key = trimmed.slice( 0, eq ).trim();
			let val = trimmed.slice( eq + 1 ).trim();
			if (
				( val.startsWith( '"' ) && val.endsWith( '"' ) ) ||
				( val.startsWith( "'" ) && val.endsWith( "'" ) )
			) {
				val = val.slice( 1, -1 );
			}
			if ( process.env[ key ] === undefined ) {
				process.env[ key ] = val;
			}
		}
		break;
	}
}

loadEnvFile();

const __dirname = path.dirname( fileURLToPath( import.meta.url ) );
const defaultDataDir = path.join( __dirname, '..', 'data' );

export const config = {
  // Default loopback; set UCPF_SCANNER_HOST=0.0.0.0 only behind a trusted reverse proxy.
  host: process.env.UCPF_SCANNER_HOST || '127.0.0.1',
  port: Number(process.env.UCPF_SCANNER_PORT || 3847),
  apiKeys: (process.env.UCPF_SCANNER_API_KEYS || process.env.UCPF_SCANNER_API_KEY || '')
    .split(',')
    .map((k) => k.trim())
    .filter(Boolean),
  /** Keys allowed to cancel-all / reset slots / list all jobs (comma-separated). No first-key fallback. */
  adminKeys: (process.env.UCPF_SCANNER_ADMIN_KEYS || '')
    .split(',')
    .map((k) => k.trim())
    .filter(Boolean),
  /** Allow unauthenticated local use only when no keys configured and bind is loopback-ish. */
  allowUnauthenticatedLocal: process.env.UCPF_SCANNER_ALLOW_LOCAL === '1',
  /**
   * Trust X-Forwarded-For for req.ip / rate limits. Off by default — enable only
   * behind a trusted reverse proxy (UCPF_SCANNER_TRUST_PROXY=1).
   */
  trustProxy: process.env.UCPF_SCANNER_TRUST_PROXY === '1',
  maxPagesPerScan: Number(process.env.UCPF_SCANNER_MAX_PAGES || 100),
  maxConcurrentScans: Math.max(1, Number(process.env.UCPF_SCANNER_MAX_CONCURRENT || 2)),
  /** Waiting queue depth when all Chromium slots are busy (agency fleets). */
  maxQueue: Math.max(0, Number(process.env.UCPF_SCANNER_MAX_QUEUE || 200)),
  /** Max running jobs per API key fingerprint. */
  maxRunningPerKey: Math.max(1, Number(process.env.UCPF_SCANNER_MAX_RUNNING_PER_KEY || 1)),
  /** Max queued (waiting) jobs per API key fingerprint. */
  maxQueuedPerKey: Math.max(0, Number(process.env.UCPF_SCANNER_MAX_QUEUED_PER_KEY || 2)),
  maxRedirects: Number(process.env.UCPF_SCANNER_MAX_REDIRECTS || 5),
  navigationTimeoutMs: Number(process.env.UCPF_SCANNER_NAV_TIMEOUT_MS || 25000),
  /** Preferred whole-job Chromium budget (ms). Split across sessions; each session also gets a page-count floor. */
  browserTimeoutMs: Number(process.env.UCPF_SCANNER_BROWSER_TIMEOUT_MS || 1800000),
  settleMs: Number(process.env.UCPF_SCANNER_SETTLE_MS || 2500),
  pageGapMs: Number(process.env.UCPF_SCANNER_PAGE_GAP_MS || 600),
  rateLimitWindowMs: Number(process.env.UCPF_SCANNER_RATE_WINDOW_MS || 60000),
  rateLimitMax: Number(process.env.UCPF_SCANNER_RATE_MAX || 180),
  /** Auto-delete finished job reports after this many ms (from completion, not create). */
  reportTtlMs: Number(process.env.UCPF_SCANNER_REPORT_TTL_MS || 3600000),
  /** Directory for durable job/queue SQLite (or JSON fallback). */
  dataDir: process.env.UCPF_SCANNER_DATA_DIR || defaultDataDir,
  headless: process.env.UCPF_SCANNER_HEADED !== '1',
  /**
   * Fail / reclaim running jobs with no progress update for this long (ms).
   * Prevents a hung Chromium from clogging the queue forever (position 1 of 1 at 0%).
   * Default: browser budget + 2 minutes (min 10 minutes).
   */
  staleJobMs: Math.max(
    600000,
    Number(process.env.UCPF_SCANNER_STALE_JOB_MS || 0) ||
      Number(process.env.UCPF_SCANNER_BROWSER_TIMEOUT_MS || 1800000) + 120000
  ),
};

/** Effective admin keys — only explicitly configured UCPF_SCANNER_ADMIN_KEYS. */
export function getAdminKeys() {
  return config.adminKeys.length ? [...config.adminKeys] : [];
}
