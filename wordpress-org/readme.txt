=== UK Cookie Consent Manager ===
Contributors: rushleighconsulting
Tags: cookies, consent, privacy, gdpr, pecr
Requires at least: 6.8
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.1.0-rc.16
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accessible cookie choices, prior blocking, reviewed cookie inventory, scanning and privacy-preserving consent evidence for UK WordPress sites.

== Description ==

UK Cookie Consent Manager helps site operators present clear cookie choices,
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
2. Search for "UK Cookie Consent Manager".
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

== Changelog ==

= 0.1.0-rc.16 =

* Recovers queued manual scans when WordPress cron loopback dispatch is delayed.
* Shows live checked and remaining counts while keeping scans resumable.
* Records a clear operational problem when initial background dispatch cannot start.
