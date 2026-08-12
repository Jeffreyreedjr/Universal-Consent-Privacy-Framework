# Privacy-behavior scanner

UCPF treats scanning as a **privacy-behavior** check: compare what the site *does* across consent states, browsers, and signals—not only which cookie names exist.

This is a **technical** tool. It does **not** guarantee legal compliance.

## Architecture

```
WordPress plugin → scanner configuration
  → Local CLI (default OSS)
  → Self-hosted agency scanner (optional URL)
  → Optional community registry (opt-in only — off by default)
         ↓
  Fresh isolated browser workers
         ↓
  Private site report (+ optional sanitized vendor intelligence)
```

- **Default OSS:** 100% local (`tools/ucpf-scanner` CLI + guest crawl). No phone-home.
- **Agencies:** set Scanner API URL / `UCPF_SCANNER_URL`.
- **Community:** opt-in contribution + signed catalog pulls only—never silent.

## Scan levels (CLI `--profile`)

| Profile | Sessions (approx.) | Pages |
|---------|-------------------|-------|
| `quick` | Fresh + Reject | ~8 representative |
| `standard` (default) | Core triad + Revoke + GPC on + DNS opt-out | ~40 |
| `compliance` | Full differential matrix (incl. DNS + GPC) | ~80 |

## Findings vocabulary

Each cookie / tracking-like request may receive:

| Finding | Meaning (technical) |
|---------|---------------------|
| `blocked_before_consent` | Necessary present pre-consent (informational) |
| `incorrectly_loaded_before_consent` | Consent-required signal before any action |
| `correctly_loaded_after_accept` | Appears after accept only |
| `still_loaded_after_reject` | Still present after reject (also seen before consent) |
| `retained_after_revoke` | Set after accept, still in jar after revoke (cleanup warning — not a “tracking still on” fail) |
| `still_loaded_after_dns` | Still present with first-party `ucpf_dns` opt-out cookie |
| `still_loaded_after_gpc` | Still present with `Sec-GPC` / navigator GPC on |
| `removed_after_revocation` | Cleared after revoke |
| `category_mismatch` | Category vs behavior conflict |
| `indeterminate` | Insufficient evidence |

Fail set for CI / pass UI (blocking / pre-consent leaks): `incorrectly_loaded_before_consent`, `still_loaded_after_reject`, `still_loaded_after_dns`, `still_loaded_after_gpc`, `category_mismatch`.

Warn (cleanup only): `retained_after_revoke` — does **not** fail the differential or CI exit code.

The `dns_opt_out` session injects `ucpf_dns` (sale/sharing/targeted/profiling/nonessential denied) before navigation and runs light interactions / recipes so cookieless beacons are exercised.

Schema: `ucpf-playwright-scan/2.0` (`findings[]`, `findings_summary`). Importer still accepts `1.x`.

## Capture v2

- Cookies via CDP: attributes, partition/CHIPS when available, **value hash only**
- Storage: local/session/IDB/Cache/SW (+ Shared Storage / Cookie Store flags)
- Network: scripts, beacons, pixels, fonts, media, websockets; initiator when CDP allows
- Dual-pass note: SW-allowed vs SW-bypass observational pass
- Proxy heuristics: `possible_server_side_tracking`, `first_party_proxy_unknown`, etc. (observed vs inferred)

## Privacy signals

- GPC: `Sec-GPC` profile + `navigator.globalPrivacyControl` probe
- TCF: existing CMP/TCF heuristics
- GCM / GPP: observational only (`consent_mode`, `gpp`) — not “lawful”

## CLI exit codes (CI)

| Code | Meaning |
|------|---------|
| 0 | Pass |
| 1 | Policy violation (fail findings / consent leaks) |
| 2 | Incomplete |
| 3 | Scanner error |

```bash
cd tools/ucpf-scanner
npm run scan -- --url https://example.com --profile standard --out report.json
echo $?   # 0 / 1 / 2 / 3
```

## Safe interactions & recipes

Default recipes never purchase, never submit forms, never request permissions.

Optional JSON:

```json
[
  { "action": "scroll" },
  { "action": "focus", "selector": "form input[type=email]" },
  { "action": "click", "selector": ".wp-block-video" }
]
```

`--recipe ./recipe.json`

## Agency federation (1.3)

- Node metadata: `GET /v1/node` (`node_id`, regions, browsers, version)
- Drift: `POST /v1/drift` or CLI `--baseline previous.json`
- Domain verification: site serves `/.well-known/ucpf-scan-token`; scanner `POST /v1/verify-domain`
- SSRF: private IP block, no redirect follow on verify, API keys required off-loopback

WP: `Agency_Scanner` stores baselines and exposes the well-known token.

## Community registry (1.4 foundations)

Modes via `UCPF_REGISTRY_MODE` or setting `registry_mode`: `local` | `agency` | `community` | `disabled`.

- Remote registry remains **off** (`remote_registry_enabled` false by default)
- Community requires **double opt-in** (mode + enable flag)
- Contributions sanitized by `Community_Registry::sanitize_contribution` — patterns only
- Catalogs: data/rules JSON, optional signature filter — **never** remote executable code

## Hybrid config in WordPress

**Advanced → Deep privacy scanner**

1. Leave URL blank → Local CLI import
2. Set agency Scanner API URL + key → remote jobs
3. Community later — document External services in `readme.txt` when enabled

## Non-goals

- Guaranteed legal compliance claims  
- Silent phone-home or auto community upload  
- Remote executable code via registry  
- Mandatory UCPF-hosted scanner  
