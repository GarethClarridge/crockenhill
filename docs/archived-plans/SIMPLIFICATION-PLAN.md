# Simplification Plan

> **ARCHIVED 2026-07-05 — superseded. Do not implement from this document.** The remaining open
> work in this plan is consolidated into
> [the archived July simplification parent](JULY-2026-SIMPLIFICATION-BACKLOG-2026-07-05.md)
> — Phase 9 → backlog item 2.3 (approved, gated on prod storage verification); Phase 25 → item 2.4
> (approved, gated per item); Phase 14 → closed with revised direction (see the backlog's tracker
> reconciliation). All other phases were complete. Backlog items 2.3/2.4 are self-contained;
> this file is retained only as the historical execution log.

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

Status: **In progress** — six increments landed (two on 2026-06-03, four on 2026-06-04). Remaining hotspots still oversized. Current line counts (2026-06-04, post-increment-6):

| Service | Lines | Public Methods (prior count) |
|---------|------:|---------------:|
| [ThumbnailGenerationService](../../app/Services/ThumbnailGenerationService.php) | 800 | 7 |
| [SermonViewPresenter](../../app/Presenters/SermonViewPresenter.php) | 736 | — |
| [MetadataExtractionService](../../app/Services/MetadataExtractionService.php) | 734 | 12 |
| [VideoExtractionService](../../app/Services/VideoExtractionService.php) | 579 | 8 |
| [AudioTranscriptionService](../../app/Services/AudioTranscriptionService.php) | 558 | 8 |
| [SermonAnalysisService](../../app/Services/SermonAnalysisService.php) | 502 | 6 |

`SermonViewPresenter` was added here from the May audit as the largest single class in the codebase.

Increment 1 (2026-06-03): extracted the dependency-free duration/outline formatting out of `SermonViewPresenter` into a stateless [SermonContentFormatter](../../app/Support/SermonContentFormatter.php) (`humanDuration`, `iso8601Duration`, `plainTextOutline`). The presenter's `formattedDuration` / `durationIso8601` / `plainTextOutline` now delegate; their per-method memoization was dropped (these are cheap pure functions of one column, so request-level caching bought nothing). Net: presenter 995 → 922 lines, plus a new 101-line collaborator covered directly by [tests/Unit/Support/SermonContentFormatterTest.php](../../tests/Unit/Support/SermonContentFormatterTest.php) — no DB or storage faking required. Public API unchanged; the 49 presenter/formatter tests and 55 downstream consumer tests pass.

Increment 2 (2026-06-03): extracted the pure, IO-free date/service inference out of `MetadataExtractionService` into [SermonFilenameParser](../../app/Services/SermonFilenameParser.php) (`extractDateFromFilename`, `tryExtractDateFromFilename`, `determineServiceFromFilename`, `determineServiceFromTime`, `isValidDate`, plus the `MONTH_NAME_NUMBERS` table and the `parseExplicitDate`/`tryExtractNamedMonthDate` helpers). The parser is injected into `MetadataExtractionService` (constructor default `new SermonFilenameParser`, so no provider binding and every existing `new MetadataExtractionService()` call site keeps working); the matching public methods now delegate, and the FFprobe-based `extractDateFromVideo` orchestration stays in the service since it is genuinely IO. Net: service 952 → 734 lines, plus a new 290-line collaborator with its own fast unit test [tests/Unit/Services/SermonFilenameParserTest.php](../../tests/Unit/Services/SermonFilenameParserTest.php). The existing `MetadataExtractionServiceTest` now doubles as delegation-seam coverage. Public API unchanged; 32 parser/service tests and 63 downstream consumer tests pass, PHPStan clean.

Increment 3 (2026-06-04): extracted the pure-string half of `SermonViewPresenter::metaDescription` into a new static [SermonContentFormatter::metaDescription](../../app/Support/SermonContentFormatter.php) — the SEO sentence assembly (verb selection from media availability, base sentence, reference/series appends, summary truncation to the 155-char limit). The presenter still resolves its inputs (explicit-attribute short-circuit, preacher name, display reference, `show_summary`/tag-stripping, `exposurePolicy->shouldExposeVideo`) but now passes primitives to the builder and assigns the result on a single memoized exit path — which incidentally closes a latent gap where the old truncation branches returned before writing `$this->memoized[$key]`. The truthiness checks on `series`/`summary` became `filled()`. Net: presenter 922 → 896 lines; formatter +73 lines covered by 8 new DB-free unit tests in [tests/Unit/Support/SermonContentFormatterTest.php](../../tests/Unit/Support/SermonContentFormatterTest.php). Public API unchanged; 56 formatter/presenter tests and 37 SEO/structured-data consumer tests pass, PHPStan clean.

