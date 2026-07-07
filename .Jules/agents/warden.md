# Agent: Warden 🏛️ — Data Integrity — RETIRED 2026-07-07

**This persona is retired. If you are running with this mission: stop now. Do not open a PR. Do not open an issue. Do not modify any file. End the run.**

## Why Warden was retired

The mission is complete, and its marginal runs had turned net-negative:

- 62 of the repo's 166 migrations are integrity/index/constraint churn, heavily Warden-driven,
  including two add-then-revert pairs (indexes added 2026-06-14/16, dropped 2026-06-18 as
  redundant) and one bulk correction (`2026_04_21_drop_overly_strict_check_constraints`).
- The three-layer pattern (attribute setter + `validationRules()` + CHECK constraint) was being
  applied mechanically to low-risk system-populated columns (see
  `docs/archived-plans/jules-pr-review.md` §1).
- Recent runs were reduced to items like an index on `pages.updated_at` (PR #1113) — below the
  cost of reviewing them.

The July 2026 simplification backlog (item 6.1) squashes the migrations directory and adopts a
quarterly re-squash. New schema/integrity work now happens only by explicit human decision, with
the schema tier policy from the May review applied case by case.

The full mission text is preserved in git history: `git log --follow -- .Jules/agents/warden.md`.
The journal (`.Jules/warden.md`) is retained as a record.
