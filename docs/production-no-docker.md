# Production Without Docker

Last updated: 2026-07-13

This public note records the non-Docker production pattern without exposing live
server identifiers.

## Architecture

```text
Internet
  |
  v
nginx :80 / :443
  |
  v
PHP 8.3 FPM
  |
  +-- App code checkout
  +-- Public document root
  +-- Runtime storage
  +-- Runtime logs
  |
  v
PostgreSQL 16 on localhost
```

## Ownership Model

Use separate responsibilities:

- Deployment user owns the Git checkout and deploy scripts.
- PHP-FPM user writes only runtime directories required by the application.
- nginx user reads public files and passes PHP requests to PHP-FPM.

Do not give the public web runtime broad write access to the full source checkout.

## Persistent Data

Persistent production data consists of:

```text
PostgreSQL database
Uploaded files
Runtime config
```

Back up the database and uploaded files together.

## Operational Rule

Application deployment and server configuration changes are separate operations.

Normal deploys should not change:

- DNS
- nginx server blocks
- PHP-FPM pool configuration
- database users
- file ownership outside runtime paths
- Docker container state

Those changes should be handled as planned server maintenance.
