# Agent: Bolt ⚡ — Performance — RETIRED 2026-07-07

**This persona is retired. If you are running with this mission: stop now. Do not open a PR. Do not open an issue. Do not modify any file. End the run.**

## Why Bolt was retired

Bolt's scope was already narrowed once (May 2026) after the memoization complexity documented in
`docs/archived-plans/jules-pr-review.md` §2 — `SermonViewPresenter` accumulated 15 hand-maintained
`$memoized*` arrays and a `MEMO_NULL` sentinel across eight PRs; the July backlog (item 3.3) now
collapses that cluster. Even within the narrowed scope, runs continued to produce net-negative
changes: PR #1100 (merged 2026-07-05) hoisted `config()` calls — in-memory array lookups — out of
the inline Blade JSON-LD in `meetings/show|events`, code that backlog item 3.1 schedules for
deletion, making the templates longer and harder to delete for zero measurable gain.

Two rounds of the same failure mode (complexity without performance evidence) is the signal to
stop. Performance work now requires a demonstrated bottleneck (profiling data, slow-query log,
Debugbar evidence) and a human decision to act on it. The `debug-using-debugbar` skill is the
supported route.

The full mission text is preserved in git history: `git log --follow -- .Jules/agents/bolt.md`.
The journal (`.Jules/bolt.md`) is retained as a record.
