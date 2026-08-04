=== Universal Consent & Privacy Framework (Alpha) ===
Contributors: jeffreyreedjr
Tags: privacy, gdpr, cookies, consent, cookie banner
Requires at least: 6.3
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.15-alpha
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Standardizes privacy, cookie consent, script blocking, privacy pages, and a developer service registry for WordPress.

== Description ==

Universal Consent & Privacy Framework (UCPF) helps support privacy compliance on WordPress sites. It is not legal advice and does not guarantee regulatory compliance.

**Features**

* Consent banner with Reject All, Accept All, and granular preferences
* Guided setup wizard (scan, services, cookie review, generate pages, go live)
* Easy branding: business name, logo, themes, accents, custom CSS
* Bundled cookie/service catalog (WordPress, WooCommerce, analytics, marketing, embeds)
* Privacy-behavior scanner (consent differential) + optional Playwright deep-scan import
* Scan noise filters for security lockout / admin artifacts
* Per-service treatment controls (necessary / consent / ignore)
* Bundled WP Consent API compatibility shim
* Script registry with local JSON vendor catalogs (remote registry off by default)
* Three-level script blocking (managed, markup, optional output buffer)
* Privacy/cookie policy page generator
* Data request forms and WordPress privacy exporter/eraser integration
* Consent audit logging (privacy-minimized)
* Google Consent Mode v2 support
* Developer API: `ucpf_register_service()`, hooks, REST endpoints
* Classic + studio design presets

**What it does not guarantee**

Final legal review is the site owner's responsibility. Generated policies are templates only.

== Screenshots ==

1. Banner & Branding admin — themes, accents, and live preview
2. Front-end consent banner — Reject All, Customize, and Accept All
3. Cookie Scanner — page selection, consent coverage, and inventory review

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install from WordPress.org
2. Activate the plugin
3. Go to **Privacy Consent → Setup Wizard** and complete setup (scan, services, pages, enable banner)
4. Optionally brand under **Banner & Branding**

== Frequently Asked Questions ==

= Does this phone home? =

No. Remote registry sync is disabled by default and requires explicit admin opt-in. There is no custom phone-home updater — updates come from WordPress.org (or GitHub Releases if you installed from zip). Optional **Contribute cookie knowledge** only downloads a scrubbed JSON file for you to attach on GitHub; WordPress does not upload it.

= How do I contribute cookie descriptions? =

Use Cookie Scanner → Contribute cookie knowledge: confirm the checkbox, download the pack, open the GitHub issue template, and attach the file. Metadata only (no cookie values). Maintainers may merge entries into the vendor catalog.

= How do I run a deep privacy scan? =

Use the companion Playwright scanner from the GitHub repository (`tools/ucpf-scanner`) locally and import the JSON, or point Advanced settings at your self-hosted scanner API. The scanner is optional companion software and is not required for basic guest crawls.

= Does it work with the WP Consent API? =

Yes. UCPF bundles a compatibility shim and syncs with the official WP Consent API when installed.

= Can agencies white-label it? =

Yes. Use Banner & Branding settings and optionally `wp-content/ucpf-brand.php`. Prefer that over forking so WordPress.org updates keep working.

== Developer API ==

`ucpf_has_consent( 'analytics' )`

`ucpf_register_service([ 'key' => 'my_tracker', 'category' => 'analytics', ... ])`

See the GitHub repository docs (`docs/DEVELOPER.md`).

== External services ==

By default, none. If you enable optional remote registry sync, the plugin fetches signed JSON metadata from an admin-configured URL only.

Optional **Contribute cookie knowledge** opens GitHub in your browser when you click the button; the plugin does not POST contribution data.

Google/Meta/Microsoft scripts load only after consent when you enable service templates and provide IDs.

Optional self-hosted scanner APIs you configure yourself are operator-controlled; the plugin never loads remote executable code from them (JSON reports only).

== Credits ==

Created and developed by Jeffrey Reed Jr.

