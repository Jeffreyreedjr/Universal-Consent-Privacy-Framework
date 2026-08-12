# Self-hosted scanner (server setup)

Run the Playwright scanner on **your** VPS/server so WordPress can start deep scans remotely. This is optional companion software — not part of the WordPress.org zip.

**Not a legal compliance guarantee.** Put the API behind HTTPS. Never commit `.env`.

WordPress-side infrastructure detection (Cloudflare proxy via headers/NS/cookies; transactional email via SMTP plugins/options) runs in the plugin scanner/import path — Playwright does not need to “see” SMTP.

---

## What you need

| Requirement | Notes |
|-------------|--------|
| Node.js **20+** | `node -v` |
| Linux/Windows server with outbound HTTPS | Chromium must reach the sites you scan |
| ~1–2 GB RAM free | Playwright + Chromium |
| Firewall | Open only what you reverse-proxy (usually 443) |

Clone or copy this repo onto the server (or at least `tools/ucpf-scanner`).

---

## 1. Install on the server

```bash
cd /opt/ucpf/tools/ucpf-scanner   # or your path
npm install
npx playwright install chromium
npx playwright install-deps chromium   # Linux: system libraries for Chromium
```

On Ubuntu/Debian, `install-deps` may need `sudo`.

---

## 2. Configure environment

```bash
cp .env.example .env
nano .env   # or your editor
```

Minimum production `.env`:

```env
UCPF_SCANNER_HOST=127.0.0.1
UCPF_SCANNER_PORT=3847
UCPF_SCANNER_API_KEYS=generate-a-long-random-secret-here
```

| Variable | Purpose |
|----------|---------|
| `UCPF_SCANNER_HOST` | Bind address. Use `127.0.0.1` if nginx/Caddy terminates TLS on the same machine; `0.0.0.0` only if you intentionally expose the port. |
| `UCPF_SCANNER_PORT` | Default `3847` |
| `UCPF_SCANNER_API_KEYS` | Comma-separated API keys. **Required** for any non-loopback client (including WordPress on another host). |
| `UCPF_SCANNER_ALLOW_LOCAL=1` | Optional. Allows **unauthenticated** calls from loopback only. Do not use this as a substitute for keys on a public API. |
| `UCPF_SCANNER_MAX_PAGES` | Cap pages per job when the client does **not** send a curated list (default 100). WordPress admin selections set `exactPaths: true` and are **not** truncated by this env var (hard ceiling 500). **Redeploy this service** after pulling plugin/scanner fixes — the WordPress zip never ships `tools/ucpf-scanner`. |
| `UCPF_SCANNER_MAX_CONCURRENT` | Parallel Chromium jobs (default 2). Budget ~1–2 GB RAM each. |
| `UCPF_SCANNER_MAX_QUEUE` | Waiting jobs when slots are full (default **200**) |
| `UCPF_SCANNER_MAX_RUNNING_PER_KEY` | Max running jobs per API key (default **1**) |
| `UCPF_SCANNER_MAX_QUEUED_PER_KEY` | Max queued jobs per API key (default **2**) |
| `UCPF_SCANNER_ADMIN_KEYS` | Keys allowed to `cancel-all` (default: first key in `API_KEYS`) |
| `UCPF_SCANNER_DATA_DIR` | Durable queue/job store (SQLite on Node 22+, else JSON) |
| `UCPF_SCANNER_SETTLE_MS` | Wait after nav / consent before cookie capture (default **2500**) |
| `UCPF_SCANNER_PAGE_GAP_MS` | Pause between pages in a session (default **600**) |

**Speed tip:** Prefer fewer pages + Light coverage, and tighten `SETTLE_MS` / `PAGE_GAP_MS`, before raising `MAX_CONCURRENT` (each Chromium needs ~1–2 GB RAM). Keep `MAX_RUNNING_PER_KEY=1` on shared nodes. Light (quick) skips the post-consent full page reload; Standard/Thorough keep it for fidelity.

Generate a key (example):

```bash
openssl rand -hex 32
```

---

## 3. Start the API

```bash
npm start
# → UCPF privacy scanner listening on http://127.0.0.1:3847
```

