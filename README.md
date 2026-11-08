# Nitro Portfolio

A PHP application for exploring options portfolios, account-scoped positions, transaction imports and historical return calculations.

Author: [Aurélio Paz da Luz](https://aureliopaz.com/en/).

This is a source-code portfolio edition. It has its own publication history and contains no production credentials, operational runbooks, account sessions or deployment automation. It is not a hosted service.

## What to inspect

- `series.php`: calculation routines for portfolio values, time-weighted returns and comparisons.
- `api.php` and `wallets.php`: account-scoped data access and multiple portfolios.
- `apl-admin/`: role-based administration.
- `security.php`: CSRF checks, login throttling and session revalidation.
- `assets/`: responsive interface in Portuguese.

## Portfolio boundaries

Market-data requests are disabled in this edition. Configuration is supplied through an ignored `config.local.php`; the tracked example contains placeholders only. Historical financial seeds and infrastructure notes are excluded. The SQL files are schema and migration references, not a production database backup.

The application requires PHP 8.3 or newer, PDO MySQL and a separate disposable MySQL database. Review the schema migrations before running an instance. Do not import an operational database or expose a first-run administrator registration to the internet.

## Verification

```sh
php tests/security.php
find . -name '*.php' -not -path './vendor/*' -exec php -l {} \;
```

Tests cover rejected GET mutations, missing and invalid CSRF tokens, session expiry, password revocation, disabled accounts and throttled login attempts. API calls use POST with a server-generated CSRF header. Sessions expire after 30 minutes of inactivity or eight hours overall. Role and password state are revalidated against the database.

No live database, broker connection or production service is required by the security tests. Full database and browser end-to-end behavior is outside this test suite.
