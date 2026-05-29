# Simplification Plan

Consolidated 2026-05-22 from the prior dated plans (`SIMPLIFICATION-PLAN-2026-02-25.md` and `SIMPLIFICATION-PLAN-2026-05-22.md`). Phases that were already completed in the 2026-02-25 plan have been dropped — see [docs/archived-plans/SIMPLIFICATION-PLAN-2026-02-25.md](../archived-plans/SIMPLIFICATION-PLAN-2026-02-25.md) for the historical execution log.

This file tracks only work that still appears necessary, re-audited against the current codebase on 2026-05-22 and spot-checked again on 2026-05-29.

## Scope

- Finish the remaining simplification work without reintroducing removed abstractions.
- Keep behavior stable while reducing the current hotspots: legacy storage fallbacks, schema hygiene, oversized services, and the architectural-surface findings from the May audit (thin wrappers, empty subclasses, duplicated path checks, misplaced classes).

## Quality Gates

- `vendor/bin/sail artisan test --compact --parallel <focused test paths>`
- `vendor/bin/sail composer phpstan` — must stay at 0 errors.
- `vendor/bin/sail bin pint --dirty`
- `vendor/bin/sail artisan dusk` for any phase that touches public routes or the upload form.

## Phases

Phase numbers from the prior plans are preserved to keep prior context referenceable.

### Phase 9: Legacy Storage Migration and Cleanup

Priority: **Medium** — the legacy runtime fallback still exists, but the 2026-05-29 local database spot-check found no remaining legacy-pattern sermon audio paths (`817` sermons total, `810` with audio paths, `0` bare legacy filenames, `810` slash-delimited paths, `7` empty audio paths). Re-confirm against the target production dataset before deleting fallback support.

Status: **Pending / mostly migrated** — runtime still branches on legacy formats in `SermonStorageService::getSermonFileInfo()`, but the current local data no longer appears to need the legacy branch.

Target files:

- [app/Services/SermonStorageService.php](../../app/Services/SermonStorageService.php) — legacy branching in `getSermonFileInfo()` / `resolveFileInfo()`.
- [app/Services/SermonStorageMaintenanceService.php](../../app/Services/SermonStorageMaintenanceService.php) — migration orchestrator (complete, ready to use).

Tooling (already complete — no changes needed):

- [app/Console/Commands/MigrateSermonStorageCommand.php](../../app/Console/Commands/MigrateSermonStorageCommand.php) — supports dry-run, batching, progress reporting.
- [app/Console/Commands/VerifySermonStorageCommand.php](../../app/Console/Commands/VerifySermonStorageCommand.php) — comprehensive diagnostics with pattern breakdown.

Tasks:

- [ ] Run `MigrateSermonStorageCommand` with `--dry-run` first to preview scope and catch issues in the target environment.
- [ ] If the target environment still has legacy rows, execute the migration against the target disk to canonicalize them.
- [ ] Run `VerifySermonStorageCommand` to confirm all files are accessible in canonical locations.
- [ ] Strip legacy-pattern detection from `SermonStorageService::getSermonFileInfo()` and `resolveFileInfo()`.
- [ ] Remove remaining `filetype` dependency from runtime storage resolution. `legacy_disk` configuration is already gone from `config/media-processing.php`; only the service fallback default remains.
- [ ] Remove `MigrateSermonStorageCommand` and `VerifySermonStorageCommand` after verification.

Exit criteria:

- Runtime no longer branches on legacy sermon storage/path formats.
- Legacy storage cleanup is a completed migration, not an indefinitely-supported fallback path.

### Phase 13: Schema Snapshot and Migration Hygiene

Priority: **Medium** — schema dump was regenerated previously, but it has drifted again and no drift guardrail exists.

Status: **Complete** — dump regenerated to include all migrations through `2026_05_26_165514_fortify_song_identity_columns`; CI drift gate added to `deploy.yml`.

Target files:

