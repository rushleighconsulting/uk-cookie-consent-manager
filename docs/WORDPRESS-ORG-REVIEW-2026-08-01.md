# WordPress.org review remediation - 1 August 2026

## Review identity

- Review ID: `AUTOPREREVIEW TRM uk-cookie-consent-manager/rushleighconsulting/1Aug26/T1 1Aug26/4.2A1 (P0TDX347924HGN)`
- Submitted version: `1.0.1`
- Corrective version: `1.0.2`
- Approved replacement display name: **Rushleigh Cookie Choices**
- Requested permanent slug: `rushleigh-cookie-choices`

The replacement identity was chosen in response to WordPress.org's finding that
the submitted name and slug were too generic. The repository URL remains
`rushleighconsulting/uk-cookie-consent-manager` so that source history and
existing evidence links remain stable.

## Reviewer-focused technical audit

### Inert script placeholder

`UCCM\Resource_Rules::placeholder()` emits a `script` element only for an
administrator-approved blocking rule. It has `type="text/plain"`, no executable
body and escaped rule, category and source metadata. The visitor-side consent
controller converts the metadata into an executable resource only after the
relevant consent exists. Enqueuing the original resource would execute it before
consent and defeat the plugin's central prior-blocking control.

### Requests, nonces and permissions

- Every privileged `admin_post_*` handler verifies an action-specific nonce and
  the relevant dedicated UCCM or network capability before mutation or export.
- Authenticated scan AJAX handlers verify their action-specific nonce and
  `run_uccm_scans` before processing observations or batches.
- The anonymous post-password bootstrap is deliberately public, origin-bound,
  rate-limited and accepts only the bounded data needed to establish temporary
  access to WordPress password-protected content.
- REST routes use explicit permission callbacks. Public consent submission is
  origin-checked and rate-limited; privileged receipt routes require dedicated
  view/export capabilities.

### Input handling

Request data is unslashed and sanitised according to its expected type before
use. Identifiers use restrictive key or identifier validation; integers use
bounded integer conversion; URLs use WordPress URL sanitisation and allow-list
checks; free text uses text-field sanitisation; stored or rendered output is
escaped for its destination. Database access uses prepared values and
plugin-owned table and field structures documented in the existing Plugin Check
manual disposition.

### Licensing and unrestricted functionality

The plugin and bundled source are licensed GPL-2.0-or-later. The submitted
package contains the complete human-readable PHP, JavaScript and CSS source and
does not require a paid service, licence key, remote account or Rushleigh-hosted
API. All consent, blocking, inventory, scanning, privacy export/erasure and
Multisite functionality runs locally. External links are limited to documented
support, security and source-provenance routes.

## Verification gates

Before a corrected package is uploaded:

1. Confirm all source, readme, text-domain, package and directory-asset identity
   references use the approved display name and slug.
2. Run PHP syntax, PHPCS, PHPStan, PHPUnit, translation, JavaScript/browser,
   normal package, WordPress.org package and Plugin Check jobs.
3. Review the exact corrective ZIP and record its SHA-256.
4. Upload the corrected package through **Add your plugin**.
5. Reply briefly in the existing WordPress.org review thread, explicitly
   requesting `rushleigh-cookie-choices` as the replacement permanent slug.

Upload, reviewer acceptance, SVN provisioning, publication, Release
Confirmation and installed-site acceptance remain separate gates.
