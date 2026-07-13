#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
APP_ROOT=$(cd -- "$SCRIPT_DIR/.." && pwd)
# shellcheck disable=SC1091
source "$APP_ROOT/devscripts/lib.sh"

CONFIG_PATH=${DEPLOY_CONFIG:-"$SCRIPT_DIR/.env"}
LOG_DIR=${DEPLOY_LOG_DIR:-"$APP_ROOT/logs/deploy"}
REMOTE_ROOT=${DEPLOY_REMOTE_ROOT:-/home/codex/1g1a/web}
REMOTE_TMP_DIR=${DEPLOY_REMOTE_TMP_DIR:-/tmp}
IMAGE=${DEPLOY_IMAGE:-1g1a-app:latest}
CONTAINER=${DEPLOY_CONTAINER:-1g1a-app}
APP_URL=${DEPLOY_APP_URL:-https://1g1a.loose.bz}
LOCAL_HEALTHCHECK_URL=${DEPLOY_LOCAL_HEALTHCHECK_URL:-http://127.0.0.1:8080/}
PUBLIC_HEALTHCHECK_URL=${DEPLOY_PUBLIC_HEALTHCHECK_URL:-https://1g1a.loose.bz/}
DB_ENV_FILE=${DEPLOY_DB_ENV_FILE:-"$REMOTE_ROOT/config/db.env"}
NGINX_CONF_SOURCE=${DEPLOY_NGINX_CONF_SOURCE:-"$REMOTE_ROOT/deploy/1g1a.nginx.conf"}
NGINX_CONF_TARGET=${DEPLOY_NGINX_CONF_TARGET:-/etc/nginx/conf.d/1g1a.conf}

LOG_FILE=$(init_log_file "$LOG_DIR" deploy)

load_env_file "$CONFIG_PATH"

: "${DEPLOY_SSH_HOST:?missing DEPLOY_SSH_HOST}"
: "${DEPLOY_SSH_PORT:?missing DEPLOY_SSH_PORT}"
: "${DEPLOY_SSH_USER:?missing DEPLOY_SSH_USER}"
: "${DEPLOY_SSH_KEY:?missing DEPLOY_SSH_KEY}"

DEPLOY_SSH_KEY=$(normalize_path "$DEPLOY_SSH_KEY")
SSH_KEY_COPY=

prepare_ssh_key() {
  local source_key=$1
  local copied_key

  if [[ ! -f "$source_key" ]]; then
    fail "SSH key not found: $source_key"
  fi

  copied_key=$(mktemp -p "${TMPDIR:-/tmp}" 1g1a-ssh-key.XXXXXX)
  cp "$source_key" "$copied_key"
  chmod 600 "$copied_key"
  printf '%s\n' "$copied_key"
}

fail() {
  log_line "$LOG_FILE" "FAILED: $*"
  printf 'Deployment failed. Log: %s\n' "$LOG_FILE"
  exit 1
}

trap 'fail "unexpected error on line $LINENO"' ERR

for cmd in git tar scp ssh; do
  command -v "$cmd" >/dev/null 2>&1 || fail "required command not found: $cmd"
done

log_line "$LOG_FILE" "Deployment started"
log_line "$LOG_FILE" "App root: $APP_ROOT"
log_line "$LOG_FILE" "Config path: $CONFIG_PATH"
log_line "$LOG_FILE" "WSL distro: ${DEPLOY_WSL_DISTRO:-default}"

archive_name="1g1a-deploy-$(date +%Y%m%d-%H%M%S).tar.gz"
archive_path=$(mktemp -p "${TMPDIR:-/tmp}" "$archive_name.XXXXXX")
remote_archive="$REMOTE_TMP_DIR/$archive_name"

cleanup() {
  rm -f "$archive_path"
  if [[ -n "$SSH_KEY_COPY" ]]; then
    rm -f "$SSH_KEY_COPY"
  fi
}
trap cleanup EXIT

SSH_KEY_COPY=$(prepare_ssh_key "$DEPLOY_SSH_KEY")
DEPLOY_SSH_KEY="$SSH_KEY_COPY"

run_logged "$LOG_FILE" git -C "$APP_ROOT" status --short
run_logged "$LOG_FILE" git -C "$APP_ROOT" rev-parse --short HEAD

log_line "$LOG_FILE" "Creating archive: $archive_path"
tar -czf "$archive_path" \
  --exclude-vcs \
  --exclude=.env \
  --exclude=.env.local \
  --exclude=.env.*.local \
  --exclude=deploy/.env \
  --exclude=deploy/.env.local \
  --exclude=docs.local \
  --exclude=logs \
  --exclude=tmp \
  --exclude=cache \
  --exclude=storage \
  --exclude=1g1a-project-backup.zip \
  -C "$APP_ROOT" . >>"$LOG_FILE" 2>&1

log_line "$LOG_FILE" "Uploading archive to ${DEPLOY_SSH_USER}@${DEPLOY_SSH_HOST}:${remote_archive}"
scp -P "$DEPLOY_SSH_PORT" -i "$DEPLOY_SSH_KEY" -o BatchMode=yes "$archive_path" "${DEPLOY_SSH_USER}@${DEPLOY_SSH_HOST}:${remote_archive}" >>"$LOG_FILE" 2>&1

log_line "$LOG_FILE" "Running remote deployment"
ssh -p "$DEPLOY_SSH_PORT" -i "$DEPLOY_SSH_KEY" -o BatchMode=yes "${DEPLOY_SSH_USER}@${DEPLOY_SSH_HOST}" bash -s <<EOF >>"$LOG_FILE" 2>&1
set -euo pipefail
mkdir -p '$REMOTE_ROOT'
cd '$REMOTE_ROOT'
tar -xzf '$remote_archive' -C '$REMOTE_ROOT'
rm -f '$remote_archive'
APP_ROOT='$REMOTE_ROOT' IMAGE='$IMAGE' CONTAINER='$CONTAINER' APP_URL='$APP_URL' LOCAL_HEALTHCHECK_URL='$LOCAL_HEALTHCHECK_URL' PUBLIC_HEALTHCHECK_URL='$PUBLIC_HEALTHCHECK_URL' DB_ENV_FILE='$DB_ENV_FILE' NGINX_CONF_SOURCE='$NGINX_CONF_SOURCE' NGINX_CONF_TARGET='$NGINX_CONF_TARGET' bash deploy/deploy-vps.sh
EOF

log_line "$LOG_FILE" "Deployment finished successfully"
printf 'Deployment complete. Log: %s\n' "$LOG_FILE"
