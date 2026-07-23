# UK Cookie Consent Manager

A privacy-by-design WordPress plugin for obtaining, recording, reviewing and
honouring cookie consent under UK GDPR and PECR.

> This project supports compliance work. It does not provide legal advice or
> guarantee that a website is legally compliant.

## Status

Release 1 is in development. The repository contains the database and lifecycle
foundation, visitor consent, prior blocking, privacy-preserving receipts and administration inventory management. It is not production ready.

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
- Multisite activation, deactivation and new-site initialization.
- Retain-by-default uninstall behaviour with explicit full cleanup.

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

UCCM-6 adds:

- A top-level Cookie Consent menu with Overview, Banner, Categories, Script
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

- Manual and idempotently scheduled 30-day scans of the homepage and up to 19
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

Do not install or enable this development version on a production site.


## Secure private-repository updates

UCCM-9 adds an opt-in update channel designed for a private GitHub repository:

- Update metadata is fetched over HTTPS and must carry a valid Ed25519 signature.
- The release ZIP is accepted only when its SHA-256 checksum matches the signed
  metadata. Any missing key, invalid signature, metadata mismatch, incompatible
  version, failed request or checksum mismatch blocks the update.
- A site-specific download credential can be stored from **Cookie Consent →
  Advanced**. It is encrypted with a key derived from the site's WordPress salts,
  is never displayed after saving, and is not included in release packages or
  visitor-facing output.
- Automatic updates are disabled until an administrator explicitly opts in.

Before enabling the channel, configure the release public key and manifest URL in
the Advanced screen. For a private repository, supply a least-privilege,
site-specific credential that can read release assets. Rotating WordPress salts
invalidates the encrypted credential, which must then be saved again.

### Release procedure

A semantic tag such as `v0.1.0` starts the Release workflow. The tag must match
both plugin version declarations. The workflow builds a reproducible archive from
the tagged Git tree, excludes tests and development dependencies, emits
`SHA256SUMS`, creates a signed `update-manifest.json`, and publishes those
assets in a new GitHub Release. Existing releases are never replaced.

Repository administrators must configure `UCCM_MANIFEST_PRIVATE_KEY` as a
GitHub Actions secret containing a base64 Ed25519 seed (32 bytes) or secret key
(64 bytes). The corresponding base64 public key is configured on each WordPress
site. The private key must never be committed or placed in the plugin ZIP.


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