Cookie descriptions may fall back to a bundled offline snapshot of the Open Cookie Database (https://github.com/jkwakman/Open-Cookie-Database). Attribution to jkwakman/Open-Cookie-Database. The snapshot is local only (no runtime phone-home to cookiedatabase.org) and is not a compliance guarantee.

== Changelog ==

= 0.1.15-alpha =

* Fix: stop MutationObserver from watching class (was breaking Elementor fade/sticky Motion Effects + Turnstile)

= 0.1.14-alpha =

* Emergency: restore consent/guard/loader JS from known-good 0.1.10 (fixes Accept/Enable browser freeze)

= 0.1.13-alpha =

* Hotfix attempt: hard-reload only (still needed full JS restore)

= 0.1.12-alpha =

* Hybrid consent UX: Enable Security/Embeds inside any modal activates in place (no reload); banner Accept/Reject/Save still hard-reloads
* After reload: scroll restore, Calendly loading-until-ready, ~5 min slow-network retries

= 0.1.11-alpha =

* Embeds & Widgets: reliably activate gated scripts after Enable/Accept; ensure Calendly SDK + re-init (Elementor popups); longer post-reload retry

= 0.1.10-alpha =

* Cloudflare: Bypass Elementor uploads/css so year-cache cannot poison post-*.css as HTML; Cache Files 4xx guidance
* Origin cache flush on any plugin/theme update and Elementor CSS clear (no Cloudflare API)
* Calendly / Elementor popup embeds: surface guard + re-init after Functional consent
* Fix form-captcha-guard insertBefore crash on Elementor shells

= 0.1.9-alpha =

* Cloudflare Cache Rules guidance in Advanced Settings (consent cookie / ?_ucpf= / plugin asset ?ver= Bypass clauses)
* Safe update: version bump so migrations + asset cache bust run on zip overwrite

= 0.1.8-alpha =

* Geo pack routing (Cloudflare auto-on): US privacy baseline vs GDPR; admin dashboard UX refresh; banner copy cleanup

= 0.1.7-alpha =
* Agency Git knowledge hub: sync status + Refresh now, merge-knowledge-hub.ps1, smarter export grouping, wildcard knowledge match (no hosted DB)
* Cookie Policy collapses property-/site-specific tracker cookies to catalog patterns; Integration IDs remain admin-only
* Replace GreenSock GSAP with CSS motion (WordPress.org GPL-compatible)
* Open Cookie Database offline snapshot attribution in Credits
* WordPress.org directory assets (icons, banners, screenshots)
* Catalog-driven consent gate for analytics/marketing/functional/security (scripts + stylesheets; any builder)
* Vendor catalog: ActiveCampaign, ConvertKit, Drip, GetResponse, MailerLite, Font Awesome

= 0.1.6-alpha =
* Agency scanner queue: waiting queue + per-key caps, no cancel-all on busy, durable jobs, queue position in admin, scheduled stagger/backoff

= 0.1.5-alpha =
* Clean inventory noise: collapse hashed *.w.hcaptcha.com workers, drop about:blank, classify hCaptcha / fonts / Jotform / UserWay / CF challenges

= 0.1.4-alpha =
* Deep scan: session time budget scales with real session count and selected page count; truncation is logged; intensity copy clarifies URLs × sessions

= 0.1.3-alpha =
* Compact banner actions stack full-width so Accept All stays inside the card (3-up row overflow)

= 0.1.2-alpha =
* Fix Save Preferences: banner boot no longer swallows the customize save click (Accept/Reject still worked)

= 0.1.1-alpha =
* Fix compact banner action row: Accept All no longer overflows the card (equal-width grid + compact padding)

= 0.1.0-alpha =
* Versioning reset: this project is pre-1.0 / Alpha. Prior 1.4.x builds were development iterations, not a stable 1.x release line.
* Privacy and Cookie Policy copy polish carried forward from the last 1.4.26 work

= 1.4.26 =
* Privacy and Cookie Policy copy: shorter professional sentences, commas and periods only (no em dashes or semicolons), clearer rights headings
* Soften generic scan purpose text to “Observed during a privacy scan of this website.”

= 1.4.25 =
* Prefs / modal action buttons: clearer gaps, no text wrap crush, wider dialog so Reject / Save / Accept stay aligned with breathing room

= 1.4.24 =
* Banner no longer shows Do Not Sell / Data Request links; those rights are stated clearly on Cookie Policy and Privacy Policy (with links when URLs are set)

= 1.4.23 =
* Banner UX: Do Not Sell / Data Request links in popup footer (no floating DNS button); quieter Cookie Settings control; aligned modal/prefs buttons
* Theme presets: load all theme CSS + sync theme class from settings; clear factory classic accent so Neon/Ocean/Light actually apply

= 1.4.22 =
* Knowledge / contribution packs anonymized: no site URL, no first-party hosts; generalize property-specific cookie ids

= 1.4.21 =
* Knowledge export syncs last scan + Cookie Review into the pack (was only sparse manual knowledge entries)

= 1.4.20 =
* Banner / prefs link Data Request and Do Not Sell URLs when set; open in a new tab

= 1.4.19 =
* Consent remember harden: Accept and Reject persist via cookie + localStorage backup; banner stays hidden on return visits

= 1.4.18 =
* Cookie Review re-matches unknowns against vendor catalog (e.g. WooCommerce sbjs_*)
* Reject ambiguous short OCD/catalog hits (bare cookie name "c" ≠ Magnite without host)

= 1.4.17 =
* Contribute cookie knowledge: scrubbed pack download + GitHub issue helper (no phone-home upload)

= 1.4.16 =
* Cookie Lookup on Cookie Scanner (catalog → site knowledge → Open Cookie Database)
* Per-site knowledge log with export/import packs for agency GitHub hub + opt-in remote registry

= 1.4.7 =
* Removed third-party agency branding; recommended defaults button; classic-only legacy theme remap

= 1.4.6 =
* Rights Inbox in UCPF sidebar; Cookie Review table layout (no crushed cookie names)

= 1.4.5 =
* Do Not Sell / Data Request no longer auto-generated; set home-site page URLs + paste shortcodes
* Rights forms field/API guide (docs/RIGHTS-FORMS.md) and Generated Pages checklist

= 1.4.4 =
* Admin tables: horizontal scroll, no mid-word squish, roomier cookie review fields
* Generated legal pages: readable document card, contrast-safe links, clearer typography

= 1.4.3 =
* Cookie display overrides (title, purpose, visibility) in Cookie Review
* Public Cookie/Privacy Policy honor ignore / hide / document-only; refresh on review save

= 1.4.2 =
* Privacy Policy generator: cookies, trackers, plugins, destinations, GDPR/CPRA/global rights sections
* Live [ucpf_privacy_disclosures] inventory; auto-refresh Privacy Policy after scan

= 1.4.1 =
* Scanner DNS opt-out session + still_loaded_after_dns / still_loaded_after_gpc findings
* Unknown-host catalog suggestions (site-local) wired into network gate extras
* Rights Inbox vendor suppress queue UI (complete / clear)

= 1.4.0 =
* Jurisdiction packs (GDPR, US/CPRA, CO/CT/VA, LGPD, Quebec) with geo routing hooks
* CPRA Do Not Sell or Share / Limit Use UX + privacy choices entry
* Expanded network gate; Rights Inbox; vendor connectors; recommended defaults + health scorecard

= 1.3.1 =
* Hard-gate Google Analytics / GTM network collects (including click events) until analytics consent
* Earlier Consent Mode defaults + broader Google catalog script patterns

= 1.3.0 =
* Privacy-behavior scanner: consent differential findings, capture v2, scan profiles, CI exit codes
* Agency/community registry foundations (local-first; remote off by default)

= 1.2.0 =
* Modern admin shell + React dashboard, self-hosted fonts, CSS consent motion (WCAG focus/hover/active)

= 1.1.0 =
* Open-source readiness, white-label branding UI, Classic theme rename
* Empty default scanner URL; hardened companion scanner auth
* WordPress.org / GitHub release packaging helpers

= 1.0.38 =
* Global scan noise filters

= 1.0.0 =
* Initial release
