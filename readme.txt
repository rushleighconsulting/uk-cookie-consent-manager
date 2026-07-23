=== UK Cookie Consent Manager ===
Contributors: rushleighconsulting
Tags: cookies, consent, privacy, gdpr, pecr
Requires at least: 6.8
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.1.0-rc.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Privacy-by-design cookie consent and management for UK WordPress sites.

== Description ==

UK Cookie Consent Manager 0.1.0-rc.2 is a release candidate for controlled
staging acceptance. It is not approved for production use.

Release 1 provides an accessible consent banner, granular preferences, prior
blocking for configured non-essential technologies, cookie inventory and
scanning, privacy-preserving consent records, WordPress privacy-tool integration
and signed private-repository updates.

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
* Adds signed, checksum-verified private-repository update support.
* Adds desktop/mobile browser tests and packaged-ZIP compatibility checks.
