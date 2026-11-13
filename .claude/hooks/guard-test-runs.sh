#!/bin/bash
# PreToolUse guard for Bash: refuses a test or Dusk run that would hit the real database.
# See AGENTS.md "Local hazards". Exit 2 blocks the call and shows stderr to the agent.

input=$(cat)
command=$(jq -r '.tool_input.command // ""' <<<"$input")
cwd=$(jq -r '.cwd // ""' <<<"$input")

if ! grep -qE '(artisan|sail)[[:space:]]+(test|dusk)|vendor/bin/(phpunit|paratest)' <<<"$command"; then
    exit 0
fi

if grep -q 'crockenhill-round' <<<"$command$cwd"; then
    echo "Blocked: never run tests or Dusk in a crockenhill-round clone or container. It has no .env.testing and shares the real crockenhill database, which RefreshDatabase drops (2026-10-15). Run the tests from the main checkout instead." >&2
    exit 2
fi

if [ -n "$cwd" ] && [ ! -f "$cwd/.env.testing" ]; then
    echo "Blocked: $cwd has no .env.testing, so this test run would use the database in .env and RefreshDatabase would drop its tables. Run from the main checkout, or copy .env.testing into this checkout first." >&2
    exit 2
fi

exit 0
