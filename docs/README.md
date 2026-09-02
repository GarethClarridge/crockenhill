# docs/ — read this first

Rules for using this folder, written for AI agents as much as humans:

1. **The code is the source of truth.** Docs here either track *work* (plans, issues) or explain
   things the code cannot (deployment topology, manual setup steps, design intent). Nothing here
   documents class-by-class behaviour — read the code and tests for that.
2. **Every document carries a date.** Distrust anything that contradicts the code, and fix or
   delete it when you notice.
3. **One index for current work.** Start at [`plans/README.md`](plans/README.md). The historic
   archive's sole authority is the
   [`HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE`](plans/HISTORIC-IMPORT-INCREMENTAL-CONVERGENCE-2026-08-14.md)
   plan (2026-08-14; the three predecessor plans are archived evidence records); the July
   simplification remainder remains a separate active spine until R15 closes it.

## Map

| Location | What it is |
|---|---|
| [`plans/`](plans/README.md) | **Active plans only.** The README is the authoritative index and ordering. |
| [`issues/README.md`](issues/README.md) | Consolidated open-issues tracker (audit findings from Mortician/Pathfinder runs land here, then the source reports are deleted). |
| `reports/` | Recent decision-support reports (currently: service automation opportunities, 2026-07-05). |
| [`design-style-guide.md`](design-style-guide.md) | **Read before any UI work.** Brand tokens, components, anti-patterns. Screenshots in `design-references/` (gitignored; regenerate per the guide). |
| [`api/media-processing.md`](api/media-processing.md) | API reference for the media/services/webhook endpoints (`routes/api.php` wins on conflict). |
| [`operations/production.md`](operations/production.md) | Production stack, Horizon queues, scheduler, deploy/rollback. |
| [`operations/historic-video-pass-control.md`](operations/historic-video-pass-control.md) | **Read before running a historic-video pass.** Starting, watching, stopping and resuming one; reading its dispositions; what a provider 429 actually means. |
| [`operations/livestream-corpus-testing.md`](operations/livestream-corpus-testing.md) | Semi-manual end-to-end regression testing of the whole livestream chain against hand-annotated real recordings. |
| [`operations/llm-structure-promotion-soak.md`](operations/llm-structure-promotion-soak.md) | **Historical (closed 2026-07-19)**; retained for its stage summaries and backfill reference. |
| [`operations/r8-data-convergence-runbook.md`](operations/r8-data-convergence-runbook.md) | **Superseded 2026-08-14**; retained for command reference only. The production round procedure is §7 of the incremental-convergence plan. |
| [`operations/SEO_SETUP_GUIDE.md`](operations/SEO_SETUP_GUIDE.md) | Manual Search Console / GA4 setup steps (maintainer tasks). |
| [`operations/section-extraction-testing.md`](operations/section-extraction-testing.md) | Local-only regression harness for section extraction against real recordings (with `structure-eval-manifest.example.json`). |
| [`operations/horizon-staging-smoke-test.md`](operations/horizon-staging-smoke-test.md) | Outstanding one-time staging verification. |
| [`archived-plans/JULY-2026-SIMPLIFICATION-BACKLOG-2026-07-05.md`](archived-plans/JULY-2026-SIMPLIFICATION-BACKLOG-2026-07-05.md) | Archived parent decision record; the active remainder plan now contains only R13–R15. |
| `reviews/july-2026-simplification/` | The domain reviews behind the July 2026 backlog — reference material for backlog items. |
| [`archived-plans/`](archived-plans/README.md) | Completed/superseded plans and historical audits. Never treat as current. |

## Conventions

- New plans go in `plans/` following the conventions at the bottom of `plans/README.md`
  (dated status header, explicit supersession).
- Completed or superseded documents move to `archived-plans/` with a dated archival header
  saying what superseded them — or are simply deleted (git history is the real archive).
  Point-in-time review/audit reports should be deleted once their findings are folded into
  `issues/README.md` or a plan.
- The plans index was last reconciled against the codebase **2026-08-24**. The broader docs cleanup
  was last performed **2026-07-05** (it removed ~50 stale files; use git history for older material).
