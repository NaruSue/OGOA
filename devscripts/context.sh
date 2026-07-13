#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
# shellcheck disable=SC1091
source "$SCRIPT_DIR/lib.sh"

ROOT=$(repo_root)
LOG_DIR=${DEVTOOLS_LOG_DIR:-"$ROOT/logs/devscripts"}
LOG_FILE=$(init_log_file "$LOG_DIR" context)

require_commands git rg

log_line "$LOG_FILE" "Context snapshot started"
log_line "$LOG_FILE" "Repo root: $ROOT"

run_logged "$LOG_FILE" git -C "$ROOT" status --short
run_logged "$LOG_FILE" git -C "$ROOT" branch --show-current
run_logged "$LOG_FILE" git -C "$ROOT" diff --stat
run_logged "$LOG_FILE" git -C "$ROOT" log -1 --stat --oneline

if (($# > 0)); then
  for pattern in "$@"; do
    log_line "$LOG_FILE" "Search pattern: $pattern"
    if ! rg -n -S --hidden --glob '!.git' --glob '!logs/**' --glob '!vendor/**' "$pattern" "$ROOT" >>"$LOG_FILE" 2>&1; then
      log_line "$LOG_FILE" "No matches for: $pattern"
    fi
  done
fi

log_line "$LOG_FILE" "Context snapshot finished"
printf '%s\n' "$LOG_FILE"