Increment 4 (2026-06-04): extracted the pure frame-scoring algorithm out of `ThumbnailGenerationService::scoreFrameQuality` into a new injectable [FrameQualityScorer](../../app/Services/FrameQualityScorer.php) (`score(\GdImage): float`). The service keeps only the IO half — resolve temp-disk path, `file_get_contents`, `imagecreatefromstring`, and `imagedestroy` (now in a `finally`, so the GD resource is freed even on a scoring throw) — and delegates the luminance/contrast/detail math. The scorer is constructor-injected with a `new FrameQualityScorer` default, so no provider binding and existing instantiations are unaffected. The buried weight/normalizer/grid literals became named constants (values unchanged). This is the first focused coverage of the scoring algorithm, which previously ran only transitively through `generateThumbnail` against real frames; the 6 new unit tests in [tests/Unit/Services/FrameQualityScorerTest.php](../../tests/Unit/Services/FrameQualityScorerTest.php) assert exact scores for solid black/grey/white frames and the relative ranking of a high-contrast pattern. Net: service 848 → 800 lines, plus a 118-line collaborator. Public API unchanged; 6 scorer tests and 36 thumbnail integration tests pass, PHPStan clean.

Increment 6 (2026-06-04, two commits): split `SermonViewPresenter`'s memoization from its array-shaping. **Commit A** collapsed the `cacheKey → isset-check → compute → store` dance that all 11 sermon-id-keyed accessors repeated (plus the duplicated `/** @var array{...} */` cast on every cached-array return) into one private generic `memoize(Sermon, type, store, Closure)`; each accessor now passes only its type tag, target memo store, and a typed compute closure, with the array shapes flowing through a `@template TValue`. The identity-keyed accessors (`serviceLabel`, `seriesUrl`, the preacher methods) and the collection-keyed `presentCollection` keep their distinct schemes. Net: 910 → 825 lines. **Commit B** extracted the four output shapes into a new [SermonPresentationAssembler](../../app/Presenters/SermonPresentationAssembler.php) (`forApi`, `forList`, `forFull`), injected with a `new` default; the presenter's `present*` methods became one-line `memoize(... fn => $this->assembler->forX($this, $sermon))` delegations, and each `array{...}` shape is now declared once on the assembler rather than restated at every memoized return. The assembler reads every value back through the presenter (so the presenter stays the single memoization layer and the assembler needs no dependencies of its own — it uses the public `transcript()` method rather than re-injecting the reader). A new [tests/Integration/Presenters/SermonPresentationAssemblerTest.php](../../tests/Integration/Presenters/SermonPresentationAssemblerTest.php) pins each shape's exact key set and asserts the assembler output equals the presenter's delegated `present*` output. Net: 825 → 736 lines, plus a 152-line collaborator. `SermonViewPresenter` (736) is no longer the codebase's largest class — `ThumbnailGenerationService` (800) now tops the table. Public API unchanged across both commits; 42 presenter/assembler tests and 35 downstream consumer tests pass, PHPStan clean.

Increment 5 (2026-06-04): de-duplicated the triplicated preacher-attribute memoization in `SermonViewPresenter`. `displayPreacherName`, `preacherUrl`, and `preacherImageUrl` each carried the *same* ~22-line skeleton (derive an identity key from the profile ID or legacy `preacher` string, short-circuit on an `*_auth` flag once an authoritative loaded-relation result exists, prefer the loaded relation over a cached unloaded fallback, otherwise cache the fallback). That skeleton now lives once in a private `resolvePreacherAttribute()` template method; the three public methods became thin delegations supplying only their loaded-profile compute, their unloaded fallback, and their memo store. Unlike increments 1–4 this is an *in-place* de-duplication, not a file extraction, so line count nudged **up** (896 → 910): the shared helper carries a substantial docblock for the subtle memoization contract, and the closures add per-call boilerplate. The win is structural — one source of truth for the identity-key/auth-flag/precedence logic instead of three copies that could drift, and three declarative call sites that each state only their distinguishing behavior. The `$this->{$store}[...]` dynamic-property write is kept type-safe by a `'memoized'|'memoizedUrls'` literal-string union. Public API and all return values unchanged; 38 presenter tests (including the loaded-over-cached-null and reflects-after-first-call transitions) and 18 downstream consumer tests pass, PHPStan clean.

