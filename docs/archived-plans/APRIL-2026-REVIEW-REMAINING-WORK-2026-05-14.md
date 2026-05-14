# April 2026 Review — Remaining Work

Date: 2026-05-14

## Why this plan exists

The April 2026 review backlog at `docs/archived-plans/APRIL-2026-REVIEW-BACKLOG-2026-04-16.md` was archived with every item marked ✅ Complete and a fully-checked Definition of Done. An independent re-audit of the original 15 review documents against the current code (2026-05-14) found that the headline P1 security/correctness items genuinely landed (private asset routing, scoped+atomic upload dedup, two-tier auth boundary, narrow upload-deletion ownership, JSON promotion of `pending_structure_merge` and livestream provenance, active-row ordering uniqueness, `SermonViewPresenter` scoped lifetime). However, **seven distinct P2/P3 concerns from the original reviews are still present in the code**.

This plan captures only those remaining items, each with concrete code evidence so they can be verified before and after the fix.

## Scope

In scope: only the unfinished sub-tasks from the April 2026 reviews that I verified against `master` on 2026-05-14.

Out of scope: anything already verified complete in the archived backlog. Do not re-litigate those items here.

## Quality gates

For every item:

- `vendor/bin/sail artisan test --compact --parallel <focused paths>`
- `vendor/bin/sail composer phpstan`
- `vendor/bin/sail bin pint --dirty`

## Items

### 1. [P1] Stop dual-writing and dual-reading `processing_metadata.extracted_date` / `extracted_service`

**Source review:** `json-metadata-contracts-and-model-integrity-review-2026-04-16.md` finding [P1] (`media_processing_logs` dual identity authorities).

**Current state (2026-05-14):**

- Read side is clean: `app/Services/MediaProcessingIdentityResolver.php:20-21` reads only the `extracted_date` and `extracted_service` columns.
- Write side still duplicates: `app/Services/ProcessingInitiator.php:71-78` writes both the JSON copies and the columns on initiation.
- Sermon creation still reads the JSON copy first: `app/Services/SermonCreationService.php:511-552` falls back to `processing_metadata['extracted_date']` / `extracted_service` before column-based paths.

**Why it matters:** New code can still behave correctly with stale or null columns as long as the JSON copy happens to exist. That keeps the most important identity contract for matching processing runs to services duplicated across a typed column and a JSON payload boundary.

**Backlog:**

- Stop writing `extracted_date` / `extracted_service` into `processing_metadata` in `ProcessingInitiator` and anywhere else they are still written. Columns become the only write target.
- In `SermonCreationService`, read the resolved identity from the model columns (or pass the resolved values in via parameter) instead of reading raw `processing_metadata` keys.
- Backfill any rows that still have populated JSON copies but null columns. Verify with a dry-run query before cleanup.
- Remove the JSON keys from existing rows once the column values are confirmed authoritative.
- Add a test that asserts a freshly-created `MediaProcessingLog` has `processing_metadata` without `extracted_date` / `extracted_service` keys.

### 2. [P2] Thread processing context into `SermonAnalysisInterface`

**Source review:** `media-processing-architecture-and-observability-review-2026-04-16.md` finding [P2] (AI-analysis failures still poorly correlated to the actual processing run).

**Current state (2026-05-14):**

- `app/Services/SermonAnalysisService.php:44` literally hard-codes `$processingId = 'unknown';` for the analysis step, retry-attempt logging, and API-call logging.
- `app/Services/SermonAnalysisService.php:98` declares `performAiAnalysis(..., string $processingId = 'unknown')` with a fallback default.
- `app/Contracts/SermonAnalysisInterface.php:9` has no place to carry a processing context.
- `app/Services/MockSermonAnalysisService.php` mirrors the same shape.

**Why it matters:** The operator log viewer filters logs by the real processing ID, but every AI-stage log line is tagged `unknown`. The AI stage is one of the most failure-prone external boundaries, and it is currently the hardest part of the pipeline to diagnose from the per-run view.

**Backlog:**

- Add a processing context (at minimum the `processing_id` string) to the `SermonAnalysisInterface` method signature.
- Pass the context from `ProcessTranscriptWithAI::handle()` so the real ID flows through.
- Update both `SermonAnalysisService` and `MockSermonAnalysisService` to use the context in their `Log::info/warning/error` and `processing_id` fields. Remove all `'unknown'` defaults.
- If AI fallback fires, also persist a structured "completed with AI fallback" marker so degraded completions remain visible after cleanup (verify whether the existing `is_degraded_completion` column already covers this — recent verification suggests yes).
- Add a focused test asserting that an AI-stage log line emitted during processing of run `X` references run `X`, not `unknown`.

