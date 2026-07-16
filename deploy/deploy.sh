#!/usr/bin/env bash
set -euo pipefail

TARGET=${1:-main}
APP_ROOT=${APP_ROOT:-.}
LOG_DIR=${LOG_DIR:-logs/deploy}
STATE_DIR=${STATE_DIR:-.deploy-state}
STATE_FILE=${STATE_FILE:-$STATE_DIR/sql-state.tsv}
HISTORY_FILE=${HISTORY_FILE:-$STATE_DIR/sql-history.tsv}

require_commands() {
    local missing=()
    local cmd
    for cmd in "$@"; do
        if ! command -v "$cmd" >/dev/null 2>&1; then
            missing+=("$cmd")
        fi
    done

    if ((${#missing[@]} > 0)); then
        printf 'Missing commands: %s\n' "${missing[*]}" >&2
        exit 1
    fi
}

load_db_env() {
    local env_file=
    local candidate
    for candidate in .env config/db.env .env.local; do
        if [ -f "$candidate" ]; then
            env_file=$candidate
            break
        fi
    done

    if [ -z "$env_file" ]; then
        echo "missing_db_env_file" >&2
        exit 1
    fi

    eval "$(python3 - "$env_file" <<'PY'
from pathlib import Path
import shlex
import sys

path = Path(sys.argv[1])
required = {'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'}
values = {}

for raw in path.read_text(encoding='utf-8-sig').splitlines():
    line = raw.strip()
    if not line or line.startswith('#') or '=' not in line:
        continue
    key, value = line.split('=', 1)
    key = key.strip()
    value = value.strip()
    if len(value) >= 2 and ((value[0] == value[-1] == '"') or (value[0] == value[-1] == "'")):
        value = value[1:-1]
    values[key] = value

missing = [key for key in sorted(required) if not values.get(key)]
if missing:
    raise SystemExit('missing_db_env_keys=' + ','.join(missing))

for key in sorted(required):
    print(f'export {key}={shlex.quote(values[key])}')
PY
)"
}

load_applied_state() {
    declare -gA APPLIED_SHA=()
    declare -gA APPLIED_AT=()

    if [ -f "$STATE_FILE" ]; then
        while IFS=$'\t' read -r kind rel_path checksum applied_at; do
            [ -n "${kind:-}" ] || continue
            APPLIED_SHA["$kind:$rel_path"]=$checksum
            APPLIED_AT["$kind:$rel_path"]=$applied_at
        done < "$STATE_FILE"
    fi
}

write_applied_state() {
    local tmp_file
    tmp_file=$(mktemp)
    {
        local key kind rel_path
        for key in "${!APPLIED_SHA[@]}"; do
            kind=${key%%:*}
            rel_path=${key#*:}
            printf '%s\t%s\t%s\t%s\n' "$kind" "$rel_path" "${APPLIED_SHA[$key]}" "${APPLIED_AT[$key]}"
        done
    } | sort > "$tmp_file"
    mv "$tmp_file" "$STATE_FILE"
}

apply_sql_file() {
    local kind=$1
    local file=$2
    local rel_path=$3
    local checksum key applied_at

    checksum=$(sha256sum "$file" | awk '{print $1}')
    key="$kind:$rel_path"

    if [ "${APPLIED_SHA[$key]-}" = "$checksum" ]; then
        printf 'sql=unchanged kind=%s file=%s\n' "$kind" "$rel_path"
        return 0
    fi

    printf 'sql=apply kind=%s file=%s\n' "$kind" "$rel_path"
    "${PSQL_BASE[@]}" -v ON_ERROR_STOP=1 --file "$file" >/dev/null
    applied_at=$(date -Is)
    APPLIED_SHA["$key"]=$checksum
    APPLIED_AT["$key"]=$applied_at
    printf '%s\t%s\t%s\t%s\n' "$applied_at" "$kind" "$rel_path" "$checksum" >> "$HISTORY_FILE"
}

apply_sql_dir() {
    local kind=$1
    local dir=$2
    local file rel_path

    if [ ! -d "$dir" ]; then
        printf 'sql=skip kind=%s dir=%s\n' "$kind" "$dir"
        return 0
    fi

    while IFS= read -r -d '' file; do
        rel_path=${file#./}
        apply_sql_file "$kind" "$file" "$rel_path"
    done < <(find "$dir" -maxdepth 1 -type f -name '*.sql' -print0 | sort -z)
}

cd "$APP_ROOT"
mkdir -p "$LOG_DIR" "$STATE_DIR"
LOG_FILE="$LOG_DIR/deploy-$(date +%Y%m%d-%H%M%S).log"
exec > >(tee "$LOG_FILE") 2>&1

require_commands git psql python3 sha256sum find sort tee awk mktemp

if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    echo "working_tree_dirty"
    git status --short
    exit 1
fi

echo "ref=$TARGET"
echo "started_at=$(date -Is)"

git fetch origin --prune
if git rev-parse --verify --quiet "origin/$TARGET" >/dev/null; then
    echo "checkout_target=origin/$TARGET"
    if git show-ref --verify --quiet "refs/heads/$TARGET"; then
        git switch "$TARGET"
    else
        git switch -c "$TARGET" --track "origin/$TARGET"
    fi
    git pull --ff-only origin "$TARGET"
else
    echo "checkout_target=$TARGET"
    git checkout --detach "$TARGET"
fi

if [ -f package.json ]; then
    if [ -f package-lock.json ]; then
        npm ci
    else
        npm install
    fi
    npm run build
else
    echo "build=skipped_no_package_json"
fi

load_db_env
export PGPASSWORD="$DB_PASSWORD"
PSQL_BASE=(psql --host "$DB_HOST" --port "$DB_PORT" --username "$DB_USERNAME" --dbname "$DB_DATABASE")

load_applied_state
apply_sql_dir migration database/migrations
apply_sql_dir seed database/seeds
write_applied_state

unset PGPASSWORD

echo "status=ok"
echo "log_file=$LOG_FILE"