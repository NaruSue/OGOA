# Database

This directory contains database artifacts that are safe to publish.

## Structure

- `migrations/`: SQL files for schema changes, applied in filename order.
- `seeds/`: SQL files for initial or master data, applied in filename order.

## Local Development

Start PostgreSQL with Docker Compose:

```bash
docker compose up -d postgres
```

Check the database:

```bash
docker compose exec postgres psql -U ogoa_app -d ogoa
```

Reset the local database:

```bash
docker compose down -v
docker compose up -d postgres
```

## Production

Production currently uses the PostgreSQL instance on the VPS directly.
Do not run Docker for production unless the deployment policy changes.

Apply migrations and seeds deliberately after reviewing the target environment.
