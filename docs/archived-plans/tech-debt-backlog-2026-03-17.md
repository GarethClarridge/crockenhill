> **Archived 2026-07-05.** Consolidation of the March 2026 review passes; statuses frozen 2026-03-25 and not reconciled item-by-item into the July 2026 backlog. Most delivered work landed via the June 2026 review implementation and TESTING-REMEDIATION-PLAN. **Do not work from this file**; if resurrecting an item, verify it against current code first. The March 2026 source reviews this file links to under `../reviews/` were deleted 2026-07-05 — retrieve them from git history if needed.

# Tech Debt Backlog (2026-03-17)

_Last updated: 2026-03-25_

## Purpose

This backlog consolidates the March 2026 architecture and review passes into implementable, reviewable items.
Each backlog item should land as one focused PR, or one tightly related pair of PRs, with tests in place before behavior is moved.

The backlog is ordered for safety:

1. freeze the riskiest behavior with direct tests and reusable test seams
2. fix security, correctness, and operator-recovery bugs at app boundaries
3. extract write-side seams before changing orchestration or schema ownership
4. normalize schema and orchestration only after the boundaries are explicit

## Source Reviews

- [architectural-review-2026-03-17.md](../reviews/architectural-review-2026-03-17.md)
- [architecture-review-2026-03-17.md](../reviews/architecture-review-2026-03-17.md)
- [media-processing-architecture-review-2026-03-17.md](../reviews/media-processing-architecture-review-2026-03-17.md)
- [media-processing-church-service-observability-audit-2026-03-17.md](../reviews/media-processing-church-service-observability-audit-2026-03-17.md)
- [oos-alignment-service-review.md](../reviews/oos-alignment-service-review.md)
- [oos-alignment-refactor-proposal.md](../reviews/oos-alignment-refactor-proposal.md)
- [eloquent-model-boundary-audit-2026-03-17.md](../reviews/eloquent-model-boundary-audit-2026-03-17.md)
- [admin-livewire-responsibility-review-2026-03-17.md](../reviews/admin-livewire-responsibility-review-2026-03-17.md)
- [json-metadata-inventory-2026-03-17.md](../reviews/json-metadata-inventory-2026-03-17.md)
- [api-webhook-boundary-review-2026-03-18.md](../reviews/api-webhook-boundary-review-2026-03-18.md)
- [read-path-performance-review-2026-03-18.md](../reviews/read-path-performance-review-2026-03-18.md)
- [test-suite-architecture-review-2026-03-18.md](../reviews/test-suite-architecture-review-2026-03-18.md)
- [database-model-integrity-review-2026-03-18.md](../reviews/database-model-integrity-review-2026-03-18.md)
- [artisan-command-architecture-review-2026-03-18.md](../reviews/artisan-command-architecture-review-2026-03-18.md)
- [external-integration-boundary-review-2026-03-18.md](../reviews/external-integration-boundary-review-2026-03-18.md)
- [public-read-side-architecture-review-2026-03-18.md](../reviews/public-read-side-architecture-review-2026-03-18.md)
- [bootstrap-registration-side-effect-map-2026-03-18.md](../reviews/bootstrap-registration-side-effect-map-2026-03-18.md)
- [authorization-exposure-boundary-review-2026-03-18.md](../reviews/authorization-exposure-boundary-review-2026-03-18.md)
- [ci-deployment-environment-review-2026-03-18.md](../reviews/ci-deployment-environment-review-2026-03-18.md)
- [frontend-view-architecture-review-2026-03-18.md](../reviews/frontend-view-architecture-review-2026-03-18.md)
- [laravel-livewire-idioms-review-2026-03-18.md](../reviews/laravel-livewire-idioms-review-2026-03-18.md)

## Status Legend

- `Completed`: implemented and verified
- `Open`: not started
- `Ready after prerequisite`: do not start until the named dependencies are complete
- `Defer`: valid work, but intentionally behind higher-risk items

## Delivery Rules

- Keep PRs vertically complete and small enough to review safely.
- Preserve current behavior unless the item explicitly calls out a bug fix.
- Do not combine schema changes with large orchestration rewrites in the same PR.
- When extracting logic from Livewire, commands, providers, or models, keep thin integration tests in place until equivalent action/service coverage exists.
- For migrations and constraints, use the sequence `audit -> backfill -> compatibility reader/writer -> constraint/index`.
- Prefer existing Laravel conventions and current folder structure.

## Global Quality Gates (every implementation item)

1. `vendor/bin/sail artisan test --compact <focused-tests>`
2. `vendor/bin/sail composer phpstan`
3. `vendor/bin/sail bin pint --dirty`

## Recommended Execution Order

1. `TD-001`
2. `TD-001A`
3. `TD-001B`
4. `TD-002`
5. `TD-002A`
6. `TD-002B`
7. `TD-002C`
8. `TD-004`
9. `TD-003`
10. `TD-003A`
11. `TD-003B`
12. `TD-004A`
13. `TD-004B`
14. `TD-005`
15. `TD-005A`
16. `TD-005B`
17. `TD-005C`
18. `TD-005D`
19. `TD-006`
20. `TD-007`
21. `TD-008`
22. `TD-009`
23. `TD-010`
24. `TD-011`
25. `TD-012`
26. `TD-013`
27. `TD-013A`
28. `TD-014`
29. `TD-014A`
30. `TD-015`
31. `TD-016`
32. `TD-016A`
33. `TD-012A`
34. `TD-012B`
35. `TD-017A`
36. `TD-017B`
37. `TD-017C`
38. `TD-018`
39. `TD-019`
40. `TD-020`
41. `TD-021`
42. `TD-024`
43. `TD-025`
44. `TD-026`
45. `TD-027`
46. `TD-030`
47. `TD-031`
48. `TD-032`
49. `TD-033`
50. `TD-022`
51. `TD-023`
52. `TD-028`
53. `TD-029`
54. `TD-039`
55. `TD-034`
56. `TD-035`
57. `TD-036`
58. `TD-036A`
59. `TD-037`
60. `TD-038`
61. `TD-040`
62. `TD-041`
63. `TD-042`
64. `TD-043`
65. `TD-044`

## Quick Wins

### TD-001 - Add characterization safety net for media, OoS, and church-service glue
- Status: `Completed`
- Priority: P0
- Impact: Very high
- Risk: Low
- Effort: M
- Dependencies: None
- Scope:
  - media-processing terminal-state behavior
  - status/progress mapping for real current-step values
  - queue `catch()` persistence boundaries
  - reconciliation trigger history
  - missing `OosAlignmentService` contract coverage
- Tests needed first:
  - This item is the test-first prerequisite.
- Safest implementation order:
  1. Add a feature test for notification-plus-cleanup end-state preservation.
  2. Extend progress/status tests for unmapped real states such as `initiated_from_livestream`, restart states, and notification states.
  3. Add direct coverage for chain and batch `catch()` persistence on failed runs.
  4. Add direct unit coverage for `ChurchServiceReconciliationDispatcher`.
  5. Extend OoS tests for empty-result linking, rerun baseline restore, non-OoS flag survival, `linked_song_canonical_key` fallback, late-arrival no-op behavior, and structural lookahead branches.
  6. If a characterization test exposes a current bug, land the fix in the immediately dependent backlog item rather than committing a permanently failing test.
- Acceptance criteria:
  1. The current hot-path behaviors are explicitly covered before refactors begin.
  2. Later backlog items can point to named tests instead of relying on indirect coverage.
  3. No production behavior is intentionally changed in this item.
- Reference reviews:
  - `media-processing-architecture-review-2026-03-17.md`
  - `media-processing-church-service-observability-audit-2026-03-17.md`
  - `oos-alignment-service-review.md`
  - `admin-livewire-responsibility-review-2026-03-17.md`

### TD-001B - Preserve notification failure outcome across cleanup completion
- Status: `Completed`
- Priority: P1
- Impact: Medium
- Risk: Low
- Effort: S
- Dependencies: `TD-001`
- Scope:
  - notification error propagation inside `SendCompletionNotification`
  - final persisted `MediaProcessingLog` state when notification delivery fails
  - cleanup completion semantics when a non-fatal notification error already occurred
- Tests needed first:
  - reuse the `TD-001` completion characterization and convert it from behavior freeze to intended outcome assertions
- Safest implementation order:
  1. Decide the intended persisted end state for notification delivery failure: preserve `notification_failed`, preserve the error message, or move the error into dedicated metadata while still completing the run.
  2. Update `SendCompletionNotification` so transport failures are not silently normalized to `notification_sent`.
  3. Adjust `CleanupTemporaryFiles` or the completion model helpers only if needed so cleanup does not erase the chosen notification failure signal.
  4. Keep notification failure non-fatal to the overall media pipeline unless a broader product decision changes that contract.
- Acceptance criteria:
  1. Notification delivery failures are visible in the final persisted processing outcome instead of being silently masked.
  2. Cleanup still runs and temporary files are removed.
  3. The final behavior is covered by explicit characterization tests rather than inferred from isolated job tests.
- Reference reviews:
  - `media-processing-church-service-observability-audit-2026-03-17.md`

### TD-001A - Build reusable scenario builders and restore real middleware defaults
- Status: `Completed`
- Priority: P0
- Impact: High
- Risk: Low
- Effort: M
- Dependencies: None
- Scope:
  - `tests/Support` scenario builders for processing logs, church services, service sections, uploads, and authenticated admins
  - opt-in throttle bypass instead of global default bypass
  - lighter shared helpers for disk fakes, Livewire uploads, and reusable assertions
  - discoverable suite grouping for browser and performance checks
- Tests needed first:
  - This item is itself the test-architecture prerequisite.
- Safest implementation order:
  1. Add helper APIs without moving any existing suites yet.
  2. Move the global `ThrottleRequests` bypass out of the default `TestCase` into a narrow trait or base class.
  3. Convert the highest-value suites that later items depend on: Mailgun/API boundaries, church-service admin flows, and processing orchestration tests.
  4. Add or document explicit PHPUnit groups/suites for `Browser` and `Performance` so they are no longer invisible.
- Acceptance criteria:
  1. New boundary and refactor work can reuse concise builders instead of hand-building large graphs inline.
  2. Security and rate-limit tests run against the real middleware stack by default.
  3. Browser/performance coverage has an explicit execution path instead of relying on tribal knowledge.
- Reference reviews:
  - `test-suite-architecture-review-2026-03-18.md`
  - `api-webhook-boundary-review-2026-03-18.md`

### TD-002 - Close admin Livewire authorization gaps
- Status: `Completed`
- Priority: P0
- Impact: High
- Risk: Low
- Effort: S
- Dependencies: `TD-001` recommended
- Scope:
  - `ListCalendarEvents`
  - `EditCalendarEvent`
  - `MediaUploadField`
  - `UploadChurchService::save()`
  - `SubmitEmailText::submit()`
- Tests needed first:
  - non-admin rejection coverage for the calendar components
  - dedicated coverage for `MediaUploadField`
  - mutating-action re-authorization coverage for upload and submit flows
- Safest implementation order:
  1. Add component-level admin authorization to the calendar components.
  2. Add explicit authorization inside `MediaUploadField`.
  3. Re-authorize inside write actions, not only in `mount()`, for `UploadChurchService` and `SubmitEmailText`.
  4. Keep the route-level protection in place; this item is about internal write-surface hardening.
- Acceptance criteria:
  1. Non-admin users are rejected by the affected components and mutating actions.
  2. Existing admin flows keep working.
  3. The new tests fail if the components regress back to route-only protection.
- Reference reviews:
  - `admin-livewire-responsibility-review-2026-03-17.md`

### TD-002A - Harden webhook trust boundaries and duplicate race handling
- Status: `Completed`
- Priority: P0
- Impact: Very high
- Risk: Medium
- Effort: M
- Dependencies: `TD-001A`
- Scope:
  - Mailgun replay protection and outer throttling
  - duplicate-key race handling for Mailgun and OpenLP imports
  - invalid webhook probes entering the outer rate-limit path
- Tests needed first:
  - Mailgun replay test that reuses a valid `(timestamp, token, signature)` with a different `Message-Id`
  - duplicate-key race characterization for Mailgun and OpenLP paths
  - rate-limit regression proving invalid webhook probes hit the outer throttle path
