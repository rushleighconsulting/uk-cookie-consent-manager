# UK Cookie Consent Manager operating guide

This guide is for site owners and administrators operating a Release 1 package.
The plugin supports compliance work; it does not replace legal advice or a
site-specific cookie audit.

## Supported platform

Release 1 is tested on supported combinations spanning WordPress 6.8–7.0 and
PHP 8.2–8.5. Use a currently supported WordPress/PHP pairing, HTTPS, a working
WP-Cron runner and a database backup process.

## Install and activate

1. Back up the WordPress files and database.
2. Download the versioned ZIP and `SHA256SUMS` from the matching GitHub Release.
3. Verify the ZIP checksum before upload.
4. In WordPress, open **Plugins → Add New → Upload Plugin**, select the ZIP and
   activate **UK Cookie Consent Manager**.
5. Open **Cookie Consent → Overview** and complete every setup item.
6. Clear page/CDN caches and test a first visit in a private browser window.

Never install a source archive from GitHub's automatic “Source code” links. Use
the versioned plugin ZIP produced by the Release workflow.

## Configure before production use

- **Banner:** review visitor-facing wording and the consent-policy version.
  Increment the policy version whenever a material policy change requires a new
  decision.
- **View Categories:** review the fixed Necessary, Functional, Analytics and
  Marketing definitions. Necessary is always enabled; optional categories default
  to denied.
- **Script Blocking:** add each recognised optional script, iframe, embed or
  pixel through the guided rule editor. Give it a unique Rule ID, choose its type
  and category, then provide a WordPress handle or complete HTTPS source. The
  Advanced JSON section is a read-only expert view. Test both denial and grant;
  UCCM does not guess a category or rewrite arbitrary HTML.
- **Privacy:** keep complete-IP storage disabled unless a documented lawful
  purpose requires it. If proxy headers are enabled, allowlist only the exact
  reverse-proxy addresses. The allowlist field is available only while
  forwarded-header trust is selected; turning trust off preserves the saved
  addresses but keeps forwarded headers ignored.
- **Retention:** confirm the consent-record retention period. Cleanup runs daily
  through WP-Cron and defaults to 365 days.
- **Updates:** UCCM needs no GitHub account or site-specific update credentials.
  Open **Plugins** to enable or disable WordPress automatic updates for UCCM.
  Open **Cookie Consent → Advanced** to check the installed and latest versions,
  run an immediate authenticated check, and review temporary-backup, disk-space
  and loopback readiness. Resolve any recovery warning before relying on
  unattended updates.

## WordPress Multisite

UCCM may be activated on one site or Network Activated. Network Activation
initializes existing sites in resumable groups of no more than 25. WP-Cron
continues any remaining installation or upgrade batches; check **Network Admin
→ Cookie Consent** until the batch reports `completed`. A site created later is
initialized automatically.

Every site keeps separate prefixed database tables, settings, roles, consent
records, inventories, scans, findings and WP-Cron events. Scheduled retention
and scanning run in the site context that owns the data. This remains true for
subdirectory, subdomain and domain-mapped networks.

Network Administrators can enable operational defaults for consent lifetime,
retention, scan limits and error-email behaviour. Settings resolve in this
order:

1. UCCM's privacy-preserving plugin default.
2. An enabled network default.
3. An explicit site override.
4. An explicit network lock.

A Site Administrator can select **Use the network default for this site** for
an unlocked setting. A locked value is visible but cannot be changed at site
level. Network defaults never control the consent policy version, scan URLs,
post-password access, complete-IP storage, forwarded-header trust or trusted
proxy addresses; those legal and privacy-sensitive choices remain site-owned.

Plugin files and updates are shared by the network. Test an update on a staging
network, confirm the Network Admin batch completes, then smoke-test more than
one representative site. Rollback restores shared plugin files, while each
site's backward-compatible data remains in its own tables.

