/**
 * Scan job store with waiting queue, per-key fairness, ownership, and durable persistence.
 * Chromium slots are claimed atomically via tryBeginScan().
 */

import { config } from './config.js';
import {
  openPersist,
  loadPersistedState,
  savePersistedState,
  fingerprintKey,
  getPersistMode,
} from './persist.js';

/** @type {Map<string, object>} */
const jobs = new Map();
/** @type {string[]} waiting job ids (FIFO) */
let waitQueue = [];
/** @type {Map<string, boolean>} */
const cancelFlags = new Map();
/** @type {Map<string, { close?: () => Promise<void> }>} */
const browsers = new Map();
/** @type {Map<string, ReturnType<typeof setTimeout>>} */
const purgeTimers = new Map();
let activeCount = 0;
/** @type {((job: object) => void)|null} */
let runHandler = null;
let persistTimer = null;
let ready = false;

export { fingerprintKey };

export function getActiveCount() {
  return activeCount;
}

export function getQueueLength() {
  return waitQueue.length;
}

export function getQueuePosition(jobId) {
  const idx = waitQueue.indexOf(jobId);
  return idx < 0 ? 0 : idx + 1;
}

/**
 * Atomically claim a Chromium slot. Returns false if at capacity.
 */
export function tryBeginScan() {
  if (activeCount >= config.maxConcurrentScans) {
    return false;
  }
  activeCount += 1;
  return true;
}

/** @deprecated use tryBeginScan — kept for clarity in drain */
export function beginScan() {
  activeCount += 1;
}

export function endScan() {
  activeCount = Math.max(0, activeCount - 1);
  schedulePersist();
  // Drain next waiter after slot frees.
  setImmediate(() => {
    try {
      drainQueue();
    } catch {
      /* ignore */
    }
  });
}

export function resetActiveCount() {
  activeCount = 0;
  schedulePersist();
}

export function canStartScan() {
  return activeCount < config.maxConcurrentScans;
}

/**
 * @param {(job: object) => void} fn
 */
export function setRunHandler(fn) {
  runHandler = typeof fn === 'function' ? fn : null;
}

function countForKey(keyFp, statuses) {
  let n = 0;
  for (const job of jobs.values()) {
    if (job.key_fp !== keyFp) continue;
    if (statuses.includes(job.status)) n += 1;
  }
  return n;
}

/**
 * @param {string} keyFp
 * @returns {{ ok: boolean, error?: string, code?: number, retryAfter?: number, hint?: string }}
 */
export function canAcceptJobForKey(keyFp) {
  const running = countForKey(keyFp, ['running', 'cancelling']);
  const queued = countForKey(keyFp, ['queued']);
  // Still room to run or queue under per-key caps.
  if (running < config.maxRunningPerKey || queued < config.maxQueuedPerKey) {
    return { ok: true };
  }
  return {
    ok: false,
    error: 'Per-key limit reached',
    code: 429,
    retryAfter: 60,
    hint: `This API key may have at most ${config.maxRunningPerKey} running and ${config.maxQueuedPerKey} queued. Wait for your job to finish, or use a dedicated key per site.`,
  };
}

/**
 * Enqueue or start a job. Does not claim a slot until drain runs it.
 * @param {object} job
 * @returns {{ accepted: boolean, started: boolean, position: number, error?: string, code?: number, retryAfter?: number, hint?: string }}
 */