- Safest implementation order:
  1. Add the missing request/feature tests around replay, duplicate races, and invalid-probe throttling.
  2. Add replay protection and an outer throttle that applies before Mailgun signature verification, while keeping the recipient-aware limiter for valid deliveries.
  3. Collapse duplicate-key races into safe duplicate/update results for Mailgun and OpenLP imports.
- Acceptance criteria:
  1. Replayed webhook tuples and duplicate-key races collapse into safe, predictable results.
  2. Invalid webhook probes are throttled before they can bypass the named Mailgun limiter.
  3. Mailgun and OpenLP duplicate handling no longer degrades into unhandled duplicate-key failures under concurrency.
- Reference reviews:
  - `api-webhook-boundary-review-2026-03-18.md`

### TD-002B - Restore external integration recovery semantics for transient failures
- Status: `Completed`
- Priority: P0
- Impact: Very high
- Risk: Low-Medium
- Effort: M
- Dependencies: `TD-001A`
- Scope:
  - Mailgun failed-then-redelivered recovery
  - Google Calendar full-sync deletion safety
  - transcription non-retryable OpenAI detection
- Tests needed first:
  - failed inbound email that later redelivers successfully
  - Google sync test proving one bad upstream event does not delete healthy local rows
  - transcription test proving invalid credentials / bad requests / oversize uploads do not retry
- Safest implementation order:
  1. Add the redelivery, partial-sync, and non-retryable transcription tests.
  2. Split Google sync tracking into "seen upstream" and "processed successfully" so deletion only happens after a fully safe sync pass.
  3. Allow Mailgun redelivery to recover a previously failed parse/import instead of being treated as a permanent duplicate.
  4. Fix non-retryable error detection so deterministic OpenAI failures fail fast instead of consuming queue attempts.
- Acceptance criteria:
  1. Provider redelivery can recover from transient inbound-email failures.
  2. Partial calendar sync failures no longer delete valid local events.
  3. Non-retryable transcription failures do not loop through queue retries.
- Reference reviews:
  - `external-integration-boundary-review-2026-03-18.md`
  - `artisan-command-architecture-review-2026-03-18.md`

### TD-002C - Harden upload ingestion and private media delivery
- Status: `Completed`
- Priority: P0
- Impact: Very high
- Risk: Medium
- Effort: M
- Dependencies: `TD-001A`
- Scope:
  - API upload idempotency for repeated media POSTs
  - OpenLP decompression-bomb defenses
  - private Children's Talk audio and thumbnail delivery
- Tests needed first:
  - direct asset test proving private Children's Talk media is not fetchable from `/storage/...` or equivalent origin URLs
  - API duplicate-upload test for repeated media POSTs
  - oversized or suspicious `.osz` archive test
- Safest implementation order:
  1. Add direct tests for repeated uploads, suspicious archives, and raw storage-path access to private media.
  2. Add idempotency-key or duplicate-key reuse on media upload endpoints so retries reuse or reject existing in-flight work safely.
  3. Reject zip uploads with unsafe entry counts, decompressed size, or compression ratio before parsing `.osj` content.
  4. Move Children's Talk asset URLs onto guarded or private delivery so controller/policy checks cannot be bypassed by raw storage/CDN URLs.
- Acceptance criteria:
  1. Repeated uploads collapse into safe reuse or rejection instead of duplicate background work and duplicate sermons.
  2. OpenLP uploads reject decompression-bomb style archives before expensive parsing.
  3. Private Children's Talk assets are no longer publicly retrievable from direct storage paths.
- Reference reviews:
  - `api-webhook-boundary-review-2026-03-18.md`

### TD-003 - Preserve degraded completion evidence and terminal-state correctness
- Status: `Completed`
- Priority: P0
- Impact: High
- Risk: Low-Medium
- Effort: S
- Dependencies: `TD-001`
- Scope:
  - `MediaProcessingLog::markAsCompleted()`
  - `CleanupTemporaryFiles`
  - notification-failure completion flow
  - terminal-state overwrite guards
- Tests needed first:
  - completion outcome preservation
  - cleanup after notification failure
  - cleanup after cancellation
- Safest implementation order:
  1. Preserve non-fatal degradation evidence on the processing run instead of clearing it during completion.
  2. Ensure cleanup can run without rewriting a cancelled or failed run to `completed`.
  3. Keep the operator-facing final state backward-compatible where possible, but do not lose warning context.
- Acceptance criteria:
  1. A run that completed with notification or cleanup problems retains durable warning context.
  2. Cancelled runs are not revived by late cleanup work.
  3. Existing success-path behavior remains intact.
- Reference reviews:
  - `media-processing-church-service-observability-audit-2026-03-17.md`
  - `media-processing-architecture-review-2026-03-17.md`

### TD-003A - Replace raw string status writes with guarded helpers
- Status: `Completed`
- Priority: P0
- Impact: High
- Risk: Low
- Effort: S
- Dependencies: `TD-001`, `TD-003`
- Scope:
  - `ValidateAudioFile`
  - `ValidateVideoFile`
  - `ExtractAudioFromVideo`
  - `SubmitToProcessing`
- Tests needed first:
  - cancellation-after-queued-jobs coverage for each touched job
  - terminal-state regression coverage for `SubmitToProcessing`
- Safest implementation order:
  1. Replace raw string status writes with guarded helpers or enum-backed updates.
  2. Preserve current behavior for non-terminal success paths.
  3. Ensure late jobs cannot overwrite cancelled or otherwise terminal runs by bypassing the transition guard.
- Acceptance criteria:
  1. The touched jobs no longer write raw terminal states directly.
  2. Cancelled runs cannot be rewritten to `failed`, `processing`, or `completed` through these paths.
  3. Existing non-cancelled job behavior remains unchanged.
- Reference reviews:
  - `media-processing-architecture-review-2026-03-17.md`

### TD-003B - Add shared failure handling to resume and reclassification dispatch
- Status: `Completed`
- Priority: P0
- Impact: High
- Risk: Low
- Effort: S
- Dependencies: `TD-001`
- Scope:
  - post-review resume in `ConfirmLivestreamSermonSegment`
  - admin reclassification in `ShowChurchService::reclassify()`
- Tests needed first:
  - post-review resume failure parity test
  - reclassification failure parity test
- Safest implementation order:
  1. Route both resume paths through the same catch/failure wrapper used by initial livestream ingest.
  2. Preserve existing success-path dispatch behavior.
  3. Ensure failure marking, cleanup, and notification semantics match the initial ingest flow.
- Acceptance criteria:
  1. Resume and reclassification failures persist the same final state shape as initial ingest failures.
  2. No direct dispatch path remains that bypasses shared failure handling for the targeted resume flows.
  3. Success-path behavior is unchanged.
- Reference reviews:
  - `media-processing-architecture-review-2026-03-17.md`

### TD-004 - Fix live timeline reader drift and step-vocabulary gaps
- Status: `Completed`
- Priority: P0
- Impact: High
- Risk: Low
- Effort: S-M
- Dependencies: `TD-001`
- Scope:
  - `ProcessingStep` / `StandardProcessingResponse` progress mapping
  - `ServiceRecordTimeline` presentation-inference read path
  - low-risk metadata compatibility fallbacks
- Tests needed first:
  - explicit progress assertions for unmapped but real states
  - timeline regression for `oos_alignment.presentation_inference`
  - compatibility coverage if historical fallback keys are retained
- Safest implementation order:
  1. Fix the `presentation_inference` reader drift by using the nested `oos_alignment` path, with a temporary fallback if needed.
  2. Map all currently-used step values to correct progress output.
  3. Remove or clearly document low-value shadow reads such as duplicated `source_segment_ids` handling.
  4. Clarify the intended source of `linked_song_canonical_key` and remove accidental ambiguity if possible without schema change.
- Acceptance criteria:
  1. Progress/status output reflects real pipeline states.
  2. Timeline rendering reads the same shape that current writers persist and no longer drops live `presentation_inference` data.
  3. No low-risk metadata drift remains undocumented.
- Reference reviews:
  - `media-processing-church-service-observability-audit-2026-03-17.md`
  - `json-metadata-inventory-2026-03-17.md`

### TD-004A - Fix public read-side correctness, visibility, and canonical invariants
- Status: `Completed`
- Priority: P1
- Impact: High
- Risk: Low-Medium
- Effort: M
- Dependencies: `TD-001A`
- Scope:
  - unknown one-segment area routes returning `200`
  - meeting/page visibility and page ownership invariants
  - default public-page filtering for navigation and cards
  - one canonical sermon URL across HTML, sitemap, and RSS
- Tests needed first:
  - feature test that an unknown top-level segment returns `404`
  - feature test that a meeting linked to a private/admin-only page is not publicly readable
  - regression that page-less meetings either render intentionally or are rejected early
  - regression that public navigation/cards never expose admin-only pages
  - regression that canonical tags, sitemap entries, and feed links agree on the same sermon URL
- Safest implementation order:
  1. Add the route, meeting-visibility, navigation, and canonical-url tests.
  2. Make public visibility the default repository/query behavior instead of a caller-by-caller opt-in filter.
  3. Enforce the meeting/page invariant and reuse the same visibility and markdown rules as `PageController`.
  4. Pick one canonical sermon route shape, redirect legacy alternatives, and use that shape consistently across HTML, sitemap, and feed generation.
- Acceptance criteria:
  1. Broken top-level URLs return `404` instead of synthetic landing pages.
  2. Public meeting and page routes obey one visibility policy.
  3. Public navigation and canonical metadata reflect one source of truth.
- Reference reviews:
  - `public-read-side-architecture-review-2026-03-18.md`

### TD-004B - Remove duplicate AI work and the easiest read-path waste
- Status: `Completed`
- Priority: P1
- Impact: High
- Risk: Low-Medium
- Effort: M
- Dependencies: `TD-001A`
- Scope:
  - duplicate sermon transcript analysis in `UpdateSermonRecord`
  - `ProcessingReviewList` row over-fetching
  - sermon-page reading-reference lookup that currently loads the full section graph
- Tests needed first:
  - regression proving the finalizer consumes stored `ai_analysis` instead of re-running analysis
  - queue-list regression proving only the needed columns are read
  - sermon-page regression for reading-reference output
- Safest implementation order:
  1. Add the direct regression tests for finalization, queue-list reads, and reading-reference output.
  2. Collapse `UpdateSermonRecord` into a lightweight finalizer that consumes existing `ai_analysis`.
  3. Narrow `ProcessingReviewList` to an explicit `select(...)` with only the rendered columns.
  4. Replace the sermon-page "load full section graph then filter in PHP" path with a targeted query or a single persisted field if that can be done without schema change.
- Acceptance criteria:
  1. Non-livestream transcript analysis happens once per run, not twice.
  2. The lightweight admin review queue no longer loads oversized log rows by default.
  3. Sermon detail pages do not hydrate the full service-section graph just to render one Bible reading reference.
- Reference reviews:
  - `read-path-performance-review-2026-03-18.md`

### TD-018 - Fix self-contradicting CI bootstrap safety guard
- Status: `Completed`
- Priority: P0
- Impact: High
- Risk: Low
- Effort: S
- Dependencies: None
- Scope:
  - `scripts/check-bootstrap-safety.sh` false-positive detection for `config()` inside `withMiddleware()`
  - `bootstrap/app.php` use of `config('app.trusted_proxies')` inside the middleware block
  - Decide whether to fix the script heuristic or move the config call out of the guarded block
- Tests needed first:
  - None (CI tooling fix)
- Safest implementation order:
  1. Confirm locally that the script currently exits 1 against the tree.
  2. Either move the `config(...)` call outside the bootstrap block (preferred) or update the script heuristic if the call is intentionally placed there.
  3. Verify the script exits 0 cleanly before committing.
- Acceptance criteria:
  1. The CI pipeline no longer fails at the bootstrap safety check against the current codebase.
  2. The script still catches genuinely unsafe bootstrap patterns.
- Reference reviews:
  - `ci-deployment-environment-review-2026-03-18.md`

### TD-019 - Pin production deploys to smoke-tested image SHA
- Status: `Completed`
- Priority: P1
- Impact: High
- Risk: Low
- Effort: S
- Dependencies: None
- Scope:
  - `.github/workflows/deploy.yml` build, smoke, and deploy jobs
  - `docker-compose.prod.yml` app image tag variable
