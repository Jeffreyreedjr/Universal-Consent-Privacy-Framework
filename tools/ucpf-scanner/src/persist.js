/**
 * Durable job + queue persistence (SQLite when node:sqlite is available, else JSON file).
 * Survives scanner process restarts for agency fleets.
 */

import fs from 'node:fs';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { config } from './config.js';

const STATE_VERSION = 1;

/** @type {any} */
let db = null;
/** @type {'sqlite'|'json'} */
let mode = 'json';
let jsonPath = '';

/**
 * @param {string} key
 */
export function fingerprintKey(key) {
  const raw = String(key || 'local');
  return createHash('sha256').update(raw).digest('hex').slice(0, 16);
}

function ensureDataDir() {
  fs.mkdirSync(config.dataDir, { recursive: true });
}

/**
 * Open durable store.
 * @returns {Promise<{ mode: string, path?: string }>}
 */
export async function openPersist() {
  ensureDataDir();
  jsonPath = path.join(config.dataDir, 'jobs-store.json');

  try {
    const sqlite = await import('node:sqlite');
    if (!sqlite?.DatabaseSync) {
      mode = 'json';
      return { mode, path: jsonPath };
    }
    const dbPath = path.join(config.dataDir, 'jobs.sqlite');
    db = new sqlite.DatabaseSync(dbPath);
    db.exec(`
      CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT);
      CREATE TABLE IF NOT EXISTS jobs (
        id TEXT PRIMARY KEY,
        payload TEXT NOT NULL
      );
      CREATE TABLE IF NOT EXISTS queue (
        position INTEGER PRIMARY KEY,
        job_id TEXT NOT NULL
      );
    `);
    mode = 'sqlite';
    return { mode, path: dbPath };
  } catch {
    mode = 'json';
    return { mode, path: jsonPath };
  }
}

/**
 * @returns {{ jobs: object[], queueIds: string[] }}
 */
export function loadPersistedState() {
  if (mode === 'sqlite' && db) {
    const jobRows = db.prepare('SELECT payload FROM jobs').all();
    const queueRows = db.prepare('SELECT job_id FROM queue ORDER BY position ASC').all();
    const jobs = [];
    for (const row of jobRows) {
      try {
        jobs.push(JSON.parse(row.payload));
      } catch {
        /* skip */
      }
    }
    return {
      jobs,
      queueIds: queueRows.map((r) => String(r.job_id)),
    };
  }

  if (!fs.existsSync(jsonPath)) {
    return { jobs: [], queueIds: [] };
  }
  try {
    const raw = JSON.parse(fs.readFileSync(jsonPath, 'utf8'));
    if (!raw || raw.version !== STATE_VERSION) {
      return { jobs: [], queueIds: [] };
    }
    return {
      jobs: Array.isArray(raw.jobs) ? raw.jobs : [],
      queueIds: Array.isArray(raw.queueIds) ? raw.queueIds : [],
    };
  } catch {
    return { jobs: [], queueIds: [] };
  }
}

/**
 * @param {{ jobs: Iterable<object>, queueIds: string[] }} state
 */
export function savePersistedState(state) {
  const jobs = [...(state.jobs || [])];
  const queueIds = [...(state.queueIds || [])];

  if (mode === 'sqlite' && db) {
    const wipeJobs = db.prepare('DELETE FROM jobs');
    const wipeQueue = db.prepare('DELETE FROM queue');
    const insertJob = db.prepare('INSERT INTO jobs (id, payload) VALUES (?, ?)');
    const insertQ = db.prepare('INSERT INTO queue (position, job_id) VALUES (?, ?)');
    db.exec('BEGIN');
    try {
      wipeJobs.run();
      wipeQueue.run();
      for (const job of jobs) {
        if (!job || !job.id) continue;
        insertJob.run(job.id, JSON.stringify(job));
      }
      queueIds.forEach((id, i) => insertQ.run(i, id));
      db.exec('COMMIT');
    } catch (err) {
      try {
        db.exec('ROLLBACK');
      } catch {
        /* ignore */
      }
      throw err;
    }
    return;
  }

  const tmp = `${jsonPath}.${process.pid}.tmp`;
  const payload = JSON.stringify({
    version: STATE_VERSION,
    updated_at: new Date().toISOString(),
    jobs,
    queueIds,
  });
  fs.writeFileSync(tmp, payload, 'utf8');
  fs.renameSync(tmp, jsonPath);
}

export function getPersistMode() {
  return mode;
}