Tasks:

- [ ] Continue splitting pure transformation logic from IO-heavy orchestration.
- [ ] Prefer focused collaborators over growing utility megaclasses.
- [ ] Keep public service APIs stable unless a rename materially improves the boundary.
- [ ] Add tests around any newly-extracted collaborators rather than only top-level orchestration paths.

Exit criteria:

- The biggest services have lower cognitive load and clearer seams for testing and change.

### Phase 15: Misplaced Exception Class

Priority: **Trivial** — single move, zero behavior change.

Status: **Complete** — moved to `app/Exceptions/ApiBibleBudgetExhaustedException.php`; all callers updated.

[app/Services/ApiBibleBudgetExhaustedException.php](../../app/Services/ApiBibleBudgetExhaustedException.php) lives in the wrong namespace. Other custom exceptions ([ProcessingException.php](../../app/Exceptions/ProcessingException.php), [InvalidFileException.php](../../app/Exceptions/InvalidFileException.php)) live in `App\Exceptions`.

Tasks:

- [x] Move the file to `app/Exceptions/` and update the namespace to `App\Exceptions`.
- [x] Update callers (`ApiBibleClient`, `FetchBibleTextForSermon`) and any test imports.
- [x] Run PHPStan + the API Bible test file to confirm.

Exit criteria:

- No file in `app/Services/` is named `*Exception.php`.

### Phase 16: Drop Empty Subclass — `MediaUpload`

Priority: **Low** — small refactor, contained blast radius.

Status: **Complete** (2026-06-03, commit `9e6c9a112` "Phase 16 + 17: Consolidate MediaUpload Livewire component"). Option 1 (rename + relocate, the recommended path) was taken.

The empty `MediaUpload extends Form {}` subclass was dropped. The real component was promoted from `MediaUpload/Form.php` up to [app/Livewire/MediaUpload.php](../../app/Livewire/MediaUpload.php), and the two child components were flattened out of the now-deleted `MediaUpload/` directory to [MediaUploadProgress.php](../../app/Livewire/MediaUploadProgress.php) / [MediaUploadStatus.php](../../app/Livewire/MediaUploadStatus.php) (aliases `media-upload-progress` / `media-upload-status`). The page-global `media-upload:*` event-name strings and the `livewire.media-upload.*` view paths were left unchanged (cross-component contract / blade paths).

Tasks:

