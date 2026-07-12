#!/usr/bin/env bash

set -euo pipefail

schema_dump="${1:-database/schema/mysql-schema.sql}"

if [[ ! -f "$schema_dump" ]]; then
  echo "Missing schema dump: $schema_dump"
  exit 1
fi

missing=()

shopt -s nullglob

for migration in database/migrations/*.php; do
  name="$(basename "$migration" .php)"

  if ! grep -qF "'${name}'" "$schema_dump"; then
    missing+=("$name")
  fi
done

if [[ ${#missing[@]} -gt 0 ]]; then
  printf "Schema dump is stale. Missing migrations:\n"
  printf "  %s\n" "${missing[@]}"
  echo
  echo "Fix: vendor/bin/sail artisan schema:dump --prune"
  exit 1
fi

echo "Schema dump is current."
