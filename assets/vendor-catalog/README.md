# Vendor catalog

Bundled cookie and service definitions that ship with the plugin zip. This is UCPF’s local “cookie database” — **no phone-home**, **no hosted Docker/DB required**.

Edit JSON under this folder and ship updates in plugin releases.

## Scanning

1. Prefer **Deep privacy scan** (Playwright companion — local CLI or your self-hosted API) from Cookie Scanner — inventories cookies (incl. HttpOnly), storage, scripts, iframes, beacons across no-consent / reject / accept.
2. Or run the built-in guest crawl for a lighter pass.
3. Review unknowns; refresh Cookie Policy.

See `tools/ucpf-scanner/README.md` and `docs/GETTING-STARTED.md`.