Smoke test:

```bash
curl -s http://127.0.0.1:3847/health
```

Authenticated check (same key you put in `.env`):

```bash
curl -s -H "X-UCPF-Scanner-Key: YOUR_KEY" http://127.0.0.1:3847/v1/node
# or: -H "Authorization: Bearer YOUR_KEY"
```

### Keep it running (systemd example)

`/etc/systemd/system/ucpf-scanner.service`:

```ini
[Unit]
Description=UCPF Privacy Scanner
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/opt/ucpf/tools/ucpf-scanner
EnvironmentFile=/opt/ucpf/tools/ucpf-scanner/.env
ExecStart=/usr/bin/npm start
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now ucpf-scanner
sudo systemctl status ucpf-scanner
```

Adjust `User` / paths to match your server.

---

## 4. HTTPS reverse proxy (required for production)

WordPress should call `https://scanner.yourdomain.com` — not raw HTTP on a public port.

**Caddy example:**

```
scanner.yourdomain.com {
  reverse_proxy 127.0.0.1:3847
}
```

**nginx example:**

```nginx
server {
  listen 443 ssl http2;
  server_name scanner.yourdomain.com;
  # ssl_certificate / ssl_certificate_key ...

  location / {
    proxy_pass http://127.0.0.1:3847;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_read_timeout 3600s;
  }
}
```

Long timeouts matter — Standard/Deep re-walk selected URLs **per consent session**, so large page lists need headroom (often 30–60+ minutes). Set `UCPF_SCANNER_BROWSER_TIMEOUT_MS` on the scanner host (default 1800000); raise further for Deep × many pages. Session budgets use that value ÷ session count, with a page-count floor so selected URLs can finish when the overall timeout allows.

---

## 5. Connect WordPress

In WP admin:

1. **Privacy Consent → Advanced**
2. **Scanner API URL:** `https://scanner.yourdomain.com` (no trailing path required; plugin appends API routes)
3. **Scanner API key:** same value as in `UCPF_SCANNER_API_KEYS` — **prefer one unique key per site** on agency fleets (list all keys comma-separated on the scanner, or shard sites across scanner nodes)
4. Save

On **WordPress Multisite**, set the shared Scanner API URL (and optional shared key) once under **Network Admin → Privacy Consent**. Leave each site’s Advanced Scanner fields blank to inherit, or fill them to override. Prefer one unique key per site on large fleets (site override, or `UCPF_SCANNER_API_KEY` in a site-specific drop-in). Avoid a single network-wide `UCPF_SCANNER_API_*` constant unless every blog should share the same endpoint/key.

Optional `wp-config.php` overrides (never written to the database; win over Advanced UI):

```php
define( 'UCPF_SCANNER_API_URL', 'https://scanner.yourdomain.com' );
define( 'UCPF_SCANNER_API_KEY', 'per-site-key-here' );
```

Keys saved via the UI are **encrypted at rest** in `ucpf_settings` (see Developer → API secrets). Leave the password field blank when saving other settings to keep the existing key.

Then use **Cookie Scanner** flows that call the remote API (or scheduled deep scan when configured). You can still **import JSON** from a local CLI scan without any server API.

### Interactive Playwright scans (admin UI)

When you click **Run Playwright scan** in Cookie Scanner:

1. WordPress starts the remote job and stores `job_id` + progress/log snapshots in the `ucpf_active_deep_scan` option.
2. WP-Cron (`ucpf_active_scan_poll`) polls the scanner about every 60s and imports the report when the job completes — **even if you close the Scanner tab**.
3. Returning to Cookie Scanner (or any UCPF admin screen) shows status: the Scanner progress panel reconnects via `GET /ucpf/v1/scan/active`; other admin pages show an **admin notice** with Open Cookie Scanner / Stop. Multiple Scanner tabs elect one poller via `BroadcastChannel`.
4. **Stop** always cancels the stored job id (not only an in-memory browser id). Safe to leave the page — the Chromium job keeps running on the scanner host.

Optional checkbox **Also capture logged-in cookies after Playwright** runs one helper HTTP pass with the current admin session after a successful guest Playwright import (no WordPress login inside Chromium).

