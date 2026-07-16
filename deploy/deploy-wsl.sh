#!/usr/bin/env bash
set -euo pipefail

TARGET=${1:-main}

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
APP_ROOT=$(cd -- "$SCRIPT_DIR/.." && pwd)
# shellcheck disable=SC1091
source "$APP_ROOT/devscripts/lib.sh"

CONFIG_PATH=${DEPLOY_CONFIG:-"$SCRIPT_DIR/.env"}
LOG_DIR=${DEPLOY_LOG_DIR:-"$APP_ROOT/logs/deploy"}
REMOTE_ROOT=${DEPLOY_REMOTE_ROOT:-~/1g1a/web}

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

require_commands ssh

log_line "$LOG_FILE" "Deployment started"
log_line "$LOG_FILE" "Target: $TARGET"
log_line "$LOG_FILE" "App root: $APP_ROOT"
log_line "$LOG_FILE" "Config path: $CONFIG_PATH"

SSH_KEY_COPY=$(prepare_ssh_key "$DEPLOY_SSH_KEY")
DEPLOY_SSH_KEY="$SSH_KEY_COPY"

remote_target=$(printf '%q' "$TARGET")
remote_root=$REMOTE_ROOT

run_logged "$LOG_FILE" ssh -p "$DEPLOY_SSH_PORT" -i "$DEPLOY_SSH_KEY" -o BatchMode=yes "${DEPLOY_SSH_USER}@${DEPLOY_SSH_HOST}" "cd $remote_root && bash deploy/deploy-vps.sh $remote_target"

log_line "$LOG_FILE" "Deployment finished successfully"
printf 'Deployment complete. Log: %s\n' "$LOG_FILE"
