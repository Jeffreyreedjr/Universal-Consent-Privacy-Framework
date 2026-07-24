/**
 * Scanner node advertisement metadata (agency federation).
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

function pkgVersion() {
  try {
    const p = JSON.parse(readFileSync(path.join(__dirname, '..', 'package.json'), 'utf8'));
    return p.version || '0.0.0';
  } catch {
    return '0.0.0';
  }
}

/**
 * @returns {object}
 */
export function getNodeInfo() {
  return {
    node_id: process.env.UCPF_SCANNER_NODE_ID || 'local',
    regions: String(process.env.UCPF_SCANNER_REGIONS || 'US')
      .split(',')
      .map((s) => s.trim())
      .filter(Boolean),
    browsers: ['chromium'],
    mobile_emulation: process.env.UCPF_SCANNER_MOBILE === '1',
    gpc_capable: true,
    scanner_version: pkgVersion(),
    schema: 'ucpf-playwright-scan/2.0',
  };
}
