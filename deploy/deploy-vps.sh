#!/usr/bin/env bash
set -euo pipefail

APP_ROOT=/home/codex/1g1a/web
IMAGE=1g1a-app:latest
CONTAINER=1g1a-app

cd "$APP_ROOT"

set -a
# shellcheck disable=SC1091
. "$APP_ROOT/config/db.env"
set +a

sudo docker build -t "$IMAGE" .
sudo docker run --rm "$IMAGE" php -l app/bootstrap.php
sudo docker run --rm "$IMAGE" php -l public/index.php
sudo docker run --rm "$IMAGE" php -l public/router.php

export PGPASSWORD="$DB_PASSWORD"
PSQL=(psql --host "$DB_HOST" --port "$DB_PORT" --username "$DB_USERNAME" --dbname "$DB_DATABASE")
"${PSQL[@]}" -v ON_ERROR_STOP=1 -f database/migrations/001_initial_schema.sql
"${PSQL[@]}" -v ON_ERROR_STOP=1 -f database/migrations/002_account_and_public_profiles.sql
"${PSQL[@]}" -v ON_ERROR_STOP=1 -f database/migrations/003_share_events_and_tokens.sql
"${PSQL[@]}" -v ON_ERROR_STOP=1 -f database/seeds/001_sns_types.sql
"${PSQL[@]}" -v ON_ERROR_STOP=1 -f database/seeds/002_demo_profile.sql
unset PGPASSWORD

sudo docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
sudo docker run -d \
    --name "$CONTAINER" \
    --restart unless-stopped \
    --network host \
    --env-file "$APP_ROOT/config/db.env" \
    -e DATABASE_URL= \
    -e APP_ENV=production \
    -e APP_URL=https://1g1a.loose.bz \
    -e APP_NAME=1G1A \
    -e APP_DEBUG=false \
    -e SESSION_SECURE=true \
    -e SESSION_HTTP_ONLY=true \
    -e SESSION_SAME_SITE=Lax \
    "$IMAGE" \
    php -S 127.0.0.1:8080 -t public public/router.php

sudo cp "$APP_ROOT/deploy/1g1a.nginx.conf" /etc/nginx/conf.d/1g1a.conf
sudo nginx -t
sudo systemctl reload nginx

curl --fail --silent --show-error --max-time 10 http://127.0.0.1:8080/ >/dev/null
curl --fail --silent --show-error --max-time 15 https://1g1a.loose.bz/ >/dev/null

sudo docker ps --filter "name=$CONTAINER"
