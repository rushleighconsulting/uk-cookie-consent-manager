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
  reverse-proxy addresses.
- **Retention:** confirm the consent-record retention period. Cleanup runs daily
  through WP-Cron and defaults to 365 days.
- **Advanced:** leave automatic updates disabled until the signed manifest URL,
  Ed25519 public key and a least-privilege release-download credential are
  configured and tested.

## Inventory and scanning

Maintain the curated inventory in **Cookie Consent → Cookie Inventory**. A scan
is evidence for human review, not an automatic publication or compliance
decision.

Run a manual scan after installation and after material theme, plugin, tag
manager or content changes. Starting a scan returns immediately. WP-Cron resumes a
persisted frontier in bounded batches, beginning with the homepage and any configured
same-origin seeds and discovering eligible same-origin links up to the configured limit
(maximum 1,024 unique URLs). Cross-origin, private, credential-bearing and unsafe URLs
are rejected. Administration, login, REST and feed paths are always excluded; additional
path patterns can be configured on the Scans screen.

If a batch is interrupted, its saved progress remains visible and a failed run can be
resumed. A queued or running scan can be cancelled without deleting its evidence. After
the server crawl completes, open that run and choose **Run browser observations** to
inspect up to 100 successfully visited pages for accessible cookie names, local-storage
keys, scripts, iframes and pixels. Review new and changed findings, then mark them
reviewed, ignored or resolved as appropriate.

### Scan limitations

Scanning is intentionally bounded and non-exhaustive. Server inspection can see
observable `Set-Cookie` response headers, while the authenticated browser-runner
boundary can report allowlisted cookies, local-storage keys, scripts, iframes and
pixels. A scan may miss conditional, authenticated, geo-specific, delayed,
interaction-triggered, blocked, consent-dependent or third-party behaviour. It
does not capture consent records, cookie values, form values or page content.
Repeat representative manual journeys and use browser developer tools alongside
the scanner.

WP-Cron is traffic-dependent unless the site uses a real cron runner. Monitor queued and
running scans and use the resume control if a batch reports failure. The 1,024-page total
remains a hard ceiling rather than a completeness guarantee. The administrator browser
pass is limited to 100 pages per session and can be prevented by a page's framing policy.

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
