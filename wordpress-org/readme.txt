=== Rushleigh Cookie Choices ===
Contributors: rushleighconsulting
Tags: cookies, consent, privacy, gdpr, pecr
Requires at least: 6.8
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accessible cookie choices, prior blocking, reviewed cookie inventory, scanning and privacy-preserving consent evidence for UK WordPress sites.

== Description ==

Rushleigh Cookie Choices helps site operators present clear cookie choices,
remember those choices and prevent configured optional technologies from running
before the relevant permission exists.

Release 1 includes:

* Accept, reject, manage and withdraw choices using keyboard, touch or pointer.
* A necessary first-party choice cookie with a configurable lifetime.
* Controlled banner colours, font, position and preview with contrast safeguards.
* Prior blocking rules for configured scripts, frames, embeds and tracking images.
* A reviewed cookie inventory and resumable same-origin site scans.
* Privacy-preserving consent receipts and WordPress privacy-tool integration.
* Single-site and WordPress Multisite operation.

UCCM supports a site's compliance work. It does not provide legal advice or
guarantee that a site complies with UK GDPR, PECR or another law. Site operators
must review their actual cookies, purposes, lawful bases, retention and notices.

= Privacy and external services =

The plugin stores consent and scan information in the site's own WordPress
database. It does not send visitor consent records or scan observations to
Rushleigh Consulting.

Cookie scans request only eligible pages on the configured WordPress site's own
origin. Administrators may explicitly configure access to one WordPress
password-protected post; that password remains encrypted on the site.

This WordPress.org distribution receives executable updates only through
WordPress.org's normal plugin-update service. It does not fetch executable
updates from GitHub.

== Installation ==

1. In WordPress, open Plugins > Add New.
2. Search for "Rushleigh Cookie Choices".
3. Select Install Now, then Activate.
4. Open Cookie Consent and review the setup pages.
5. Configure the banner, inventory and any blocking rules.
6. Test accept, reject, preferences, withdrawal and blocking on a staging site.
7. Run a scan and review every finding before changing the curated inventory.

On Multisite, a Network Administrator may activate UCCM for the network or an
individual Site Administrator may activate it for one site.

== Frequently Asked Questions ==

= Does this plugin guarantee legal compliance? =

No. It provides technical controls and evidence to support compliance work.
The site operator remains responsible for legal review and configuration.

= Why is a cookie set after I reject optional cookies? =

One necessary first-party cookie remembers the visitor's choice so the site does
not repeatedly ask. Optional categories remain rejected.

= Does a scan find every cookie? =

No. Scans are bounded observations. Logged-in, personalised, regional and
third-party journeys may behave differently. Administrators must review findings
and test representative visitor journeys.

= Where can I ask for help? =

Use the plugin's WordPress.org support forum for ordinary support. Do not post
security vulnerabilities, visitor records, cookie values, complete IP addresses,
credentials or database exports publicly.

= How do I report a security problem? =

Follow the private reporting instructions in the Security section of the
project repository:
https://github.com/rushleighconsulting/uk-cookie-consent-manager/security/policy

== Security ==

Report suspected vulnerabilities privately through GitHub Private Vulnerability
Reporting:

https://github.com/rushleighconsulting/uk-cookie-consent-manager/security/advisories/new

If that form is unavailable, email security@rushleighconsulting.co.uk. Do not
post vulnerability details in a public support topic or ordinary bug report.
Before sending evidence, remove consent records, cookie values, complete IP
addresses, credentials, access tokens, database exports and unrelated personal
data. See the repository SECURITY.md for the coordinated-disclosure process.

== Screenshots ==

1. Accessible first-visit banner with equally prominent Accept all, Reject non-essential and Manage preferences actions.
2. Anonymous Cookie preferences dialog for Necessary, Functional, Analytics and Marketing choices.
3. Banner settings with controlled colours, font, position, consent lifetime, policy version and preview controls.
4. Multisite network status and shared defaults with site-specific override and lock visibility.
5. Privacy-conscious scanning settings with bounded coverage and explicit browser-check controls and limitations.
6. Privacy-by-default settings for retention, masked identifiers and optional full-IP collection disabled.

== Changelog ==

= 1.0.2 =

* Renames the plugin and permanent directory identity to Rushleigh Cookie Choices with the distinctive `rushleigh-cookie-choices` slug.
* Records the reviewer-requested nonce, capability, sanitisation, licensing and functionality audit.
* Clarifies that the inert `type="text/plain"` resource marker is consent metadata and is deliberately not enqueued.

= 1.0.1 =

* Removes obsolete manual text-domain loading so WordPress.org language packs load through modern WordPress just-in-time translation handling.
* Records the reviewed disposition of the remaining Plugin Check warnings without changing consent or blocking behaviour.

= 1.0.0 =

* First stable release following controlled WordPress Multisite and Windows 11/NVDA acceptance.
* Provides accessible consent choices, prior blocking, reviewed inventory and scanning, privacy-preserving evidence, translation support and Multisite operation.
* Uses WordPress.org's native plugin update channel for directory-installed copies.

= 0.1.0-rc.21 =

* Registers the source-free visitor stylesheet handle before attaching and enqueueing the canonical inline CSS.
* Restores the accessible consent presentation without an external plugin CSS request.
* Adds real WordPress Multisite package acceptance for the inline-only style-loader contract.

= 0.1.0-rc.20 =

* Delivers the canonical visitor-interface stylesheet inline through WordPress, removing the external plugin CSS request.
* Preserves the accessible consent presentation from one maintainable stylesheet.
* Adds unit and browser regression coverage for source-free inline style delivery.

= 0.1.0-rc.19 =

* Uses a neutral public visitor-interface stylesheet path after a client blocker rejected the legacy filename.
* Preserves the existing accessible consent presentation without changing the stylesheet bytes.
* Adds delivery and visual-regression coverage for the neutral asset.

= 0.1.0-rc.18 =

* Limits the optional administrator browser check to five eligible pages and spaces consent scenarios by three seconds.
* Bounds status saves and retries one transient connection failure without creating a retry storm.
* Retains per-page heartbeats and abandoned-client recovery.

= 0.1.0-rc.17 =

* Persists browser-check heartbeats and terminal timestamps.
* Recovers an abandoned browser check to a visible terminal failure while preserving server scan evidence.

= 0.1.0-rc.16 =

* Recovers queued manual scans when WordPress cron loopback dispatch is delayed.
* Shows live checked and remaining counts while keeping scans resumable.
* Records a clear operational problem when initial background dispatch cannot start.
