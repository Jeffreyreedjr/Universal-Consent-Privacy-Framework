/**
 * In-memory scan job store with TTL auto-delete (no cookie values stored).
 */

import { config } from './config.js';

/** @type {Map<string, object>} */
const jobs = new Map();
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
  }, config.reportTtlMs).unref?.();
}

export function purgeJob(id) {
  jobs.delete(id);
}
