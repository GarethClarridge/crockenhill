#!/usr/bin/env bash

# Guards against case-only path clashes in the git index.
#
# macOS/Windows filesystems are case-insensitive, but git's index is
# case-sensitive. A path committed under two different cases (e.g.
# `.Jules/scribe.md` and `.jules/scribe.md`) collapses to one physical file
# locally, leaving a "ghost" index entry that surfaces as a phantom
# modification on every edit. This check fails the build before such an entry
# can land on master.
#
# It enforces two rules:
#   1. No two tracked paths may differ only in case.
#   2. The agent directory is canonically `.Jules/` (capital J); any tracked
#      path under lowercase `.jules/` is rejected.

set -euo pipefail

status=0

# Rule 1: case-insensitive duplicate paths anywhere in the tree.
collisions="$(git ls-files | awk '{
  lower = tolower($0)
  if (lower in seen) {
    print seen[lower]
    print $0
  } else {
    seen[lower] = $0
  }
}')"

if [ -n "$collisions" ]; then
  echo "Case-only path collisions detected (these paths differ only in letter case):"
  echo "$collisions"
  echo
  echo "On a case-insensitive filesystem these share one physical file and cause"
  echo "phantom 'modified' status. Keep a single canonical case for each path."
  status=1
fi

# Rule 2: the agent directory must be capital '.Jules/'.
# Grep the full file list case-sensitively; a `.jules` pathspec would be folded
# to `.Jules` by core.ignorecase and match nothing.
wrong_case="$(git ls-files | grep '^\.jules/' || true)"

if [ -n "$wrong_case" ]; then
  echo "Lowercase '.jules/' paths are tracked; the canonical directory is '.Jules/' (capital J):"
  echo "$wrong_case"
  echo
  echo "Re-case them with: git rm --cached <path> && git add .Jules"
  status=1
fi

if [ "$status" -eq 0 ]; then
  echo "Case-collision check passed."
fi

exit "$status"
