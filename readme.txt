=== Universal Consent & Privacy Framework ===
Contributors: universalconsent
Tags: privacy, gdpr, cookies, consent, cookie banner
Requires at least: 6.3
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.4.7
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

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install from WordPress.org
2. Activate the plugin
3. Go to **Privacy Consent → Setup Wizard** and complete setup (scan, services, pages, enable banner)
4. Optionally brand under **Banner & Branding**

== Frequently Asked Questions ==

= Does this phone home? =

No. Remote registry sync is disabled by default and requires explicit admin opt-in. There is no custom phone-home updater — updates come from WordPress.org (or GitHub Releases if you installed from zip).

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

Google/Meta/Microsoft scripts load only after consent when you enable service templates and provide IDs.

Optional self-hosted scanner APIs you configure yourself are operator-controlled; the plugin never loads remote executable code from them (JSON reports only).

== Changelog ==

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
* Modern admin shell + React dashboard, self-hosted fonts, GSAP consent motion (WCAG focus/hover/active)

= 1.1.0 =
* Open-source readiness, white-label branding UI, Classic theme rename
* Empty default scanner URL; hardened companion scanner auth
* WordPress.org / GitHub release packaging helpers

= 1.0.38 =
* Global scan noise filters

= 1.0.0 =
* Initial release
