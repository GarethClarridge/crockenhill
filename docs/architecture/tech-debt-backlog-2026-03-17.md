# Tech Debt Backlog (2026-03-17)

_Last updated: 2026-03-18_

## Purpose

This backlog consolidates the March 2026 architecture and review passes into implementable, reviewable items.
Each backlog item should land as one focused PR, or one tightly related pair of PRs, with tests in place before behavior is moved.

The backlog is ordered for safety:

1. freeze the riskiest behavior with direct tests and reusable test seams
2. fix security, correctness, and operator-recovery bugs at app boundaries
3. extract write-side seams before changing orchestration or schema ownership
4. normalize schema and orchestration only after the boundaries are explicit

## Source Reviews

- [architectural-review-2026-03-17.md](./architectural-review-2026-03-17.md)
- [architecture-review-2026-03-17.md](./architecture-review-2026-03-17.md)
- [media-processing-architecture-review-2026-03-17.md](./media-processing-architecture-review-2026-03-17.md)
- [media-processing-church-service-observability-audit-2026-03-17.md](./media-processing-church-service-observability-audit-2026-03-17.md)
- [oos-alignment-service-review.md](./oos-alignment-service-review.md)
- [oos-alignment-refactor-proposal.md](./oos-alignment-refactor-proposal.md)
- [eloquent-model-boundary-audit-2026-03-17.md](./eloquent-model-boundary-audit-2026-03-17.md)
- [admin-livewire-responsibility-review-2026-03-17.md](./admin-livewire-responsibility-review-2026-03-17.md)
- [json-metadata-inventory-2026-03-17.md](./json-metadata-inventory-2026-03-17.md)
- [api-webhook-boundary-review-2026-03-18.md](../reviews/api-webhook-boundary-review-2026-03-18.md)
- [read-path-performance-review-2026-03-18.md](./read-path-performance-review-2026-03-18.md)
- [test-suite-architecture-review-2026-03-18.md](../reviews/test-suite-architecture-review-2026-03-18.md)
- [database-model-integrity-review-2026-03-18.md](./database-model-integrity-review-2026-03-18.md)
- [artisan-command-architecture-review-2026-03-18.md](./artisan-command-architecture-review-2026-03-18.md)
- [external-integration-boundary-review-2026-03-18.md](../reviews/external-integration-boundary-review-2026-03-18.md)
- [public-read-side-architecture-review-2026-03-18.md](./public-read-side-architecture-review-2026-03-18.md)
- [bootstrap-registration-side-effect-map-2026-03-18.md](./bootstrap-registration-side-effect-map-2026-03-18.md)

## Status Legend

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
3. `TD-002`
4. `TD-002A`
5. `TD-002B`
6. `TD-002C`
7. `TD-004`
8. `TD-003`
9. `TD-003A`
10. `TD-003B`
11. `TD-004A`
12. `TD-004B`
13. `TD-005`
14. `TD-005A`
15. `TD-005B`
16. `TD-005C`
17. `TD-005D`
18. `TD-006`
19. `TD-007`
20. `TD-008`
21. `TD-009`
22. `TD-010`
23. `TD-011`
24. `TD-012`
25. `TD-013`
26. `TD-013A`
27. `TD-014`
28. `TD-014A`
29. `TD-015`
30. `TD-016`
31. `TD-012A`
32. `TD-012B`
33. `TD-017A`
34. `TD-017B`
35. `TD-017C`

## Quick Wins

### TD-001 - Add characterization safety net for media, OoS, and church-service glue
- Status: `Open`
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

### TD-001A - Build reusable scenario builders and restore real middleware defaults
- Status: `Open`
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
- Status: `Ready after prerequisite`
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
- Status: `Ready after prerequisite`
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
- Status: `Ready after prerequisite`
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
- Status: `Ready after prerequisite`
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
- Status: `Ready after prerequisite`
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
- Status: `Ready after prerequisite`
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
- Status: `Ready after prerequisite`
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
- Status: `Ready after prerequisite`
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
- Status: `Ready after prerequisite`
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
- Status: `Ready after prerequisite`
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

## Medium Refactors

### TD-005 - Add minimal queue and church-service observability hardening
- Status: `Ready after prerequisite`
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
- Status: `Ready after prerequisite`
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
- Status: `Ready after prerequisite`
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
- Status: `Ready after prerequisite`
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
- Status: `Ready after prerequisite`
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
- Status: `Ready after prerequisite`
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
- Status: `Ready after prerequisite`
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
- Status: `Ready after prerequisite`
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
- Status: `Ready after prerequisite`
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
- Status: `Ready after prerequisite`
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
- Status: `Ready after prerequisite`
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
- Status: `Ready after prerequisite`
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
- Status: `Ready after prerequisite`
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
- Status: `Ready after prerequisite`
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
- Status: `Ready after prerequisite`
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
- Status: `Ready after prerequisite`
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
- Status: `Ready after prerequisite`
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
- Reference reviews:
  - `json-metadata-inventory-2026-03-17.md`

### TD-014A - Add targeted schema guardrails with safe rollout patterns
- Status: `Ready after prerequisite`
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

## Major Architectural Changes

### TD-015 - Introduce one `ProcessingRunOrchestrator` and a phase registry
- Status: `Ready after prerequisite`
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
- Status: `Ready after prerequisite`
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

### TD-017A - Promote high-value JSON/reporting state into first-class columns
- Status: `Ready after prerequisite`
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
- Status: `Ready after prerequisite`
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
- Status: `Ready after prerequisite`
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

## Deferred For Later Reassessment

These are valid debts, but they should not pre-empt the backlog above unless operator pain or production scale changes:

- full folder rename of the test suite if PHPUnit groups and support builders already solve the main confusion
- broader Dusk/page-object investment beyond a slim smoke suite
- broad queue lifecycle tracing and universal job tags
- activation of the dedicated `sermon-processing` logging channel
- broad enum/check conversion for the remaining non-lifecycle string vocabularies before the business language is stable