- [x] Decide between option 1 and option 2. (Option 1 — rename + relocate.)
- [x] Update [resources/views/sermons/upload.blade.php:15](../../resources/views/sermons/upload.blade.php#L15) (`@livewire('media-upload')` — unchanged alias) plus the two child-component references in [resources/views/livewire/media-upload/form.blade.php](../../resources/views/livewire/media-upload/form.blade.php) (now `<livewire:media-upload-progress>` / `<livewire:media-upload-status>`).
- [x] Re-run the existing media-upload Dusk + feature tests. (41 focused tests + 41 Dusk tests pass.)

Exit criteria:

- One class per Livewire component on the upload screen; no empty subclasses.

### Phase 17: Collapse `WithUploadLifecycle` Into the Component

Priority: **Medium** — touches the largest Livewire component but the change is mechanical.

Status: **Complete** (2026-06-03, same commit as Phase 16, `9e6c9a112`).

`app/Livewire/Traits/WithUploadLifecycle.php` was ~255 lines of stateful upload, validation, and progress-tracking logic used by exactly one class. As planned, it was inlined into the promoted [MediaUpload](../../app/Livewire/MediaUpload.php) component and deleted; the trait's `@property` bridge was replaced by real property declarations. `WithFileUploads` (the Livewire-provided trait) is retained; `HasConditionalLogging` had already been removed under Phase 18.

Tasks:

- [x] Combine with Phase 16: after the rename, inline the trait's contents into the (renamed) `MediaUpload` component.
- [x] Keep `WithFileUploads` (Livewire-provided trait) — retained. (`HasConditionalLogging` was already gone via Phase 18.)
- [x] Adjust PHPStan baseline if necessary. (PHPStan clean, 0 errors.)

Exit criteria:

- No project-local trait is used by exactly one class for state-bearing behavior.

### Phase 18: Remove `HasConditionalLogging`

Priority: **Low** — small surface, but eliminates a confusing pattern.

Status: **Complete** — trait deleted; all `$this->log*()` calls in the upload component (then `MediaUpload/Form.php` + its `WithUploadLifecycle` trait, since merged into [MediaUpload.php](../../app/Livewire/MediaUpload.php) under Phases 16/17) and `ProcessingLogsViewer.php` replaced with direct `Log::` facade calls. Test logging is silenced via the existing `LOG_CHANNEL=testing` config in `phpunit.xml`.

[app/Livewire/Traits/HasConditionalLogging.php](../../app/Livewire/Traits/HasConditionalLogging.php) wraps every `Log::info|warning|error|debug` call in `if (! app()->runningUnitTests())`. Production code should not branch on test context. The trait is used in two files ([MediaUpload/Form.php](../../app/Livewire/MediaUpload/Form.php) and [ProcessingLogsViewer.php](../../app/Livewire/ProcessingLogsViewer.php)).

The right place to silence logging during tests is the test config.

Tasks:

- [x] Verify or add a `LOG_CHANNEL=null` (or `LOG_LEVEL=emergency`) entry in [phpunit.xml](../../phpunit.xml) and the Dusk env file. (`LOG_CHANNEL=testing` already set; routes to a file channel — no test noise.)
- [x] Replace all `$this->logInfo(...)` / `logWarning` / `logError` / `logDebug` calls in the two consumers with direct `Log::` facade calls. (`WithUploadLifecycle` also updated.)
- [x] Delete the trait file.
- [x] Run the affected test files to confirm no stray log output appears.

Exit criteria:

- No project code branches on `app()->runningUnitTests()` for logging.

### Phase 19: Consolidate Path-Safety Checks

Priority: **Low-Medium** — security-adjacent; worth doing once.

Status: **Complete** (2026-06-03).

Consolidated to a single static helper `App\Support\Path` (sibling of `MediaAssetPath`), replacing the stateless `HandlesSafePaths` trait. Two predicates now live there:

- `Path::isUnsafe()` — the canonical reject-guard (traversal `..`, absolute `/`/`\`, URI scheme `://`). All 5 former trait callers ([SermonAssetController](../../app/Http/Controllers/SermonAssetController.php), [SermonThumbnailCandidateController](../../app/Http/Controllers/Admin/SermonThumbnailCandidateController.php), [ServiceSectionCandidateMediaController](../../app/Http/Controllers/Admin/ServiceSectionCandidateMediaController.php), [SermonStorageService](../../app/Services/SermonStorageService.php), [SermonTranscriptReader](../../app/Services/SermonTranscriptReader.php)) now call it directly, as does the stored-target branch of [DeleteLivestreamUpload](../../app/Actions/DeleteLivestreamUpload.php#L311) (replacing its open-coded `str_contains($path, '..')`).
- `Path::isAlreadyResolvableUrl()` — captures `Preacher`'s *inverse* `http|//|/` predicate verbatim (it is an allow-as-is check, not a reject-guard, so it is a distinct named method rather than an abuse of `isUnsafe()`).

`DeleteLivestreamUpload::isAbsolutePath()` / `isSafeAbsoluteDeletionPath()` were **intentionally left in place** — they implement the opposite policy (deliberately accept absolute paths, then confine them to `storage_path()`/`sys_get_temp_dir()`), so they are not duplicates of the canonical guard.

The trait and its test were deleted; a new focused [tests/Unit/Support/PathTest.php](../../tests/Unit/Support/PathTest.php) covers both predicates.

Tasks:

- [x] Promote the logic to a single static helper on a new `app/Support/Path.php`.
- [x] Replace the inline checks in `DeleteLivestreamUpload` and `Preacher` with calls to the canonical helper.
- [x] Add a single focused unit test covering: relative traversal (`../foo`), absolute path (`/foo`), windows path (`\foo`), URI scheme (`http://`, `file://`), and a safe relative path.

Exit criteria (met):

- Exactly one implementation of "is this path unsafe?" across the codebase.

### Phase 20: Reframe `Repositories/` as Caches (or fold into `Services/`)

Priority: **Medium** — clarifies an existing naming inconsistency.

Status: **Complete** (2026-06-03). All three read-side caching wrappers were renamed and moved into `app/Services/` (`MeetingListCache`, `PreacherListCache`, `PageListCache`); `SermonRepository` stays put. Their two integration tests moved to `tests/Integration/Services/`. `PageCardService` dropped its now-same-namespace import. PHPStan clean; 117 focused tests pass (caches, consumers, sitemap/page-card/SEO presenters, calendar-event Livewire).

Three of the four classes under [app/Repositories/](../../app/Repositories/) are not repositories in the DDD sense. They are read-side caching wrappers:

- [MeetingListRepository.php](../../app/Repositories/MeetingListRepository.php) — 58 lines, single cached lookup plus request memoization.
- [PreacherListRepository.php](../../app/Repositories/PreacherListRepository.php) — 87 lines, two cached lookups plus request memoization.
- [PageRepository.php](../../app/Repositories/PageRepository.php) — 125 lines, two cached lookups + cache-bust helper.
- [SermonRepository.php](../../app/Repositories/SermonRepository.php) — 696 lines (this one *is* a real repository with substantive query logic; leave it).

Two sibling classes already follow a cleaner convention in `Services/`: [PublicPageReadModelCache.php](../../app/Services/PublicPageReadModelCache.php) and [PublicMeetingReadModelCache.php](../../app/Services/PublicMeetingReadModelCache.php). Having both `Repositories/` and `Services/` host the same pattern doubles the place a future reader has to look.

Tasks:

- [x] Rename `MeetingListRepository` → `MeetingListCache`, move to `app/Services/`.
- [x] Rename `PreacherListRepository` → `PreacherListCache`, move to `app/Services/`.
- [x] Rename `PageRepository` → `PageListCache`, move to `app/Services/`. (The `ADMIN_LIST_CACHE_KEY` constants and observer references must be updated.)
- [x] Leave `SermonRepository` in place — it is a genuine repository with substantive query logic.
- [x] Update [SitemapCacheObserver.php](../../app/Observers/SitemapCacheObserver.php), [AppServiceProvider.php](../../app/Providers/AppServiceProvider.php), `tests/TestCase.php`, and any controllers/Livewire components/presenters/tests that import the renamed classes from `App\Repositories`. `PreacherObserver` no longer imports these classes.

Exit criteria:

- `app/Repositories/` contains only `SermonRepository`, or is removed entirely if `SermonRepository` is also relocated.
- All cache-key constants live in one namespace, making cache-bust audits scriptable.

### Phase 21: Inline Thin Action Wrappers

Priority: **Low** — file count reduction, not architectural change.

Status: **Complete** (2026-06-03).

Several action classes were pure single-call delegations that served no test-seam purpose (the underlying service is already mockable).

Resolution per candidate:

- [app/Actions/CategorizeCalendarEvent.php] — **Inlined and deleted.** The null/non-null branch was routing, not guard logic. Inlined `$calendarService->manuallyUnCategorizeEvent(...)` / `manuallyCategorizeEvent(...)` directly at the two call sites in [ListCalendarEvents.php](../../app/Livewire/Admin/CalendarEvents/ListCalendarEvents.php) and [EditCalendarEvent.php](../../app/Livewire/Admin/CalendarEvents/EditCalendarEvent.php). The seam in `ListCalendarEventsTest` was moved down one layer to mock `CalendarService::manuallyCategorizeEvent` instead.
- [app/Actions/Meetings/CreateMeetingCalendarEvent.php] — **Deleted** (plus its test and the now-empty `Meetings/` directories). Confirmed zero production callers; its sole consumer was its own test. Note: its target method `GoogleCalendarSyncService::createEventForMeeting()` was then left unreferenced — that dead-method sweep was completed in commit `fd3e5f278` ("Remove orphaned GoogleCalendarSyncService::createEventForMeeting").
- [app/Actions/QueueScriptureEnrichment.php](../../app/Actions/QueueScriptureEnrichment.php) — **Kept.** It carries real guard logic (config `services.api_bible.enabled` check + empty-reference check), is injected into `SaveSermonDetails` and `ScriptureOperatorService`, and is mocked as a seam in two test files. A genuine collaborator, not a thin wrapper.

Exit criteria (met):

- No action class in `app/Actions/` is a pure single-call wrapper without guard logic.

### Phase 22: Reorganize `Presenters/`

Priority: **Low** — readability improvement, not behavior change.

Status: **Complete** (2026-06-04). Target shape: **split into three directories** (the most-discoverable option, chosen with maintainer consensus). The four sitemap presenters moved to `app/Sitemap/` (`App\Sitemap`, adjacent to `SitemapService`), the six SEO/Schema.org builders moved to `app/Seo/` (`App\Seo`), and the nine view-data presenters stayed in `app/Presenters/` (`App\Presenters`). All moves used `git mv` so blame history follows each file. Net diff: 10 renamed presenters (one-line `namespace` change each) plus import-only edits to 19 consumers (`AppServiceProvider`, `SitemapService`, the four models, controllers, Livewire components, and tests). Test files were left in place — only their `use` imports changed; their `Tests\*\Presenters` namespaces are organizational and need not mirror the production split.

A subtle hazard surfaced and was caught by PHPStan: four moved files (`Sitemap\PageSitemapPresenter`, `Sitemap\PreacherSitemapPresenter`, `Sitemap\SermonSitemapPresenter`, `Seo\SermonItemListPresenter`) referenced a view-data presenter (`PageImagePresenter` / `SermonViewPresenter`) by its **unqualified** name, relying on same-namespace resolution that broke once the namespace changed. Each gained an explicit `use App\Presenters\…` import. Public APIs and container binding keys unchanged; PHPStan clean (0 errors), Pint clean, 39 focused presenter/sitemap/SEO/singleton-registration tests pass (110 assertions).

The 20 files under [app/Presenters/](../../app/Presenters/) served three different responsibilities (pre-move snapshot):

| Group | Files | Pattern |
|-------|-------|---------|
| Sitemap | MeetingSitemapPresenter, PageSitemapPresenter, PreacherSitemapPresenter, SermonSitemapPresenter | `Url::create(...)` builder |
| SEO / Schema.org | SermonArchiveSeoPresenter, SeriesItemListPresenter, SongArchiveSeoPresenter, SongItemListPresenter, SermonItemListPresenter, PreacherItemListPresenter | JSON-LD shape builder |
| View data | PageLayoutPresenter, PageCardPresenter, PageImagePresenter, RelatedPagePresenter, SermonViewPresenter (736 lines, down from 995 via Phase 14), SermonPresentationAssembler (new in Phase 14), ChurchServiceShowPresenter, MeetingShowPresenter, BreadcrumbPresenter | Model → view-ready array/object |

"Presenter" is currently a junk-drawer label. The sitemap presenters are tightly coupled to [SitemapService.php](../../app/Services/SitemapService.php) and would be more discoverable adjacent to it. The SEO/Schema.org presenters share a more cohesive purpose than they share with the view-data presenters.

Tasks:

- [x] Decide on a target shape — `app/Sitemap/`, `app/Seo/`, and a slimmer `app/Presenters/` (the three-directory split).
- [x] Verify that `SermonViewPresenter` (formerly the 995-line outlier) is on the Phase 14 decomposition list — it is, and Phase 14 has since reduced it to 736 lines.
- [x] Do not move without consensus — this is the largest-blast-radius item in this plan. (Maintainer chose the three-directory split before any files were touched.)

Exit criteria:

- The `Presenters/` directory contains files that share a single responsibility, or is split into directories that each do. **Met** — `app/Presenters/` now holds only the nine Model→view-ready presenters; sitemap and SEO builders live in `app/Sitemap/` and `app/Seo/`.

### Phase 23: Correct Stale `PageController` Docblock

Priority: **Trivial** — documentation fix, zero behavior change.

Status: **Complete** — both `layouts/page` references in the docblock updated to `pages.show`.

The `resolveView()` docblock in [app/Http/Controllers/PageController.php:91-101](../../app/Http/Controllers/PageController.php#L91-L101) describes the fallback as "the standard `layouts/page` template". That template no longer exists (deleted in commit `237896e81`); the method actually returns `'pages.show'` ([PageController.php:106](../../app/Http/Controllers/PageController.php#L106)). Pure documentation rot.

Tasks:

- [x] Update the docblock so the two `layouts/page` references (lines 94 and 99) name the real fallback, `pages.show`.

Exit criteria:

- No docblock references the deleted `layouts/page` template.

### Phase 24: Resolve Commented-Out Destructive Delete in `SermonStorageService`

Priority: **Trivial** — single-line decision, no behavior change until acted on.

Status: **Complete** — commented-out delete and its surrounding comment removed. The source file is intentionally kept intact during a copy operation; if deletion is later required it should be an explicit, tested code path, not a commented-out line.

```php
// Storage::disk($info['disk'])->delete($info['path']);
```

Commented-out destructive code is neither a working safeguard nor a clear "don't do this" warning. Either uncomment it (if the file should actually be deleted at this point) or remove the comment entirely.

This is a different concern from Phase 9, which edits the same file: Phase 9 strips legacy *path-format branching* from `getSermonFileInfo()` / `resolveFileInfo()`, whereas this line is a dormant *delete* operation in a separate method. Non-overlapping, but coordinate if both land in the same window.

Tasks:

- [x] Decide whether the delete should fire; uncomment it or delete the comment accordingly.

Exit criteria:

- No commented-out destructive storage operation remains in `SermonStorageService`.

### Phase 25: Decide Fate of Legacy One-Shot Importers

Priority: **Low** — file/table removal contingent on an operational decision.

Status: **Investigate** — carried over from the 2026-05-14 audit; the four files still exist as of 2026-06-03.

Two pairs of importer + command look like completed one-shot backfills:

- [app/Services/LegacySermonImporter.php](../../app/Services/LegacySermonImporter.php) + [app/Console/Commands/ImportLegacySermonBatchCommand.php](../../app/Console/Commands/ImportLegacySermonBatchCommand.php) — imports MP3s from a CSV tape index.
- [app/Services/LegacyPlayDateSongUsageImporter.php](../../app/Services/LegacyPlayDateSongUsageImporter.php) + [app/Console/Commands/ImportLegacySongUsageCommand.php](../../app/Console/Commands/ImportLegacySongUsageCommand.php) — backfills song usage from old SQL dumps.

If the historic import (commit `b5088b74e` "Historic video import") is complete and won't be re-run, these — plus the `play_date` table, if it backs the second importer and nothing else reads it — can be removed.

Tasks:

- [ ] Confirm with the maintainer that the historic imports are complete and will not be re-run.
- [ ] If confirmed, delete both importer/command pairs and their tests.
- [ ] Check whether the `play_date` table is read by anything other than `LegacyPlayDateSongUsageImporter`; if not, drop it in a migration.

Exit criteria:

- No completed one-shot importer remains as live, runnable code.

## Suggested Order

1. **Phase 15 + Phase 23 + Phase 24** — Trivial, no risk. Bundle the namespace move and the two doc/comment cleanups into one housekeeping PR. ~15 minutes.
2. **Phase 13** — Regenerate or retire the stale schema dump, then add the drift guardrail.
3. **Phase 9** — Verify production storage counts, then remove fallback/tooling if the migration is truly complete.
4. **Phase 18** — Independent; removes a confusing pattern.
5. **Phase 21** — File-count reduction; bundle the action deletions into one PR.
6. **Phase 19** — Security-adjacent; consolidate before anything else touches `DeleteLivestreamUpload`.
7. ~~**Phase 16 + Phase 17 together** — Both rework the same `MediaUpload` component; do as one PR with full Dusk run.~~ **Done** (commit `9e6c9a112`).
8. **Phase 20** — Mechanical rename across a known caller set.
9. **Phase 25** — Investigate-then-decide; needs maintainer sign-off that the historic imports are done before any deletion.
10. **Phase 14** — Ongoing incremental decomposition of oversized services (including `SermonViewPresenter`).
11. **Phase 22** — Last, and only after Phase 14 has reduced `SermonViewPresenter`.

## Definition of Done

- [ ] Sermon legacy storage fallbacks are retired after canonical migration completion.
- [ ] Schema snapshot strategy is consistent and drift-free, with automatic guardrails.
- [ ] Remaining hotspot services are decomposed with focused tests.
- [x] No file in `app/Services/` is an exception class.
- [x] No project-local trait is consumed by exactly one class for state-bearing behavior.
- [x] No project code branches on `app()->runningUnitTests()`.
- [x] Exactly one canonical implementation of path-safety checks.
- [x] `app/Repositories/` holds only genuine repositories; cache wrappers live in `app/Services/`.
- [x] No action class is a pure single-call wrapper without guard logic.
- [x] `app/Presenters/` is split or scoped to a single responsibility.
- [x] No docblock or comment references the deleted `layouts/page` template.
- [x] No commented-out destructive storage operation remains in the codebase.
- [ ] Completed one-shot legacy importers are removed (or explicitly retained with a documented reason).
- [ ] Required quality gates pass for each delivered phase.
