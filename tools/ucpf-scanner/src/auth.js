/**
 * Simple API-key auth + rate limiting.
 *
 * Production: set UCPF_SCANNER_API_KEYS. Unauthenticated access is only
 * allowed when ALLOW_LOCAL=1 AND the client is loopback.
 */

import crypto from 'node:crypto';
import { config, getAdminKeys } from './config.js';
import { fingerprintKey } from './persist.js';

/** @type {Map<string, number[]>} */
const hits = new Map();

/**
 * @param {import('express').Request} req
 * @returns {boolean}
 */
function isLoopback(req) {
  const raw = String(req.ip || req.socket?.remoteAddress || '');
  const ip = raw.replace(/^::ffff:/, '');
  return ip === '127.0.0.1' || ip === '::1' || ip === 'localhost';
}

/**
 * Constant-time membership check for API keys (hashes both sides when lengths differ).
 * @param {string} token
 * @param {string[]} keys
 * @returns {boolean}
 */
export function keyListIncludes(token, keys) {
  if (!token || !keys?.length) return false;
  const tokenBuf = Buffer.from(String(token), 'utf8');
  for (const candidate of keys) {
    const candBuf = Buffer.from(String(candidate), 'utf8');
    if (tokenBuf.length === candBuf.length) {
      if (crypto.timingSafeEqual(tokenBuf, candBuf)) return true;
      continue;
    }
    // Different lengths: compare SHA-256 digests to avoid early length leaks.
    const a = crypto.createHash('sha256').update(tokenBuf).digest();
    const b = crypto.createHash('sha256').update(candBuf).digest();
    if (crypto.timingSafeEqual(a, b)) return true;
  }
  return false;
}

/**
 * Extract bearer / header API key from request.
 * @param {import('express').Request} req
 * @returns {string}
 */
export function extractApiKey(req) {
  const header = req.get('authorization') || '';
  const keyHeader = req.get('x-ucpf-scanner-key') || '';
  let token = keyHeader;
  if (header.toLowerCase().startsWith('bearer ')) {
    token = header.slice(7).trim();
  }
  return String(token || '').trim();
}

/**
 * @param {import('express').Request} req
 * @param {import('express').Response} res
 * @param {import('express').NextFunction} next
 */
export function requireAuth(req, res, next) {
  if (config.apiKeys.length) {
    const token = extractApiKey(req);
    if (!token || !keyListIncludes(token, config.apiKeys)) {
      return res.status(401).json({ error: 'Unauthorized' });
    }
    req.ucpfApiKey = token;
    req.ucpfKeyFp = fingerprintKey(token);
    req.ucpfIsAdmin = keyListIncludes(token, getAdminKeys());
    return next();
  }

  // No keys configured: allow only explicit local loopback mode.
  if (config.allowUnauthenticatedLocal && isLoopback(req)) {
    req.ucpfApiKey = '';
    req.ucpfKeyFp = fingerprintKey('local');
    req.ucpfIsAdmin = true;
    return next();
  }

  return res.status(401).json({
    error: 'Unauthorized',
    hint: 'Set UCPF_SCANNER_API_KEYS, or use UCPF_SCANNER_ALLOW_LOCAL=1 from loopback only.',
  });
}

/**
 * @param {import('express').Request} req
 * @param {import('express').Response} res
 * @param {import('express').NextFunction} next
 */
export function rateLimit(req, res, next) {
  // Status polls must not burn the budget — WP hits these every ~4s during a scan.
  const path = String(req.path || '');
  if (req.method === 'GET' && (/^\/v1\/scans\/[^/]+$/.test(path) || path === '/v1/scans' || path === '/health')) {
    return next();
  }

  const ip = req.ip || req.socket.remoteAddress || 'unknown';
  const now = Date.now();
  const windowMs = config.rateLimitWindowMs;
  const list = (hits.get(ip) || []).filter((t) => now - t < windowMs);
  list.push(now);
  hits.set(ip, list);
  if (list.length > config.rateLimitMax) {
    return res.status(429).json({
      error: 'Rate limit exceeded',
      hint: 'Wait a minute, or raise UCPF_SCANNER_RATE_MAX. GET job polls are not counted.',
    });
  }
  return next();
}
