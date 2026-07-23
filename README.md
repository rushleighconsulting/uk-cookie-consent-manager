# UK Cookie Consent Manager

A privacy-by-design WordPress plugin for obtaining, recording, reviewing and
honouring cookie consent under UK GDPR and PECR.

> This project supports compliance work. It does not provide legal advice or
> guarantee that a website is legally compliant.

## Status

Release 1 is in development. The current branch is an architectural scaffold,
not a production-ready consent solution.

- [Canonical product specification](https://rushleighconsulting.atlassian.net/wiki/spaces/UCCM/pages/16973869/Product+specification+-+UK+Cookie+Consent+Manager)
- [Jira delivery project](https://rushleighconsulting.atlassian.net/jira/software/projects/UCCM/)
- [Release 1 Epic](https://rushleighconsulting.atlassian.net/browse/UCCM-1)

## Planned Release 1 capabilities

- Accessible first-visit banner and granular preference centre.
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
composer phpcs
composer phpstan
```

Do not install or enable this development scaffold on a production site.