### 3. [P2] Make `service_sections.confidence` the only runtime confidence authority

**Source review:** `json-metadata-contracts-and-model-integrity-review-2026-04-16.md` finding [P2] (section confidence still has two authorities).

**Current state (2026-05-14):**

- The numeric `service_sections.confidence` column exists and is written.
- `app/Support/ServiceSectionConfidence.php:29` still reads `$metadata['confidence_level']` as part of the resolved confidence value.
- Read-paths that the original review called out (classifier, extraction planner, publication handler) should be re-audited to confirm they have or have not been migrated off `metadata->confidence_level`.

**Why it matters:** Selection, publication, and display can still key off a legacy or derived representation instead of the single canonical numeric measure.

**Backlog:**

- Make `ServiceSectionConfidence` read only from the numeric column.
- Audit and update any remaining `metadata->confidence_level` / `metadata['confidence_level']` reads in `app/Services/ServiceSectionClassifier.php`, `app/Services/SermonExtractionPlanResolver.php`, and `app/Services/SectionPublication/SermonPublicationHandler.php` (verify each).
- Allow `confidence_level` to remain only as derived display metadata, never as a runtime decision input. Compute it from the column on the fly if needed.
- Add an integrity test that asserts selection/publication decisions are unchanged when `metadata.confidence_level` is removed but the numeric column is present.

### 4. [P2] Choose one owner for `ProcessingLogsViewer` auto-refresh state

**Source review:** `frontend-interactions-review-2026-04-16.md` finding 2.

**Current state (2026-05-14):**

- The Alpine helper now has a correct `destroy()` teardown (verified by an earlier audit) — that part of the finding is fixed.
- The checkbox still has both `wire:model.live="autoRefresh"` and `wire:change="toggleAutoRefresh"`, and `toggleAutoRefresh()` flips the same property again. Final state depends on request ordering.

**Why it matters:** Two writers to one boolean is the exact ambiguity the original review called out. It is fragile under reordering and confusing to read.

**Backlog:**

- Pick one owner. Either keep `wire:model.live="autoRefresh"` and remove the `wire:change` + `toggleAutoRefresh()` path, or keep the explicit method and drop the `wire:model.live` binding. Recommended: keep `wire:model.live` only.
- Add a focused Livewire test that toggles the checkbox and asserts a single net state transition.

### 5. [P2] Scope media-upload events per uploader instance

**Source review:** `frontend-interactions-review-2026-04-16.md` finding 3.

**Current state (2026-05-14):**

- Livewire dispatches now carry `id: $this->formComponentId` in the payload: `app/Livewire/MediaUpload/Progress.php:29`, `app/Livewire/MediaUpload/Status.php:44`, `app/Livewire/MediaUpload/Status.php:49`. That is partial progress.
- Event names are still page-global at both ends: `app/Livewire/MediaUpload/Form.php:197,207,217` use unscoped `#[On('media-upload:cancel-upload')]` listeners, and `resources/js/livewire/media-upload-controller.js:40,74` listens on `window.addEventListener(eventName, ...)`.

**Why it matters:** A second upload instance on the same page would have both `Form` handlers fire on every dispatch. Today the screen is singleton-only, but the contract is still page-global by name.

**Backlog:**

- Decide whether the upload screen will ever be multi-instance. If not, document the singleton assumption and close this item.
- If yes, encode the instance scope in the event name (e.g. `media-upload:{componentId}:cancel-upload`) rather than relying on payload filtering, so that one component does not even see the events meant for another.
- Update the JS controller to listen for the scoped names. Update the Livewire `#[On(...)]` attributes accordingly.
- Add an integration test with two upload form instances to prove cross-talk is impossible.

### 6. [P2] Move remaining presentation, workflow, and cached-list logic off Eloquent models

**Source review:** `laravel-php-standards-and-boundary-review-2026-04-16.md` finding [P2] (model boundary leaks).

**Current state (2026-05-14):**

- `app/Models/ServiceSection.php` — `hasExtractedMedia()` still performs storage existence checks from the model.
- `app/Models/SermonProcessingStep.php` — still exposes `markAs*()` methods that persist workflow transitions directly from the entity.
- `app/Models/Preacher.php` — still resolves storage-backed profile URLs and builds cached admin/public list shapes on the model (`getForAdminList()` / `getForPublicList()`).
- `app/Models/Meeting.php` — still builds the cached admin dropdown list on the model.

**Why it matters:** None of these leaks is severe on its own, but together they mean the model layer is still carrying a mix of persistence, workflow, and read-model responsibilities. That makes the same change in a query, a job, or a Blade harder than it needs to be.

**Backlog:**

