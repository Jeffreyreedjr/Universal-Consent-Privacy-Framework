/**
 * In-memory scan job store with TTL auto-delete (no cookie values stored).
 */

import { config } from './config.js';

/** @type {Map<string, object>} */
const jobs = new Map();
/** @type {Map<string, boolean>} */
const cancelFlags = new Map();
/** @type {Map<string, { close?: () => Promise<void> }>} */
const browsers = new Map();
let activeCount = 0;

export function getActiveCount() {
  return activeCount;
}

export function canStartScan() {
  return activeCount < config.maxConcurrentScans;
}

export function beginScan() {
  activeCount += 1;
}

export function endScan() {
  activeCount = Math.max(0, activeCount - 1);
}

/** Force concurrency counter to zero (stuck slots after crashes). */
export function resetActiveCount() {
  activeCount = 0;
}

/**
 * @param {string} id
 * @param {object} job
 */
export function putJob(id, job) {
  jobs.set(id, job);
  schedulePurge(id);
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
    cancel_requested: !!cancelFlags.get(j.id),
  }));
}

export function updateJob(id, patch) {
  const cur = jobs.get(id);
  if (!cur) return null;
  const next = { ...cur, ...patch, updated_at: new Date().toISOString() };
  jobs.set(id, next);
  return next;
}

function schedulePurge(id) {
  setTimeout(() => {
    jobs.delete(id);
    clearJobRuntime(id);
  }, config.reportTtlMs).unref?.();
}

export function purgeJob(id) {
  requestCancel(id);
  jobs.delete(id);
  clearJobRuntime(id);
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
 * Request cancel and close Playwright browser if registered (aborts in-flight navigations).
 * @param {string} id
 * @returns {boolean} whether the job existed
 */
export function requestCancel(id) {
  cancelFlags.set(id, true);
  const browser = browsers.get(id);
  if (browser && typeof browser.close === 'function') {
    Promise.resolve(browser.close()).catch(() => {});
  }
  return jobs.has(id);
}

export function clearJobRuntime(id) {
  cancelFlags.delete(id);
  browsers.delete(id);
}

/**
 * Cancel every running/queued job and close browsers.
 * @returns {string[]} cancelled ids
 */
export function cancelAllJobs() {
  const ids = [];
  for (const [id, job] of jobs.entries()) {
    if (job.status === 'running' || job.status === 'queued' || job.status === 'cancelling') {
      requestCancel(id);
      updateJob(id, {
        status: 'cancelling',
        progress: {
          ...(job.progress || {}),
          phase: 'cancelling',
          message: 'Cancel requested — closing Chromium…',
        },
      });
      ids.push(id);
    }
  }
  return ids;
}
