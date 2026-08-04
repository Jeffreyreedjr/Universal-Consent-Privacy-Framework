#!/usr/bin/env node
/**
 * HTTP API for the UCPF privacy scanner.
 * Self-host behind TLS; set UCPF_SCANNER_API_KEYS for production.
 * Agency fleets: waiting queue + per-key caps + durable jobs (SQLite/JSON).
 */

import express from 'express';
import { randomUUID } from 'node:crypto';
import { config } from './config.js';
import { requireAuth, rateLimit } from './auth.js';
import {
  getJob,
  updateJob,
  enqueueJob,
  drainQueue,
  endScan,
  purgeJob,
  requestCancel,
  isCancelRequested,
  registerBrowser,
  clearJobRuntime,
  cancelAllJobs,
  resetActiveCount,
  getActiveCount,
  getQueueLength,
  getQueuePosition,
  listJobs,
  setRunHandler,
  initStore,
  canCancelJob,
  estimatedWaitHint,
  fingerprintKey,
} from './store.js';
import { getPersistMode } from './persist.js';
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

/**
 * Run Chromium for a job that already holds an active slot.
 * @param {object} job
 */
async function executeJob(job) {
  const id = job.id;
  const safeUrl = job.url;
  const paths = Array.isArray(job.paths) ? job.paths : ['/'];
  const options = job.options && typeof job.options === 'object' ? job.options : {};

  try {
    const report = await runPrivacyScan({
      url: safeUrl,
      paths,
      options: {
        ...options,
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
        queue_position: 0,
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
          queue_position: 0,
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
          queue_position: 0,
        },
      });
    }
  } finally {
    clearJobRuntime(id);
    endScan();
  }
}

app.get('/health', (_req, res) => {
  res.json({
    ok: true,
    service: 'ucpf-scanner',
    version: '1.4.0',
    concurrent: config.maxConcurrentScans,
    active: getActiveCount(),
    queue: getQueueLength(),
    max_queue: config.maxQueue,
    persist: getPersistMode(),
    jobs: listJobs().length,
    node: getNodeInfo(),
  });
});

app.get('/v1/node', requireAuth, (_req, res) => {
  res.json({
    ...getNodeInfo(),
    capacity: {
      max_concurrent: config.maxConcurrentScans,
      max_queue: config.maxQueue,
      max_running_per_key: config.maxRunningPerKey,
      max_queued_per_key: config.maxQueuedPerKey,
      active: getActiveCount(),
      queued: getQueueLength(),
      persist: getPersistMode(),
    },
  });
});

app.post('/v1/drift', requireAuth, rateLimit, (req, res) => {
  const previous = req.body?.previous;
  const current = req.body?.current;
  if (!previous || !current) {
    return res.status(400).json({ error: 'previous and current report objects required' });
  }
  return res.json(compareReports(previous, current));
});

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
  const url = req.body?.url;
  const paths = Array.isArray(req.body?.paths) ? req.body.paths : ['/'];
  if (!url || typeof url !== 'string') {
    return res.status(400).json({ error: 'url is required' });
  }

  // Validate URL before claiming queue/slot capacity (no TOCTOU on slots during await).
  const safe = await assertSafePublicUrl(url);
  if (!safe.ok) {
    return res.status(400).json({ error: safe.error });
  }

  const id = randomUUID();
  const keyFp = req.ucpfKeyFp || fingerprintKey(req.ucpfApiKey || 'local');
  const job = {
    id,
    status: 'queued',
    created_at: new Date().toISOString(),
    updated_at: new Date().toISOString(),
    url: safe.url,
    paths: paths.slice(0, config.maxPagesPerScan),
    options: req.body?.options || {},
    key_fp: keyFp,
    report: null,
    error: null,
    progress: {
      percent: 0,
      step: 0,
      total: 0,
      phase: 'queued',
      message: 'Queued — waiting for worker…',
      log: [],
      queue_position: 0,
    },
  };

  // putJob briefly so enqueue can see it — enqueueJob calls putJob again.
  const result = enqueueJob(job);
  if (!result.accepted) {
    const code = result.code || 503;
    res.setHeader('Retry-After', String(result.retryAfter || 120));
    return res.status(code).json({
      error: result.error || 'Unable to accept scan',
      active: getActiveCount(),
      max: config.maxConcurrentScans,
      queue: getQueueLength(),
      max_queue: config.maxQueue,
      hint: result.hint || 'Retry later. Do not cancel other sites’ jobs.',
      retry_after: result.retryAfter || 120,
    });
  }

  const position = result.position || getQueuePosition(id) || 0;
  return res.status(202).json({
    id,
    status: result.started ? 'running' : 'queued',
    position,
    queue_length: getQueueLength(),
    estimated_wait_hint: position > 0 ? estimatedWaitHint(position) : null,
    active: getActiveCount(),
    max: config.maxConcurrentScans,
  });
});

