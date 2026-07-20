# docs/ — read this first

Rules for using this folder, written for AI agents as much as humans:

1. **The code is the source of truth.** Docs here either track *work* (plans, issues) or explain
   things the code cannot (deployment topology, manual setup steps, design intent). Nothing here
   documents class-by-class behaviour — read the code and tests for that.
2. **Every document carries a date.** Distrust anything that contradicts the code, and fix or
   delete it when you notice.
3. **One active tracker.** All current work flows from
   [`plans/JULY-2026-SIMPLIFICATION-BACKLOG-2026-07-05.md`](plans/JULY-2026-SIMPLIFICATION-BACKLOG-2026-07-05.md),
   executed via its remainder plan
   [`plans/JULY-2026-SIMPLIFICATION-REMAINDER-2026-07-19.md`](plans/JULY-2026-SIMPLIFICATION-REMAINDER-2026-07-19.md)
   (which corrects the backlog's stale statuses) — start at [`plans/README.md`](plans/README.md).

## Map

| Location | What it is |
|---|---|
| [`plans/`](plans/README.md) | **Active plans only.** The README is the index; the July 2026 backlog is the spine. |
| [`issues/README.md`](issues/README.md) | Consolidated open-issues tracker (audit findings from Mortician/Pathfinder runs land here, then the source reports are deleted). |
| `reports/` | Recent decision-support reports (currently: service automation opportunities, 2026-07-05). |
| [`design-style-guide.md`](design-style-guide.md) | **Read before any UI work.** Brand tokens, components, anti-patterns. Screenshots in `design-references/` (gitignored; regenerate per the guide). |
| [`api/media-processing.md`](api/media-processing.md) | API reference for the media/services/webhook endpoints (`routes/api.php` wins on conflict). |
| [`operations/production.md`](operations/production.md) | Production stack, Horizon queues, scheduler, deploy/rollback. |
| [`operations/SEO_SETUP_GUIDE.md`](operations/SEO_SETUP_GUIDE.md) | Manual Search Console / GA4 setup steps (maintainer tasks). |
| [`operations/section-extraction-testing.md`](operations/section-extraction-testing.md) | Local-only regression harness for section extraction against real recordings (with `structure-eval-manifest.example.json`). |
| [`operations/horizon-staging-smoke-test.md`](operations/horizon-staging-smoke-test.md) | Outstanding one-time staging verification. |
| [`archived-plans/simplification-backlog-2026-07-20.md`](archived-plans/simplification-backlog-2026-07-20.md) | Archived superseded simplification backlog; the remainder plan is now authoritative. |
| `reviews/july-2026-simplification/` | The domain reviews behind the July 2026 backlog — reference material for backlog items. |
| [`archived-plans/`](archived-plans/README.md) | Completed/superseded plans and historical audits. Never treat as current. |

## Conventions

- New plans go in `plans/` following the conventions at the bottom of `plans/README.md`
  (dated status header, explicit supersession).
- Completed or superseded documents move to `archived-plans/` with a dated archival header
  saying what superseded them — or are simply deleted (git history is the real archive).
  Point-in-time review/audit reports should be deleted once their findings are folded into
  `issues/README.md` or a plan.
- Docs were last reconciled against the codebase **2026-07-05** (this cleanup removed ~50 stale
  files; see git history for anything referenced by an old conversation or commit).