- Move `ServiceSection::hasExtractedMedia()` behind a storage-aware service.
- Move `SermonProcessingStep::markAs*()` into a transition service or action.
- Move `Preacher::getForAdminList()` / `getForPublicList()` and `Meeting::getForAdminList()` into dedicated read-model services or query objects, with their own cache keys.
- Update callers and tests. No behavior changes expected.

### 7. [P2] Decide on `WithAdminAuthorization` duplication — formalise the choice and document it

**Source review:** `standards-review-2026-04-16.md` finding [P1] (admin Livewire authorization split). Also `laravel-php-standards-and-boundary-review-2026-04-16.md` finding [P2] and `march-23-to-30-tech-debt-review-2026-04-16.md` finding 2.

**Current state (2026-05-14):**

- Route entry uses `auth`, `verified`, `admin` middleware on the admin group.
- `WithAdminAuthorization::authorizeAdmin()` is still called from 26-30 routed admin Livewire components in `mount()` and most mutating actions.
- No persistent Livewire middleware is registered in `AppServiceProvider`.

**Why it matters:** The archived backlog reframed this as an intentional "safety net". The original review treated it as enforcement drift between first page load and subsequent Livewire requests. Both readings are defensible, but the *codebase doesn't yet declare which one is current*, so a future maintainer cannot tell whether to add the trait to a new admin component or rely on route middleware.

**Backlog (two options — pick one before implementation):**

- **Option A (consolidate):** Register persistent admin enforcement so routed admin Livewire components rely only on the route contract. Remove `WithAdminAuthorization` calls from `mount()` and from mutating actions in a follow-up pass. Update `AdminLivewireAuthorizationTest` to assert this is the new contract.
- **Option B (formalise safety net):** Document in `CLAUDE.md` and at the top of the trait that the trait is a deliberate defense-in-depth layer, mandatory in every routed admin Livewire `mount()` and every mutating action, even though route middleware also enforces it. Add a test that asserts every routed admin Livewire component class uses the trait.

This must not be left ambiguous a third time.

### 8. [P2/P3] Performance suite: modernise or formally demote

**Source review:** `test-suite-architecture-review-2026-04-16.md` finding [P2] (performance layer is the weakest part of the architecture story).

**Current state (2026-05-14):**

- `tests/Performance/` still contains `LivestreamProcessingPerformanceTest.php` (and others). The phpunit configuration declares it as a separate `Performance Tests` testsuite outside the default run.
- The original concerns about stale service references, reflection into private methods, and a stale `README_THUMBNAIL_TESTS.md` were not verified to be resolved by this audit; the tests are just no longer in the default path.

**Why it matters:** Segregation is progress, but if the contents are still broken or referencing things that no longer exist, the suite is dead code masquerading as a test asset.

**Backlog:**

- Read each file in `tests/Performance/` and confirm whether the services and methods they reference still exist.
- For any that target removed code: either rewrite against current boundaries or delete the file outright.
- Update or delete any supporting docs (`tests/README_THUMBNAIL_TESTS.md` if still stale).
- Add a one-line note in `tests/Performance/README.md` (already exists) that documents the suite as opt-in benchmark tooling, not part of trusted CI.

## Suggested delivery order

1. Item 2 (AI processing_id) — smallest user-facing fix with the most diagnostic value.
2. Item 1 (`extracted_*` dual-write) — finishes the JSON-promotion compatibility window the March work started.
3. Item 3 (`confidence_level` JSON reads) — same shape as item 1, can share testing patterns.
4. Item 7 (admin authorization decision) — needs a decision before items 6 changes any admin component.
5. Item 6 (model boundary leaks) — straightforward extraction work.
6. Item 4 (logs viewer auto-refresh) — quick win, small surface.
7. Item 5 (upload event scoping) — depends on the product decision about multi-instance uploads.
8. Item 8 (performance suite) — lowest urgency, can run in parallel with anything else.

## Definition of done

- `ProcessingInitiator` no longer writes `extracted_*` JSON copies; `SermonCreationService` no longer reads them; backfill complete.
- AI-stage log lines reference the real `processing_id` for every run, never `'unknown'`.
- `service_sections.confidence` is the only runtime confidence authority; `confidence_level` is derived display only.
- The `ProcessingLogsViewer` auto-refresh checkbox has a single owner.
- The upload event-scoping decision is recorded (either documented singleton-only or scoped event names implemented and tested).
- `ServiceSection::hasExtractedMedia()`, `SermonProcessingStep::markAs*()`, `Preacher::getFor*List()`, and `Meeting::getForAdminList()` are no longer on the model classes.
- The `WithAdminAuthorization` story is either consolidated or formally documented as a safety net.
- The performance suite is either current or formally retired with no stale references.