export function enqueueJob(job) {
  const keyFp = job.key_fp || fingerprintKey('');
  const perKey = canAcceptJobForKey(keyFp);
  if (!perKey.ok) {
    return {
      accepted: false,
      started: false,
      position: 0,
      error: perKey.error,
      code: perKey.code || 429,
      retryAfter: perKey.retryAfter || 60,
      hint: perKey.hint,
    };
  }

  // If we can start immediately (slot + per-key running room), do so.
  const runningForKey = countForKey(keyFp, ['running', 'cancelling']);
  const canRunNow =
    runningForKey < config.maxRunningPerKey && tryBeginScan();

  if (canRunNow) {
    job.status = 'running';
    job.progress = {
      ...(job.progress || {}),
      phase: 'starting',
      message: 'Starting scanner…',
      queue_position: 0,
    };
    putJob(job.id, job);
    schedulePersist();
    if (runHandler) {
      setImmediate(() => runHandler(job));
    }
    return { accepted: true, started: true, position: 0 };
  }

  // Need to wait — if we claimed a slot incorrectly we shouldn't be here.
  // tryBeginScan only succeeds when we can run; if false, queue.
  if (waitQueue.length >= config.maxQueue) {
    return {
      accepted: false,
      started: false,
      position: 0,
      error: 'Scan queue is full',
      code: 503,
      retryAfter: 120,
      hint: `Queue capacity is ${config.maxQueue}. Retry later, stagger scheduled scans, or add another scanner node.`,
    };
  }

  const queuedForKey = countForKey(keyFp, ['queued']);
  if (queuedForKey >= config.maxQueuedPerKey) {
    return {
      accepted: false,
      started: false,
      position: 0,
      error: 'Per-key queue limit reached',
      code: 429,
      retryAfter: 60,
      hint: `This API key may queue at most ${config.maxQueuedPerKey} job(s). Wait or use one key per site.`,
    };
  }

  job.status = 'queued';
  job.progress = {
    ...(job.progress || {}),
    phase: 'queued',
    message: 'Queued — waiting for a Chromium slot…',
    queue_position: waitQueue.length + 1,
  };
  putJob(job.id, job, { skipPurge: true });
  waitQueue.push(job.id);
  refreshQueuePositions();
  schedulePersist();
  // In case a slot freed between tryBeginScan and now.
  setImmediate(() => drainQueue());
  return {
    accepted: true,
    started: false,
    position: getQueuePosition(job.id),
  };
}

function refreshQueuePositions() {
  waitQueue.forEach((id, i) => {
    const job = jobs.get(id);
    if (!job || job.status !== 'queued') return;
    updateJob(id, {
      progress: {
        ...(job.progress || {}),
        phase: 'queued',
        message: `Queued — position ${i + 1} of ${waitQueue.length}`,
        queue_position: i + 1,
        queue_length: waitQueue.length,
      },
    });
  });
}

/**
 * Start waiting jobs until slots / per-key caps are full.
 */
export function drainQueue() {
  while (waitQueue.length > 0) {
    const nextId = waitQueue[0];
    const job = jobs.get(nextId);
    if (!job || job.status !== 'queued') {
      waitQueue.shift();
      continue;
    }
    if (isCancelRequested(nextId)) {
      waitQueue.shift();
      updateJob(nextId, {
        status: 'cancelled',
        progress: {
          ...(job.progress || {}),
          phase: 'cancelled',
          message: 'Cancelled while queued',
          queue_position: 0,
        },
      });
      schedulePurgeFromNow(nextId);
      continue;
    }
    const keyFp = job.key_fp || '';
    const runningForKey = countForKey(keyFp, ['running', 'cancelling']);
    if (runningForKey >= config.maxRunningPerKey) {
      // Skip this key's job; try to find another key further in the queue (fairness).
      let swapped = false;
      for (let i = 1; i < waitQueue.length; i += 1) {
        const alt = jobs.get(waitQueue[i]);
        if (!alt || alt.status !== 'queued') continue;
        const altFp = alt.key_fp || '';
        if (countForKey(altFp, ['running', 'cancelling']) >= config.maxRunningPerKey) continue;
        if (!tryBeginScan()) return;
        waitQueue.splice(i, 1);
        startQueuedJob(alt);
        swapped = true;
        break;
      }
      if (!swapped) return;
      continue;
    }
    if (!tryBeginScan()) {
      return;
    }
    waitQueue.shift();
    startQueuedJob(job);
  }
  refreshQueuePositions();
  schedulePersist();
}