- Tests needed first:
  - None (CI workflow change)
- Safest implementation order:
  1. Pass the SHA-tagged image from the build job output to both the smoke and deploy jobs via a workflow output or artifact.
  2. Update the deploy job to reference the specific SHA rather than `${IMAGE_TAG:-latest}`.
  3. Verify the full workflow path: build → smoke (same SHA) → deploy (same SHA).
- Acceptance criteria:
  1. The deploy job always references the same image SHA that the smoke job validated.
  2. A re-run of the deploy job in isolation cannot silently pick up a different `latest` image.
- Reference reviews:
  - `ci-deployment-environment-review-2026-03-18.md`

### TD-020 - Add scheduler to production runtime and a post-deploy operational smoke path
- Status: `Completed`
- Priority: P1
- Impact: High
- Risk: Low-Medium
- Effort: M
- Dependencies: None
- Scope:
  - `docker-compose.prod.yml` scheduler sidecar or cron service
  - `docker/production/supervisord.conf` or dedicated scheduler container
  - Post-deploy smoke command covering web, DB, queue, writable storage, and scheduler presence
  - Documentation of the supported health check path, retiring phantom `/health` and `health:check` references
- Tests needed first:
  - None (infrastructure and deploy change)
- Safest implementation order:
  1. Add an explicit scheduler process to the production runtime using `php artisan schedule:work` or a cron entry in a dedicated container.
  2. Verify scheduled commands run without out-of-band setup: `calendar:sync`, `media:cleanup-temp-files`, `media:cleanup-unpublished-section-assets`, `scripture:refresh-passages`.
  3. Add a post-deploy smoke command or script that asserts web up, DB reachable, queue alive, storage writable, and scheduler running.
  4. Update deployment docs and remove all references to phantom endpoints (`/health`, `health:check`, `/api/sermons/processing/health`).
- Acceptance criteria:
  1. The production runtime includes an explicit, discoverable scheduler process.
  2. A single post-deploy command gives operators a pass/fail signal for all five operational concerns.
  3. Deployment docs no longer reference health endpoints or commands that do not exist.
- Reference reviews:
  - `ci-deployment-environment-review-2026-03-18.md`

### TD-021 - Align deployment documentation to actual production queue and runtime
- Status: `Completed`
- Priority: P1
- Impact: Medium
- Risk: Low
- Effort: S
- Dependencies: None
- Scope:
  - `docs/deployment/automated-sermon-processing.md` database-queue worker instructions and git branch reference
  - `docs/deployment/media-processing.md` database-queue worker instructions
  - `docs/deployment/thumbnail-generation-deployment.md` database-queue worker instruction
  - `scripts/server-setup.sh` SSH key instruction error (public key stated where private key is required)
- Tests needed first:
  - None (documentation change)
- Safest implementation order:
  1. Replace all `QUEUE_CONNECTION=database` worker instructions with `queue:work redis` to match the real production worker.
  2. Correct the `scripts/server-setup.sh` instruction: the deploy action needs the private key, not the public key.
  3. Update `docs/deployment/automated-sermon-processing.md` to reference `master` rather than `main`.
  4. Add a single canonical "production stack" summary pointing to Docker Compose + Redis as the authoritative path.
- Acceptance criteria:
  1. An operator following the docs alone cannot ship `QUEUE_CONNECTION=database` to a Redis-backed production worker.
  2. The SSH key instruction is correct.
  3. There is one unambiguous "this is how production works" reference.
- Reference reviews:
  - `ci-deployment-environment-review-2026-03-18.md`

### TD-022 - Remove dead thumbnail queue configuration and correct its queue coverage test
- Status: `Completed`
- Priority: P2
- Impact: Medium
- Risk: Low
- Effort: S
- Dependencies: None
- Scope:
  - `config/thumbnail-generation.php` dead `queue` and `connection` fields
  - `tests/Unit/Config/QueueWorkerCoverageTest.php` thumbnail worker assertion
  - `app/Jobs/GenerateThumbnail.php` (no queue or connection set)
- Tests needed first:
  - None (the test itself needs correcting)
- Safest implementation order:
  1. Decide whether to wire `THUMBNAIL_QUEUE_CONNECTION` and `THUMBNAIL_QUEUE_NAME` into real dispatch behavior on `GenerateThumbnail`, or to remove the dead config and correct the test.
  2. If removing: delete the dead config fields and update the queue coverage test to assert only the queues that workers actually serve.
  3. If wiring: set `$connection` and `$queue` on `GenerateThumbnail`, verify it lands on the thumbnail worker, and keep the config.
- Acceptance criteria:
  1. The queue coverage test validates real dispatch behavior rather than a phantom queue.
  2. Operators cannot waste time debugging a `thumbnails` worker that never receives jobs.
- Reference reviews:
  - `ci-deployment-environment-review-2026-03-18.md`

### TD-023 - Restore podcast feed cache invalidation on sermon changes
- Status: `Completed`
- Priority: P2
- Impact: Medium
- Risk: Low
- Effort: S
- Dependencies: None
- Scope:
  - `app/Observers/SitemapCacheObserver.php` podcast feed cache skip
  - The test that caused the skip to be added
  - Cache invalidation coverage for podcast feed
- Tests needed first:
  - Feed freshness test verifying the podcast feed is invalidated after sermon create/update/delete
- Safest implementation order:
  1. Identify the test that originally broke when podcast feed invalidation was active and fix the test root cause instead of skipping invalidation.
  2. Re-enable feed cache invalidation in `SitemapCacheObserver`.
  3. Add a regression test covering feed invalidation after relevant model changes.
- Acceptance criteria:
  1. Podcast feed cache is invalidated when underlying sermon data changes.
  2. The fix does not break the previously problematic test.
  3. Invalidation behavior is covered by an explicit test rather than the comment "to prevent test failures".
- Reference reviews:
  - `ci-deployment-environment-review-2026-03-18.md`

### TD-024 - Move unpublished section-publication media off the public disk
- Status: `Completed`
- Priority: P0
- Impact: Very high
- Risk: Medium
- Effort: M
- Dependencies: `TD-001A`
- Scope:
  - `app/Jobs/PrepareSectionPublicationCandidates.php` candidate extraction write path
  - `config/media-processing.php` `sermon_disk` default
  - `app/Livewire/Admin/ChurchServices/ServiceReviewDashboard.php` candidate URL generation
  - Private or signed delivery for candidate media before `APPROVED` + published state
- Tests needed first:
  - Direct test proving extracted but not-yet-published section media is not publicly retrievable from a raw storage URL
  - Regression that the admin review dashboard can still serve candidate media to authenticated admins via a guarded path
- Safest implementation order:
  1. Add direct tests for the current public-leakage path and the intended private/signed delivery path.
  2. Write extracted candidates to a private disk or path prefix instead of the public sermon disk.
  3. Update the admin review dashboard to generate signed URLs or guarded delivery routes for candidate media.
  4. Ensure the publication job moves or copies media to the public path only on `APPROVED` → publish transition.
- Acceptance criteria:
  1. Pre-publication candidate media cannot be retrieved via a direct storage URL without authentication.
  2. Admin review dashboard users can still preview candidate media through a guarded path.
  3. The publication workflow correctly delivers finalized media to the public path.
- Reference reviews:
  - `authorization-exposure-boundary-review-2026-03-18.md`

### TD-025 - Normalize admin verification requirement across all admin mutation paths
- Status: `Completed`
- Priority: P1
- Impact: High
- Risk: Low
- Effort: S
- Dependencies: None
- Scope:
  - `routes/web.php` legacy meeting mutation routes (`meetings.index`, `.update`, `.destroy`) missing `verified`
  - `routes/web.php` legacy sermon delete endpoints missing `verified`
  - `app/Policies/MeetingPolicy.php` and `app/Policies/SermonPolicy.php` verification gap
  - `app/Http/Middleware/EnsureUserIsAdmin.php` verification gap
  - `app/Livewire/Traits/WithAdminAuthorization.php` verification gap
- Tests needed first:
  - Test that an unverified admin cannot reach legacy meeting and sermon mutation routes
- Safest implementation order:
  1. Add the `verified` middleware to the legacy meeting and sermon mutation route groups that currently only require `auth + admin`.
  2. Update `MeetingPolicy` and `SermonPolicy` to include the verified check where they previously only checked `is_admin`.
  3. Decide whether to update `EnsureUserIsAdmin` and `WithAdminAuthorization` globally or rely on route-level `verified` for mutation coverage.
- Acceptance criteria:
  1. Unverified admins cannot reach any admin mutation path.
  2. The visible admin gates and the enforceable backend rules are consistent.
  3. Existing verified-admin flows are unchanged.
- Reference reviews:
  - `authorization-exposure-boundary-review-2026-03-18.md`
  - `laravel-livewire-idioms-review-2026-03-18.md`

### TD-026 - Align calendar side routes and sitemap visibility with the confirmed-only exposure policy
- Status: `Completed`
- Priority: P1
- Impact: Medium
- Risk: Low
- Effort: S
- Dependencies: `TD-001A`
- Scope:
  - `app/Services/CalendarService::getEventsForMeeting()` status filter
  - `app/Services/CalendarService::getUncategorizedEvents()` status filter
  - `app/Services/SitemapService` members-page exclusion (currently only `admin=yes` is excluded)
  - `app/Services/SitemapService` meeting page-link visibility check
- Tests needed first:
  - Test that `/meetings/{meeting}/events` does not return non-confirmed events
  - Test that `/calendar/uncategorized` does not return non-confirmed events
  - Test that `sitemap.xml` does not include `PageArea::MEMBERS` URLs
- Safest implementation order:
  1. Apply the same `confirmed()` status scope to `getEventsForMeeting()` and `getUncategorizedEvents()`.
  2. Exclude `PageArea::MEMBERS` pages from sitemap generation, not only `admin=yes` pages.
  3. Add a page-level visibility check for meetings in the sitemap where meeting-page links are present.
- Acceptance criteria:
  1. All public calendar routes use consistent status filtering.
  2. The sitemap excludes members-area URLs.
  3. Meeting sitemap entries do not expose linked protected page content.
- Reference reviews:
  - `authorization-exposure-boundary-review-2026-03-18.md`

### TD-027 - Bring sermon API and metadata exposure in line with show_summary and show_points
- Status: `Completed`
- Priority: P1
- Impact: Medium
- Risk: Low
- Effort: S
- Dependencies: `TD-001A`
- Scope:
  - `app/Models/Sermon::getMetaDescriptionAttribute()` ignores `show_summary`
  - `app/Http/Resources/SermonResource.php` always returns `points` regardless of `show_points`
  - `app/Http/Resources/SermonResource.php` exposes `thumbnail_metadata` internal storage paths (`plain_thumbnail_path`, `overlay_thumbnail_path`)
  - Meta tag and JSON-LD rendering in `resources/views/sermons/sermon.blade.php`
- Tests needed first:
  - Test that a sermon with `show_summary=false` does not include the summary in meta description or JSON-LD
  - Test that the public sermon API omits `points` when `show_points=false`
  - Test that `thumbnail_metadata` does not include internal storage paths in the public API response
- Safest implementation order:
  1. Respect `show_summary` in `getMetaDescriptionAttribute()` and remove summary text from meta tags when the flag is false.
  2. Conditionally include `points` in `SermonResource` based on `show_points`.
  3. Remove `plain_thumbnail_path` and `overlay_thumbnail_path` from the public API response; expose only the public CDN URL.
- Acceptance criteria:
  1. Hidden summary text is not recoverable from page metadata, JSON-LD, or API responses.
  2. Hidden outline points are not available through the public API.
  3. Internal storage paths are not exposed in public API responses.
- Reference reviews:
  - `authorization-exposure-boundary-review-2026-03-18.md`

### TD-028 - Expose page visibility admin flag in the page editor UI
- Status: `Completed`
- Priority: P2
- Impact: Medium
- Risk: Low
- Effort: S
- Dependencies: None
- Scope:
  - `app/Livewire/Admin/Pages/PageForm.php` form fields
  - `app/Livewire/Admin/Pages/ListPages.php` listing columns
  - `resources/views/livewire/admin/pages/page-form.blade.php`
  - `resources/views/livewire/admin/pages/list-pages.blade.php`