app.post('/v1/scans/:id/cancel', requireAuth, rateLimit, async (req, res) => {
  const id = req.params.id;
  const job = getJob(id);
  if (!job) {
    return res.status(404).json({ error: 'Scan not found or expired' });
  }

  const caller = {
    keyFp: req.ucpfKeyFp || '',
    isAdmin: !!req.ucpfIsAdmin,
  };
  if (!canCancelJob(job, caller)) {
    return res.status(403).json({
      error: 'Forbidden',
      hint: 'You can only cancel jobs started with your API key.',
    });
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
  if (job.status === 'queued') {
    updateJob(id, {
      status: 'cancelled',
      progress: {
        ...(job.progress || {}),
        phase: 'cancelled',
        message: 'Cancelled while queued',
        queue_position: 0,
      },
    });
    drainQueue();
    return res.json({
      id,
      status: 'cancelled',
      report: null,
      partial: false,
      message: 'Removed from queue',
    });
  }

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
  if (!req.ucpfIsAdmin) {
    return res.status(403).json({
      error: 'Forbidden',
      hint: 'cancel-all requires an admin API key (UCPF_SCANNER_ADMIN_KEYS or the first key in UCPF_SCANNER_API_KEYS). Use per-job cancel instead.',
    });
  }
  const ids = cancelAllJobs({ admin: true });
  await sleep(1500);
  if (req.body?.reset_slots) {
    resetActiveCount();
    drainQueue();
  }
  return res.json({
    cancelled: ids,
    active: getActiveCount(),
    queue: getQueueLength(),
    message: ids.length ? `Cancel requested for ${ids.length} job(s)` : 'No running/queued jobs',
  });
});

app.get('/v1/scans', requireAuth, rateLimit, (_req, res) => {
  res.json({
    active: getActiveCount(),
    max: config.maxConcurrentScans,
    queue: getQueueLength(),
    max_queue: config.maxQueue,
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
  const position = job.status === 'queued' ? getQueuePosition(job.id) : 0;
  return res.json({
    id: job.id,
    status: job.status,
    created_at: job.created_at,
    updated_at: job.updated_at,
    url: job.url,
    error: job.error,
    progress: job.progress || null,
    position,
    queue_length: getQueueLength(),
    estimated_wait_hint: position > 0 ? estimatedWaitHint(position) : null,
    cancel_requested: isCancelRequested(job.id),
    report: withReport ? job.report : null,
  });
});

app.delete('/v1/scans/:id', requireAuth, rateLimit, async (req, res) => {
  const id = req.params.id;
  const job = getJob(id);
  if (job) {
    const caller = {
      keyFp: req.ucpfKeyFp || '',
      isAdmin: !!req.ucpfIsAdmin,
    };
    if (!canCancelJob(job, caller)) {
      return res.status(403).json({ error: 'Forbidden' });
    }
    if (job.status === 'running' || job.status === 'queued' || job.status === 'cancelling') {
      requestCancel(id);
      await waitForJobSettle(id, 8000);
    }
  }
  purgeJob(id);
  drainQueue();
  return res.json({ deleted: true });
});

async function main() {
  setRunHandler((job) => {
    executeJob(job);
  });

  const restored = await initStore();
  // eslint-disable-next-line no-console
  console.log(
    `UCPF scanner store ready (${restored.mode}): ${restored.jobs} jobs, ${restored.queued} queued`
  );

  drainQueue();

  app.listen(config.port, config.host, () => {
    // eslint-disable-next-line no-console
    console.log(`UCPF privacy scanner listening on http://${config.host}:${config.port}`);
    // eslint-disable-next-line no-console
    console.log(
      `Capacity: ${config.maxConcurrentScans} concurrent, queue ${config.maxQueue}, per-key ${config.maxRunningPerKey} running / ${config.maxQueuedPerKey} queued`
    );
    if (!config.apiKeys.length) {
      // eslint-disable-next-line no-console
      console.warn('WARNING: No UCPF_SCANNER_API_KEYS set — auth requires ALLOW_LOCAL=1 on loopback.');
    }
  });
}

main().catch((err) => {
  // eslint-disable-next-line no-console
  console.error(err);
  process.exit(1);
});
