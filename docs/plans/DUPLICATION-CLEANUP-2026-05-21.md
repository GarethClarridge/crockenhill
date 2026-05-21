# Duplication Cleanup Plan (2026-05-21)

Generated from a parallel audit across services, Livewire components, controllers/jobs, and Blade views. The pattern across findings: most of the right abstractions already exist (`StorageAdapterHelper`, `ProcessingJob`, `WithFilterableListing`, `<x-alert>`, Form Objects) — the remaining duplication is incomplete migration. This plan finishes those migrations and extracts the small number of new abstractions that pay back their cost.

## Scope

- Eliminate duplicate code that hides bug surfaces (divergent cancellation checks, hand-rolled alert markup that bypasses the design system).
- Finish partially-completed migrations to existing helpers.
- Extract a small, focused set of new traits/components where three or more occurrences justify the abstraction.
- Do not introduce abstractions used only twice.
- Preserve behaviour — every phase ends green on the standard quality gates.

## Quality Gates (run after every phase)

- `vendor/bin/sail bin pint --dirty`
- `vendor/bin/sail composer phpstan`
- `vendor/bin/sail artisan test --compact --parallel`
- `vendor/bin/sail artisan dusk` (only if the phase touches admin UI or Livewire)

## Out of Scope (real duplicates, but extraction would hurt clarity)

These were considered and deliberately rejected:

- The `try { ... } catch (\Exception $e) { Log::error(...); throw $e; }` pattern across services. Real, 100+ occurrences — but each has unique context keys. A `LogsAndRethrows` trait would force callers into a less-obvious API.
- `MediaProcessingRequest::authorize()` inheritance. That is the correct pattern, not duplication.
- S3 upload retry. Already centralized in `StorageAdapterHelper::uploadWithRetry()`.
- Two-column form grid layouts (`grid grid-cols-1 md:grid-cols-2 gap-4`) — already concise, no component needed.

## Phases (ordered by ROI)

### Phase 1: Consolidate cancellation checks

Priority: **Critical** — this is the only finding that closes a bug surface, not just LOC.

Why it matters: there are currently two implementations of "is this processing cancelled?" that can drift. If they fall out of sync, cancellation works in some jobs and silently fails in others.

Target files:

- `app/Jobs/ProcessingJob.php` (lines 136-155) — version that queries `SermonProcessingStep` count and falls back to `MediaProcessingLog::isCancelled()`.
- `app/Traits/ChecksCancellation.php` (lines 18-37) — version used by jobs that do not extend `ProcessingJob` (e.g., `ExtractAudioFromVideo`, `StoreSermonVideo`).

Tasks:

- [ ] Pick the canonical implementation (likely the `ProcessingJob` version, since it covers both step-level and log-level cancellation).
- [ ] Reduce `ChecksCancellation` to a thin trait that calls a single static helper, or remove the trait and have non-`ProcessingJob` jobs depend on the same helper directly.
- [ ] Add a focused unit/feature test that proves step-level cancellation and log-level cancellation are both honoured by every consumer.
- [ ] Sweep callers (`ExtractAudioFromVideo`, `StoreSermonVideo`, anything still using `abortIfCancelled`) and migrate them.

Exit criteria:

- One implementation of "is this cancelled?" — used by both `ProcessingJob` descendants and standalone jobs.
- A test that fails if a future job re-introduces a divergent check.

### Phase 2: Hoist job `failed()` boilerplate into `ProcessingJob`

Priority: **High** — biggest LOC reduction in the pipeline (~80 lines across 8+ jobs).

Why it matters: every job's `failed()` handler logs the same three keys (`processing_id`, `error`, `attempts`) and calls `markAsFailed` on the transition service. Centralizing this prevents "I forgot to log `attempts` in the new job" drift.

Target files:

- `app/Jobs/ProcessingJob.php`
- `app/Jobs/TranscribeAudio.php` (lines 172-197)
- `app/Jobs/StoreSermonVideo.php` (lines 80-94)
- `app/Jobs/ExtractAudioFromVideo.php` (lines 165-169)
- `app/Jobs/CreateSermonRecord.php` (lines 50-68 for handle init; failed() block also)
- Any other `ProcessingJob` descendant with a `failed()` override.

Tasks:

- [ ] Add `final public function failed(\Throwable $e): void` to `ProcessingJob` that logs the common keys and calls `markAsFailed`.
- [ ] Add `protected function onJobFailure(\Throwable $e): void` as an empty hook for subclasses that need extra context.
- [ ] Move job-specific context from each subclass's `failed()` into its `onJobFailure()` override.
- [ ] Delete the now-empty `failed()` implementations from subclasses.

Exit criteria:

- `ProcessingJob` is the single source of truth for failure logging shape.
- No subclass duplicates the three baseline log keys.

### Phase 3: Extract `ProcessingJob::refreshAndCheckCancellation()`

Priority: **High** — pairs naturally with Phase 1 and Phase 2.