- Tests needed first:
  - Test that the admin page form loads and saves the `admin` column
  - Test that the listing shows which pages are admin-only
- Safest implementation order:
  1. Add the `admin` boolean field to `PageForm` and its view.
  2. Add an `admin` column to the `ListPages` view so administrators can see current visibility at a glance.
  3. Update any related validation that currently omits the `admin` field.
- Acceptance criteria:
  1. Administrators can see which pages are admin-only from the listing.
  2. Administrators can set or change the `admin` restriction through the page editor.
  3. The `admin` column is saved and retrieved correctly.
- Reference reviews:
  - `authorization-exposure-boundary-review-2026-03-18.md`

### TD-029 - Remove WithAdminAuthorization duplication from routed admin components
- Status: `Completed`
- Priority: P2
- Impact: Medium
- Risk: Low
- Effort: S
- Dependencies: `TD-002`
- Scope:
  - `app/Livewire/Traits/WithAdminAuthorization.php`
  - All routed full-page admin Livewire components that call `authorizeAdmin()` in `mount()` and mutating actions
  - Trivial `manage-*` gates in `app/Providers/AuthServiceProvider.php`
- Tests needed first:
  - Regression confirming non-admin users cannot access routed admin components after removing the trait checks
- Safest implementation order:
  1. Confirm that the `/admin/*` route group middleware (`auth + verified + admin`) fully covers the authorization requirement for all routed components.
  2. Remove `WithAdminAuthorization` calls from full-page routed admin components; keep the trait only where a component is not guaranteed to be inside the admin route group.
  3. Collapse trivial `manage-*` gates that are purely "is admin" and rely on route middleware instead.
- Acceptance criteria:
  1. No full-page routed admin component carries redundant `authorizeAdmin()` calls when route middleware already enforces the same rules.
  2. The trivial `manage-*` gates are replaced by a consistent route/policy-level check.
  3. No regression in admin access control.
- Reference reviews:
  - `laravel-livewire-idioms-review-2026-03-18.md`
  - `authorization-exposure-boundary-review-2026-03-18.md`

## Medium Refactors

### TD-005 - Add minimal queue and church-service observability hardening
- Status: `Completed`
- Priority: P1
- Impact: Medium-High
- Risk: Low-Medium
- Effort: M
- Dependencies: `TD-001`, `TD-003`, `TD-004`
- Scope:
  - failed-run correlation metadata
  - inbound-email failure context
  - direct service-level tests for review-state and reconciliation glue
  - provider-level test coverage for media-processing service wiring
- Tests needed first:
  - queue failure-payload assertions
  - direct `ChurchServiceCanonicalUpdateService` coverage
  - direct `ChurchServiceReviewStateService` coverage
  - direct `ChurchServiceReconciliationDispatcher` coverage if not completed in `TD-001`
  - direct `MediaProcessingServiceProvider` coverage
- Safest implementation order:
  1. Persist a minimal failure payload on media runs and inbound emails: job class, attempt number, queue name, exception class, and failed timestamp.
  2. Keep the payload intentionally small; do not turn this item into full tracing.
  3. Add direct unit tests for the review/canonical-conflict/reconciliation/provider glue that currently relies on indirect coverage.
  4. Do not activate the dedicated `sermon-processing` channel in this item.
- Acceptance criteria:
  1. Failed records carry enough correlation data for basic diagnosis without log-grepping.
  2. Church-service review, reconciliation, and media-processing provider glue have direct test coverage.
  3. Existing log-viewer behavior is unchanged.
- Reference reviews:
  - `media-processing-church-service-observability-audit-2026-03-17.md`
  - `architectural-review-2026-03-17.md`
  - `bootstrap-registration-side-effect-map-2026-03-18.md`

### TD-005A - Widen cancellation coverage across remaining jobs
- Status: `Completed`
- Priority: P1
- Impact: High
- Risk: Low-Medium
- Effort: M
- Dependencies: `TD-003`, `TD-003A`
- Scope:
  - `ValidateAudioFile`
  - `ValidateVideoFile`
  - `ExtractAudioFromVideo`
  - `AnalyzeSegments`
  - `GenerateThumbnail`
  - `SendCompletionNotification`
  - `CleanupTemporaryFiles`
  - `SubmitToProcessing`
  - `PrepareSectionPublicationCandidates`
- Tests needed first:
  - per-job cancellation characterization tests for the touched jobs
  - coverage proving cancelled runs are not revived by late queued work
- Safest implementation order:
  1. Add cancellation guards to every remaining unguarded job in scope.
  2. Reuse existing shared helpers where possible instead of inventing a second cancellation mechanism.
  3. Keep the changes narrow; this item is about job-level compliance with current cancellation semantics, not a new orchestrator.
- Acceptance criteria:
  1. Cancelled runs are not moved back into active or completed states by the touched jobs.
  2. Each touched job has direct cancellation regression coverage.
  3. The item can land before the orchestrator work without creating a second orchestration path.
- Reference reviews:
  - `media-processing-architecture-review-2026-03-17.md`

### TD-005B - Normalize retry ownership, backoff, and outbound-attempt accounting
- Status: `Completed`
- Priority: P1
- Impact: High
- Risk: Medium
- Effort: M-L
- Dependencies: `TD-001A`, `TD-002B`, `TD-005`
- Scope:
  - queued OoS parsing retry/backoff and exception classification
  - transcription retry ownership between job layer and service layer
  - api.bible budget accounting against actual outbound attempts
  - speaker-identification retry policy for transient failures versus true no-match outcomes
- Tests needed first:
  - OoS parsing tests for 429s, transport failures, and delayed retry behavior
  - transcription tests proving there is only one retry owner and no blocking sleep loop in the worker
  - api.bible tests proving retry attempts are counted against budget
  - speaker-identification tests for timeout/storage/process failures versus deterministic empty matches
- Safest implementation order:
  1. Add explicit contract tests around provider-facing error shapes and retry timing.
  2. Put backoff and retry classification at one layer per integration boundary instead of keeping nested loops.
  3. Move api.bible accounting to the actual request-attempt boundary.
  4. Decide and encode the speaker-identification policy: delayed retry for transient infrastructure failures, best-effort/no-retry for deterministic no-match outcomes.
- Acceptance criteria:
  1. Each integration has one clear retry policy owner.
  2. Budget accounting reflects real provider traffic, not optimistic logical calls.
  3. Queue workers are not tied up by avoidable in-process sleep loops.
- Reference reviews:
  - `external-integration-boundary-review-2026-03-18.md`

### TD-005C - Make scheduled commands and destructive operators safe by default
- Status: `Completed`
- Priority: P1
- Impact: High
- Risk: Medium
- Effort: M-L
- Dependencies: `TD-001A`, `TD-002B`
- Scope:
  - `media:cleanup-temp-files`
  - `media:cleanup-unpublished-section-assets`
  - `images:convert-to-webp`
  - scheduler overlap protection and environment gating for destructive/networked jobs
  - operator-facing exit codes and summary output on partial failure
- Tests needed first:
  - cleanup test proving files referenced by active/manual-review logs are not deleted
  - cleanup test proving expired approved sections transition state instead of silently dangling
  - command test proving `--skip-convert` does not rewrite references without successful conversions
  - scheduler/command tests for overlap protection and non-zero partial-failure exit codes
- Safest implementation order:
  1. Add focused command/service tests around the current destructive edge cases.
  2. Make temp-file cleanup consult application state before deleting files and protect scheduled runs with `withoutOverlapping()`.
  3. Turn unpublished-asset cleanup into an explicit state transition with audit metadata instead of just a file deletion.
  4. Scope JPG-to-WebP reference updates strictly to successfully converted files, then standardize dry-run, exit-code, and summary behavior across destructive commands.
- Acceptance criteria:
  1. Scheduled cleanup jobs no longer destroy files still needed for retries or manual review.
  2. Destructive commands leave explicit state/audit trails instead of creating dangling rows.
  3. Operators can detect partial failure from exit codes and summary output instead of console prose alone.
- Reference reviews:
  - `artisan-command-architecture-review-2026-03-18.md`
  - `bootstrap-registration-side-effect-map-2026-03-18.md`

### TD-005D - Make registration side effects explicit and after-commit safe
- Status: `Completed`
- Priority: P2
- Impact: Medium
- Risk: Low-Medium
- Effort: M
- Dependencies: `TD-005`, `TD-005C`
- Scope:
  - move `ChurchServiceCanonicalListChanged` listener registration out of `AppServiceProvider`
  - consolidate media/AI bindings into one domain provider
  - narrow and after-commit-align `SitemapCacheObserver`
  - local-only registration for `phpinfo`
  - removal of redundant provider noise (`path.public`, self-publishing config, empty test provider if still unused)
- Tests needed first:
  - direct provider/registration coverage for event listeners and bindings
  - cache invalidation tests around create/update/delete after commit
  - route registration test ensuring `phpinfo` is not exposed outside local
- Safest implementation order:
  1. Add direct tests for the listener, binding, and cache invalidation contracts that must stay stable.
  2. Relocate event registration and media bindings into explicit domain providers without changing runtime behavior.
  3. Make public-cache invalidation after-commit and narrower before deleting redundant bootstrap code.
  4. Remove low-value provider noise only after the new registrations are covered and live.
- Acceptance criteria:
  1. Hidden write and cache invalidation paths are discoverable from dedicated providers/tests instead of app-shell bootstrap only.
  2. Public cache invalidation timing matches committed data.
  3. No environment-sensitive diagnostic route remains globally registered by default.
- Reference reviews:
  - `bootstrap-registration-side-effect-map-2026-03-18.md`

### TD-006 - Replace `ManagesSectionPublication` with explicit publication actions
- Status: `Completed`
- Priority: P1
- Impact: High
- Risk: Medium
- Effort: M
- Dependencies: `TD-001`, `TD-002`, `TD-005C`
- Scope:
  - `app/Livewire/Admin/ChurchServices/Concerns/ManagesSectionPublication.php`
  - publication paths used by `ListSectionPublications` and `ServiceReviewDashboard`
  - explicit expiry/unpublish transitions where operator cleanup now mutates the same state
- Tests needed first:
  - keep `AdminSectionPublicationQueueTest`
  - add direct action tests for approval, signature checks, publish dispatch behavior, and expiry/unpublish transitions
- Safest implementation order:
  1. Extract the publication state changes, storage checks, signature writes, audit writes, and dispatch behavior into explicit action classes.
  2. Keep Livewire responsible only for validation, authorization, and UI feedback.
  3. Route expiry or cleanup-driven publication changes through the same action/state layer instead of side-stepping it from commands.
- Acceptance criteria:
  1. The trait is deleted or reduced to a thin adapter with no real domain behavior.
  2. Both consuming Livewire surfaces delegate to the same action layer.
  3. Publication behavior remains covered by both integration and action-level tests.
- Reference reviews:
  - `admin-livewire-responsibility-review-2026-03-17.md`
  - `eloquent-model-boundary-audit-2026-03-17.md`
  - `artisan-command-architecture-review-2026-03-18.md`

### TD-007 - Extract `ManageChurchService` write and prefill workflows
- Status: `Completed`
- Priority: P1
- Impact: High
- Risk: Medium
- Effort: L
- Dependencies: `TD-001`, `TD-002`, `TD-006`
- Scope:
  - `ManageChurchService::save()`
  - inbound-email prefill and section-type inference logic in the component
- Tests needed first:
  - keep the existing `AdminChurchServiceTest` integration coverage
  - add direct tests for `SaveChurchServiceFromAdmin`
  - add direct tests for `PrefillChurchServiceFromInboundEmail`
- Safest implementation order:
  1. Extract a write use case for the transactional save flow.
  2. Extract a read/prefill collaborator for inbound-email-derived defaults and parsed-item shaping.
  3. Keep the component as a thin adapter for form state, authorization, redirects, and error presentation.
  4. Preserve the current import metadata and inbound-email handoff behavior.
- Acceptance criteria:
  1. The component no longer owns transactions, sync orchestration, or prefill parsing logic.
  2. Behavior remains stable for create, edit, and inbound-email handoff flows.
  3. Equivalent action-level tests exist before trimming component assertions.
- Reference reviews:
  - `admin-livewire-responsibility-review-2026-03-17.md`

