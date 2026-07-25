=== UK Cookie Consent Manager ===
Contributors: rushleighconsulting
Tags: cookies, consent, privacy, gdpr, pecr
Requires at least: 6.8
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.1.0-rc.12
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Privacy-by-design cookie consent and management for UK WordPress sites.

== Description ==

UK Cookie Consent Manager 0.1.0-rc.12 is a release candidate for controlled
staging acceptance. It is not approved for production use.

Release 1 provides an accessible consent banner, granular preferences, prior
blocking for configured non-essential technologies, cookie inventory and
scanning, privacy-preserving consent records, WordPress privacy-tool integration
and signed updates through WordPress's normal plugin-update interface.

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

== Changelog ==

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
