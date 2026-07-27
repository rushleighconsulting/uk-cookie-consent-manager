# Security policy

## Reporting a vulnerability

Do not disclose a suspected vulnerability in a public GitHub issue, WordPress.org
support topic, ordinary support request, chat room or social-media post.

Use GitHub Private Vulnerability Reporting as the primary confidential route:

https://github.com/rushleighconsulting/uk-cookie-consent-manager/security/advisories/new

If GitHub private reporting is unavailable, email
security@rushleighconsulting.co.uk. The mailbox is monitored by Jeremy Sedgley
and Matthew Worley. Do not send vulnerability evidence to any other Rushleigh
Consulting address unless a security maintainer explicitly agrees a secure
alternative with you.

Include the affected UCCM and WordPress versions, a concise impact description,
and the minimum reproduction steps needed to assess the problem. Before sending
logs or files, remove real consent records, cookie values, complete IP addresses,
credentials, access tokens, authentication cookies, database exports and
unrelated personal data.

We aim to acknowledge a report within two working days. Acknowledgement does not
confirm severity or eligibility for a CVE. Reporter communication, remediation,
release and public disclosure are separate approval stages.

## Coordinated disclosure

The security maintainers will restrict the report and evidence to the security
response group, assess severity and affected versions, and agree communication
and disclosure timing with the reporter where practicable. Confidential fixes
must be developed outside public issues and pull requests. A GitHub security
advisory and CVE may be used when appropriate.

If a vulnerability is mentioned publicly, do not request further evidence in
public. Direct the reporter to the private form and preserve only the public URL
needed for the response record.

Evidence is retained only while assessment, remediation, release and agreed
disclosure require it. The security maintainers must securely delete unnecessary
copies and record the deletion without copying the evidence into ordinary Jira,
support queues, GitHub Actions logs or general notifications.

If both GitHub private reporting and the security mailbox are unavailable, use a
known Rushleigh Consulting contact only to request an alternative private
channel. Do not include vulnerability details in that initial contact.

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