function startQueuedJob(job) {
  updateJob(job.id, {
    status: 'running',
    progress: {
      ...(job.progress || {}),
      phase: 'starting',
      message: 'Starting scanner…',
      queue_position: 0,
    },
  });
  schedulePersist();
  if (runHandler) {
    const fresh = jobs.get(job.id);
    setImmediate(() => runHandler(fresh || job));
  }
}

/**
 * @param {string} id
 * @param {object} job
 * @param {{ skipPurge?: boolean }} [opts]
 */
export function putJob(id, job, opts = {}) {
  jobs.set(id, job);
  if (!opts.skipPurge && isTerminal(job.status)) {
    schedulePurgeFromNow(id);
  }
  schedulePersist();
}

function isTerminal(status) {
  return status === 'completed' || status === 'cancelled' || status === 'failed';
}

export function getJob(id) {
  return jobs.get(id) || null;
}

export function listJobs() {
  return [...jobs.values()].map((j) => ({
    id: j.id,
    status: j.status,
    created_at: j.created_at,
    updated_at: j.updated_at,
    url: j.url,
    key_fp: j.key_fp ? `${String(j.key_fp).slice(0, 6)}…` : '',
    queue_position: j.status === 'queued' ? getQueuePosition(j.id) : 0,
    cancel_requested: !!cancelFlags.get(j.id),
  }));
}

export function updateJob(id, patch) {
  const cur = jobs.get(id);
  if (!cur) return null;
  const next = { ...cur, ...patch, updated_at: new Date().toISOString() };
  jobs.set(id, next);
  if (isTerminal(next.status)) {
    // Remove from wait queue if present.
    waitQueue = waitQueue.filter((qid) => qid !== id);
    schedulePurgeFromNow(id);
  }
  schedulePersist();
  return next;
}

/** TTL starts at completion / terminal state — not at create. */
function schedulePurgeFromNow(id) {
  const prev = purgeTimers.get(id);
  if (prev) clearTimeout(prev);
  const t = setTimeout(() => {
    jobs.delete(id);
    clearJobRuntime(id);
    waitQueue = waitQueue.filter((qid) => qid !== id);
    purgeTimers.delete(id);
    schedulePersist();
  }, config.reportTtlMs);
  t.unref?.();
  purgeTimers.set(id, t);
}

export function purgeJob(id) {
  requestCancel(id);
  jobs.delete(id);
  waitQueue = waitQueue.filter((qid) => qid !== id);
  clearJobRuntime(id);
  const prev = purgeTimers.get(id);
  if (prev) clearTimeout(prev);
  purgeTimers.delete(id);
  schedulePersist();
}

/**
 * @param {string} id
 * @param {{ close?: () => Promise<void> }|null} browser
 */
export function registerBrowser(id, browser) {
  if (!browser) {
    browsers.delete(id);
    return;
  }
  browsers.set(id, browser);
}

export function isCancelRequested(id) {
  return !!cancelFlags.get(id);
}

/**
 * @param {string} id
 * @returns {boolean}
 */
export function requestCancel(id) {
  cancelFlags.set(id, true);
  // Drop from wait queue immediately.
  const before = waitQueue.length;
  waitQueue = waitQueue.filter((qid) => qid !== id);
  if (waitQueue.length !== before) {
    refreshQueuePositions();
  }
  const browser = browsers.get(id);
  if (browser && typeof browser.close === 'function') {
    Promise.resolve(browser.close()).catch(() => {});
  }
  schedulePersist();
  return jobs.has(id);
}

export function clearJobRuntime(id) {
  cancelFlags.delete(id);
  browsers.delete(id);
}

/**
 * Cancel jobs. If keyFp is set, only that tenant's jobs (unless admin).
 * @param {{ keyFp?: string, admin?: boolean }} [opts]
 * @returns {string[]}
 */
