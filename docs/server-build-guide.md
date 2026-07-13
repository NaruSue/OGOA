# Server Build Guide

Last updated: 2026-07-13

This guide describes the standard way to build a production-like server for this
project without exposing any real hostnames, IP addresses, credentials, or private
paths.

Use `docs.local/` for machine-specific notes.

## Target Stack

Use the same major versions across production, staging, and local WSL where practical.

```text
Ubuntu 24.04 LTS
nginx
PHP 8.3 FPM
PostgreSQL 16
Git
Node.js / npm when the frontend needs a build step
C++ build tools when native components need compilation
```

Docker is not required for the production request path.

## Environment Roles

Use at least two environments:

- Production: stable release branch, production database, production runtime config.
- Staging: explicit branch or ref, isolated database, staging runtime config.

Staging should be used before production for:

- HTTPS behavior
- OAuth redirects
- Secure cookies
- nginx changes
- database migrations
- upload and storage behavior

## Users

Use separate responsibilities instead of one all-powerful runtime user.

Recommended roles:

- Deployment user: owns the Git checkout and deploy scripts.
- PHP-FPM user: runs PHP and writes only runtime directories.
- nginx user: serves static files and proxies PHP requests.
- Database superuser: kept for database administration only.
- Database app user: used by the application.

The deployment user may have limited sudo for service reloads if needed, but the web
runtime user should not have broad sudo access.

## Directory Layout

Use placeholders in documentation and scripts:

```text
<app-base>/
|-- deploy.sh
|-- deploy-state/
|-- logs/deploy/
`-- web/
    |-- app/
    |-- database/
    |-- public/
    |-- config/
    |-- storage/
    `-- logs/
```

The `web/` directory should be a Git clone.

Keep server-local runtime data outside Git:

```text
.env
config/
storage/
logs/
```

Do not deploy by deleting and recreating these runtime paths.

## Packages

Base packages:

```bash
sudo apt-get update
sudo apt-get install -y \
  nginx \
  php8.3-fpm php8.3-cli php8.3-pgsql php8.3-curl php8.3-mbstring php8.3-xml php8.3-zip \
  postgresql postgresql-client \
  git curl ca-certificates rsync unzip zip jq \
  build-essential cmake make g++ pkg-config \
  nodejs npm
```

Install only the packages the application actually needs.

## PostgreSQL

Create a database and a dedicated application role for each environment.

Pattern:

```text
Production database: <app_db>
Production app user: <app_db_user>
Staging database:    <app_staging_db>
Staging app user:    <app_staging_db_user>
```

Keep passwords in server-local runtime config, not in Git.

Apply schema through migrations stored in the repository. Avoid manual-only schema
changes; if a manual fix is needed during an incident, add a migration afterward.

## PHP-FPM

nginx should pass PHP requests to PHP-FPM through a Unix socket or local TCP port.

The PHP-FPM runtime user must be able to write only the app runtime paths that need it,
for example:

```text
storage/
logs/
```

Do not make the full Git checkout writable by PHP-FPM.

## nginx

Use separate nginx server blocks for production and staging.

The server block should:

- Redirect HTTP to HTTPS in production.
- Serve from the app public document root.
- Pass PHP requests to PHP-FPM.
- Deny access to dotfiles.
- Deny direct access to private runtime directories.
- Set a reasonable upload size limit.

Use staging or a local hostname for configuration testing before production.

## TLS

Use Let's Encrypt only for publicly reachable environments.

Local WSL should normally use HTTP:

```text
http://localhost:<port>
http://<local-app-host>
```

If local HTTPS is needed, use a local development certificate tool such as `mkcert`.

## Deployment Script Contract

Each environment should have a small deploy script.

Production pattern:

```bash
./deploy.sh main
```

Staging pattern:

```bash
./deploy-st.sh <branch-or-ref>
```

The script should:

1. Fetch the requested Git ref.
2. Check out the requested branch or commit.
3. Install or update dependencies when needed.
4. Run the frontend build when the project has one.
5. Run PHP syntax checks.
6. Apply database migrations.
7. Validate nginx configuration.
8. Reload PHP-FPM/nginx only when needed.
9. Run a health check.
10. Write logs and current-state metadata.

Do not put server credentials or deployment tokens in public GitHub Actions settings for
a public repository. Use manual SSH deployment unless a separate deployment security
design has been reviewed.

## Backups

Back up database and uploaded files together.

Minimum backup set:

```text
PostgreSQL dump
Uploaded files
Runtime config inventory, without printing secrets into public logs
nginx config backup before server changes
PHP-FPM config backup before server changes
```

Before switching runtime architecture, also keep the previous runtime available until
the new path is verified.

## Security Baseline

Minimum checklist:

- SSH key authentication only.
- Disable SSH password login when practical.
- Keep private keys out of the repository.
- Keep `.env`, database passwords, and OAuth secrets out of Git.
- Restrict database listening to localhost unless there is a reviewed reason.
- Use staging for risky nginx/PHP/database changes.
- Keep public documentation generic.
- Put real hostnames, IP addresses, ports, and paths in `docs.local/`.

## Public Documentation Rule

Public docs may describe:

- Architecture
- Roles
- Required packages
- Directory layout pattern
- Deployment script contract
- Operational rules

Public docs must not include:

- Real hostnames or IP addresses
- Real SSH ports or users
- Real server paths
- Backup paths
- Secrets or tokens
- Basic authentication details
- Private key names or locations
