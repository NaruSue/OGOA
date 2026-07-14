#!/usr/bin/env bash
set -euo pipefail

TARGET=${1:-main}

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
# shellcheck disable=SC1091
source "$SCRIPT_DIR/../devscripts/lib.sh"

APP_ROOT=${APP_ROOT:-$(cd -- "$SCRIPT_DIR/.." && pwd)}
IMAGE=${IMAGE:-1g1a-app:latest}
CONTAINER=${CONTAINER:-1g1a-app}
APP_URL=${APP_URL:-https://1g1a.loose.bz}
LOCAL_HEALTHCHECK_URL=${LOCAL_HEALTHCHECK_URL:-http://127.0.0.1:8080/}
PUBLIC_HEALTHCHECK_URL=${PUBLIC_HEALTHCHECK_URL:-https://1g1a.loose.bz/}
DB_ENV_FILE=${DB_ENV_FILE:-"$APP_ROOT/.env"}
NGINX_CONF_SOURCE=${NGINX_CONF_SOURCE:-"$APP_ROOT/deploy/1g1a.nginx.conf"}
NGINX_CONF_TARGET=${NGINX_CONF_TARGET:-/etc/nginx/conf.d/1g1a.conf}
STORAGE_DIR=${STORAGE_DIR:-"$APP_ROOT/storage"}
APP_SERVER_LOG=${APP_SERVER_LOG:-"$APP_ROOT/logs/php-server.log"}
APP_SERVER_PID=${APP_SERVER_PID:-"$APP_ROOT/.php-server.pid"}

cd "$APP_ROOT"
load_env_file "$DB_ENV_FILE"

require_commands sudo curl psql php

if [[ -n "$(git status --porcelain)" ]]; then
    echo "working_tree_dirty"
    git status --short
    exit 1
fi

git fetch origin --prune
if git rev-parse --verify --quiet "origin/$TARGET" >/dev/null; then
    target_sha=$(git rev-parse "origin/$TARGET")
else
    target_sha=$(git rev-parse "$TARGET")
fi

git checkout --detach "$target_sha"

php -l app/bootstrap.php
php -l public/index.php
php -l public/router.php

sudo mkdir -p "$STORAGE_DIR/uploads"
sudo chown -R "$(id -u)":"$(id -g)" "$STORAGE_DIR"
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

if [[ -f "$APP_SERVER_PID" ]]; then
    old_pid=$(cat "$APP_SERVER_PID")
    if [[ -n "$old_pid" ]] && kill -0 "$old_pid" >/dev/null 2>&1; then
        kill "$old_pid" || true
        sleep 1
    fi
fi

mkdir -p "$(dirname "$APP_SERVER_LOG")"
nohup php -S 127.0.0.1:8080 -t public public/router.php >"$APP_SERVER_LOG" 2>&1 &
echo $! >"$APP_SERVER_PID"

sudo cp "$NGINX_CONF_SOURCE" "$NGINX_CONF_TARGET"
sudo nginx -t
sudo systemctl reload nginx

curl --fail --silent --show-error --max-time 10 "$LOCAL_HEALTHCHECK_URL" >/dev/null
curl --fail --silent --show-error --max-time 15 "$PUBLIC_HEALTHCHECK_URL" >/dev/null
