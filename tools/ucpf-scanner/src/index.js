#!/usr/bin/env node
/**
 * HTTP API for the UCPF privacy scanner.
 * Self-host behind TLS; set UCPF_SCANNER_API_KEYS for production.
 */

import express from 'express';
import { randomUUID } from 'node:crypto';
import { config } from './config.js';
import { requireAuth, rateLimit } from './auth.js';
import {
  putJob,
  getJob,
  updateJob,
  canStartScan,
  beginScan,
  endScan,
  purgeJob,
} from './store.js';
import { runPrivacyScan } from './scanner.js';
import { assertSafePublicUrl } from './ssrf.js';
import { getNodeInfo } from './node-info.js';
import { compareReports } from './drift.js';

const app = express();
app.set('trust proxy', 1);
app.use(express.json({ limit: '256kb' }));

app.get('/health', (_req, res) => {
  res.json({
    ok: true,
    service: 'ucpf-scanner',
    version: '1.3.0',
    concurrent: config.maxConcurrentScans,
    node: getNodeInfo(),
  });
});

/** Agency: advertise scanner capabilities / node registration metadata */
app.get('/v1/node', requireAuth, (_req, res) => {
  res.json(getNodeInfo());
});

/** Agency: compare two reports for drift (bodies: { previous, current }) */
app.post('/v1/drift', requireAuth, rateLimit, (req, res) => {
  const previous = req.body?.previous;
  const current = req.body?.current;
  if (!previous || !current) {
    return res.status(400).json({ error: 'previous and current report objects required' });
  }
  return res.json(compareReports(previous, current));
});

/**
 * Domain-control challenge: site must host /.well-known/ucpf-scan-token
 * or echo token via REST before deep/authenticated scans (agency).
 */
app.post('/v1/verify-domain', requireAuth, rateLimit, async (req, res) => {
  const url = req.body?.url;
  const token = String(req.body?.token || '').trim();
  if (!url || !token || token.length < 16) {
    return res.status(400).json({ error: 'url and token (min 16 chars) required' });
  }
  const safe = await assertSafePublicUrl(url);
  if (!safe.ok) {
    return res.status(400).json({ error: safe.error });
  }
  try {
    const base = new URL(safe.url);
    const wellKnown = new URL('/.well-known/ucpf-scan-token', base).toString();
    const wkSafe = await assertSafePublicUrl(wellKnown);
    if (!wkSafe.ok) {
      return res.status(400).json({ error: wkSafe.error, verified: false });
    }
    const resp = await fetch(wkSafe.url, {
      headers: { Accept: 'text/plain' },
      redirect: 'manual',
      signal: AbortSignal.timeout(8000),
    });
    if (resp.status >= 300 && resp.status < 400) {
      return res.status(400).json({
        verified: false,
        error: 'Redirects not followed for domain verification (SSRF hardening).',
      });
    }
    const body = (await resp.text()).trim();
    const verified = body === token || body.includes(token);
    return res.json({
      verified,
      method: 'well-known',
      url: wellKnown,
      note: 'Domain verification only — not a compliance claim.',
    });
  } catch (err) {
    return res.status(502).json({
      verified: false,
      error: err && err.message ? err.message : 'Verification failed',
    });
  }
});

app.post('/v1/scans', requireAuth, rateLimit, async (req, res) => {
  if (!canStartScan()) {
    return res.status(429).json({ error: 'Too many concurrent scans' });
  }

  const url = req.body?.url;
  const paths = Array.isArray(req.body?.paths) ? req.body.paths : ['/'];
  if (!url || typeof url !== 'string') {
    return res.status(400).json({ error: 'url is required' });
  }

  const safe = await assertSafePublicUrl(url);
  if (!safe.ok) {
    return res.status(400).json({ error: safe.error });
  }

  const id = randomUUID();
  putJob(id, {
    id,
    status: 'queued',
    created_at: new Date().toISOString(),
    updated_at: new Date().toISOString(),
    url: safe.url,
    paths: paths.slice(0, config.maxPagesPerScan),
    report: null,
    error: null,
  });

  beginScan();
  updateJob(id, { status: 'running' });

  setImmediate(async () => {
    try {
      const report = await runPrivacyScan({
        url: safe.url,
        paths: paths.slice(0, config.maxPagesPerScan),
        options: req.body?.options || {},
      });
      updateJob(id, { status: 'completed', report });
    } catch (err) {
      updateJob(id, {
        status: 'failed',
        error: err && err.message ? err.message : 'Scan failed',
      });
    } finally {
      endScan();
    }
  });

  return res.status(202).json({ id, status: 'queued' });
});

app.get('/v1/scans/:id', requireAuth, rateLimit, (req, res) => {
  const job = getJob(req.params.id);
  if (!job) {
    return res.status(404).json({ error: 'Scan not found or expired' });
  }
  return res.json({
    id: job.id,
    status: job.status,
    created_at: job.created_at,
    updated_at: job.updated_at,
    url: job.url,
    error: job.error,
    report: job.status === 'completed' ? job.report : null,
  });
});

app.delete('/v1/scans/:id', requireAuth, rateLimit, (req, res) => {
  purgeJob(req.params.id);
  return res.json({ deleted: true });
});

app.listen(config.port, config.host, () => {
  // eslint-disable-next-line no-console
  console.log(`UCPF privacy scanner listening on http://${config.host}:${config.port}`);
  if (!config.apiKeys.length) {
    // eslint-disable-next-line no-console
    console.warn('WARNING: No UCPF_SCANNER_API_KEY set — set keys before public deploy.');
  }
});
