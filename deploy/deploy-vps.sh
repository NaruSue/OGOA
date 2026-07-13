#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
# shellcheck disable=SC1091
source "$SCRIPT_DIR/../devscripts/lib.sh"

APP_ROOT=${APP_ROOT:-/home/codex/1g1a/web}
IMAGE=${IMAGE:-1g1a-app:latest}
CONTAINER=${CONTAINER:-1g1a-app}
APP_URL=${APP_URL:-https://1g1a.loose.bz}
LOCAL_HEALTHCHECK_URL=${LOCAL_HEALTHCHECK_URL:-http://127.0.0.1:8080/}
PUBLIC_HEALTHCHECK_URL=${PUBLIC_HEALTHCHECK_URL:-https://1g1a.loose.bz/}
DB_ENV_FILE=${DB_ENV_FILE:-"$APP_ROOT/config/db.env"}
NGINX_CONF_SOURCE=${NGINX_CONF_SOURCE:-"$APP_ROOT/deploy/1g1a.nginx.conf"}
NGINX_CONF_TARGET=${NGINX_CONF_TARGET:-/etc/nginx/conf.d/1g1a.conf}
STORAGE_DIR=${STORAGE_DIR:-"$APP_ROOT/storage"}

cd "$APP_ROOT"
load_env_file "$DB_ENV_FILE"

require_commands sudo docker curl psql

sudo docker build -t "$IMAGE" .
sudo docker run --rm "$IMAGE" php -l app/bootstrap.php
sudo docker run --rm "$IMAGE" php -l public/index.php
sudo docker run --rm "$IMAGE" php -l public/router.php

sudo mkdir -p "$STORAGE_DIR/uploads"
sudo chown -R 33:33 "$STORAGE_DIR"
sudo chmod -R u+rwX,g+rwX "$STORAGE_DIR"

export PGPASSWORD="$DB_PASSWORD"
PSQL=(psql --host "$DB_HOST" --port "$DB_PORT" --username "$DB_USERNAME" --dbname "$DB_DATABASE")
"${PSQL[@]}" -v ON_ERROR_STOP=1 -f database/migrations/001_initial_schema.sql
"${PSQL[@]}" -v ON_ERROR_STOP=1 -f database/migrations/002_account_and_public_profiles.sql
"${PSQL[@]}" -v ON_ERROR_STOP=1 -f database/migrations/003_share_events_and_tokens.sql
"${PSQL[@]}" -v ON_ERROR_STOP=1 -f database/migrations/004_share_event_expiration.sql
"${PSQL[@]}" -v ON_ERROR_STOP=1 -f database/migrations/005_guest_messages.sql
"${PSQL[@]}" -v ON_ERROR_STOP=1 -f database/migrations/006_profile_avatar_url.sql
"${PSQL[@]}" -v ON_ERROR_STOP=1 -f database/seeds/001_sns_types.sql
"${PSQL[@]}" -v ON_ERROR_STOP=1 -f database/seeds/002_demo_profile.sql
unset PGPASSWORD

sudo docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
sudo docker run -d \
    --name "$CONTAINER" \
    --restart unless-stopped \
    --network host \
    --env-file "$DB_ENV_FILE" \
    -v "$STORAGE_DIR:/var/www/html/storage" \
    -e DATABASE_URL= \
    -e APP_ENV=production \
    -e APP_URL="$APP_URL" \
    -e APP_NAME=1G1A \
    -e APP_DEBUG=false \
    -e SESSION_SECURE=true \
    -e SESSION_HTTP_ONLY=true \
    -e SESSION_SAME_SITE=Lax \
    "$IMAGE" \
    php -S 127.0.0.1:8080 -t public public/router.php

sudo cp "$NGINX_CONF_SOURCE" "$NGINX_CONF_TARGET"
sudo nginx -t
sudo systemctl reload nginx

curl --fail --silent --show-error --max-time 10 "$LOCAL_HEALTHCHECK_URL" >/dev/null
curl --fail --silent --show-error --max-time 15 "$PUBLIC_HEALTHCHECK_URL" >/dev/null

sudo docker ps --filter "name=$CONTAINER"
