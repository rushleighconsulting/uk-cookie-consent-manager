# UK Cookie Consent Manager

A privacy-by-design WordPress plugin for obtaining, recording, reviewing and
honouring cookie consent under UK GDPR and PECR.

> This project supports compliance work. It does not provide legal advice or
> guarantee that a website is legally compliant.

## Status

Release 1 is at release-candidate stage for controlled staging acceptance.
Version `0.1.0-rc.3` was rejected during observed WordPress acceptance and is not approved for production use.

- [Canonical product specification](https://rushleighconsulting.atlassian.net/wiki/spaces/UCCM/pages/16973869/Product+specification+-+UK+Cookie+Consent+Manager)
- [Jira delivery project](https://rushleighconsulting.atlassian.net/jira/software/projects/UCCM/)
- [Release 1 Epic](https://rushleighconsulting.atlassian.net/browse/UCCM-1)
- [Installation and operating guide](docs/OPERATING-GUIDE.md)

## Current foundation

UCCM-2 establishes:

- Versioned, per-site database tables for consent events, cookie inventory,
  scan runs and scan findings.
- Idempotent activation and runtime schema upgrade checks.
- Dedicated settings, inventory, scanning and consent-record capabilities.
- Privacy-preserving defaults: full IP storage is disabled and consent-record
  retention defaults to 365 days.
- Resumable Multisite network activation and upgrades, new-site initialization,
  per-site data isolation, Network Administrator diagnostics and controlled
  operational defaults.
- Retain-by-default uninstall behaviour with explicit full cleanup.

### WordPress Multisite

UCCM supports individual-site activation and Network Activation. Each site owns
its own prefixed tables, settings, capabilities, schedules, inventories, scans,
findings and consent evidence. Network installation and version upgrades run in
resumable batches of at most 25 sites and initialize sites created after Network
Activation.

Network Administrators receive **Cookie Consent → Cookie Consent Network** in
Network Admin. The screen reports rollout progress and shared update status and
allows a deliberately limited set of operational defaults. Precedence is:
plugin default, enabled network default, explicit site override, then an
explicit network lock. Consent policy versions, scan URLs, post passwords,
complete-IP collection and proxy trust are always site-specific.

Network-wide data deletion is disabled by default and requires the separate
Network Administrator setting. A single site's uninstall preference can never
authorize deletion across the network.

UCCM-3 adds:

- A cache-safe first-visit banner with equally prominent accept, reject and
  preference actions.
- Necessary, Functional, Analytics and Marketing choices, with optional
  categories disabled by default.
- A native accessible preferences dialog and persistent Cookie settings button.
- A first-party, versioned browser cookie holding the current decision for up to
  180 days by default.
- A `uccm:consent-changed` browser event and document category-state attributes
  for later script blocking and server-side receipt integration.
- Responsive styles, visible keyboard focus and reduced-motion handling.

The browser decision is the active interface state. Server-side evidential
consent receipts, privacy-preserving IP processing and retention are delivered
by UCCM-5.

UCCM-4 adds:

- Explicit administrator-managed rules stored in the `uccm_blocking_rules`
  option for scripts, iframes, embeds and pixels.
- Server-side conversion of configured WordPress script handles into inert
  `text/plain` markup before the browser can execute them.
- Consent-aware activation for configured resources, including resources added
  after the initial page load.
- Default protection for core WordPress and common WooCommerce basket handles.
- Diagnostics for invalid or unknown declarations rather than guessed
  categories.

### Managing blocking rules

Use **Cookie Consent → Script Blocking** to add, edit and remove rules through
labelled fields. Each rule needs a unique ID, a resource type, an optional consent
category and either a WordPress script handle or a complete HTTPS source. The
advanced JSON section is a read-only expert view of the object that will be saved;
a clean installation displays `{}`, never `[]`.

### Blocking rule format

Rules are allowlisted declarations. UCCM never rewrites arbitrary page HTML.

```php
update_option(
    'uccm_blocking_rules',
    array(
        'analytics-test' => array(
            'type'     => 'script',
            'handle'   => 'analytics-test',
            'category' => 'analytics',
        ),
        'map-frame' => array(
            'type'     => 'iframe',
            'source'   => 'https://maps.example.test/embed',
            'category' => 'functional',
            'title'    => 'Location map',
        ),
    )
);
```

WordPress script rules are applied by handle. Explicit iframe, embed, pixel and
URL-based script placeholders can be rendered with:

```php
do_action( 'uccm_render_resource', 'map-frame', array( 'title' => 'Location map' ) );
```

### Developer integration hooks

- `uccm_blocking_rules` filters stored rules before strict validation.
- `uccm_protected_script_handles` extends the handles that must not be blocked.
- `uccm_resource_blocked` fires when a configured WordPress script is made inert.
- `uccm_unknown_resource` reports invalid or incomplete declarations.
- Browser events: `uccm:consent-ready`, `uccm:consent-changed`,
  `uccm:resource-activated`, `uccm:resource-blocked` and
  `uccm:resource-unknown`.

Revocation prevents later eligible loads and unloads activated iframe, embed and
pixel sources. JavaScript already executed by the browser cannot be undone; it
will not be executed again unless a new eligible placeholder is subsequently
added and consent is granted.

UCCM-5 adds:

- One append-only server receipt for each grant, reject, update or withdrawal.
- Server-owned UTC time, policy and plugin versions, category choices, a keyed
  site identifier and integrity signature.
- A masked address plus keyed non-reversible IP fingerprint by default. IPv4 is
  masked to /24 and IPv6 to /48.
- A public write-only REST endpoint. Listing, evidence export and full-IP reveal
  routes require the dedicated consent-record capabilities.
- A daily retention cleanup job with a configurable 365-day default.
- Direct peer IP handling by default. Forwarded headers are considered only
  when proxy trust is explicitly enabled and the peer is allowlisted.

### Complete IP warning

Complete IP collection is disabled by default and is not required for normal
receipt evidence. Enabling `store_full_ip` increases privacy and security risk
and should be done only after documenting a lawful purpose, transparency,
retention and access controls. When enabled, the address is encrypted using
site-held WordPress salt material; plaintext is never written to the receipt
table. If encryption is unavailable, UCCM stores no complete address.

The settings `trust_proxy_headers` and `trusted_proxy_ips` must both be
configured before `X-Forwarded-For` is considered. Never enable proxy trust
without an exact allowlist of the site's reverse proxies.

In **Cookie Consent → Privacy**, the trusted-proxy address field is available
only while forwarded-header trust is selected. Turning trust off preserves the
saved allowlist for later use but keeps forwarded headers ignored.

UCCM-6 adds:

- A top-level Cookie Consent menu with Overview, Banner, View Categories, Script
  Blocking, Cookie Inventory, Scans, Consent Records, Privacy and Advanced
  screens.
- Validated banner, retention, IP/privacy, proxy and uninstall settings.
- Capability-gated cookie inventory creation and review with strict category,
  party, storage-type and status allowlists.
- Search, category/status filters and bounded 20-item pagination.
- Filter-preserving CSV export with spreadsheet formula-injection protection.
- Masked consent-record presentation restricted to the dedicated consent
  capability, plus explicit empty and future-scan states.

UCCM-7 adds:

- Manual and idempotently scheduled 30-day scans of the homepage and up to 1,023
  configured same-origin public URLs.
- Safe HTTP inspection of observable `Set-Cookie` responses, including
  HttpOnly cookies exposed in response headers.
- An authenticated, bounded browser-runner boundary for cookies, local storage,
  scripts, iframes and pixels; no consent records are included.
- Strict same-origin and safe-HTTP target validation to reject credentials,
  private/reserved network targets and disallowed origins.
- Bounded pending findings plus scan coverage, methods, pages, timing, warnings
  and explicit non-exhaustive limitations.
- A capability- and nonce-protected Scans screen for URL configuration, manual
  execution, schedule visibility and recent-run evidence.

UCCM-18 replaces the administrator-request scan path with a persisted,
WP-Cron-driven crawl frontier. It discovers canonical same-origin links, excludes
administration/authentication and configured path patterns, de-duplicates cycles,
and processes configurable batches up to the 1,024-page hard ceiling. Progress,
frontier, visited pages, warnings, cancellation and resumable failure state remain
visible in the scan record.

UCCM-21 seeds that frontier from the current site's published WordPress pages and
posts as well as the homepage. Draft, pending, scheduled, trashed, private,
password-protected and attachment records are excluded; `noindex` and
`nofollow` do not exclude otherwise published content. Link discovery ignores
direct media, attachment routes, archives, search and pagination by default,
while explicitly configured same-origin URLs remain eligible. Tracking-only and
trailing-slash variants are de-duplicated without collapsing meaningful query
values. Scan progress separates seeded pages/posts, other accepted links, ignored
links, checked URLs and remaining URLs.

UCCM-22 adds an optional, disabled-by-default path for WordPress's built-in
post-password protection. One site-local password can be saved encrypted with
WordPress salt material, replaced or removed without ever being displayed, logged,
exported or included in scan evidence. When enabled, only published pages and posts
whose WordPress post password matches become eligible. Server requests use a fresh
native `wp-postpass_` cookie only for matching same-origin targets. The isolated
administrator browser pass establishes the same native HttpOnly cookie through a
short-lived, target-bounded bootstrap token; neither the password nor cookie value is
returned to JavaScript. WordPress user login, HTTP Basic, identity-provider and
cross-origin authentication are intentionally out of scope.

Completed server crawls can be followed by the packaged administrator browser
runner. It inspects up to 100 successfully visited same-origin pages for
accessible cookie names, local-storage keys, scripts, iframes and pixels and
records whether the browser pass ran. The isolated visitor check currently requires
a current Chrome, Edge or other Chromium-based browser; Safari and Firefox display
that requirement and keep the action disabled before a check can start. HttpOnly
cookie values, page content, form values and consent records are never collected.

UCCM-8 adds:

- Comparison of bounded scan observations with the curated inventory, producing
  actionable `new` and `changed` findings only when human review is required.
- Duplicate suppression for identical pending findings and clear material diffs
  for duration, domain, source URL and category candidates.
- Capability- and nonce-protected review outcomes: reviewed, ignored or resolved.
  Review outcomes never silently publish or recategorise inventory entries.
- Summary-only administrator notifications with a direct link to the relevant
  scan. Messages contain no consent records, IP data, captured page content or
  form values.
- Bounded findings filtering by scan and explicit pending/reviewed status
  presentation in the Scans administration screen.


UCCM-23 adds bounded operational error reporting. Safely handled scan failures,
stalled background work and unsuccessful browser checks appear on the main
WordPress Dashboard for authorised administrators, with a stable code, UTC time
and a link to the relevant scan. Records are per site, contain no consent data,
IP data, credentials, cookie values, page content, form values or stack traces,
and can be dismissed or resolved; a genuine recurrence reopens the notice.

Email delivery is disabled by default. An administrator can opt in under
**Cookie Consent → Advanced** to send the same safe summary through WordPress
`wp_mail()` to that site's configured administration email address. Identical
problems are grouped. The repeat-email suppression period is configurable in
minutes under **Cookie Consent → Advanced**, defaults to 360 minutes and is
bounded to a maximum of 1,440 minutes (24 hours). Delivery attempt time and
status are retained without message bodies.


## Release 1 verification

UCCM-11 adds independently reported source-quality, unit/integration, browser,
package and release gates. Consent, withdrawal, prior-blocking and
accessibility-critical flows run in Chromium at desktop and mobile sizes. The
generated plugin ZIP is installed and activated against supported combinations
spanning WordPress 6.8–7.0 and PHP 8.2–8.5.

Operational installation, configuration, scanning, privacy, update and
database-aware rollback procedures are maintained in the
[operating guide](docs/OPERATING-GUIDE.md).

## Development

Install the development tools:

```sh
composer install
```

Run the current quality checks:

```sh
composer lint
composer test
composer phpcs
composer phpstan
```

Install only the named versioned ZIP from a matching GitHub Release, verify its
published checksum, and use the release candidate on a staging site only.


## Secure WordPress updates

UCCM publishes signed packages through its public GitHub Releases channel:

- The plugin declares an `Update URI`, so releases and automatic-update controls
  appear in WordPress's normal Plugins interface.
- Update metadata is fetched over HTTPS and must carry a valid Ed25519 signature
  made by the official release key embedded in UCCM.
- The release ZIP is accepted only when its SHA-256 checksum matches the signed
  metadata. Invalid metadata, signatures, compatibility requirements, package
  URLs or checksums stop the update before installation.
- No GitHub account, access token, manifest address or public-key setup is needed
  on individual sites.
- Administrators enable or disable automatic updates using WordPress's normal
  Plugins control. UCCM does not override that choice.
- Signed manifests may introduce a release to a deterministic percentage of
  sites. A site stays in the same rollout group for that release, and older
  manifests without rollout fields remain fully eligible.
- WordPress 6.6 and later performs a loopback fatal-error check during automatic
  plugin updates and restores the temporary backup when that check fails. UCCM's
  Advanced screen reports backup, disk-space and loopback readiness and records
  update or rollback problems through its operational alerts.

### Release procedure

A semantic tag such as `v0.1.0-rc.1` starts the Release workflow. The tag must match
both plugin version declarations. The workflow builds a reproducible archive from
the tagged Git tree, excludes tests and development dependencies, emits
`SHA256SUMS`, creates a signed `update-manifest.json`, and publishes those
assets in a new GitHub Release. Existing releases are never replaced.

Repository administrators must configure `UCCM_MANIFEST_PRIVATE_KEY` as a
GitHub Actions secret containing a base64 Ed25519 seed (32 bytes) or secret key
(64 bytes). The corresponding non-secret public key is embedded in the plugin.
The private key must never be committed or placed in the plugin ZIP.


## WordPress privacy tools and security boundaries

UCCM-10 integrates consent evidence with **Tools → Export Personal Data** and
**Tools → Erase Personal Data**. Only receipts linked to a logged-in WordPress
account can be found from an email address. Exported evidence contains the
recorded decision and masked IP, never an encrypted complete IP or repository
credential.

Erasure removes the WordPress user link, masked IP, keyed IP fingerprint and any
encrypted complete IP. The remaining decision is no longer attributable through
UCCM and is removed by the normal retention job (365 days by default). Anonymous
receipts cannot be matched to an email address.

Suggested disclosure text is registered with the WordPress privacy-policy guide.
Site operators must review and adapt it for their actual cookies, purposes,
retention period, lawful basis and third-party services.

Security boundaries include same-origin and rate-limited consent submission,
authenticated and rate-limited browser-runner observations, SSRF-resistant scan
targets, capability-gated evidence access, explicit prior blocking, and signed,
checksum-verified updates. See [SECURITY.md](SECURITY.md) for reporting, proxy,
data-flow and retention details.
