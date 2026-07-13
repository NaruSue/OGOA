# VPS Deployment Notes

Last updated: 2026-07-13

This public document describes the deployment model without exposing live server details.
Hostnames, SSH ports, account names, backup paths, credentials, and local machine paths
belong in `docs.local/`, which is intentionally ignored by Git.

## Current Model

1G1A production runs without Docker in the live request path.

The intended stack is:

```text
nginx
  -> PHP 8.3 FPM
  -> application source checkout
  -> PostgreSQL 16
```

Docker may remain installed during migration or rollback windows, but it should not be
required for normal production traffic after cutover.

## Environments

Use separate production and staging environments.

Production:

- Deployed from the main release branch.
- Uses the production database and production runtime config.
- Should only receive changes after staging has been checked.

Staging:

- Deployed from an explicit branch or ref.
- Uses an isolated staging database and staging runtime config.
- May use Basic authentication or other access restriction.
- Should be used for HTTPS, cookie, OAuth, and certificate-sensitive checks.

## Runtime Data

The application checkout should not own all runtime state.

Keep these server-local and outside Git:

```text
.env
config/
storage/
logs/
```

`storage/` and the PostgreSQL database must be backed up as a pair. Restoring only one
side can leave database records pointing at missing files, or uploaded files that no
longer have database records.

## Deployment Flow

Deployment is manual from inside the server environment.

Production pattern:

```bash
./deploy.sh main
```

Staging pattern:

```bash
./deploy-st.sh <branch-or-ref>
```

The deployment scripts should:

1. Fetch the requested Git ref.
2. Check out the requested branch or commit.
3. Run a frontend build when the project has one.
4. Run PHP syntax checks.
5. Apply database migrations.
6. Validate nginx configuration.
7. Reload or restart only the required services.
8. Run a health check.
9. Write deploy logs and current-state metadata.

GitHub Actions should be used for CI only unless a separate deployment security design
has been reviewed. Do not store live server URLs, SSH details, or deployment tokens in
a public repository.

## Local Development

Local WSL can mirror the production OS and major packages:

```text
Ubuntu 24.04
nginx
PHP 8.3 FPM
PostgreSQL 16
build-essential / cmake / git / node / npm
```

Local development should normally use HTTP:

```text
http://localhost:<port>
http://1g1a.local
```

Use a hosts entry for local URL generation checks when needed:

```text
127.0.0.1  1g1a.local
127.0.0.1  s1g1a.local
```

Use staging, not local WSL, for Let's Encrypt and production-like HTTPS validation.
If local HTTPS is required, use a local development certificate such as `mkcert`.

## Private Notes

Keep the following only in `docs.local/` or another private location:

- Real hostnames and IP addresses.
- SSH users, ports, and key paths.
- Basic authentication credentials.
- Database passwords.
- Backup directories and dump paths.
- Exact nginx config paths for a live server.
- Local WSL import paths and machine-specific notes.
