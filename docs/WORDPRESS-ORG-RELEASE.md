# WordPress.org Plugin Directory release

This document governs the WordPress.org distribution of UK Cookie Consent
Manager. GitHub remains the public development and provenance repository.
WordPress.org becomes the only executable update channel for copies installed
from the Plugin Directory.

## Permanent identity

- Intended slug: `uk-cookie-consent-manager`
- Directory URL after approval:
  `https://wordpress.org/plugins/uk-cookie-consent-manager/`
- SVN URL after approval:
  `https://plugins.svn.wordpress.org/uk-cookie-consent-manager`

The WordPress.org plugin API returned `Plugin not found` for the intended slug
on 27 July 2026. This is evidence that no published plugin currently uses the
slug, not a reservation. Only WordPress.org approval reserves it.

## Distribution boundary

The normal GitHub release contains the signed GitHub updater used during
controlled release-candidate testing. The WordPress.org package instead uses
three committed overlays:

- `wordpress-org/uk-cookie-consent-manager.php` omits the external `Update URI`;
- `wordpress-org/class-secure-updater.php` reports native WordPress.org update
  state and contains no GitHub package or manifest download path;
- `wordpress-org/readme.txt` provides directory-specific installation, privacy,
  support and security copy.

The package builder copies shared production PHP, CSS and JavaScript directly
from the approved Git tag, then substitutes only those committed overlay files.
It excludes tests, build tools, repository automation and development metadata.
`SOURCE-MANIFEST.sha256` records the exact resulting publication bytes.

## Submission readiness

Completed evidence:

- The named maintainer account and contributor username
  `rushleighconsulting` are confirmed.
- UCCM-32 Windows 11/NVDA acceptance passed on `0.1.0-rc.21`.
- UCCM-34 established the ordinary support and triage route. Live
  WordPress.org forum-feed testing remains post-listing evidence.
- UCCM-35 established and tested the private vulnerability-reporting route.
- UCCM-43 and UCCM-44 completed translation readiness and per-language consent
  content.
- Six approved WordPress.org screenshots and their readme captions are
  committed.
- Stable `1.0.1` corrective version metadata is prepared consistently in both
  entry points, both readmes, the translation template and the version-agreement test.

Do not submit until all of the following remaining gates are true:

1. The exact corrective stable source is approved and an immutable `v1.0.1`
   tag is created from that accepted revision.
2. Current source, privacy, licensing, trademark and manual security review is
   complete.
3. Full WordPress Plugin Check passes against the exact directory package.
4. Upload of that exact package for WordPress.org review is explicitly approved.
5. WordPress.org has approved the submission and provisioned the SVN repository.

## Plugin Check manual disposition for 1.0.1

The prepare-only 1.0.0 run completed with no Plugin Check errors and 28
warnings. Version 1.0.1 removes the one actionable warning by deleting the
obsolete manual `load_plugin_textdomain()` call. Modern WordPress and the
Plugin Directory load language packs just in time for the declared text domain.

The remaining warning classes have this reviewed disposition:

- Direct database query warnings are accepted where values are passed through
  `$wpdb->prepare()` and identifiers are drawn only from fixed plugin-owned
  table and field definitions. The queries are bounded or lifecycle operations,
  capability-gated where exposed to administrators, and retain their explicit
  PHPCS rationale. No visitor-controlled SQL structure is accepted.
- The resource blocker is intentionally loaded early and on public pages because
  deferring it can allow configured optional scripts, frames, embeds or pixels
  to execute before consent. This privacy control takes precedence over the
  generic non-blocking-script recommendation.
- The consent interface script is intentionally available on public pages and
  loaded in the footer. Adding asynchronous execution could reorder it against
  the early blocker and consent state; the current deterministic order is
  retained.
- `wpml_current_language` is an intentional third-party WPML compatibility
  hook and therefore cannot use the UCCM prefix.

These dispositions do not suppress Plugin Check output. They require the exact
1.0.1 package to complete Plugin Check without errors and to emit only the
reviewed warning classes before upload approval.

## One-time GitHub configuration after directory approval

Create a GitHub environment named `wordpress-org-release` and require a human
reviewer. Add these environment secrets:

- `WORDPRESS_ORG_USERNAME`: the case-sensitive WordPress.org committer username;
- `WORDPRESS_ORG_PASSWORD`: the account's dedicated SVN password, not its normal
  sign-in password.

In the WordPress.org Release Management dashboard, enable Release Confirmation.
Committers must be able to receive its tokenised emails directly.

## Release procedure

1. Approve and create the immutable corrective stable Git tag `v1.0.1`.
2. Run **WordPress.org publication** manually with that exact tag and leave
   **Release confirmation** at `NOT APPROVED`.
3. Review the generated package, checksum manifest, assets and Plugin Check
   result.
4. Once the remaining submission gates above are accepted, re-run with the same
   tag, `APPROVED` and support/security routes `VERIFIED`.
5. Approve the protected `wordpress-org-release` GitHub environment.
6. Confirm the SVN commit contains the same package bytes, versioned tag and
   directory assets.
7. Use WordPress.org's Release Management dashboard and emailed token to approve
   the pending release.
8. Separately verify directory visibility, update discovery, installation,
   update, rollback and installed-version identity.

The workflow refuses prerelease tags, version mismatches, an existing SVN tag,
missing credentials, failed package checks or failed Plugin Check. It never
publishes merely because a GitHub tag was pushed.

## Sources

- WordPress.org Detailed Plugin Guidelines:
  https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
- Using Subversion:
  https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/
- Plugin Readmes:
  https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/
- Plugin assets:
  https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/
- Release Confirmation:
  https://developer.wordpress.org/plugins/wordpress-org/release-confirmation-emails/