**Re-verify (fast Playwright)** (after enabling blocking / Cookie Review) is a narrower job: Light coverage (`no_consent` + `reject_all`) on pages from the last Playwright report (capped ~8), or Standard sessions if the last FAILs include GPC/DNS. It does not change your coverage dropdown or full page selection — use **Run Playwright scan** for a full inventory crawl.

Scheduled / fleet scans still use `scheduled_scan_last_status` separately; interactive and scheduled status fields do not overwrite each other.

---

## 5b. Agency fleets (100–300+ sites)

The scanner is **dummy-proof for shared hosts**:

1. Jobs **wait in a queue** when Chromium slots are full (not hard-fail for the first overflow).
2. **Per-key caps** stop one chatty site from filling the node (`MAX_RUNNING_PER_KEY` / `MAX_QUEUED_PER_KEY`).
3. WordPress **never auto cancel-all** on busy — that used to kill every tenant’s job.
4. Jobs persist under `UCPF_SCANNER_DATA_DIR` (SQLite on Node 22+, else JSON) so a restart re-queues work.
5. `cancel-all` requires an **admin key** (`UCPF_SCANNER_ADMIN_KEYS` or the first API key).

### Sizing cheat sheet

| Concurrent Chromium | Rough RAM | Jobs/hour if Deep ≈ 20 min |
|---------------------|-----------|----------------------------|
| 2 | ~2–4 GB | ~6 |
| 4 | ~4–8 GB | ~12 |
| 8 | ~8–16 GB | ~24 |

**300 nightly Deep scans** at concurrency 4 ≈ **25 hours** on one node — use **staggered WP-Cron** (plugin spreads first run 1–7h by hostname) and/or **multiple scanner nodes** with site cohorts (each site’s Advanced → Scanner API URL points at its node).

Raise `UCPF_SCANNER_MAX_QUEUE` for large scheduled waves. When the queue is full, the API returns **503 + Retry-After** (sites retry; they do not cancel others).

---

## 6. API surface (reference)

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| GET | `/health` | No | Liveness + queue depth |
| GET | `/v1/node` | Yes* | Node metadata + capacity |
| POST | `/v1/scans` | Yes* | Start or enqueue scan (202 + `position`) |
| POST | `/v1/scans/:id/cancel` | Yes* | Cancel **your** job (ownership by API key) |
| POST | `/v1/scans/cancel-all` | Admin* | Emergency cancel all (admin key only) |
| POST | `/v1/drift` | Yes* | Baseline compare |
| POST | `/v1/verify-domain` | Yes* | Domain ownership challenge |

\*Auth required unless loopback + `UCPF_SCANNER_ALLOW_LOCAL=1`.

Headers accepted:

- `X-UCPF-Scanner-Key: <key>`
- `Authorization: Bearer <key>`

Private/reserved IPs are blocked (SSRF protection). The scanner fetches **public** site URLs you ask it to scan.

---

## Local CLI (no server)

If you only need reports on your laptop:

```bash
cd tools/ucpf-scanner
npm install && npx playwright install chromium
npm run scan -- --url https://yoursite.example/ --profile standard --out report.json
```

Import `report.json` under **Privacy Consent → Cookie Scanner → Import scan JSON**.

---

## Security checklist

- [ ] Strong `UCPF_SCANNER_API_KEYS` (one key per site on fleets)
- [ ] TLS on the public hostname
- [ ] Process binds to localhost behind the proxy when possible
- [ ] `.env` not in git / not world-readable
- [ ] Firewall: do not expose `3847` to the internet
- [ ] Treat scan reports as sensitive (client hosts, inventory)
- [ ] Size `MAX_CONCURRENT` to RAM; use queue + multi-node for 300+ nightly scans
- [ ] Restrict `cancel-all` to admin keys only

---

## Related docs

- Architecture / findings: [PRIVACY-BEHAVIOR-SCANNER.md](PRIVACY-BEHAVIOR-SCANNER.md)
- Quick start (plugin): [GETTING-STARTED.md](GETTING-STARTED.md)
- Scanner package README: [`tools/ucpf-scanner/README.md`](../tools/ucpf-scanner/README.md)