Uninstall retains every site's data by default. Network-wide deletion requires
the separate destructive option on **Network Admin → Cookie Consent**. A
single-site uninstall preference never authorizes network-wide deletion. Take a
verified database backup before enabling that option.

## Inventory and scanning

Maintain the curated inventory in **Cookie Consent → Cookie Inventory**. A scan
is evidence for human review, not an automatic publication or compliance
decision.

Run a manual scan after installation and after material theme, plugin, tag
manager or content changes. Starting a scan returns immediately. WP-Cron resumes a
persisted frontier in bounded batches. Targets are ordered predictably: the homepage
first, then the current site's published WordPress pages and posts ordered by content
type and WordPress ID, then administrator-configured URLs. The configured limit applies
to the whole queue and cannot exceed 1,024 URLs.

Published pages and posts are seeded even when they are unlinked or carry `noindex`
or `nofollow`. Draft, pending, auto-draft, future, trashed, private,
password-protected and attachment records are not seeded by default. On the Scans
screen, an administrator with the scan capability may explicitly enable protected
content and save one WordPress post password. The password is encrypted using
site-held WordPress salt material and is never displayed again. Only published pages
and posts unlocked by that exact password become eligible; all other protected,
unpublished and authenticated content remains excluded. Link discovery continues
from eligible HTML but ignores direct media files, WordPress attachments, category,
tag, author, date and search archives, and pagination by default. An archive or other
same-origin public URL entered explicitly by an administrator remains eligible.
Trailing-slash and known tracking-query variants are de-duplicated; meaningful query
values remain separate targets. Cross-origin, private, credential-bearing and unsafe
URLs are rejected. Administration, login, REST and feed paths are always excluded;
additional path patterns can be configured on the Scans screen.

To replace the protected-content password, enter a new value and save. To remove it,
select **Remove the stored post password** and save. Rotating WordPress authentication
salts deliberately makes the encrypted password unusable; enter it again if protected
scanning is still required. The setting and encrypted value are per site on Multisite.
The scanner never uses WordPress user accounts, HTTP Basic credentials, identity
providers or cross-origin credentials.

Progress reports distinguish eligible WordPress pages/posts, other accepted links,
ignored links, checked URLs and URLs still waiting. Ignored-link evidence is stored only
as non-sensitive summary counts.

If a batch is interrupted, its saved progress remains visible and a failed run can be
resumed. A queued or running scan can be cancelled without deleting its evidence. After
the server crawl completes, open that run and choose **Run browser check** to inspect up
to 100 successfully visited pages for accessible cookie names, local-storage keys,
scripts, iframes and pixels. For privacy, this isolated visitor check currently requires
a current Chrome, Edge or other Chromium-based browser. Safari and Firefox show the
requirement and keep the action disabled instead of starting an unsafe or unsupported
check. Review new and changed findings, then mark them reviewed, ignored or resolved as
appropriate.

### Scan limitations

Scanning is intentionally bounded and non-exhaustive. Server inspection can see
observable `Set-Cookie` response headers, while the authenticated browser-runner
boundary can report allowlisted cookies, local-storage keys, scripts, iframes and
pixels. A scan may miss conditional, authenticated, geo-specific, delayed,
interaction-triggered, blocked, consent-dependent or third-party behaviour. It
does not capture consent records, cookie values, form values or page content. The
stored post password and native WordPress post-password cookie are also excluded from
findings, database evidence, email, logs, REST responses and exports.
Repeat representative manual journeys and use browser developer tools alongside
the scanner.

WP-Cron is traffic-dependent unless the site uses a real cron runner. Monitor queued and
running scans and use the resume control if a batch reports failure. The 1,024-page total
remains a hard ceiling rather than a completeness guarantee. The administrator browser
pass is limited to 100 pages per session and can be prevented by a page's framing policy.


## Operational errors and notifications

