#!/usr/bin/env bash

set -euo pipefail

file="${1:-bootstrap/app.php}"

if [[ ! -f "$file" ]]; then
  echo "Missing $file"
  exit 1
fi

checker_script="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/check-bootstrap-safety.php"

if [[ ! -f "$checker_script" ]]; then
  echo "Missing $checker_script"
  exit 1
fi

run_checker() {
  if command -v php >/dev/null 2>&1; then
    php "$checker_script" "$file"
    return
  fi

  if [[ -x "./vendor/bin/sail" ]]; then
    local escaped_checker escaped_file
    escaped_checker=$(printf '%q' "$checker_script")
    escaped_file=$(printf '%q' "$file")

    /bin/zsh -lc "./vendor/bin/sail php ${escaped_checker} ${escaped_file}"
    return
  fi

  echo "Missing PHP runtime. Install php or run via ./vendor/bin/sail."
  exit 1
}

matches="$(run_checker)"

if [[ -n "$matches" ]]; then
  echo "Unsafe direct config() usage detected inside withMiddleware() in $file."
  echo "The config repository may not be bound at this bootstrap stage."
  echo "Use env() in bootstrap or defer config() inside a runtime closure."
  echo
  echo "$matches"
  exit 1
fi

echo "Bootstrap middleware safety check passed."