### TD-008 - Extract `ReviewInboundEmails` workflows and preview factory
- Status: `Completed`
- Priority: P1
- Impact: High
- Risk: Medium
- Effort: M-L
- Dependencies: `TD-001`, `TD-002`, `TD-002B`, `TD-007`
- Scope:
  - approve
  - reparse
  - edit-and-approve handoff
  - reject
  - preview DTO/read-model assembly
  - explicit recovery path for failed inbound-email processing
- Tests needed first:
  - keep `AdminInboundEmailReviewTest`
  - add direct action tests for approve, reparse, reject, and failed-message recovery
  - add focused tests for preview assembly and sanitization
- Safest implementation order:
  1. Extract separate command-style actions for each write workflow.
  2. Extract a preview factory or read model for metadata shaping and HTML sanitization.
  3. Preserve redirect behavior into service create/show flows.
  4. Make recovery from failed inbound parsing/import an explicit action path rather than an incidental duplicate suppression side effect.
- Acceptance criteria:
  1. The component no longer owns write orchestration or preview assembly.
  2. Each workflow has a directly testable action boundary.
  3. Existing admin review behavior, including recovery flows, is preserved.
- Reference reviews:
  - `admin-livewire-responsibility-review-2026-03-17.md`
  - `external-integration-boundary-review-2026-03-18.md`

### TD-009 - Split `ServiceReviewDashboard` into a query model plus focused actions
- Status: `Completed`
- Priority: P1
- Impact: High
- Risk: Medium-High
- Effort: L (re-estimate after `TD-006` through `TD-008`)
- Dependencies: `TD-001`, `TD-006`, `TD-007`, `TD-008`
- Scope:
  - dashboard read model
  - section save/update actions
  - mark-service-reviewed action
  - batch publication approval action
  - split queue reads from per-field edit state
  - cached `preacherOptions()` and reduced `wire:model.live` churn
- Tests needed first:
  - keep `AdminServiceReviewDashboardTest`
  - add direct tests for the extracted actions
  - add read-model coverage for grouping, summary, and readiness logic
  - add UI regression coverage for `defer` / `blur` field behavior where live typing is not required
- Safest implementation order:
  1. Move the assembled dashboard query into a dedicated read model or presenter.
  2. Split the queue list from edit-form state so typing does not rebuild the whole queue on every render.
  3. Move mutating behavior into focused actions shared with other admin surfaces where sensible.
  4. Cache option lists and only escalate to a persisted projection table if the read model is still structurally too heavy after the split.
- Acceptance criteria:
  1. The component stops acting like an application service.
  2. The dashboard render path no longer rebuilds the whole queue on every small field update.
  3. Write-side behavior is delegated and directly testable.
- Reference reviews:
  - `admin-livewire-responsibility-review-2026-03-17.md`
  - `architectural-review-2026-03-17.md`
  - `read-path-performance-review-2026-03-18.md`

### TD-010 - Stage 1 `OosAlignmentService` extraction: small collaborators first
- Status: `Completed`
- Priority: P1
- Impact: High
- Risk: Medium
- Effort: L
- Dependencies: `TD-001`
- Scope:
  - `PresentationItemClassifier`
  - `ChurchServiceReviewSynchronizer`
  - `SectionAlignmentBaselineRestorer`
- Tests needed first:
  - empty-result linking
  - baseline restore and confidence normalization
  - parent review-state sync behavior
  - preservation of non-OoS review flags
- Safest implementation order:
  1. Extract the most self-contained collaborator first: presentation-item classification.
  2. Extract parent review synchronization without changing `needs_review` semantics.
  3. Extract baseline restore and confidence persistence without changing rerun behavior.
  4. Keep the transaction boundary and in-memory `ServiceSection` mutation model unchanged.
- Acceptance criteria:
  1. The extracted collaborators are individually testable.
  2. The current transaction boundary and metadata contract remain intact.
  3. Reruns still restore `base_*` state before applying new OoS decisions.
- Reference reviews:
  - `oos-alignment-refactor-proposal.md`
  - `oos-alignment-service-review.md`

### TD-011 - Stage 2 `OosAlignmentService` extraction: aligners and thin coordinator
- Status: `Completed`
- Priority: P1
- Impact: High
- Risk: Medium-High
- Effort: XL
- Dependencies: `TD-001`, `TD-010`
- Scope:
  - `SongSectionAligner`
  - `StructuralSectionAligner`
  - trigger evaluator split/rename
  - thin coordinator
- Tests needed first:
  - greedy song-match preservation
  - inferred-vs-confirmed song side effects
  - structural lookahead branches
  - late-arrival trigger behavior
- Safest implementation order:
  1. Extract song alignment without changing `similar_text()` scoring or greedy ordering.
  2. Extract structural alignment without changing authoritative reclassification rules.
  3. Split or rename the trigger evaluator so its mutation behavior is explicit.
  4. Finish by introducing a thin coordinator once the heavy logic is already externalized.
- Acceptance criteria:
  1. The main service stops acting as the full workflow coordinator.
  2. Reporting and review surfaces still receive the same persisted metadata keys and meanings.
  3. Existing alignment algorithms are preserved, not silently improved.
- Reference reviews:
  - `oos-alignment-refactor-proposal.md`
  - `oos-alignment-service-review.md`

### TD-012 - Extract processing-run transitions out of `MediaProcessingLog` before orchestrator work
- Status: `Completed`
- Priority: P1
- Impact: High
- Risk: Medium
- Effort: M
- Dependencies: `TD-003`, `TD-003A`, `TD-005A`
- Scope:
  - `MediaProcessingLog` workflow transitions
  - step-level status helpers where touched
- Tests needed first:
  - direct service/action tests for processing transitions
  - regression tests for terminal-state guards
- Safest implementation order:
  1. Extract command-style services or actions for processing-log transitions and step-level status updates.
  2. Migrate the current callers to the new transition boundary without changing orchestration policy yet.
  3. Keep `MediaProcessingLog` focused on persistence primitives, scopes, and relationships.
  4. Land this before `TD-015` so the orchestrator is built on explicit transition actions rather than model-owned commands.
- Acceptance criteria:
  1. Processing-run mutation is no longer globally callable from `MediaProcessingLog` model methods.
  2. Transition behavior remains explicit and directly testable.
  3. `TD-015` can depend on an explicit transition boundary instead of cementing model-owned workflow commands.
- Reference reviews:
  - `eloquent-model-boundary-audit-2026-03-17.md`
  - `architectural-review-2026-03-17.md`
  - `external-integration-boundary-review-2026-03-18.md`

### TD-012A - Move publication and meeting transition commands out of models
- Status: `Completed`
- Priority: P2
- Impact: Medium-High
- Risk: Medium
- Effort: M-L
- Dependencies: `TD-006`, `TD-011`, `TD-012`; `TD-015` recommended
- Scope:
  - `ServiceSection` publication-transition commands
  - `Meeting`-level infrastructure calls where models currently resolve integration services directly
- Tests needed first:
  - publication transition tests
  - direct coverage for meeting update flows that currently leak Google infrastructure concerns
- Safest implementation order:
  1. Extract command-style services or actions for section publication transitions.
  2. Stop resolving concrete integration services from model methods where that leakage currently exists.
  3. Keep models focused on persistence primitives, scopes, and relationships.
  4. Migrate callers incrementally; do not mass-edit unrelated call sites in one PR.
- Acceptance criteria:
  1. Publication and meeting-related workflow mutation is no longer globally callable from model methods.
  2. Transition behavior remains explicit and testable.
  3. Models become noticeably smaller and less stateful without blocking the orchestrator work that `TD-012` already unlocked.
- Reference reviews:
  - `eloquent-model-boundary-audit-2026-03-17.md`
  - `architectural-review-2026-03-17.md`
  - `external-integration-boundary-review-2026-03-18.md`

### TD-012B - Consolidate console/operator workflows around reusable services
- Status: `Completed`
- Priority: P2
- Impact: High
- Risk: Medium
- Effort: L
- Dependencies: `TD-005C`, `TD-012A`
- Scope:
  - retire or wrap `livestream:create-sermon` through the real application flow
  - shared service/action for scripture enrichment and refresh
  - shared storage-maintenance operator service for migration/verify commands
  - file-level rerunnable meeting photo migration
  - `preachers:cutover` reuse of `PreacherResolutionService`
- Tests needed first:
  - dedicated command tests for each high-risk wrapper command
  - direct service tests for the new shared scripture, storage, meeting-photo, and preacher-cutover operators
  - regression that `livestream:create-sermon` can no longer report success after a partial downstream failure
- Safest implementation order:
  1. Extract the reusable operator services from the safest/highest-reuse boundaries first: scripture enrichment and storage maintenance.
  2. Rewire commands to become thin wrappers over those services with shared dry-run and summary behavior.
  3. Make meeting-photo migration rerunnable at the file level instead of the meeting level.
  4. Retire or reduce `livestream:create-sermon` so it can no longer bypass the real sermon creation/review path.
- Acceptance criteria:
  1. High-risk commands become thin wrappers over shared application services instead of alternate business workflows.
  2. Migration/import commands are safely rerunnable after partial success.
  3. Operator behavior is consistent across CLI and app entrypoints.
- Reference reviews:
  - `artisan-command-architecture-review-2026-03-18.md`

### TD-013 - Replace route-aware layout resolution and move hidden read-side I/O out of models
- Status: `Completed`
- Priority: P2
- Impact: Medium
- Risk: Medium
- Effort: L
- Dependencies: `TD-004A`
- Scope:
  - `Sermon::getTranscriptAttribute()`
  - `Sermon` URL/exposure accessors
  - storage-backed `Page` and `Meeting` accessors
  - `LayoutPageComposer` route-segment resolution and route-aware presenter selection
  - make `layouts/page` a passive layout fed by controller-provided data
  - presenter/composer re-queries that ignore controller-resolved data
  - model-level policy/query leakage where touched
- Tests needed first:
  - transcript read-path regression coverage
  - presenter/read-model coverage for public page, meeting, and sermon detail reads
  - visibility/query regression for sitemap/public exposure behavior
- Safest implementation order:
  1. Move transcript retrieval into an application service.
  2. Replace `LayoutPageComposer` route-shape resolution with controller-provided layout data so `layouts/page` becomes passive instead of controller-like.
  3. Move URL/exposure shaping into presenters or read models driven by controller intent instead of route-shape inference.
  4. Move storage-backed page and meeting image/read helpers into dedicated presenter/read-side collaborators.
  5. Remove redundant presenter re-queries only after controller-owned layout data is covered by tests.
- Acceptance criteria:
  1. Reading common model attributes no longer triggers hidden storage/container I/O.
  2. Public read-path behavior remains stable while becoming easier to reason about.
  3. Controllers and explicit read models, not route-aware layouts, `LayoutPageComposer`, or model accessors, own public presentation state.
- Reference reviews:
  - `eloquent-model-boundary-audit-2026-03-17.md`
  - `public-read-side-architecture-review-2026-03-18.md`
  - `read-path-performance-review-2026-03-18.md`

### TD-013A - Add cached public read models and move hot asset delivery off PHP
- Status: `Completed`
- Priority: P2
- Impact: High
- Risk: Medium
- Effort: L
- Dependencies: `TD-004A`, `TD-004B`, `TD-013`
- Scope:
  - cached page payloads with rendered HTML and resolved hero/image URLs
  - cached meeting DTOs for public pages
  - podcast feed manifest or persisted enclosure metadata
  - versioned public asset URLs for thumbnails/audio so PHP no longer hashes files on hot paths
- Tests needed first:
  - cache invalidation regressions for pages, meetings, sermons, and preachers
  - feed regression for fresh invalidation and stable enclosure metadata
  - thumbnail/audio delivery tests for versioned URL generation or redirect behavior
- Safest implementation order:
  1. Introduce explicit page/meeting/feed read models without changing public URLs yet.
  2. Persist or cache resolved media metadata so the read models stop probing storage on every request.
  3. Restore feed invalidation on relevant model changes and stop relying on intentional cache staleness.
  4. Move hot thumbnail/audio delivery to versioned storage/CDN URLs or cheap redirects instead of per-request hashing in PHP.
- Acceptance criteria:
  1. Public page, meeting, and feed reads use explicit cached payloads instead of hidden render-time I/O.
  2. Feed freshness and asset delivery no longer trade correctness for performance.
  3. High-traffic media reads do not require PHP to compute hashes or proxy immutable files.