Safely handled scan failures, background scans which have not progressed for 30
minutes, and failed or partial administrator browser checks appear on the main
WordPress Dashboard for users with the UCCM settings capability. Each notice
contains a plain-language summary, stable error code, last-seen UTC time and a
link to the affected scan. Dismissing a notice hides that occurrence; completing,
resuming or cancelling the affected work resolves it. A later recurrence can
open it again.

Email is off by default on clean installation and upgrade. To opt in, open
**Cookie Consent → Advanced**, enable **Email operational error notifications to
the site administrator**, and save. UCCM sends through WordPress `wp_mail()` to
the current site's **Administration Email Address**. On Multisite, both the
setting and recipient remain scoped to the affected site.

Repeated instances of the same code, component and scan are grouped into one
bounded record. **Repeat email suppression (minutes)** controls when another
email may be sent for that same problem. It defaults to 360 minutes, accepts
whole-minute values from 1 to 1,440, and cannot exceed 24 hours. UCCM retains
the attempt time, count and delivery status, but never the message body. Notices and emails exclude consent records, IP addresses or
fingerprints, passwords, update credentials, authentication cookies, cookie
values, page content, form values and stack traces.

If delivery fails, confirm the site's administration email, then test the
WordPress mail configuration or its SMTP provider. The Dashboard notice remains
the authoritative recovery route even when email is unavailable.


## Consent records and privacy requests

Consent records are stored on the WordPress site. Default evidence includes a
random receipt ID, UTC time, decision, policy/plugin versions, category choices,
a keyed site identifier, masked IP and a non-reversible keyed IP fingerprint.
Complete IP storage is off by default.

Restrict consent-record viewing and export capabilities to authorised staff.
WordPress **Tools → Export Personal Data** and **Erase Personal Data** operate
only on receipts linked to a logged-in WordPress account. Erasure removes the
account and IP-derived identifiers; non-attributable decision evidence remains
until retention cleanup. Anonymous receipts cannot be matched to an email.

Review and adapt the suggested WordPress privacy-policy text to reflect the
site's actual services, purposes, lawful basis and retention.

## Updates

1. Back up the database and plugin directory.
2. Confirm the release tag, signed manifest version and ZIP filename agree.
3. Verify the manifest signature and SHA-256 checksum through the configured
   updater.
4. Apply the update first on a staging copy.
5. Test activation, first-visit accept/reject/manage flows, withdrawal, prior
   blocking, inventory access, a manual scan and privacy export/erasure.
6. Apply to production during a monitored maintenance window and clear caches.

Automatic updates are opt-in. A missing key, bad signature, incompatible
version, failed request or checksum mismatch blocks the update. Never bypass a
failed integrity check.

## Rollback

1. Put the site into a controlled maintenance window and record the failure.
2. Disable automatic updates for UCCM.
3. Restore the previous verified versioned ZIP; do not use an unverified source
   archive.
4. If the update included an incompatible schema change or data damage, restore
   the matching pre-update database backup. Installing old PHP files alone does
   not reverse database migrations.
5. Clear caches, reactivate the plugin and repeat the consent, blocking, scan and
   privacy smoke tests.
6. Preserve logs and version/checksum evidence for investigation.

Deactivation clears scheduled plugin jobs but retains data. Uninstall also
retains data unless explicit full cleanup was enabled beforehand. Treat full
cleanup and database restoration as destructive operations requiring a verified
backup.

## Routine checks

- Daily: confirm normal site operation and investigate consent-receipt failures.
- Monthly: confirm the scheduled scan ran, review findings and test representative
  visitor journeys.
- After site changes: repeat prior-blocking and category tests.
- Before and after updates: record version, package checksum, backup and smoke-test
  results.
- Periodically: review privileged users, retention, proxy allowlists, update
  credentials, privacy text and inventory accuracy.

For vulnerabilities, follow [SECURITY.md](../SECURITY.md). Do not include consent
records, IP data, credentials or captured private content in issue reports.