Why it matters: every job's `handle()` opens with the same five-line ritual (refresh the log, null-check it, initialize step logging, check cancellation). This is the kind of setup that goes wrong when copy-pasted.

Target files:

- `app/Jobs/ProcessingJob.php`
- `app/Jobs/CreateSermonRecord.php` (lines 50-68)
- `app/Jobs/TranscribeAudio.php` (lines 50-61)
- `app/Jobs/StoreSermonVideo.php` (lines 39-55)
- Plus any other `handle()` in `app/Jobs/` repeating the same intro.

Tasks:

- [ ] Add `protected function refreshAndCheckCancellation(): bool` to `ProcessingJob`. Returns `true` if the job should stop early.
- [ ] Replace the five-line intro in each `handle()` with a single call.
- [ ] Confirm Phase 1's consolidated cancellation logic is what this method delegates to.

Exit criteria:

- Every `ProcessingJob::handle()` begins with at most one or two lines of setup.
- Cancellation early-return behaviour is unchanged.

### Phase 4: Admin Livewire CRUD traits

Priority: **High** — most LOC saved, but biggest blast radius across files.

Why it matters: every admin Create/Edit/List component repeats `authorizeAdmin()` → mutate → `Log::warning(...)` → `success(...)`. The log shape is what the audit trail relies on — if it drifts per-component, audit queries get inconsistent.

Target files:

- New: `app/Livewire/Traits/WithAdminSave.php`
- New: `app/Livewire/Traits/WithAdminDelete.php`
- `app/Livewire/Admin/Preachers/CreatePreacher.php` (lines 56-75)
- `app/Livewire/Admin/Preachers/ListPreachers.php` (lines 68-82)
- `app/Livewire/Admin/Pages/CreatePage.php` (lines 26-40)
- `app/Livewire/Admin/Pages/ListPages.php` (lines 80-93)
- `app/Livewire/Admin/Users/CreateUser.php` (lines 60-91)
- `app/Livewire/Admin/Users/ListUsers.php` (lines 69-88)
- `app/Livewire/Admin/Meetings/ListMeetings.php` (lines 82-95)
- `app/Livewire/Admin/Sermons/*` — verify whether sermons follow the same pattern; if so include.

Tasks:

- [ ] Design the trait signatures. Likely shape: `protected function adminSave(callable $save, string $logAction, array $logFields = []): void` and `protected function adminDelete(Model $model, string $logAction, array $logFields = []): void`.
- [ ] Implement both traits. Each must call `$this->authorizeAdmin()` first (defense-in-depth — matches the existing `WithAdminAuthorization` invariant in [CLAUDE.md](../../CLAUDE.md)).
- [ ] Migrate one component (suggest Preachers — smallest surface) and run that component's tests to confirm the audit log shape is byte-identical.
- [ ] Roll out to the remaining components one at a time. After each, run the focused test plus `AdminLivewireComponentsUseTraitTest`.
- [ ] Update or add a pinning test that fails if a new admin mutating action skips the new traits.

Exit criteria:

- Every admin Create/Edit/List uses `WithAdminSave` / `WithAdminDelete`.
- Audit log shape (`admin_id`, model id, action name) is consistent across all admin mutations.
- `AdminLivewireComponentsUseTraitTest` still passes (and a new test pins the save/delete pattern).

### Phase 5: List-component filter-update boilerplate

Priority: **Medium** — clean win once Phase 4 is done.

Why it matters: each list component declares one `updatedXyzFilter(): void { $this->resetPage(); }` per filter property. `ListSermons` has six of them.

Target files:

- `app/Livewire/Traits/WithFilterableListing.php` (assumed location — confirm)
- `app/Livewire/Admin/Sermons/ListSermons.php` (lines 75-119)
- `app/Livewire/Admin/Pages/ListPages.php` (lines 53-73)
- `app/Livewire/Admin/Users/ListUsers.php` (lines 50-62)
- `app/Livewire/Admin/Preachers/ListPreachers.php`
- `app/Livewire/Admin/Meetings/ListMeetings.php`
- `app/Livewire/Admin/CalendarEvents/*` if present.

Tasks:

- [ ] Add `public function updated(string $name): void` (or equivalent Livewire hook) to `WithFilterableListing` that calls `resetPage()` when `$name` is in the component's `filterProperties()`.
- [ ] Remove the per-filter `updatedXyzFilter()` methods.
- [ ] Confirm tests around pagination + filter interaction still pass.

Exit criteria:

- No list component declares per-filter `updatedXyzFilter()` methods.
- Pagination still resets when any filter changes.

### Phase 6: FFmpeg setup consolidation

Priority: **Medium** — contained, 3-file change.

Why it matters: the `testing` env check (which avoids instantiating FFmpeg in tests) is in three places. If a new service forgets it, tests start hitting real FFmpeg.

Target files:

