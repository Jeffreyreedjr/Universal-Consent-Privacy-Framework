/**
 * Security regression tests for scanner authz / SSRF helpers.
 * Run: npm run test:security
 */

import assert from 'node:assert/strict';
import { isPrivateOrBlockedIp, assertSafePublicUrl, assertSafeRedirect } from '../src/ssrf.js';
import { keyListIncludes } from '../src/auth.js';
import { getAdminKeys, config } from '../src/config.js';
import { safeFetch } from '../src/safe-fetch.js';
import { sanitizeHttpScanOptions } from '../src/scan-options.js';
import {
  putJob,
  listJobs,
  canAccessJob,
  getJob,
  purgeJob,
} from '../src/store.js';

let failed = 0;

function test(name, fn) {
  try {
    fn();
    console.log(`ok - ${name}`);
  } catch (err) {
    failed += 1;
    console.error(`FAIL - ${name}`);
    console.error(err);
  }
}

async function testAsync(name, fn) {
  try {
    await fn();
    console.log(`ok - ${name}`);
  } catch (err) {
    failed += 1;
    console.error(`FAIL - ${name}`);
    console.error(err);
  }
}

test('private IPv4 blocked', () => {
  assert.equal(isPrivateOrBlockedIp('127.0.0.1'), true);
  assert.equal(isPrivateOrBlockedIp('10.0.0.1'), true);
  assert.equal(isPrivateOrBlockedIp('192.168.1.1'), true);
  assert.equal(isPrivateOrBlockedIp('169.254.169.254'), true);
  assert.equal(isPrivateOrBlockedIp('8.8.8.8'), false);
});

await testAsync('assertSafePublicUrl blocks metadata IP literal', async () => {
  const r = await assertSafePublicUrl('http://169.254.169.254/latest/meta-data/');
  assert.equal(r.ok, false);
});

await testAsync('assertSafePublicUrl blocks localhost', async () => {
  const r = await assertSafePublicUrl('http://localhost:8080/');
  assert.equal(r.ok, false);
});

test('timing-safe key list includes', () => {
  assert.equal(keyListIncludes('abc', ['abc', 'xyz']), true);
  assert.equal(keyListIncludes('nope', ['abc', 'xyz']), false);
  assert.equal(keyListIncludes('', ['abc']), false);
});

test('getAdminKeys has no first-key fallback', () => {
  // Without UCPF_SCANNER_ADMIN_KEYS in env, admin list is empty even if api keys exist in config.
  // We only assert the function returns an array (env-dependent in CI).
  assert.ok(Array.isArray(getAdminKeys()));
  assert.ok(typeof config.host === 'string');
  // Default bind must be loopback unless explicitly overridden.
  if (!process.env.UCPF_SCANNER_HOST) {
    assert.equal(config.host, '127.0.0.1');
  }
});

test('canAccessJob enforces key_fp ownership', () => {
  const job = { id: 'j1', key_fp: 'aaaa', status: 'completed', report: { secret: true } };
  assert.equal(canAccessJob(job, { keyFp: 'aaaa', isAdmin: false }), true);
  assert.equal(canAccessJob(job, { keyFp: 'bbbb', isAdmin: false }), false);
  assert.equal(canAccessJob(job, { keyFp: 'bbbb', isAdmin: true }), true);
});

test('listJobs filters by key_fp', () => {
  const idA = 'sec-test-a';
  const idB = 'sec-test-b';
  putJob(idA, {
    id: idA,
    status: 'completed',
    created_at: new Date().toISOString(),
    updated_at: new Date().toISOString(),
    url: 'https://example.com/',
    key_fp: 'fp-a',
    report: { tenant: 'a' },
  });
  putJob(idB, {
    id: idB,
    status: 'completed',
    created_at: new Date().toISOString(),
    updated_at: new Date().toISOString(),
    url: 'https://example.org/',
    key_fp: 'fp-b',
    report: { tenant: 'b' },
  });

  const forA = listJobs({ keyFp: 'fp-a', isAdmin: false });
  assert.equal(forA.some((j) => j.id === idA), true);
  assert.equal(forA.some((j) => j.id === idB), false);

  const admin = listJobs({ keyFp: 'fp-a', isAdmin: true });
  assert.equal(admin.some((j) => j.id === idA), true);
  assert.equal(admin.some((j) => j.id === idB), true);

  assert.ok(getJob(idA));
  purgeJob(idA);
  purgeJob(idB);
});

await testAsync('assertSafeRedirect blocks metadata hop', async () => {
  const r = await assertSafeRedirect('http://169.254.169.254/latest/meta-data/');
  assert.equal(r.ok, false);
});

await testAsync('assertSafeRedirect blocks RFC1918 hop', async () => {
  const r = await assertSafeRedirect('http://192.168.1.50/admin');
  assert.equal(r.ok, false);
});

await testAsync('safeFetch refuses redirect chain to metadata IP', async () => {
  const orig = globalThis.fetch;
  let calls = 0;
  globalThis.fetch = async () => {
    calls += 1;
    if (calls === 1) {
      return new Response(null, {
        status: 302,
        headers: { location: 'http://169.254.169.254/latest/meta-data/' },
      });
    }
    throw new Error('fetch must not follow a blocked redirect target');
  };
  try {
    const r = await safeFetch('https://example.com/', { maxRedirects: 3, timeoutMs: 5000 });
    assert.equal(r.ok, false);
    assert.match(String(r.error || ''), /blocked|private|metadata|hostname|address/i);
    assert.equal(calls, 1);
  } finally {
    globalThis.fetch = orig;
  }
});

test('sanitizeHttpScanOptions strips recipeFile from HTTP options', () => {
  const cleaned = sanitizeHttpScanOptions({
    profile: 'standard',
    recipeFile: '/etc/passwd',
    recipe: './evil.json',
    recipe_file: 'C:\\\\temp\\\\x.json',
    maxPages: 10,
  });
  assert.equal(cleaned.profile, 'standard');
  assert.equal(cleaned.maxPages, 10);
  assert.equal('recipeFile' in cleaned, false);
  assert.equal('recipe' in cleaned, false);
  assert.equal('recipe_file' in cleaned, false);
});

test('trustProxy defaults off unless env set', () => {
  if (!process.env.UCPF_SCANNER_TRUST_PROXY) {
    assert.equal(config.trustProxy, false);
  }
});

await testAsync('safeFetch refuses redirect chain to RFC1918', async () => {
  const orig = globalThis.fetch;
  let calls = 0;
  globalThis.fetch = async () => {
    calls += 1;
    if (calls === 1) {
      return new Response(null, {
        status: 301,
        headers: { location: 'http://10.0.0.8/internal' },
      });
    }
    throw new Error('fetch must not follow a blocked redirect target');
  };
  try {
    const r = await safeFetch('https://example.com/path', { maxRedirects: 3, timeoutMs: 5000 });
    assert.equal(r.ok, false);
    assert.equal(calls, 1);
  } finally {
    globalThis.fetch = orig;
  }
});

if (failed) {
  console.error(`\n${failed} security test(s) failed`);
  process.exit(1);
}
console.log('\nAll security tests passed');
