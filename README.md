# UK Cookie Consent Manager

A privacy-by-design WordPress plugin for obtaining, recording, reviewing and
honouring cookie consent under UK GDPR and PECR.

> This project supports compliance work. It does not provide legal advice or
> guarantee that a website is legally compliant.

## Status

Release 1 is in development. The repository contains the database and lifecycle
foundation plus the UCCM-3 public consent interface. It is not production ready.

- [Canonical product specification](https://rushleighconsulting.atlassian.net/wiki/spaces/UCCM/pages/16973869/Product+specification+-+UK+Cookie+Consent+Manager)
- [Jira delivery project](https://rushleighconsulting.atlassian.net/jira/software/projects/UCCM/)
- [Release 1 Epic](https://rushleighconsulting.atlassian.net/browse/UCCM-1)

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

## Planned Release 1 capabilities

- Prior blocking for configured non-essential scripts and embeds.
- Privacy-preserving, versioned consent receipts.
- Curated cookie inventory and hybrid detection.
- Manual and monthly scans with administrator review.
- WordPress privacy-tool integration.
- Integrity-checked private repository updates.
- WordPress 6.8–7.0 and PHP 8.2–8.5 compatibility.

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
