# Security policy

## Reporting a vulnerability

Please do not disclose suspected vulnerabilities in a public issue.

Use GitHub's private vulnerability reporting for this repository when available,
or contact the repository owner through an agreed private channel. Include the
affected version, reproduction steps, impact, and any suggested mitigation.

Do not include real visitor consent records, IP addresses, credentials, tokens,
database exports, or other personal data in a report.

## Supported versions

No production version has been released yet. Supported versions will be listed
here when Release 1 is published.

## Security principles

The project applies least privilege, explicit consent, data minimisation, bounded
retention, authenticated update metadata, package integrity checking, input
validation, output escaping, prepared queries, and privacy-safe logging.


## Data-flow and boundary controls

- Consent decisions are submitted only to this site's same-origin WordPress REST
  endpoint. Cross-origin browser requests are rejected and per-source submission
  rates are bounded.
- The browser scanner accepts only an authenticated, allowlisted and rate-limited
  observation payload. Consent records, cookie values, page content and form
  values are outside that runner boundary.
- Scan targets must remain same-origin, pass WordPress safe-HTTP validation, and
  must not resolve from a private or reserved IP literal.
- Update metadata and packages use HTTPS. Metadata is authenticated with
  Ed25519, package URLs must match the signed manifest, and downloaded bytes must
  match its SHA-256 digest. Metadata is cached for six hours to bound upstream
  requests.
- Site-specific repository credentials and optional complete IP addresses use
  authenticated encryption derived from WordPress site salts. They are never
  rendered to visitors or written to production packages.

## Personal data and retention

Consent receipts may contain a random receipt ID, UTC timestamp, choices, policy
and plugin versions, a keyed site identifier, optional WordPress user ID, masked
IP address, keyed IP fingerprint and—only after explicit opt-in—an encrypted
complete IP address.

The default retention period is 365 days. Daily cleanup removes expired receipts.
WordPress privacy export includes receipts linked to the requesting WordPress
account. Erasure removes the account link and all IP-derived fields; the remaining
non-attributable decision evidence is retained only until scheduled retention
cleanup. Anonymous receipts cannot be attributed to an email address.

Forwarded IP headers are ignored unless proxy trust is explicitly enabled and the
direct peer appears in the administrator-maintained trusted-proxy list. Operators
are responsible for keeping that list accurate.
