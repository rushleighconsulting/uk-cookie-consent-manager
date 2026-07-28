=== UK Cookie Consent Manager ===
Contributors: rushleighconsulting
Tags: cookies, consent, privacy, gdpr, pecr
Requires at least: 6.8
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.1.0-rc.21
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Privacy-by-design cookie consent and management for UK WordPress sites.

== Description ==

UK Cookie Consent Manager 0.1.0-rc.21 is a release candidate for controlled
staging acceptance. It is not approved for production use.

Release 1 provides an accessible consent banner, granular preferences, prior
blocking for configured non-essential technologies, cookie inventory and
scanning, privacy-preserving consent records, WordPress privacy-tool integration
and signed updates through WordPress's normal plugin-update interface. Multisite
supports Network Activation, per-site data isolation, resumable network
installation and controlled operational defaults.

This plugin supports a site's compliance work. It does not provide legal advice
or guarantee compliance.

== Installation ==

1. Back up the WordPress files and database.
2. Download the named versioned plugin ZIP from the matching GitHub Release.
3. Verify the ZIP against the published SHA256SUMS file.
4. Upload the ZIP through Plugins > Add New > Upload Plugin.
5. Activate the plugin and complete the Cookie Consent setup checklist.
6. Test first-visit choices, withdrawal, prior blocking, scanning and privacy tools on staging.

Do not install GitHub's automatic Source code archives.

== Security ==

Report suspected vulnerabilities privately through GitHub Private Vulnerability
Reporting:

https://github.com/rushleighconsulting/uk-cookie-consent-manager/security/advisories/new

If that form is unavailable, email security@rushleighconsulting.co.uk. Do not
post vulnerability details in a public support topic or ordinary bug report.
Before sending evidence, remove consent records, cookie values, complete IP
addresses, credentials, access tokens, database exports and unrelated personal
data. See the repository SECURITY.md for the coordinated-disclosure process.

== Changelog ==

= 0.1.0-rc.21 =
* Registers the source-free visitor stylesheet handle before attaching and enqueueing the canonical inline CSS.
* Restores the accessible grid layout, focus treatment, minimum targets and equal first-layer consent choices without an external plugin CSS request.
* Adds real WordPress Multisite package acceptance for the inline-only style-loader contract.

= 0.1.0-rc.21 =
* Delivers the canonical visitor-interface stylesheet inline through WordPress, removing the external plugin CSS request.
* Preserves the accessible grid layout, focus treatment, minimum targets and equal first-layer consent choices from one maintainable stylesheet.
* Adds unit and browser regression coverage for the source-free style handle and inline delivery.

= 0.1.0-rc.19 =
* Renames the public consent stylesheet to a neutral visitor-interface asset path after a client blocker rejected the legacy filename.
* Preserves the existing accessible layout, focus treatment, minimum targets and equal first-layer consent choices without changing the stylesheet bytes.
* Adds regression coverage for the neutral asset request, rendered grid layout, 44-pixel action targets and equal action presentation.

= 0.1.0-rc.18 =
* Limits the optional administrator browser check to five eligible HTML pages for a provider-neutral gentle default.
* Spaces consent-scenario navigations by three seconds to avoid request bursts on shared and managed hosting.
* Bounds browser status saves with an eight-second timeout and retries one transient failure with backoff.
* Preserves per-page heartbeats and the 30-minute abandoned-client recovery lease.
* Adds browser regression coverage for recovery after a transient connection refusal.

= 0.1.0-rc.17 =
* Adds per-page browser-check heartbeats and persists start, heartbeat and terminal timestamps.
* Recovers an abandoned browser check to a visible, privacy-safe terminal failure after a 30-minute lease.
* Preserves server scan evidence and site isolation when the administrator browser loses connectivity.
* Adds regression coverage for double connectivity failure and stale-running recovery.

= 0.1.0-rc.16 =
* Recovers queued manual scans from the authenticated Scans screen when WordPress cron loopback dispatch is delayed.
* Uses the persisted per-run lock so the Scans-screen worker and WordPress cron cannot process the same batch concurrently.
* Shows live checked and remaining counts while preserving resumable progress when an administrator leaves and returns.
* Records an operational problem when initial background dispatch cannot start instead of silently leaving a scan queued.
* Adds desktop, mobile and PHP regression coverage for stalled-scan recovery.

= 0.1.0-rc.15 =
* Adds WordPress Multisite Network Activation with resumable, bounded installation and upgrade batches.
* Keeps consent evidence, inventories, scans, findings, policy versions, settings and scheduled work isolated per site.
* Adds Network Administrator controls for safe operational defaults and locks while preserving site-specific legal and privacy choices.
* Initialises newly created sites and requires separate approval before any network-wide uninstall deletion.
* Adds documented Multisite operation and automated two-site package verification for WordPress 6.8.

= 0.1.0-rc.14 =
* Provides the deliberately minimal target for the controlled RC.13 to RC.14 unattended WordPress update test.
* Preserves the accepted RC.13 automatic-update-control correction without functional changes.
* Keeps the verified RC.13 release available for rollback and manual recovery evidence.

= 0.1.0-rc.13 =
* Restores WordPress's native automatic-update control when its current plugin-update cache no longer contains UCCM.
* Checks the live WordPress update metadata instead of treating an older successful-check timestamp as proof that the control is available.
* Avoids redundant update checks when UCCM is already present in WordPress's available-update or no-update list.

