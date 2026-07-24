/**
 * Simple API-key auth + rate limiting.
 *
 * Production: set UCPF_SCANNER_API_KEYS. Unauthenticated access is only
 * allowed when ALLOW_LOCAL=1 AND the client is loopback.
 */

import { config } from './config.js';

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
 * @param {import('express').Request} req
 * @param {import('express').Response} res
 * @param {import('express').NextFunction} next
 */
export function requireAuth(req, res, next) {
  if (config.apiKeys.length) {
    const header = req.get('authorization') || '';
    const keyHeader = req.get('x-ucpf-scanner-key') || '';
    let token = keyHeader;
    if (header.toLowerCase().startsWith('bearer ')) {
      token = header.slice(7).trim();
    }
    if (!token || !config.apiKeys.includes(token)) {
      return res.status(401).json({ error: 'Unauthorized' });
    }
    return next();
  }

  // No keys configured: allow only explicit local loopback mode.
  if (config.allowUnauthenticatedLocal && isLoopback(req)) {
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
  const ip = req.ip || req.socket.remoteAddress || 'unknown';
  const now = Date.now();
  const windowMs = config.rateLimitWindowMs;
  const list = (hits.get(ip) || []).filter((t) => now - t < windowMs);
  list.push(now);
  hits.set(ip, list);
  if (list.length > config.rateLimitMax) {
    return res.status(429).json({ error: 'Rate limit exceeded' });
  }
  return next();
}
