#!/bin/sh
set -eu

run_sql_dir() {
  dir="$1"
  if [ ! -d "$dir" ]; then
    return 0
  fi

  find "$dir" -maxdepth 1 -type f -name '*.sql' | sort | while read -r file; do
    echo "Applying $file"
    psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" --file "$file"
  done
}

run_sql_dir /docker-entrypoint-initdb.d/10-migrations
run_sql_dir /docker-entrypoint-initdb.d/20-seeds