- [database/schema/mysql-schema.sql](../../database/schema/mysql-schema.sql) — currently stale; needs regeneration or removal from the bootstrap strategy.
- CI or local tooling that depends on schema snapshots.
- [AuditSchemaGuardrailsCommand](../../app/Console/Commands) — already exists; confirm it is wired into a regular check.

Tasks:

- [x] Decide one approach and enforce it: keep the schema dump current, or stop relying on it.
- [x] If retained, regenerate the dump after all current migrations, then add a CI check (or pre-push hook) that fails when migrations diverge from `mysql-schema.sql`.
- [x] Remove stale tooling assumptions tied to pre-cleanup schema shapes.

Exit criteria:

- Database bootstrap paths are deterministic.
- The schema dump, if kept, reflects the real schema and drift is caught automatically.

### Phase 14: Complexity Hotspot Decomposition

Priority: **Low-Medium** — incremental work, no urgency.

Status: **Pending** — six hotspots remain oversized, including five services plus the `SermonViewPresenter` outlier. Current line counts (2026-05-29):

| Service | Lines | Public Methods (prior count) |
|---------|------:|---------------:|
| [SermonViewPresenter](../../app/Presenters/SermonViewPresenter.php) | 995 | — |
| [MetadataExtractionService](../../app/Services/MetadataExtractionService.php) | 891 | 12 |
| [ThumbnailGenerationService](../../app/Services/ThumbnailGenerationService.php) | 848 | 7 |
| [VideoExtractionService](../../app/Services/VideoExtractionService.php) | 579 | 8 |
| [AudioTranscriptionService](../../app/Services/AudioTranscriptionService.php) | 558 | 8 |
| [SermonAnalysisService](../../app/Services/SermonAnalysisService.php) | 502 | 6 |

`SermonViewPresenter` is added here from the May audit — it is the largest single class in the codebase.

Tasks:

- [ ] Continue splitting pure transformation logic from IO-heavy orchestration.
- [ ] Prefer focused collaborators over growing utility megaclasses.
- [ ] Keep public service APIs stable unless a rename materially improves the boundary.
- [ ] Add tests around any newly-extracted collaborators rather than only top-level orchestration paths.

Exit criteria:

- The biggest services have lower cognitive load and clearer seams for testing and change.

### Phase 15: Misplaced Exception Class

Priority: **Trivial** — single move, zero behavior change.

Status: **Pending**.

[app/Services/ApiBibleBudgetExhaustedException.php](../../app/Services/ApiBibleBudgetExhaustedException.php) lives in the wrong namespace. Other custom exceptions ([ProcessingException.php](../../app/Exceptions/ProcessingException.php), [InvalidFileException.php](../../app/Exceptions/InvalidFileException.php)) live in `App\Exceptions`.

Tasks:

- [ ] Move the file to `app/Exceptions/` and update the namespace to `App\Exceptions`.
- [ ] Update callers (`ApiBibleClient`, `FetchBibleTextForSermon`) and any test imports.
- [ ] Run PHPStan + the API Bible test file to confirm.

Exit criteria:

- No file in `app/Services/` is named `*Exception.php`.

### Phase 16: Drop Empty Subclass — `MediaUpload`

Priority: **Low** — small refactor, contained blast radius.

Status: **Pending**.

[app/Livewire/MediaUpload.php](../../app/Livewire/MediaUpload.php) is a 9-line empty `extends Form {}` whose only role is to expose the alias `media-upload` to Livewire's class-name inference. The sibling files ([Form.php](../../app/Livewire/MediaUpload/Form.php), [Progress.php](../../app/Livewire/MediaUpload/Progress.php), [Status.php](../../app/Livewire/MediaUpload/Status.php)) already nest under `MediaUpload/`, so future readers reasonably expect `MediaUpload` to be a directory, not a class.

Options (pick one):

1. **Rename + relocate (recommended).** Rename `App\Livewire\MediaUpload\Form` → `App\Livewire\MediaUpload`, move it up one directory. The two child components (`Progress`, `Status`) sit in a sibling `MediaUploadParts/` directory or are renamed `MediaUploadProgress` / `MediaUploadStatus`. Delete the empty subclass.
2. **Explicit Livewire alias.** Keep `Form` where it is, register `Livewire::component('media-upload', Form::class)` in a service provider, delete the empty subclass.