= 0.1.0-rc.12 =
* Keeps Cookie preferences closed after accept, reject, granular-save and withdrawal decisions while visitors navigate between pages.
* Clears restored or cached dialog state and refuses synthetic or external dialog opening unless a visitor explicitly activates a UCCM preferences control.
* Adds full-page Chromium and WebKit regression coverage across five navigations for every consent decision.

= 0.1.0-rc.11 =
* Provides the authenticated unattended-update target for the controlled RC.10 to RC.11 WordPress acceptance test.
* Initialises WordPress's native auto-update controls on the first Plugins-screen visit without requiring a manual check from UCCM's Advanced screen.
* Limits automatic initialisation to administrators who can update plugins and prevents repeated checks while the first request is in progress.

= 0.1.0-rc.10 =
* Uses WordPress's normal Plugins screen to check for releases and control automatic updates.
* Embeds the official release-verification key, removing per-site GitHub credentials, manifest addresses and public-key setup.
* Shows installed and latest versions, authenticated-check status, update outcomes, temporary-backup readiness, disk space and loopback health.
* Adds an immediate authenticated update check, update and rollback alerts, immutable release-path validation and signed staged rollouts.

= 0.1.0-rc.9 =
* Keeps portrait mobile actions compact instead of expanding each button into a large vertical panel.
* Bounds the banner to the visible screen and keeps every choice reachable when the viewport is short or text is enlarged.
* Respects iOS safe areas and adds iPhone-sized WebKit regression coverage alongside Chrome and Android-sized checks.

= 0.1.0-rc.8 =
* Makes Trusted proxy IPs available only while forwarded IP headers are trusted, preserving the saved allowlist when trust is turned off.
* Keeps server-side forwarded-header handling disabled unless proxy trust is explicitly enabled.
* Replaces the technical administration Overview with plain-language guidance about cookie choices, background scans and reviewing new items.

= 0.1.0-rc.7 =
* Shows the Chrome, Edge or other Chromium browser requirement before the optional visitor check can start.
* Disables the browser-check action in Safari, Firefox and other unsupported browsers instead of offering a check that will fail.
* Preserves the isolated Chromium visitor flow without falling back to administrator cookies, storage or sign-in state.
* Replaces the technical isolated-context failure note with a plain, actionable explanation.

= 0.1.0-rc.6 =
* Ensures failed or partial administrator browser checks create their privacy-safe WordPress Dashboard alert and enabled administrator email before later scan-record work can fail.
* Replaces the prominent persistent Cookie settings text pill with a compact accessible cookie-icon control, retaining its translated name, keyboard focus label and 48-pixel touch target.
* Preserves the safe refusal of browser observations where an isolated visitor context is unavailable; broader Safari support remains outside this candidate.

= 0.1.0-rc.5 =
* Makes background scans continue reliably on low-traffic sites, simplifies scan wording and clearly explains the necessary choice cookie set after any consent decision.
* Isolates browser observations across pre-consent and category-specific choices, excludes non-page targets and de-duplicates detected storage items across pages and consent states.
* Adds labelled Script Blocking controls for scripts, iframes, embeds and pixels, with accessible validation and a read-only expert view.
* Expands crawler coverage across eligible published pages and posts while excluding media, archive, search, pagination, draft, private and trashed content; password-protected content remains excluded unless explicitly enabled.
* Adds optional encrypted access for one WordPress post password and privacy-safe WordPress Dashboard alerts, with disabled-by-default administrator email notifications and configurable repeat suppression.

= 0.1.0-rc.4 =
* Renames the read-only Categories administration screen to View Categories and validates configured same-origin scan targets before saving.
* Adds a persisted, resumable WP-Cron crawler that discovers safe same-origin public links, de-duplicates cycles and reports progress up to the 1,024-page hard ceiling.
* Adds cancellation, recovery and packaged administrator browser observations for accessible cookies, local storage, scripts, iframes and pixels on successfully visited pages.
* Preserves the WordPress auto-update fatal-error correction delivered in 0.1.0-rc.3.

= 0.1.0-rc.3 =
* Corrects the secure updater callback to accept WordPress's documented nullable auto-update filter value.
* Preserves null, false and true decisions for unrelated plugins while applying UCCM's explicit opt-in only to UCCM.
* Adds regression coverage for the WordPress Plugins-list call path that exposed the rc.2 fatal error.

= 0.1.0-rc.2 =
* Corrects the release archive credential check so documented configuration names do not produce false positives.
* Adds regression checks that accept documentation and reject actual configured signing-key values and GitHub token patterns.
* Preserves the Release 1 functionality prepared in 0.1.0-rc.1.

= 0.1.0-rc.1 =
* First Release 1 candidate; its automated publication stopped safely before assets were published.
* Adds accessible accept, reject, granular preference and withdrawal flows.
* Adds explicit prior blocking for configured scripts, iframes, embeds and pixels.
* Adds privacy-preserving consent receipts with configurable retention.
* Adds administration, curated cookie inventory and CSV export.
* Adds bounded manual and 30-day scheduled scans with human-reviewed findings.
* Adds WordPress personal-data export, erasure and suggested policy text.
* Adds signed, checksum-verified plugin update support.
* Adds desktop/mobile browser tests and packaged-ZIP compatibility checks.
