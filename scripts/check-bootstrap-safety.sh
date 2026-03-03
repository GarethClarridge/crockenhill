#!/usr/bin/env bash

set -euo pipefail

file="bootstrap/app.php"

if [[ ! -f "$file" ]]; then
  echo "Missing $file"
  exit 1
fi

matches="$(
  awk '
    /->withMiddleware\(function \(Middleware \$middleware\) \{/ { in_block = 1 }
    in_block && /config[[:space:]]*\(/ { print NR ":" $0 }
    in_block && /^[[:space:]]*\}\)/ { in_block = 0 }
  ' "$file"
)"

if [[ -n "$matches" ]]; then
  echo "Unsafe config() usage detected inside withMiddleware() in $file."
  echo "The config repository may not be bound at this bootstrap stage."
  echo "Use env() in bootstrap or move config() access to runtime middleware."
  echo
  echo "$matches"
  exit 1
fi

echo "Bootstrap middleware safety check passed."
