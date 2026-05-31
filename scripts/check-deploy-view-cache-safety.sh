#!/usr/bin/env bash

set -euo pipefail

workflow="${1:-.github/workflows/deploy.yml}"

if [[ ! -f "$workflow" ]]; then
  echo "Missing $workflow"
  exit 1
fi

if ! grep -Fq "php artisan optimize --except=views" "$workflow"; then
  echo "Deploy workflow must use: php artisan optimize --except=views"
  exit 1
fi

if grep -E "php artisan (view:cache|view:clear)\\b" "$workflow"; then
  echo "Deploy workflow must not run view:cache or view:clear."
  exit 1
fi

if grep -F "php artisan optimize" "$workflow" | grep -Fv -- "--except=views" >/dev/null; then
  echo "Found unsafe optimize command. Use --except=views."
  exit 1
fi

echo "Deploy view cache safety check passed."