- Reference reviews:
  - `read-path-performance-review-2026-03-18.md`
  - `public-read-side-architecture-review-2026-03-18.md`

### TD-014 - Wrap stable JSON shapes in typed casts or value objects
- Status: `Completed`
- Priority: P2
- Impact: Medium
- Risk: Low-Medium
- Effort: M
- Dependencies: `TD-010`, `TD-011`, `TD-013`
- Scope:
  - `thumbnail_metadata`
  - `import_metadata` typed portions
  - section classification and OoS alignment metadata
  - children's-talk speaker metadata
  - manual-review metadata
  - ID3 metadata
  - song clusters
- Tests needed first:
  - serialization round-trip coverage
  - compatibility-reader coverage for mixed historical data
- Safest implementation order:
  1. Reuse `App\Data\SermonAnalysis` instead of introducing duplicate analysis types.
  2. Add typed casts/value objects only for stable shapes; do not normalize to columns in this item.
  3. Preserve raw JSON for telemetry-heavy or obviously transient blobs.
- Acceptance criteria:
  1. The highest-value stable JSON blobs are no longer handled as ad hoc arrays everywhere.
  2. Existing persisted shapes remain readable during transition.
  3. No schema migration is required for this item.
- Follow-up note:
  - If the typed-wrapper pattern expands further, add a small successor item to introduce `with*()`-style builders for readonly JSON value objects and extract shared cast plumbing so callers do not have to unwrap-mutate-rewrap arrays everywhere.
- Reference reviews:
  - `json-metadata-inventory-2026-03-17.md`

### TD-014A - Add targeted schema guardrails with safe rollout patterns
- Status: `Completed`
- Priority: P2
- Impact: Medium-High
- Risk: Medium
- Effort: M-L
- Dependencies: `TD-001A`, `TD-014`
- Scope:
  - speaker profile/sample uniqueness and supporting indexes
  - `media_processing_logs.sermon_id` foreign key semantics
  - `livestream_segments.segment_index` widening
  - closed-set constraints for already-stable lifecycle columns such as `service_sections.status` and `service_sections.publication_status`
  - missing composite indexes for heavy current query paths
  - redundant legacy/redundant index and table cleanup after compatibility checks
- Tests needed first:
  - migration/backfill safety tests for duplicate or orphan audit queries
  - contract tests around `firstOrCreate` / `updateOrCreate` assumptions for speaker identity
  - regression tests for processing-log retention after sermon deletion
  - state-transition regression coverage for the constrained `service_sections` lifecycle columns
- Safest implementation order:
  1. Add audit queries and one-off scripts/tests that show whether production-like data is clean enough for each constraint.
  2. Land high-confidence additive indexes, integer widening, and closed-set constraints for already-stable lifecycle columns before destructive cleanup.
  3. Add speaker uniqueness constraints and change `media_processing_logs.sermon_id` to `SET NULL` only after compatibility readers and data audits are in place.
  4. Remove redundant tables/indexes only after nothing still reads them.
- Acceptance criteria:
  1. The schema protects the invariants the code already assumes for speaker identity and processing-log retention.
  2. The highest-value heavy query paths have explicit index support.
  3. Stable lifecycle columns no longer depend on free-text drift for their closed-set values.
  4. Rollout uses `audit -> backfill -> constraint` instead of destructive cleanup first.
- Reference reviews:
  - `database-model-integrity-review-2026-03-18.md`

### TD-030 - Decide and enforce consistent access model for members-area and Children's Corner
- Status: `Completed`
- Priority: P1
- Impact: High
- Risk: Medium
- Effort: M
- Dependencies: None
- Scope:
  - `app/Livewire/Auth/Register.php` self-registration and immediate login behavior
  - `routes/web.php` members dashboard, songs, and catch-all page auth requirements
  - `app/Services/SermonExposurePolicy.php` Children's Corner auth-only check
  - `app/Http/Middleware/EnsureChildrensCornerAccess.php`
  - Decision: is "members only" meant to be "has a user account" or "trusted church member"?
- Decision:
  - "Members only" means "has a user account".
  - Self-registration remains open and signs the user in immediately.
  - Email verification is not part of the members-area or Children's Corner access boundary at this stage.
  - A stricter "verified members" layer can be added later if there is a real product need.
- Tests needed first:
  - Characterization test documenting current behavior: self-registered, unverified user can reach members dashboard, songs, and Children's Corner
  - Regression test preventing unauthorized access once the chosen policy is implemented
- Safest implementation order:
  1. Agree on the intended access model: current permissive model vs email-verification or invite/approval requirement.
  2. If tightening: add email verification requirement for members-area routes and Children's Corner access.
  3. If the current model is intentional: document it explicitly and close the finding.
  4. Consider whether self-registration should remain open or require an invite/approval step for church membership.
- Acceptance criteria:
  1. The effective access boundary for members-area content is explicitly decided and documented.
  2. The code enforces that decision consistently.
  3. A self-registered unverified user can only access content that is intentionally public.
- Reference reviews:
  - `authorization-exposure-boundary-review-2026-03-18.md`

### TD-031 - Convert admin list/filter components from $queryString to Livewire 3 #[Url]
- Status: `Completed`
- Priority: P1
- Impact: Medium
- Risk: Low
- Effort: M
- Dependencies: None
- Scope:
  - `app/Livewire/Admin/Sermons/ListSermons.php`
  - `app/Livewire/Admin/ChurchServices/ManageChurchService.php`
  - `ListPages`, `ListMeetings`, `ListUsers`, `ListChurchServices`, `ListSongs`, `ReviewInboundEmails`, `ListSectionPublications`, `ListCalendarEvents`, `ListPreachers`
- Tests needed first:
  - Regression that URL filter state is preserved correctly after conversion for each component
- Safest implementation order:
  1. Convert read-only list components first (lowest risk): `ListSermons`, `ListPages`, `ListMeetings`, `ListUsers`, `ListPreachers`, `ListCalendarEvents`.
  2. Use `#[Url]` with `except`, aliases, and boolean defaults on filter/search properties.
  3. Remove the parallel `$queryString` arrays.
  4. Convert the remaining write-capable components (`ManageChurchService`, `ReviewInboundEmails`) after the read-only set is stable.
- Acceptance criteria:
  1. All admin list/filter components use `#[Url]` instead of legacy `$queryString` arrays.
  2. Filter URL state behavior is unchanged from the user's perspective.
  3. `$queryString` arrays are removed from all converted components.
- Reference reviews:
  - `laravel-livewire-idioms-review-2026-03-18.md`

### TD-032 - Replace PageForm and MeetingForm traits with Livewire\Form objects
- Status: `Completed`
- Priority: P1
- Impact: Medium
- Risk: Low
- Effort: M
- Dependencies: None
- Scope:
  - `app/Livewire/Admin/Pages/PageForm.php`
  - `app/Livewire/Admin/Meetings/MeetingForm.php`
  - `app/Livewire/Admin/Pages/CreatePage.php`, `EditPage.php`
  - `app/Livewire/Admin/Meetings/CreateMeeting.php`, `EditMeeting.php`
- Tests needed first:
  - Regression that page and meeting create/edit flows still work after conversion
- Safest implementation order:
  1. Create `PageFormData` extending `Livewire\Form` with the rules, normalization helpers, and derived properties currently in the trait.
  2. Update `CreatePage` and `EditPage` to use the form object; remove manual model-to-form mapping.
  3. Repeat for `MeetingFormData` and the meeting components.
  4. Delete the old trait-based form implementations once all consumers are converted.
- Acceptance criteria:
  1. Page and meeting admin forms use `Livewire\Form` objects.
  2. Host components contain only `mount()`, `save()`, and page-level render concerns.
  3. The old trait-based form implementations are deleted.
- Reference reviews:
  - `laravel-livewire-idioms-review-2026-03-18.md`

### TD-033 - Extract media-processing status query service from ProcessingLogsViewer
- Status: `Completed`
- Priority: P1
- Impact: High
- Risk: Medium
- Effort: M
- Dependencies: `TD-012`
- Scope:
  - `app/Livewire/ProcessingLogsViewer.php` controller-resolution in `findControllerForProcessingId()`
  - `app/Http/Controllers/Api/MediaController.php` former `ProcessingStatusContract`-style status implementation
  - New `GetMediaProcessingStatus` service or equivalent
- Tests needed first:
  - Test for the new status query service covering processing log retrieval
  - Regression that the Livewire viewer still receives the same data after refactor
- Safest implementation order:
  1. Extract a dedicated `GetMediaProcessingStatus` service from the controller's status-query implementation.
  2. Update `MediaController` to share that service for JSON responses, directly or through `UnifiedMediaProcessor`.
  3. Update `ProcessingLogsViewer` to inject the new service directly rather than resolving `MediaController` from the container.
  4. Remove `ProcessingStatusContract` from the controller layer once the shared service exists.
- Acceptance criteria:
  1. `ProcessingLogsViewer` no longer resolves an HTTP controller as an application service.
  2. `MediaController` and `ProcessingLogsViewer` share one status query service.
  3. The Livewire viewer behavior is unchanged.
- Reference reviews:
  - `laravel-livewire-idioms-review-2026-03-18.md`

### TD-034 - Build admin shell component family
- Status: `Completed`
- Priority: P2
- Impact: High
- Risk: Low
- Effort: M
- Dependencies: None
- Scope:
  - `x-admin.page` — consistent page title/action row wrapper
  - `x-admin.list-shell` — standard header, filter bar, empty state, and table container
  - `x-admin.form-shell` — standard form card wrapper
  - `x-admin.filter-bar` — reusable filter/search row
  - `x-admin.empty-state` — standardized empty state
  - Shared Livewire trait or computed helper for `hasFilters` and common URL query-string patterns
- Tests needed first:
  - None (presentational; existing admin screen tests serve as regression)
- Safest implementation order:
  1. Build the new shell components without modifying any existing views yet.
  2. Test them in isolation in the component gallery.
  3. Adopt them in one admin list page and one admin form page as a pilot.
  4. Roll out to remaining admin screens incrementally.
- Acceptance criteria:
  1. The shell components exist and are used by at least one admin list page and one admin form page.
  2. Admin list pages converge on a consistent structure without manual duplication.
  3. The component gallery documents all new shell components.
- Reference reviews:
  - `frontend-view-architecture-review-2026-03-18.md`
  - `laravel-livewire-idioms-review-2026-03-18.md`

### TD-035 - Refactor shared primitive components for design-system neutrality
- Status: `Completed`
- Priority: P2
- Impact: High
- Risk: Low-Medium
- Effort: M-L
- Dependencies: `TD-034`
- Scope:
  - `x-card`: split into a neutral surface card and a prose/content card (currently wraps all content in `.prose`)
  - `x-button`: make internal/external/download navigation explicit rather than inferring from `#`
  - `x-toggle`: add a Livewire-optional `x-switch` or make toggle usable in plain Blade forms
  - New `x-alert` / notice component
  - New `x-badge` / status-pill component
  - New `x-checkbox` primitive
  - New icon-button primitive
- Tests needed first:
  - None (presentational; regression covered by existing views and component gallery)
- Safest implementation order:
  1. Add the missing primitives (`x-alert`, `x-badge`, `x-checkbox`, icon button) before touching existing ones.
  2. Split `x-card` by adding a neutral variant, keeping prose behavior available via a prop rather than as the default.
  3. Update `x-button` to use a navigation prop or explicit method rather than inferring from `#`.
  4. Update the component gallery to document all primitives.
- Acceptance criteria:
  1. `x-card` can be used as a neutral surface without inheriting `.prose` typography by default.
  2. `x-button` does not silently assume `wire:navigate` for all non-`#` URLs.
  3. `x-alert`, `x-badge`, `x-checkbox`, and icon-button primitives exist and are documented.
- Reference reviews:
  - `frontend-view-architecture-review-2026-03-18.md`

### TD-036 - Migrate legacy admin Blade screens onto the modern admin architecture
- Status: `Completed`
- Priority: P2
- Impact: Medium
- Risk: Medium
- Effort: L
- Dependencies: `TD-034`, `TD-035`
- Scope:
  - `resources/views/meetings/index.blade.php`
  - `resources/views/admin/calendar/uncategorized.blade.php`
  - `resources/views/admin/calendar/patterns.blade.php`
  - `resources/views/sermons/edit.blade.php`
  - `x-admin-table` and `x-admin-actions` legacy components
  - Migrate from `layouts/page` + raw alerts/selects to `layouts/admin` + modern admin shells