Tasks:

- [ ] Decide between option 1 and option 2.
- [ ] Update [resources/views/sermons/upload.blade.php:15](../../resources/views/sermons/upload.blade.php#L15) (`@livewire('media-upload')`) plus the two `<livewire:media-upload.progress>` / `<livewire:media-upload.status>` references in [resources/views/livewire/media-upload/form.blade.php](../../resources/views/livewire/media-upload/form.blade.php).
- [ ] Re-run the existing media-upload Dusk + feature tests.

Exit criteria:

- One class per Livewire component on the upload screen; no empty subclasses.

### Phase 17: Collapse `WithUploadLifecycle` Into the Component

Priority: **Medium** — touches the largest Livewire component but the change is mechanical.

Status: **Pending**.

[app/Livewire/Traits/WithUploadLifecycle.php](../../app/Livewire/Traits/WithUploadLifecycle.php) is ~270 lines of stateful upload, validation, and progress-tracking logic. It is used by exactly one class ([MediaUpload/Form.php](../../app/Livewire/MediaUpload/Form.php)). A single-consumer trait that carries this much state is just a chapter of the component placed in a different file. It cannot be tested in isolation and forces every reader to chase imports.

Tasks:

- [ ] Combine with Phase 16: after the rename, inline the trait's contents into the (renamed) `MediaUpload` component.
- [ ] Keep `WithFileUploads` (Livewire-provided trait) and `HasConditionalLogging` for now (Phase 18 removes the latter).
- [ ] Adjust PHPStan baseline if necessary.

Exit criteria:

- No project-local trait is used by exactly one class for state-bearing behavior.

### Phase 18: Remove `HasConditionalLogging`

Priority: **Low** — small surface, but eliminates a confusing pattern.

Status: **Pending**.

[app/Livewire/Traits/HasConditionalLogging.php](../../app/Livewire/Traits/HasConditionalLogging.php) wraps every `Log::info|warning|error|debug` call in `if (! app()->runningUnitTests())`. Production code should not branch on test context. The trait is used in two files ([MediaUpload/Form.php](../../app/Livewire/MediaUpload/Form.php) and [ProcessingLogsViewer.php](../../app/Livewire/ProcessingLogsViewer.php)).

The right place to silence logging during tests is the test config.

Tasks:

- [ ] Verify or add a `LOG_CHANNEL=null` (or `LOG_LEVEL=emergency`) entry in [phpunit.xml](../../phpunit.xml) and the Dusk env file.
- [ ] Replace all `$this->logInfo(...)` / `logWarning` / `logError` / `logDebug` calls in the two consumers with direct `Log::` facade calls.
- [ ] Delete the trait file.
- [ ] Run the affected test files to confirm no stray log output appears.

Exit criteria:

- No project code branches on `app()->runningUnitTests()` for logging.

### Phase 19: Consolidate Path-Safety Checks

Priority: **Low-Medium** — security-adjacent; worth doing once.

Status: **Pending**.

There are currently three distinct implementations of the same path-traversal / scheme-injection guard:

1. [app/Traits/HandlesSafePaths.php](../../app/Traits/HandlesSafePaths.php) — the canonical version (`isUnsafePath()`), used by 5 callers.
2. [app/Actions/DeleteLivestreamUpload.php:310](../../app/Actions/DeleteLivestreamUpload.php#L310) and [app/Actions/DeleteLivestreamUpload.php:436-447](../../app/Actions/DeleteLivestreamUpload.php#L436-L447) — open-coded `str_contains($path, '..')`, absolute path, and Windows drive-letter checks.
3. [app/Models/Preacher.php:117](../../app/Models/Preacher.php#L117) — separate `http|//|/` check on `image_path`.

Tasks:

- [ ] Promote the logic to a single static helper on a new `app/Support/Path.php` (or keep the trait but use it consistently — the trait pattern has no benefit since there is no state).
- [ ] Replace the inline checks in `DeleteLivestreamUpload` and `Preacher` with calls to the canonical helper.
- [ ] Add a single focused unit test covering: relative traversal (`../foo`), absolute path (`/foo`), windows path (`\foo`), URI scheme (`http://`, `file://`), and a safe relative path.

Exit criteria:

- Exactly one implementation of "is this path unsafe?" across the codebase.

### Phase 20: Reframe `Repositories/` as Caches (or fold into `Services/`)

Priority: **Medium** — clarifies an existing naming inconsistency.

Status: **Pending**.

Three of the four classes under [app/Repositories/](../../app/Repositories/) are not repositories in the DDD sense. They are read-side caching wrappers:

- [MeetingListRepository.php](../../app/Repositories/MeetingListRepository.php) — 58 lines, single cached lookup plus request memoization.
- [PreacherListRepository.php](../../app/Repositories/PreacherListRepository.php) — 87 lines, two cached lookups plus request memoization.
- [PageRepository.php](../../app/Repositories/PageRepository.php) — 125 lines, two cached lookups + cache-bust helper.
- [SermonRepository.php](../../app/Repositories/SermonRepository.php) — 696 lines (this one *is* a real repository with substantive query logic; leave it).

Two sibling classes already follow a cleaner convention in `Services/`: [PublicPageReadModelCache.php](../../app/Services/PublicPageReadModelCache.php) and [PublicMeetingReadModelCache.php](../../app/Services/PublicMeetingReadModelCache.php). Having both `Repositories/` and `Services/` host the same pattern doubles the place a future reader has to look.

Tasks:

- [ ] Rename `MeetingListRepository` → `MeetingListCache`, move to `app/Services/`.
- [ ] Rename `PreacherListRepository` → `PreacherListCache`, move to `app/Services/`.
- [ ] Rename `PageRepository` → `PageListCache`, move to `app/Services/`. (The `ADMIN_LIST_CACHE_KEY` constants and observer references must be updated.)
- [ ] Leave `SermonRepository` in place — it is a genuine repository with substantive query logic.
- [ ] Update [SitemapCacheObserver.php](../../app/Observers/SitemapCacheObserver.php), [AppServiceProvider.php](../../app/Providers/AppServiceProvider.php), `tests/TestCase.php`, and any controllers/Livewire components/presenters/tests that import the renamed classes from `App\Repositories`. `PreacherObserver` no longer imports these classes.

Exit criteria:

- `app/Repositories/` contains only `SermonRepository`, or is removed entirely if `SermonRepository` is also relocated.
- All cache-key constants live in one namespace, making cache-bust audits scriptable.

### Phase 21: Inline Thin Action Wrappers

Priority: **Low** — file count reduction, not architectural change.

Status: **Pending**.

Several action classes are pure single-call delegations and serve no test-seam purpose (the underlying service is already mockable).

Candidates (verify call counts before deleting):

- [app/Actions/CategorizeCalendarEvent.php](../../app/Actions/CategorizeCalendarEvent.php) — 24 lines. Two callers, both `app(CategorizeCalendarEvent::class)->execute(...)`. Inline `$calendarService->manuallyCategorizeEvent(...)` / `manuallyUnCategorizeEvent(...)` directly at the call sites in [ListCalendarEvents.php:56](../../app/Livewire/Admin/CalendarEvents/ListCalendarEvents.php#L56) and [EditCalendarEvent.php:97](../../app/Livewire/Admin/CalendarEvents/EditCalendarEvent.php#L97).
- [app/Actions/Meetings/CreateMeetingCalendarEvent.php](../../app/Actions/Meetings/CreateMeetingCalendarEvent.php) — 24 lines, single line of behavior. As of 2026-05-29 it appears to have no production callers; only the dedicated test ([CreateMeetingCalendarEventTest.php](../../tests/Integration/Actions/Meetings/CreateMeetingCalendarEventTest.php)) references it. Delete it unless a hidden external entrypoint exists.
- [app/Actions/QueueScriptureEnrichment.php](../../app/Actions/QueueScriptureEnrichment.php) — confirm call sites; this one at least has guard logic (config check + empty-reference check), so consider it a "keep" candidate unless the same guards live on every call path.

Tasks:

- [ ] For each candidate, confirm the call count and that the underlying service is already covered by tests.
- [ ] Delete the action + its dedicated test if both conditions hold; delete `CreateMeetingCalendarEvent` outright if the no-production-caller finding still holds.
- [ ] Verify by running the affected feature tests.

Exit criteria:

- No action class in `app/Actions/` is a pure single-call wrapper without guard logic.

### Phase 22: Reorganize `Presenters/`

Priority: **Low** — readability improvement, not behavior change.

Status: **Investigate**.

The 18 files under [app/Presenters/](../../app/Presenters/) serve three different responsibilities:

| Group | Files | Pattern |
|-------|-------|---------|
| Sitemap | MeetingSitemapPresenter, PageSitemapPresenter, PreacherSitemapPresenter, SermonSitemapPresenter | `Url::create(...)` builder |
| SEO / Schema.org | SermonArchiveSeoPresenter, SeriesItemListPresenter, SongArchiveSeoPresenter, SongItemListPresenter, SermonItemListPresenter, PreacherItemListPresenter | JSON-LD shape builder |
| View data | PageLayoutPresenter, PageCardPresenter, PageImagePresenter, RelatedPagePresenter, SermonViewPresenter (995 lines!), ChurchServiceShowPresenter, MeetingShowPresenter, BreadcrumbPresenter | Model → view-ready array/object |

"Presenter" is currently a junk-drawer label. The sitemap presenters are tightly coupled to [SitemapService.php](../../app/Services/SitemapService.php) and would be more discoverable adjacent to it. The SEO/Schema.org presenters share a more cohesive purpose than they share with the view-data presenters.

Tasks:

- [ ] Decide on a target shape — options include `app/Sitemap/`, `app/Seo/`, and a slimmer `app/Presenters/`.
- [ ] Verify that `SermonViewPresenter` (the 995-line outlier) is on the Phase 14 decomposition list — it now is.
- [ ] Do not move without consensus — this is the largest-blast-radius item in this plan.

Exit criteria:

- The `Presenters/` directory contains files that share a single responsibility, or is split into directories that each do.

## Suggested Order

1. **Phase 15** — Trivial, no risk. 5 minutes.
2. **Phase 13** — Regenerate or retire the stale schema dump, then add the drift guardrail.
3. **Phase 9** — Verify production storage counts, then remove fallback/tooling if the migration is truly complete.
4. **Phase 18** — Independent; removes a confusing pattern.
5. **Phase 21** — File-count reduction; bundle the action deletions into one PR.
6. **Phase 19** — Security-adjacent; consolidate before anything else touches `DeleteLivestreamUpload`.
7. **Phase 16 + Phase 17 together** — Both rework the same `MediaUpload` component; do as one PR with full Dusk run.
8. **Phase 20** — Mechanical rename across a known caller set.
9. **Phase 14** — Ongoing incremental decomposition of oversized services (including `SermonViewPresenter`).
10. **Phase 22** — Last, and only after Phase 14 has reduced `SermonViewPresenter`.

## Definition of Done

- [ ] Sermon legacy storage fallbacks are retired after canonical migration completion.
- [ ] Schema snapshot strategy is consistent and drift-free, with automatic guardrails.
- [ ] Remaining hotspot services are decomposed with focused tests.
- [ ] No file in `app/Services/` is an exception class.
- [ ] No project-local trait is consumed by exactly one class for state-bearing behavior.
- [ ] No project code branches on `app()->runningUnitTests()`.
- [ ] Exactly one canonical implementation of path-safety checks.
- [ ] `app/Repositories/` holds only genuine repositories; cache wrappers live in `app/Services/`.
- [ ] No action class is a pure single-call wrapper without guard logic.
- [ ] `app/Presenters/` is split or scoped to a single responsibility.
- [ ] Required quality gates pass for each delivered phase.
