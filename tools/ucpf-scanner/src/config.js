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
  // WP polls ~every 4s; default of 10/min blocks the next scan. Allow headroom for polls + starts.
  rateLimitMax: Number(process.env.UCPF_SCANNER_RATE_MAX || 180),
  /** Auto-delete completed job reports after this many ms. */
  reportTtlMs: Number(process.env.UCPF_SCANNER_REPORT_TTL_MS || 3600000),
  headless: process.env.UCPF_SCANNER_HEADED !== '1',
};
