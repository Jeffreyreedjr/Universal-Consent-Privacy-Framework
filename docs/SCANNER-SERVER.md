# Self-hosted scanner (server setup)

Run the Playwright scanner on **your** VPS/server so WordPress can start deep scans remotely. This is optional companion software — not part of the WordPress.org zip.

**Not a legal compliance guarantee.** Put the API behind HTTPS. Never commit `.env`.

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
| `UCPF_SCANNER_MAX_PAGES` | Cap pages per job (default 100) |
| `UCPF_SCANNER_MAX_CONCURRENT` | Parallel scans (default 2) |

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
    proxy_read_timeout 600s;
  }
}
```

Long timeouts matter — deep scans can run several minutes.

---

## 5. Connect WordPress

In WP admin:

1. **Privacy Consent → Advanced**
2. **Scanner API URL:** `https://scanner.yourdomain.com` (no trailing path required; plugin appends API routes)
3. **Scanner API key:** same value as in `UCPF_SCANNER_API_KEYS`
4. Save

Optional `wp-config.php` overrides (if your install supports them via brand/constants — prefer Advanced UI):

- Site setting `scanner_api_url` / `scanner_api_key`

Then use **Cookie Scanner** flows that call the remote API (or scheduled deep scan when configured). You can still **import JSON** from a local CLI scan without any server API.

---

## 6. API surface (reference)

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| GET | `/health` | No | Liveness |
| GET | `/v1/node` | Yes* | Node metadata |
| POST | `/v1/scans` | Yes* | Start scan job |
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

- [ ] Strong `UCPF_SCANNER_API_KEYS`
- [ ] TLS on the public hostname
- [ ] Process binds to localhost behind the proxy when possible
- [ ] `.env` not in git / not world-readable
- [ ] Firewall: do not expose `3847` to the internet
- [ ] Treat scan reports as sensitive (client hosts, inventory)

---

## Related docs

- Architecture / findings: [PRIVACY-BEHAVIOR-SCANNER.md](PRIVACY-BEHAVIOR-SCANNER.md)
- Quick start (plugin): [GETTING-STARTED.md](GETTING-STARTED.md)
- Scanner package README: [`tools/ucpf-scanner/README.md`](../tools/ucpf-scanner/README.md)