- New trait: `app/Traits/RequiresFfmpeg.php` (or extend an existing trait — check `app/Services/Concerns/` first).
- New helper method on `app/Services/StorageAdapterHelper.php`: `createFFMpeg(): ?FFMpeg`.
- `app/Services/VideoStorageService.php` (lines 36-42, 472-479)
- `app/Services/VideoExtractionService.php` (lines 32-66, 601-608)
- `app/Services/AudioCompressionService.php` (lines 36-54, 325-331)

Tasks:

- [ ] Add `StorageAdapterHelper::createFFMpeg(): ?FFMpeg` that handles the testing-env check and config loading.
- [ ] Add `RequiresFfmpeg` trait providing `requireFfmpeg(): FFMpeg` guard and a nullable `$ffmpeg` property.
- [ ] Migrate the three services to use both.
- [ ] Confirm the testing-env behaviour: each service must still be constructable in tests without FFmpeg installed.

Exit criteria:

- One place creates an FFMpeg instance; one place guards on its presence.
- The testing-env guard is no longer duplicated.

### Phase 7: Finish `StorageAdapterHelper` migration in `VideoSegmentationService`

Priority: **Medium** — pure deletion plus one small addition.

Why it matters: `VideoSegmentationService` has three private file-op methods that already exist in `StorageAdapterHelper`. This is leftover from an unfinished migration.

Target files:

- `app/Services/VideoSegmentationService.php` (lines 378-423)
- `app/Services/StorageAdapterHelper.php` (lines 189-224)

Tasks:

- [ ] Add `getFileContents(string $disk, string $path): string` to `StorageAdapterHelper`.
- [ ] Replace `VideoSegmentationService`'s `fileExists()`, `getFileSize()`, `getFileContents()` calls with the helper.
- [ ] Delete the three private methods.

Exit criteria:

- `VideoSegmentationService` no longer branches on storage type itself.
- Existing segmentation tests still pass.

### Phase 8: Hoist Form Request `prepareForValidation()` into base

Priority: **Low** — small win, but trivially safe.

Target files:

- `app/Http/Requests/MediaProcessingRequest.php`
- `app/Http/Requests/CancelMediaProcessingRequest.php` (line 13)
- `app/Http/Requests/RetryMediaProcessingRequest.php` (line 13)
- `app/Http/Requests/MediaStatusRequest.php` (line 12 — note this one also does boolean normalization; preserve it)
- `app/Http/Requests/ConfirmMediaSegmentRequest.php` (line 13)

Tasks:

- [ ] Move `prepareForValidation()` calling `assertProcessingIdShape()` into `MediaProcessingRequest`.
- [ ] Have `MediaStatusRequest` override with `parent::prepareForValidation()` + its boolean normalization.
- [ ] Delete the redundant overrides in the other three subclasses.

Exit criteria:

- Only one place asserts processing-id shape during preparation.

### Phase 9: `MediaController` exception-handling helper

Priority: **Low** — single-file change, minor LOC saving.

Target files:

- `app/Http/Controllers/Api/MediaController.php` (lines 74-87, 114-127, 187-196, 257-267)

Tasks:

- [ ] Add `private function handleApiException(\Exception $e, string $message, ?Request $request = null): JsonResponse` to the controller.
- [ ] Replace the four duplicated blocks with calls to it.

Exit criteria:

- One place renders 500 JSON responses from controller exceptions.

### Phase 10: Design-system alignment — alert markup + metadata-list

Priority: **Low** — design hygiene rather than logic.

Why it matters: hand-rolled amber alert boxes bypass the existing `<x-alert>` component, which the [design style guide](../design-style-guide.md) flags as an anti-pattern. The metadata list (`<dl>` + `<dt>/<dd>` flex) appears in three admin views with the same layout.

Target files:

- `resources/views/livewire/admin/sermons/edit-sermon.blade.php` (lines 52-71, 156-173)
- `resources/views/livewire/admin/church-services/show-song.blade.php` (lines 20-24)
- `resources/views/livewire/admin/church-services/partials/processing-step-timeline.blade.php` (lines 39-52)
- `resources/views/livewire/admin/church-services/processing-review.blade.php` (lines 10-15)
- New: `resources/views/components/metadata-list.blade.php`

Tasks:

- [ ] Replace hand-rolled amber alert markup with `<x-alert type="warning" title="...">`. Read `docs/design-style-guide.md` and activate the `frontend-design` skill first.
- [ ] Build `<x-metadata-list :items="[['label' => ..., 'value' => ...], ...]" />`.
- [ ] Register it in `/dev/components` so the gallery reflects the new component.
- [ ] Migrate the three view sites to use it.

Exit criteria:

- No hand-rolled alert HTML in admin views.
- The metadata-list pattern lives in one Blade component visible in the gallery.

## Definition of Done

- All ten phases checked off, or explicitly archived with reasoning.
- All four quality gates green after the final phase.
- No new abstraction has fewer than three callers (re-evaluate any phase that ends with two-caller traits).
- Move this file to `docs/archived-plans/` once complete.
