#!/usr/bin/env bash
set -euo pipefail

DEVTOOLS_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)

script_dir() {
  printf '%s\n' "$DEVTOOLS_DIR"
}

repo_root() {
  cd -- "$DEVTOOLS_DIR/.." && pwd
}

timestamp() {
  date +%Y%m%d-%H%M%S
}

ensure_dir() {
  mkdir -p "$1"
}

init_log_file() {
  local log_dir=$1
  local prefix=${2:-tool}
  ensure_dir "$log_dir"
  local log_file="$log_dir/${prefix}-$(timestamp).log"
  : >"$log_file"
  printf '%s\n' "$log_file"
}

log_line() {
  local log_file=$1
  shift
  printf '%s %s\n' "$(date '+%F %T')" "$*" >>"$log_file"
}

run_logged() {
  local log_file=$1
  shift
  log_line "$log_file" "RUN: $*"
  "$@" >>"$log_file" 2>&1
}

require_commands() {
  local missing=()
  local cmd
  for cmd in "$@"; do
    command -v "$cmd" >/dev/null 2>&1 || missing+=("$cmd")
  done

  if ((${#missing[@]} > 0)); then
    printf 'Missing commands: %s\n' "${missing[*]}" >&2
    return 1
  fi
}

load_env_file() {
  local path=$1
  if [[ -f "$path" ]]; then
    set -a
    # shellcheck disable=SC1090
    source "$path"
    set +a
  fi
}

normalize_path() {
  local value=$1
  if [[ $value =~ ^[A-Za-z]:[\\/].* || $value == *\\* ]]; then
    if command -v wslpath >/dev/null 2>&1; then
      wslpath -u "$value"
      return
    fi
    if command -v cygpath >/dev/null 2>&1; then
      cygpath -u "$value"
      return
    fi
  fi
  printf '%s\n' "$value"
}
