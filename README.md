# UK Cookie Consent Manager

A privacy-by-design WordPress plugin for obtaining, recording, reviewing and
honouring cookie consent under UK GDPR and PECR.

> This project supports compliance work. It does not provide legal advice or
> guarantee that a website is legally compliant.

## Status

Release 1 is in development. The repository contains the database and lifecycle
foundation, the UCCM-3 public consent interface and UCCM-4 explicit prior blocking. It is not production ready.

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

## Planned Release 1 capabilities

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
