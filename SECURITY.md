# Security Policy

## Supported versions

Security fixes are applied to the latest release on `main` and published via GitHub Releases / WordPress.org when listed.

## Reporting a vulnerability

Please use **GitHub Security Advisories** on the public repository:

https://github.com/Jeffreyreedjr/Universal-Consent-Privacy-Framework/security/advisories/new

If Advisories are unavailable, email the maintainers listed on the repository — do not file a public issue with exploit details.

We aim to acknowledge reports within **7 days** and to ship a fix or mitigation as quickly as practical.

## Scope

In scope:

- WordPress plugin (`includes/`, admin, REST, consent storage)
- Optional Playwright scanner companion (`tools/ucpf-scanner`) when used as documented

Out of scope:

- Misconfiguration (e.g. scanner exposed without API keys / TLS)
- Third-party plugins/themes interacting with consent
- Legal/compliance interpretations

## Security posture (by design)

- No phone-home updater or telemetry by default
- Remote registry off by default; never loads remote executable code
- Scanner API requires keys for non-loopback clients
- Prefer `UCPF_SCANNER_API_KEY` in `wp-config.php` over storing keys only in the database on production
- Do not commit scan reports that contain client inventories
