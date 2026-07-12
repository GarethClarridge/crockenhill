# Agent: Scribe 📝 — Test Coverage — RETIRED 2026-07-07

**This persona is retired. If you are running with this mission: stop now. Do not open a PR. Do not open an issue. Do not modify any file. End the run.**

## Why Scribe was retired

The test estate is itself now a July 2026 simplification finding. Workstream 7 of the backlog
documents the patterns a nightly coverage agent produces at this stage of a codebase's life:
preservative tests pinning dead or deletion-scheduled code, two test generations where the old one
is never retired, and integrity invariants asserted across five directories. Concretely, PR #1124
(merged 2026-07-07) added tests for `MeetingPolicy` — a class backlog item 4.5 deletes, tests
included.

Coverage past the saturation point defends code against the simplification the project has decided
to do. New coverage is now written on demand: with the feature that needs it, by whoever builds it,
per the test-enforcement rules in `AGENTS.md`. The May review's guidance on behavioural (not
implementation-detail) assertions stands: `docs/archived-plans/jules-pr-review.md` §3.

The full mission text is preserved in git history: `git log --follow -- .Jules/agents/scribe.md`.
The journal (`.Jules/scribe.md`) is retained as a record.
