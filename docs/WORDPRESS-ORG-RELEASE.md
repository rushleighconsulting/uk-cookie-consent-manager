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

## Submission blockers

Do not submit until all of the following are true:

1. A named maintainer has a personal WordPress.org account and the contributor
   username in `wordpress-org/readme.txt` is confirmed.
2. UCCM-34 has established the ordinary support and triage route.
3. UCCM-35 has established and tested the private vulnerability-reporting route.
4. A stable release version such as `1.0.0` replaces the release-candidate
   version in both entry points and both readmes.
5. Current source, privacy, licensing, trademark and manual security review is
   complete.
6. Full WordPress Plugin Check passes against the directory package.
7. Real screenshots and their readme captions have been approved.
8. WordPress.org has approved the submission and provisioned the SVN repository.

## One-time GitHub configuration after directory approval

Create a GitHub environment named `wordpress-org-release` and require a human
reviewer. Add these environment secrets:

- `WORDPRESS_ORG_USERNAME`: the case-sensitive WordPress.org committer username;
- `WORDPRESS_ORG_PASSWORD`: the account's dedicated SVN password, not its normal
  sign-in password.

In the WordPress.org Release Management dashboard, enable Release Confirmation.
Committers must be able to receive its tokenised emails directly.

## Release procedure

1. Approve and create an immutable stable Git tag such as `v1.0.0`.
2. Run **WordPress.org publication** manually with that exact tag and leave
   **Release confirmation** at `NOT APPROVED`.
3. Review the generated package, checksum manifest, assets and Plugin Check
   result.
4. After UCCM-34 and UCCM-35 are accepted, re-run with the same tag,
   `APPROVED` and support/security routes `VERIFIED`.
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
