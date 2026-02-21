#!/usr/bin/env bash

set -euo pipefail

BASE_SHA="${1:-}"
HEAD_SHA="${2:-HEAD}"

if [[ -n "${BASE_SHA}" ]] && ! git cat-file -e "${BASE_SHA}^{commit}" 2>/dev/null; then
  BASE_SHA=""
fi

if [[ -z "${BASE_SHA}" ]] && git rev-parse --verify HEAD^ >/dev/null 2>&1; then
  BASE_SHA="$(git rev-parse HEAD^)"
fi

if [[ -z "${BASE_SHA}" ]]; then
  echo "Unable to determine a base commit for diff; skipping typing baseline check."
  exit 0
fi

mapfile -t files < <(git diff --name-only --diff-filter=AM "${BASE_SHA}" "${HEAD_SHA}" -- ':(glob)app/**/*.php')

if [[ ${#files[@]} -eq 0 ]]; then
  echo "No added or modified app PHP files to check."
  exit 0
fi

php scripts/check-typing-baseline.php "${files[@]}"
