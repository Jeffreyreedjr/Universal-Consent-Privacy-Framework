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
  requestCancel,
  isCancelRequested,
  registerBrowser,
  clearJobRuntime,
  cancelAllJobs,
  resetActiveCount,
  getActiveCount,
  listJobs,
} from './store.js';
import { runPrivacyScan } from './scanner.js';
import { assertSafePublicUrl } from './ssrf.js';
import { getNodeInfo } from './node-info.js';
import { compareReports } from './drift.js';

const app = express();
app.set('trust proxy', 1);
app.use(express.json({ limit: '256kb' }));

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * Wait briefly for a cancelling job to settle with a partial/final report.
 * @param {string} id
 * @param {number} waitMs
 */
async function waitForJobSettle(id, waitMs = 20000) {
  const deadline = Date.now() + waitMs;
  while (Date.now() < deadline) {
    const j = getJob(id);
    if (!j) {
      return null;
    }
    if (j.status === 'cancelled' || j.status === 'completed' || j.status === 'failed') {
      return j;
    }
    await sleep(250);
  }
  return getJob(id);
}

app.get('/health', (_req, res) => {
  res.json({
    ok: true,
    service: 'ucpf-scanner',
    version: '1.4.0',
    concurrent: config.maxConcurrentScans,
    active: getActiveCount(),
    jobs: listJobs().length,
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
    return res.status(429).json({
      error: 'Too many concurrent scans',
      active: getActiveCount(),
      max: config.maxConcurrentScans,
      hint: 'POST /v1/scans/cancel-all or cancel the stuck job id, then retry.',
    });
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
    progress: {
      percent: 0,
      step: 0,
      total: 0,
      phase: 'queued',
      message: 'Queued — waiting for worker…',
      log: [],
    },
  });

  beginScan();
  updateJob(id, {
    status: 'running',
    progress: {
      percent: 0,
      step: 0,
      total: 0,
      phase: 'starting',
      message: 'Starting scanner…',
      log: [],
    },
  });

  setImmediate(async () => {
    try {
      const report = await runPrivacyScan({
        url: safe.url,
        paths: paths.slice(0, config.maxPagesPerScan),
        options: {
          ...(req.body?.options || {}),
          onProgress: (progress) => {
            updateJob(id, { progress });
          },
          shouldCancel: () => isCancelRequested(id),
          onBrowser: (browser) => {
            registerBrowser(id, browser);
          },
        },
      });
      const prev = getJob(id);
      const wasCancelled = isCancelRequested(id) || !!(report && report.cancelled);
      updateJob(id, {
        status: wasCancelled ? 'cancelled' : 'completed',
        report,
        error: null,
        progress: {
          ...(prev && prev.progress ? prev.progress : {}),
          percent: 100,
          phase: 'done',
          message: wasCancelled
            ? 'Cancelled — partial results ready to import'
            : 'Scan complete — ready to import',
        },
      });
    } catch (err) {
      const prev = getJob(id);
      if (err && err.name === 'ScanCancelledError') {
        updateJob(id, {
          status: 'cancelled',
          report: err.partialReport || null,
          error: null,
          progress: {
            ...(prev && prev.progress ? prev.progress : {}),
            phase: 'cancelled',
            message: 'Cancelled before usable results',
          },
        });
      } else {
        updateJob(id, {
          status: 'failed',
          error: err && err.message ? err.message : 'Scan failed',
          progress: {
            ...(prev && prev.progress ? prev.progress : {}),
            phase: 'failed',
            message: err && err.message ? err.message : 'Scan failed',
          },
        });
      }
    } finally {
      clearJobRuntime(id);
      endScan();
    }
  });

  return res.status(202).json({ id, status: 'queued' });
});

app.post('/v1/scans/:id/cancel', requireAuth, rateLimit, async (req, res) => {
  const id = req.params.id;
  const job = getJob(id);
  if (!job) {
    return res.status(404).json({ error: 'Scan not found or expired' });
  }
  if (job.status === 'completed' || job.status === 'cancelled' || job.status === 'failed') {
    return res.json({
      id,
      status: job.status,
      report: job.report || null,
      partial: !!(job.report && job.report.partial),
      message: 'Job already finished',
    });
  }

  requestCancel(id);
  updateJob(id, {
    status: 'cancelling',
    progress: {
      ...(job.progress || {}),
      phase: 'cancelling',
      message: 'Cancel requested — closing Chromium…',
    },
  });

  const settled = await waitForJobSettle(id, 22000);
  const finalJob = settled || getJob(id);
  return res.json({
    id,
    status: finalJob ? finalJob.status : 'unknown',
    report: finalJob && finalJob.report ? finalJob.report : null,
    partial: !!(finalJob && finalJob.report && finalJob.report.partial),
    progress: finalJob ? finalJob.progress : null,
    message:
      finalJob && finalJob.report
        ? 'Cancelled — partial report available'
        : 'Cancel requested; Chromium closing',
  });
});

app.post('/v1/scans/cancel-all', requireAuth, rateLimit, async (req, res) => {
  const ids = cancelAllJobs();
  await sleep(1500);
  if (req.body?.reset_slots) {
    resetActiveCount();
  }
  return res.json({
    cancelled: ids,
    active: getActiveCount(),
    message: ids.length ? `Cancel requested for ${ids.length} job(s)` : 'No running jobs',
  });
});

app.get('/v1/scans', requireAuth, rateLimit, (_req, res) => {
  res.json({
    active: getActiveCount(),
    max: config.maxConcurrentScans,
    jobs: listJobs(),
  });
});

app.get('/v1/scans/:id', requireAuth, rateLimit, (req, res) => {
  const job = getJob(req.params.id);
  if (!job) {
    return res.status(404).json({ error: 'Scan not found or expired' });
  }
  const withReport =
    job.status === 'completed' || job.status === 'cancelled' || (job.status === 'cancelling' && job.report);
  return res.json({
    id: job.id,
    status: job.status,
    created_at: job.created_at,
    updated_at: job.updated_at,
    url: job.url,
    error: job.error,
    progress: job.progress || null,
    cancel_requested: isCancelRequested(job.id),
    report: withReport ? job.report : null,
  });
});

app.delete('/v1/scans/:id', requireAuth, rateLimit, async (req, res) => {
  const id = req.params.id;
  const job = getJob(id);
  if (job && (job.status === 'running' || job.status === 'queued' || job.status === 'cancelling')) {
    requestCancel(id);
    await waitForJobSettle(id, 8000);
  }
  purgeJob(id);
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
