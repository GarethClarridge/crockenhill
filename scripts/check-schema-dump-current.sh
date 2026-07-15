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
  echo "Routine fix:"
  echo "  vendor/bin/sail artisan migrate"
  echo "  vendor/bin/sail artisan schema:dump"
  echo
  echo "The --prune option is reserved for a deliberate quarterly squash after every"
  echo "included migration has been verified on every long-lived environment."
  exit 1
fi

echo "Schema dump is current."