export function cancelAllJobs(opts = {}) {
  const ids = [];
  const admin = !!opts.admin;
  const keyFp = opts.keyFp || '';
  for (const [id, job] of jobs.entries()) {
    if (job.status !== 'running' && job.status !== 'queued' && job.status !== 'cancelling') {
      continue;
    }
    if (!admin && keyFp && job.key_fp && job.key_fp !== keyFp) {
      continue;
    }
    if (!admin && keyFp && !job.key_fp) {
      continue;
    }
    requestCancel(id);
    updateJob(id, {
      status: job.status === 'queued' ? 'cancelled' : 'cancelling',
      progress: {
        ...(job.progress || {}),
        phase: job.status === 'queued' ? 'cancelled' : 'cancelling',
        message:
          job.status === 'queued'
            ? 'Cancelled while queued'
            : 'Cancel requested — closing Chromium…',
        queue_position: 0,
      },
    });
    if (job.status === 'queued') {
      schedulePurgeFromNow(id);
    }
    ids.push(id);
  }
  schedulePersist();
  return ids;
}

/**
 * Whether caller may cancel this job.
 * @param {object} job
 * @param {{ keyFp: string, isAdmin: boolean }} caller
 */
export function canCancelJob(job, caller) {
  if (!job) return false;
  if (caller.isAdmin) return true;
  if (!caller.keyFp) return true; // local unauthenticated mode
  return !job.key_fp || job.key_fp === caller.keyFp;
}

function schedulePersist() {
  if (persistTimer) return;
  persistTimer = setTimeout(() => {
    persistTimer = null;
    flushPersist();
  }, 250);
  persistTimer.unref?.();
}

function flushPersist() {
  try {
    savePersistedState({
      jobs: [...jobs.values()],
      queueIds: [...waitQueue],
    });
  } catch (err) {
    // eslint-disable-next-line no-console
    console.error('UCPF scanner persist failed:', err && err.message ? err.message : err);
  }
}

/**
 * Load durable state; interrupted running jobs are re-queued.
 */
export async function initStore() {
  await openPersist();
  const { jobs: savedJobs, queueIds } = loadPersistedState();
  const restoredQueue = [];

  for (const job of savedJobs) {
    if (!job || !job.id) continue;
    if (job.status === 'running' || job.status === 'cancelling') {
      // Process died mid-scan — re-queue for a clean Chromium run.
      job.status = 'queued';
      job.error = null;
      job.progress = {
        ...(job.progress || {}),
        phase: 'queued',
        message: 'Re-queued after scanner restart…',
        queue_position: 0,
      };
      jobs.set(job.id, job);
      restoredQueue.push(job.id);
    } else if (job.status === 'queued') {
      jobs.set(job.id, job);
      restoredQueue.push(job.id);
    } else if (isTerminal(job.status)) {
      jobs.set(job.id, job);
      schedulePurgeFromNow(job.id);
    } else {
      jobs.set(job.id, job);
    }
  }

  // Prefer saved queue order; append any restored running→queued not listed.
  const seen = new Set();
  waitQueue = [];
  for (const id of queueIds) {
    if (jobs.has(id) && jobs.get(id).status === 'queued' && !seen.has(id)) {
      waitQueue.push(id);
      seen.add(id);
    }
  }
  for (const id of restoredQueue) {
    if (!seen.has(id) && jobs.has(id) && jobs.get(id).status === 'queued') {
      waitQueue.push(id);
      seen.add(id);
    }
  }

  activeCount = 0;
  refreshQueuePositions();
  ready = true;
  schedulePersist();
  return {
    mode: getPersistMode(),
    jobs: jobs.size,
    queued: waitQueue.length,
  };
}

export function isStoreReady() {
  return ready;
}

export function estimatedWaitHint(position) {
  const avgMs = Math.max(60000, Math.floor(config.browserTimeoutMs / Math.max(2, config.maxConcurrentScans)));
  const slots = Math.max(1, config.maxConcurrentScans);
  const ahead = Math.max(0, (position || 1) - 1);
  const waves = Math.ceil((ahead + 1) / slots);
  const mins = Math.max(1, Math.round((waves * avgMs) / 60000));
  return `~${mins} min (estimate)`;
}
