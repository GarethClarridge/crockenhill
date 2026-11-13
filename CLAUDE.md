# CLAUDE.md

@AGENTS.md

## Claude Code notes

`AGENTS.md` above is the source of truth for every agent. This file adds only what is specific to
Claude Code. Keep project facts out of it.

- **Skills** live in `.agents/skills/`; `.claude/skills/` holds symlinks to them. Edit the
  `.agents/skills/` file.
- **Boost MCP** (`laravel-boost`): `database-query` and `database-schema` for read-only database
  work, `search-docs` for version-specific Laravel, Livewire and package documentation,
  `last-error` and `read-log-entries` for failures, `get-absolute-url` before sharing a local URL.
  Boost no longer has a tinker tool; use `vendor/bin/sail artisan tinker --execute '...'`.
- **Hook**: `.claude/hooks/guard-test-runs.sh` blocks a test or Dusk run from a checkout with no
  `.env.testing` and from any `crockenhill-round` clone. It cannot see a data pass in flight; that
  check in "Local hazards" is still yours to make.
- **Subagent**: `transcript-reviewer` is only for `storage/scratch/output-review-*` bundles.
- **Boost writes to `AGENTS.md` only.** Claude Code is deliberately absent from the `agents` list
  in `boost.json`; adding it back makes Boost write a second copy of its guidelines into this file
  and replace the skill symlinks with copies.