- Tests needed first:
  - Route/feature regressions for each legacy screen before migration
- Safest implementation order:
  1. Add route regression tests for each legacy admin screen.
  2. Migrate screens one at a time: replace `layouts/page` with `layouts/admin`, replace raw HTML patterns with shell components.
  3. Retire `x-admin-table` once all consumers are migrated.
  4. Retire `x-admin-actions` after confirming no remaining usages.
- Acceptance criteria:
  1. All admin-like screens use the modern admin shell architecture.
  2. `x-admin-table` and `x-admin-actions` are retired.
  3. Maintainers no longer need to remember two different admin frontend systems.
- Reference reviews:
  - `frontend-view-architecture-review-2026-03-18.md`

### TD-036A - Retire legacy sermon admin edit/update controller path after Livewire cutover
- Status: `Completed`
- Priority: P2
- Impact: Medium
- Risk: Low-Medium
- Effort: S
- Dependencies: `TD-017C`, `TD-036`
- Scope:
  - `app/Http/Controllers/SermonAdminController.php` legacy `edit`, `update`, and `updateWithDate` actions
  - legacy sermon edit routes in `routes/web.php`
  - retired compatibility references to `resources/views/sermons/edit.blade.php` once the Livewire editor is the only supported edit surface
  - route and mutation coverage proving sermon editing resolves through `App\Livewire\Admin\Sermons\EditSermon`
- Tests needed first:
  - route regression confirming every surviving sermon edit URL redirects to or resolves through the Livewire editor
  - mutation regression confirming no controller-only sermon edit write path remains
- Safest implementation order:
  1. Freeze the current redirect behavior for legacy sermon edit URLs.
  2. Confirm any remaining shared sermon identity/write compatibility logic lives behind reusable services rather than controller-only code.
  3. Delete the retired controller edit/update actions and remove their unused route entries and view references.
  4. Keep only the still-active non-edit responsibilities on `SermonAdminController` until those flows are migrated separately.
- Acceptance criteria:
  1. There is one supported sermon edit UI and write path.
  2. `SermonAdminController` no longer owns legacy sermon edit/update behavior.
  3. Legacy sermon edit URLs either redirect to the Livewire editor or are removed with explicit compatibility coverage.
- Reference reviews:
  - `frontend-view-architecture-review-2026-03-18.md`
  - `laravel-livewire-idioms-review-2026-03-18.md`

### TD-037 - Resolve upload and log-viewer frontend state ownership
- Status: `Completed`
- Priority: P2
- Impact: Medium
- Risk: Medium
- Effort: M
- Dependencies: `TD-033`
- Scope:
  - `resources/views/livewire/media-upload/form.blade.php` Alpine/Livewire/JS state split
  - `app/Livewire/MediaUpload/Status.php` event dispatch boundary
  - `resources/views/livewire/media-upload/progress.blade.php` parent Alpine dependency
  - `app/Livewire/ProcessingLogsViewer.php` duplicate Livewire/Alpine state (`expanded`, `autoRefresh`)
  - `resources/views/livewire/processing-logs-viewer.blade.php` global `window.logsViewer`
- Tests needed first:
  - Characterization tests for upload cancel behavior and log-viewer expand/refresh state
- Safest implementation order:
  1. Pick one owner per concern in the upload flow: Livewire for lifecycle data, small Alpine enhancements for drag/drop only.
  2. Remove the cross-component Alpine dependency (`progress` calling a parent method it does not own).
  3. For the logs viewer: prefer fully Livewire-driven state for `expanded` and `autoRefresh`, removing the duplicate Alpine entanglement.
  4. Remove the `window.logsViewer` global after the state is properly encapsulated.
- Acceptance criteria:
  1. Upload lifecycle state has one owner; Alpine is used only for pure client-side enhancements.
  2. `expanded` and `autoRefresh` are owned by one layer in the logs viewer.
  3. No cross-component implicit state dependencies remain.
- Reference reviews:
  - `frontend-view-architecture-review-2026-03-18.md`
  - `laravel-livewire-idioms-review-2026-03-18.md`

### TD-038 - Centralise accessibility primitives and fix known gaps
- Status: `Completed`
- Priority: P2
- Impact: Medium
- Risk: Low
- Effort: M
- Dependencies: `TD-035`
- Scope:
  - Auth view alert markup (login, verify-email) — replace with shared `x-alert` primitive
  - `verify-email` success notice: add live-region role (`role="status"` or `aria-live="polite"`)
  - Processing-log refresh control: replace `title`-only with `aria-label`
  - Raw blue focus styles in uncategorized calendar, meeting show, and media upload views — replace with design-system tokens
- Tests needed first:
  - None (accessibility fixes; manual verification and component gallery checks)
- Safest implementation order:
  1. Build `x-alert` first (covered by `TD-035`) and replace the duplicated auth alert markup.
  2. Add `role="status"` or `aria-live="polite"` to the verify-email success notice.
  3. Add `aria-label` to the processing-log refresh icon button.
  4. Replace raw `focus:ring-blue-500` / `focus:border-blue-300` patterns with design-system tokens across the flagged views.
- Acceptance criteria:
  1. Auth notices use the shared `x-alert` component.
  2. The verify-email success notice announces itself to screen readers.
  3. All icon-only buttons in scope have accessible labels.
  4. Design-system focus tokens replace raw blue focus styles in the flagged views.
- Reference reviews:
  - `frontend-view-architecture-review-2026-03-18.md`

### TD-039 - Sweep internal link navigation and CTA component consistency
- Status: `Completed`
- Priority: P3
- Impact: Low
- Risk: Low
- Effort: S
- Dependencies: None
- Scope:
  - `resources/views/full-width-pages/community.blade.php` missing `wire:navigate`
  - `resources/views/full-width-pages/church.blade.php` missing `wire:navigate`
  - `resources/views/meetings/events.blade.php` missing `wire:navigate`
  - `resources/views/components/calendar-event-card.blade.php` missing `wire:navigate` (three places)
  - `resources/views/livewire/auth/login.blade.php` missing `wire:navigate`
  - `resources/views/livewire/media-upload/form.blade.php` and `status.blade.php` missing `wire:navigate`
  - `resources/views/childrens-corner/index.blade.php` hand-rolled teal gradient CTA — replace with `x-public-cta`
  - `resources/views/church/songs/index.blade.php` hand-rolled teal gradient CTA — replace with `x-public-cta`
- Tests needed first:
  - None (presentational consistency fix)
- Safest implementation order:
  1. Add `wire:navigate` to all internal `<a>` links in the listed files.
  2. Replace hand-rolled teal gradient CTA wrappers with `x-public-cta`.
- Acceptance criteria:
  1. All internal navigation links use `wire:navigate`.
  2. All teal gradient CTA sections use `x-public-cta` instead of duplicated inline markup.
- Reference reviews:
  - `frontend-view-architecture-review-2026-03-18.md`

### TD-040 - Decompose overloaded shared components
- Status: `Completed`
- Priority: P3
- Impact: Medium
- Risk: Medium
- Effort: M
- Dependencies: `TD-034`, `TD-035`
- Scope:
  - `resources/views/components/breadcrumbs.blade.php` (JSON-LD generation, clipboard UI, route-dependent data all inline)
  - `resources/views/components/calendar-event-card.blade.php` (four variants with large repeated markup sections)
  - `resources/views/components/page-card.blade.php` admin control overlay mixed into public card
  - `resources/views/components/sermon-card.blade.php` admin control overlay mixed into public card
- Tests needed first:
  - Feature regression for breadcrumb rendering in public views
  - Regression for calendar event card rendering across all four variants
- Safest implementation order:
  1. Move breadcrumb data assembly and JSON-LD generation into a presenter/view model; keep the view purely presentational.
  2. Extract clipboard behavior from breadcrumbs into a small Alpine component or a dedicated partial.
  3. Break `calendar-event-card` into dedicated variant partials or a more explicit variant prop instead of one template with large repeated sections.
  4. Separate admin overlay actions from public card components into dedicated wrappers or admin-only components.
- Acceptance criteria:
  1. `breadcrumbs.blade.php` does not contain JSON-LD generation or clipboard logic inline.
  2. `calendar-event-card` is split into components or renders variants through one explicit prop without large duplicated markup sections.
  3. Public card components do not embed admin controls.
- Reference reviews:
  - `frontend-view-architecture-review-2026-03-18.md`

### TD-041 - Normalize legacy sermon route naming to Laravel conventions
- Status: `Completed`
- Priority: P3
- Impact: Low
- Risk: Low-Medium
- Effort: M
- Dependencies: `TD-004A`
- Scope:
  - `routes/web.php` sermon route group: `sermonIndex`, `allSermons`, `getPreachers`, `getSerieses`, `showSermonWithDate`
  - `app/Http/Controllers/SermonController.php` method names: `getAll()`, `getPreachers()`, `getSerieses()`, `getService()`, `showWithDate()`
  - Compatibility redirect aliases for legacy routes
- Tests needed first:
  - Route regression test for all public sermon URLs
  - Redirect regression test for all legacy aliases
- Safest implementation order:
  1. Add redirect aliases for all legacy route names before renaming anything.
  2. Rename routes to dotted, resource-style names (`sermons.index`, `sermons.show`, etc.).
  3. Rename controller methods to conventional action names.
  4. Remove redirect aliases only after all callers (views, controllers, tests) reference the new names.
- Acceptance criteria:
  1. Sermon routes follow dotted Laravel naming conventions.
  2. Controller method names use conventional action names.
  3. Existing URLs continue to work through redirect aliases during the transition.
- Reference reviews:
  - `laravel-livewire-idioms-review-2026-03-18.md`

## Major Architectural Changes

### TD-015 - Introduce one `ProcessingRunOrchestrator` and a phase registry
- Status: `Completed`
- Priority: P1
- Impact: Very high
- Risk: High
- Effort: XL
- Dependencies: `TD-001`, `TD-003`, `TD-003A`, `TD-003B`, `TD-004`, `TD-005`, `TD-005A`, `TD-012`
- Scope:
  - start
  - resume after manual review
  - retry
  - cancel
  - reclassify
  - shared dispatch and failure handling
- Tests needed first:
  - retry-from-phase continuation coverage
  - shared failure handling for manual-review resume and reclassify
  - cancellation after downstream jobs are already queued
- Safest implementation order:
  1. Introduce a single orchestration service that wraps existing jobs and `ProcessingPipelineBuilder`.
  2. Add a phase registry that becomes the source of truth for ordered phases, retryability, and progress mapping.
  3. Migrate entrypoints one by one off direct `Bus::chain(...)` dispatch.
  4. Keep the existing jobs and dispatch primitives; this item is about coordination policy, not a rewrite.
- Acceptance criteria:
  1. All media-processing entrypoints dispatch through one orchestrator.
  2. Resume, retry, cancel, and reclassify share the same catch/failure semantics.
  3. No direct orchestration forks remain in controllers, actions, or Livewire components for the targeted paths, and the orchestrator no longer depends on model-owned workflow commands.
- Reference reviews:
  - `media-processing-architecture-review-2026-03-17.md`
  - `architectural-review-2026-03-17.md`

### TD-016 - Replace string-based retry/cancel behavior with phase-cursor rebuild and explicit idempotency rules
- Status: `Completed`
- Priority: P1
- Impact: Very high
- Risk: High
- Effort: XL
- Dependencies: `TD-015`
- Scope:
  - retry semantics
  - cancellation semantics
  - idempotency/reset policy for write-heavy phases
- Tests needed first:
  - `AnalyzeSegments` rerun behavior after partial writes
  - `SubmitToProcessing` rerun and mixed-state behavior
  - `PrepareSectionPublicationCandidates` rerun behavior
  - cancellation after queued downstream jobs
- Safest implementation order:
  1. Replace raw-string retry switches with "rebuild remaining work from phase cursor" behavior.
  2. Make cancellation a first-class orchestration rule instead of best-effort job checks only.
  3. Define whether each write-heavy phase is rerunnable, needs targeted reset, or requires full restart.
  4. Keep these changes behind the orchestrator introduced in `TD-015`.
