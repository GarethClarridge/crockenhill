#!/usr/bin/env bash

set -euo pipefail

repository_root="${1:-.}"
status=0

while read -r mode _ _ path; do
  if [[ "$mode" != "120000" || "$path" != *"skills/"* ]]; then
    continue
  fi

  if [[ ! -r "$repository_root/$path" ]]; then
    printf 'Tracked skill symlink does not resolve to a readable file: %s\n' "$path"
    status=1
  fi
done < <(git -C "$repository_root" ls-files -s)

if [[ "$status" -eq 0 ]]; then
  echo "Skill symlink check passed."
fi

exit "$status"
