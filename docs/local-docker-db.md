# Local Docker Database

Docker is used only as a local development helper for PostgreSQL.
Production currently runs directly on the VPS and does not use Docker.

## Files

- `compose.yaml`: local PostgreSQL service
- `database/migrations/`: schema SQL
- `database/seeds/`: seed SQL
- `docker/postgres/init/`: startup runner for local PostgreSQL
- `.env.sample`: local development environment sample
- `.env`: local development environment, excluded from Git

## Start

Copy `.env.sample` to `.env`, then start PostgreSQL:

```bash
docker compose up -d postgres
```

Check the database:

```bash
docker compose exec postgres psql -U ogoa_app -d ogoa
```

## Reset Local DB

This removes local Docker database data and reapplies migrations and seeds.

```bash
docker compose down -v
docker compose up -d postgres
```

## Production

Production database settings are kept in server-local runtime config.

```text
config/db.env or .env
```

Do not commit production credentials to Git.