- Acceptance criteria:
  1. Retry follows the actual active pipeline definition instead of stale string switches.
  2. Cancelled runs cannot be revived by delayed jobs.
  3. Write-heavy phases have explicit rerun semantics.
- Reference reviews:
  - `media-processing-architecture-review-2026-03-17.md`
  - `api-webhook-boundary-review-2026-03-18.md`

### TD-016A - Remove legacy media-processing orchestration helpers after orchestrator cutover
- Status: `Completed`
- Priority: P2
- Impact: High
- Risk: Medium
- Effort: M
- Dependencies: `TD-015`, `TD-016`
- Scope:
  - remove `SermonJobPipelineService` once no active runtime path depends on it
  - remove dead orchestration wrappers/helpers left behind by the orchestrator migration
  - rewrite or delete tests that still model legacy orchestration concepts instead of active pipelines
  - retire compatibility-only step names only if they are no longer needed for persisted historical runs
- Tests needed first:
  - direct coverage for the surviving orchestrator entrypoints
  - replacement coverage for any legacy helper tests that are still asserting useful runtime behavior
  - regression coverage for status/progress on historical processing logs if compatibility-only step names are removed
- Safest implementation order:
  1. Audit container bindings, production call sites, and test-only references for `SermonJobPipelineService` and similar orchestration leftovers.
  2. Migrate any still-useful assertions onto `ProcessingRunOrchestrator`, `ProcessingPhaseRegistry`, and the active controller/action entrypoints.
  3. Remove the dead helper classes, stale retry/restart branches, and redundant test scaffolding.
  4. Drop compatibility-only step names only after confirming they are no longer needed for persisted rows or operator-facing history.
- Acceptance criteria:
  1. The active media-processing runtime no longer depends on legacy orchestration helper classes.
  2. Tests describe the orchestrator-era pipeline behavior rather than obsolete helper internals.
  3. Dead orchestration abstractions do not remain available to drift away from the hot path again.
- Reference reviews:
  - `media-processing-architecture-review-2026-03-17.md`
  - `architectural-review-2026-03-17.md`

### TD-017A - Promote high-value JSON/reporting state into first-class columns
- Status: `Completed`
- Priority: P1
- Impact: High
- Risk: Medium-High
- Effort: L-XL
- Dependencies: `TD-009`, `TD-011`, `TD-014`, `TD-014A`
- Scope:
  - `service_sections.metadata.oos_alignment.song_match_type`
  - `church_service_items` semantic section type
  - OoS matched/expected item IDs
- Tests needed first:
  - migration/backfill coverage
  - query/report regressions for public song usage and timeline rendering
  - compatibility-reader coverage for mixed old/new JSON-column shapes
- Safest implementation order:
  1. Add additive columns plus compatibility readers/writers for semantic section type, `song_match_type`, and matched/expected item IDs.
  2. Backfill from the current JSON and inference paths with audit output for rows that need manual attention.
  3. Migrate reporting and read paths to the new columns.
  4. Only then stop depending on the old JSON paths for those specific fields.
- Acceptance criteria:
  1. Critical reporting and read-side state is no longer hidden in brittle JSON paths.
  2. Backfills are reversible and compatibility readers exist for the transition window.
  3. The new columns support direct query/index paths instead of JSON-only reporting.
- Reference reviews:
  - `json-metadata-inventory-2026-03-17.md`
  - `database-model-integrity-review-2026-03-18.md`

### TD-017B - Formalize review/publication state and ordering invariants
- Status: `Completed`
- Priority: P1
- Impact: Very high
- Risk: High
- Effort: XL
- Dependencies: `TD-006`, `TD-009`, `TD-014A`, `TD-015`, `TD-016`, `TD-017A`
- Scope:
  - `church_services` manual-review and canonical-conflict current state
  - publication and timing invariants on `service_sections`
  - active ordering invariant for `church_service_items`
  - stable lifecycle constraints for `service_sections.status` and `service_sections.publication_status`
- Tests needed first:
  - migration/backfill coverage
  - review-state reopening and canonical-conflict regression coverage
  - constraint-oriented tests for active ordering and publication-state rules
- Safest implementation order:
  1. Audit current rows and add explicit repair/backfill steps for state and ordering violations.
  2. Normalize current review/canonical-conflict state into explicit columns/reason fields with compatibility logic during the rollout window.
  3. Add active-row ordering support and cross-column publication/timing checks only after the backfill proves the data is ready.
  4. Tighten the closed-set state constraints after the write paths already honor the normalized lifecycle.
- Acceptance criteria:
  1. Review and publication state is no longer primarily protected by service-layer repair code and free-text drift.
  2. `church_service_items` active ordering and `service_sections` publication lifecycle invariants are enforced explicitly.
  3. The code and schema describe the same state machine.
- Reference reviews:
  - `media-processing-architecture-review-2026-03-17.md`
  - `architectural-review-2026-03-17.md`
  - `database-model-integrity-review-2026-03-18.md`

### TD-017C - Resolve sermon identity authority and finish aggregate ownership boundaries
- Status: `Completed`
- Priority: P1
- Impact: High
- Risk: High
- Effort: XL
- Dependencies: `TD-013`, `TD-013A`, `TD-015`, `TD-016`, `TD-017A`, `TD-017B`
- Scope:
  - split authority on `sermons` between legacy text fields and normalized relationships
  - final ownership cleanup between runtime, review, publication, and published aggregates
  - stop duplicating data onto the wrong aggregate once the canonical owner is explicit
- Tests needed first:
  - migration/backfill coverage
  - public/admin regressions for preacher and scripture display, search, and sort behavior
  - explicit product decision captured for whether text fields remain denormalized caches or are retired
- Safest implementation order:
  1. Make the product/codebase decision explicit: are preacher/reference text fields caches or authoritative values?
  2. Add compatibility readers/writers and sync rules that reflect that decision.
  3. Migrate read and write paths to the canonical identity owner.
  4. Stop duplicating runtime/review/published data onto the wrong aggregate once the ownership boundary is stable.
- Acceptance criteria:
  1. The canonical owner of preacher and scripture identity is explicit in schema and write paths.
  2. Non-canonical representations are treated consistently as caches or removed.
  3. Aggregate ownership is visible in code structure: runtime on `MediaProcessingLog`, review summary on `ChurchService`, candidate/publication state on `ServiceSection`, and published sermon state on `Sermon`.
- Reference reviews:
  - `media-processing-architecture-review-2026-03-17.md`
  - `architectural-review-2026-03-17.md`
  - `eloquent-model-boundary-audit-2026-03-17.md`
  - `database-model-integrity-review-2026-03-18.md`

### TD-042 - Differentiate transient infrastructure failures from no-match in speaker identification
- Status: `Completed`
- Priority: P2
- Impact: Medium
- Risk: Low
- Effort: S
- Dependencies: `TD-005B`
- Scope:
  - `app/Jobs/IdentifySpeaker.php` — catch-all at line 197 swallows every `\Throwable` without differentiating transient provider failures (connection reset, timeout, storage unavailable) from deterministic no-match outcomes
  - `tries = 1` applies equally to both cases; transient failures never get a retry opportunity
  - `failed()` method is dead code in practice because exceptions are caught internally before the job can fail
- Tests needed first:
  - Speaker identification test for transient infrastructure failure (connection timeout, storage exception) — assert the failure is recorded distinctly from a no-match and the job can be retried
  - Confirm existing `SpeakerIdentificationTest` test at line 576 (exception swallowing) is updated to reflect the new policy
- Safest implementation order:
  1. Classify exception types: `\OpenAI\Exceptions\ErrorException` with retryable status codes and network-level throwables (`\Illuminate\Http\Client\ConnectionException`, `\GuzzleHttp\Exception\ConnectException`) are transient; the SDK's no-match signal and deterministic errors are not.
  2. Let transient exceptions propagate so the job queue retries them (raise `$tries` accordingly, e.g. `3`).
  3. Keep the catch-all only for deterministic no-match and unknown errors.
  4. Remove or repurpose `failed()` — it should log the permanent failure once retries are exhausted.
- Acceptance criteria:
  1. A transient infrastructure failure triggers at least one job retry rather than being silently discarded.
  2. Deterministic no-match outcomes remain best-effort and do not retry.
  3. `failed()` is reachable code and records the permanent failure signal.
- Reference reviews:
  - `external-integration-boundary-review-2026-03-18.md`

### TD-043 - Move storage and container I/O out of the remaining model methods
- Status: `Completed`
- Priority: P3
- Impact: Low-Medium
- Risk: Low
- Effort: M
- Dependencies: `TD-013`
- Scope:
  - `app/Models/Page.php` — `hasImage()` (line 259) and `getHeadingImageSrcsetAttribute()` call `Storage::disk('public')->exists()` inside a model method; hidden I/O on every attribute read
  - `app/Models/MediaProcessingLog.php` — `sourceVideoExists()` (line 382) calls `Storage::disk()` and `file_exists()`; filesystem I/O inside a model
  - `app/Models/Sermon.php` — `scopeWhereVisibleInSitemap()` (line 254) resolves `SermonExposurePolicy` from the container via `app()` inside a query scope; couples the model to application-layer policy resolution on every sitemap query
- Tests needed first:
  - Characterization tests confirming current return values for `hasImage()`, `sourceVideoExists()`, and `scopeWhereVisibleInSitemap()` before moving the I/O
- Safest implementation order:
  1. Move `Page::hasImage()` and heading srcset logic into a `PagePresenter` or the existing read-model layer from `TD-013A`.
  2. Move `MediaProcessingLog::sourceVideoExists()` into an operator service or the `ProcessingRunOrchestrator`.
  3. Replace the `app()` call in `Sermon::scopeWhereVisibleInSitemap()` with an injected or statically configured policy flag — the scope should not resolve services at query time.
- Acceptance criteria:
  1. `Page`, `Sermon`, and `MediaProcessingLog` models no longer call `Storage`, `file_exists`, or `app()` internally.
  2. Equivalent behavior is covered by tests in the presenter or service that now owns the I/O.
- Reference reviews:
  - `eloquent-model-boundary-audit-2026-03-17.md`
  - `public-read-side-architecture-review-2026-03-18.md`

### TD-044 - Fix calendar manual categorization to report Google sync failure distinctly
- Status: `Completed`
- Priority: P3
- Impact: Low
- Risk: Low
- Effort: S
- Dependencies: None
- Scope:
  - `app/Services/CalendarService::manuallyCategorizeEvent()` (line 80) — updates the local row first, then attempts a Google Calendar extended-property write; Google failure is swallowed and only logged as a warning
  - `app/Http/Controllers/Admin/CalendarAdminController::categorizeEvent()` (line 46) — always flashes a success message regardless of whether the Google write succeeded, so the operator has no visibility into sync drift
- Tests needed first:
  - `CalendarServiceTest` already has a test at line 246 for Google failure — extend it to assert that the return value or a flag distinguishes a clean sync from a local-only update
  - Add a controller test asserting the flash message differs (or includes a note) when Google sync fails
- Safest implementation order:
  1. Have `manuallyCategorizeEvent()` return a result object or add a boolean `$googleSynced` indicator alongside the `CalendarEvent`.
  2. Update `categorizeEvent()` in the controller to flash a distinct "categorized (Google sync failed — will retry on next sync)" message when the Google write did not succeed.
  3. Keep local update as the source of truth; Google sync remains best-effort and non-blocking.
- Acceptance criteria:
  1. The operator can distinguish "categorized and synced to Google" from "categorized locally, Google sync pending" from the admin UI flash message.
  2. The local categorization is never rolled back due to a Google failure.
  3. No new exception propagation — Google failure remains non-fatal.
- Reference reviews:
  - `external-integration-boundary-review-2026-03-18.md`

## Deferred For Later Reassessment

These are valid debts, but they should not pre-empt the backlog above unless operator pain or production scale changes:

- full folder rename of the test suite if PHPUnit groups and support builders already solve the main confusion
- broader Dusk/page-object investment beyond a slim smoke suite
- broad queue lifecycle tracing and universal job tags
- activation of the dedicated `sermon-processing` logging channel
- broad enum/check conversion for the remaining non-lifecycle string vocabularies before the business language is stable
