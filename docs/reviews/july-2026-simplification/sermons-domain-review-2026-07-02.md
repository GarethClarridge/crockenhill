# Sermons Domain Review — July 2026 Simplification, Phase 3

Reviewed 2026-07-02, per the doctrine, ground rules, and template in
[docs/archived-plans/JULY-2026-SIMPLIFICATION-REVIEW-PLAN-2026-07-02.md](../../archived-plans/JULY-2026-SIMPLIFICATION-REVIEW-PLAN-2026-07-02.md).
Findings only — no code changes.

Prior art consulted: [SIMPLIFICATION-PLAN.md](../../archived-plans/SIMPLIFICATION-PLAN.md) Phases 9 (legacy storage), 14 (hotspot decomposition), 24, 25 (legacy importers); [APRIL-2026-REVIEW-REMAINING-WORK-2026-05-14.md](../../archived-plans/APRIL-2026-REVIEW-REMAINING-WORK-2026-05-14.md); [simplification-backlog.md](../../architecture/simplification-backlog.md) (PR 6: `SermonProcessingStep` deletion rescinded; PR 20: Sermon model slimming); [SEMANTIC-SERMON-SEARCH-AND-QA-2026-06-18.md](../../plans/SEMANTIC-SERMON-SEARCH-AND-QA-2026-06-18.md).

## 1. Scope reviewed

- **Services:** `app/Services/Sermon/` (15 files, ~5,700 lines), `app/Services/Public/SermonRepository.php` (646), `app/Services/Public/PodcastFeedService.php` (166), `app/Services/Preacher/` (PreacherResolutionService, PreacherCutoverService; the speaker-ID services were noted but belong to Phase 1's media pipeline).
- **Jobs:** `MoveSermonToPrivateStorage`, `CreateSermonRecord`, `UpdateSermonRecord`, `ProcessTranscriptWithAI`, `AssessSermonVideoQuality`, `FetchBibleTextForSermon` (touched; deep pipeline jobs like `ExtractSermon` are Phase 1's).
- **Commands:** `MigrateSermonStorageCommand`, `VerifySermonStorageCommand`, `MigrateLivestreamAudioFiles`, `MigrateLocalFilesToSpacesCommand`, `MoveChildrensTalksToPrivateStorage`, `ImportLegacySermonBatchCommand`, `GenerateProdSermonPatchCommand`, `PreacherCutoverCommand`, `AssessSermonVideoQualityCommand`, `EnrichSermonsScripture`, `RefreshScripturePassages`, `SyncSermonScriptureFilters`.
- **Models:** `Sermon` (578), `SermonProcessingStep` (106), `SermonScriptureFilter` (69), `Preacher` (185), `PreacherAlias` (79), `Builders/SermonBuilder` (173).
- **UI:** `app/Livewire/Admin/Sermons/` (3 components), `app/Livewire/Admin/Preachers/` (3), `app/Livewire/Sermons/BrowseSermons` (329), `app/Livewire/Forms/SermonFormData` (151), `SermonController` (307), `SermonAssetController` (305), `PodcastFeedController` (45), `resources/views/rss/` (2 views).
- **Config:** `config/sermons.php` (7 lines), `config/podcast.php` (91), the `storage`/`analysis` sections of `config/media-processing.php`.
- **Analysis seam:** `SermonAnalysisInterface`, `SermonAnalysisService`, `MockSermonAnalysisService`, `SermonAnalysisValidator`, `SermonAnalysisPromptBuilder`, `AiServiceProvider` binding, `App\Data\SermonAnalysis`.
- **Tests:** ~180 files matching sermon/preacher/podcast (~35k lines excluding Playwright snapshots) — roughly a quarter of the whole suite by volume.

## 2. What this area is for

The church records its Sunday preaching, and this domain turns a raw recording into a public artefact: a sermon page with audio (video where quality allows), an AI-derived title/series/Bible reference/points/summary, a browsable archive filterable by Bible book/chapter, preacher, and series, and two podcast RSS feeds (morning/evening) consumed by Apple Podcasts etc. Children's talks are the same records with a `content_type` flag, kept members-only and stored privately. Operators get an admin list/edit surface for correcting AI output, choosing thumbnails, and controlling video visibility. The archive holds ~800+ sermons going back decades (digitised tapes), which is why import/migration tooling accumulated here.

## 3. Complexity inventory

| Cluster | Files | Lines | Notes |
|---|---|---:|---|
| Storage (runtime) | `SermonStorageService`, `MoveSermonToPrivateStorage` job, `SermonObserver` | ~940 | Four path "patterns" resolved at runtime; URL/CDN/version logic; metadata cache |
| Storage (one-shot ops) | `SermonStorageMaintenanceService` + 5 commands (`MigrateSermonStorage`, `VerifySermonStorage`, `MigrateLivestreamAudioFiles`, `MigrateLocalFilesToSpaces`, `MoveChildrensTalksToPrivateStorage`) | ~1,100 | Four distinct migration flavours + verification, all completed or pending final prod run (plan Phase 9) |
| Legacy import / cutover (one-shot) | `LegacySermonImporter`, `ImportLegacySermonBatchCommand`, `GenerateProdSermonPatchCommand`, `PreacherCutoverCommand` + `PreacherCutoverService` | ~1,410 | Tape-archive CSV import, a hand-rolled SQL-dump parser, preacher backfill |
| Creation / upsert | `SermonCreationService` (+2 in-file enums) | 747 | Richness matrix (enrich/replace/reject), preacher resolution, title heuristics |
| Analysis seam | `SermonAnalysisService` 504, `MockSermonAnalysisService` 463, validator 273, prompt builder 117, interface, DTO | ~1,430 | One-method interface; mock is a heuristic simulator |
| Read path | `SermonRepository` 646, `PodcastFeedService` 166, `SermonExposurePolicy` 256, presenters (`SermonViewPresenter` 309 + assembler 152 + URL builder 123 + formatter 180) | ~1,830 | Repository mixes read model, write helpers, and a hand-rolled cache-invalidation registry |
| Livestream-derived sermon services | `LivestreamSegmentationService` 310, `SermonExtractionPlanResolver` 355, `SermonCandidateConfidenceService` 159, `LivestreamCreateSermonService` 92 | ~920 | Media-pipeline concerns housed in the Sermon namespace |
| Tests (this domain) | ~180 files | ~35,000 | Includes ~2,070 lines pinned to spent one-shot code |

Parallel implementations / duplication observed: two byte-identical RSS Blade templates; four storage path patterns where (post-migration) two suffice; duplicate `EditSermonTest` classes in two namespaces; presenter tests split across Unit and Integration layers; `filetype`-driven legacy branch still live in `SermonStorageService`.

## 4. Findings

### F1. The storage lifecycle is simple; the code around it is not (~2,000 lines for a two-rule read path)

The actual lifecycle, traced end to end: the pipeline writes audio/video once to `sermons/audio|video/{Y}/{m}/{uuid}.ext` on the configured `sermon_disk`; children's talks are relocated under `private/` on the local disk by `MoveSermonToPrivateStorage` (dispatched from `SermonObserver::saved()`); the read side resolves a URL — guarded route if the path starts with `private/`, public disk/CDN URL otherwise. That is two rules.

What exists instead:

- `SermonStorageService` (647 lines) resolves **four** storage patterns (`private`/`legacy`/`storage`/`processing`) in `resolveFileInfo()` ([app/Services/Sermon/SermonStorageService.php:112-161](../../../app/Services/Sermon/SermonStorageService.php)). Post-migration, `legacy` is dead (plan Phase 9: 0 legacy-pattern rows in the 2026-05-29 spot-check) and `storage` vs `processing` is a distinction without a difference — both return the same disk and the same path. It also reads a config key that no longer exists (`media-processing.storage.legacy_disk`, removed from config but still read with a `'public'` fallback at line 75), and carries a public `moveFile()` with **zero production callers** (only its own tests exercise it).
- `SermonStorageMaintenanceService` (655 lines) is four one-shot migration flavours plus verification, each consumed by exactly one command. Plan Phase 9 already marks the migration "mostly migrated" with the runtime fallback awaiting a final production confirmation.
- The commands (`MigrateSermonStorageCommand` 84, `VerifySermonStorageCommand` 105, `MigrateLivestreamAudioFiles` 79, `MigrateLocalFilesToSpacesCommand` 110, `MoveChildrensTalksToPrivateStorage` 64) are thin shells over the maintenance service; `MoveChildrensTalksToPrivateStorage` merely re-dispatches the job the observer already dispatches — a backfill for records that predate the observer.

**Direction (doctrine #6 — prove, promote, retire):** execute plan Phase 9's outstanding production verification, then (a) strip `legacy` and collapse `storage`/`processing` in `resolveFileInfo()` so file info becomes "private → local+guarded, else sermon_disk", (b) delete `SermonStorageMaintenanceService` and its five commands, (c) delete `moveFile()` and the dangling `legacy_disk` read, (d) drop the runtime `filetype` dependency. One service (~350–400 lines: URL building, CDN, cache-busting versions, guarded delivery, metadata cache) then owns the whole lifecycle, with `MoveSermonToPrivateStorage` as its single write-side companion. Tests shrink accordingly (`SermonStorageServiceTest` 528 + `SermonStorageMaintenanceServiceTest` 244 + five command tests ~443 lines).

### F2. The legacy import / cutover path is spent — ~1,410 lines of production code plus ~1,170 of tests riding on completed one-time events

Three independent one-shot tools, all keyed to historical events:

- `LegacySermonImporter` (528) + `ImportLegacySermonBatchCommand` (161): imports MP3s from a "Tape Index" CSV. Already flagged in plan Phase 25, pending maintainer confirmation that the tape digitisation is finished.
- `GenerateProdSermonPatchCommand` (456): parses a **production SQL dump by hand** (its own INSERT-statement tokenizer, string-escape state machine, ~180 lines of parser) to emit a merge patch of local legacy sermons into production. This is the machinery of a one-time local→prod reconciliation. Not tracked in any plan.
- `PreacherCutoverCommand` (81) + `PreacherCutoverService` (182): backfills canonical `Preacher` rows from denormalised sermon preacher strings. The runtime path (`PreacherResolutionService`) has owned this for every sermon created since; the cutover only matters if un-linked legacy rows still exist in production.

**Direction:** confirm each event is complete (see Open Questions), then delete the tools and their tests (`ImportLegacySermonBatchCommandTest` 470, `LegacySermonImporterTest` 362, `GenerateProdSermonPatchCommandTest` 213, `PreacherCutoverCommandTest` 70, `PreacherCutoverServiceTest` 54). If a future tape batch is genuinely expected, keep only the importer pair and still delete the patch and cutover commands. This extends plan Phase 25 rather than replacing it.

### F3. `MockSermonAnalysisService` is a 463-line heuristic simulator, not a mock

The brief called it the heaviest commented-out-code file; in fact it contains no commented-out code — it is worse in a different way. It re-implements sermon analysis with regex heuristics: title extraction patterns, a 39-entry Bible-book table, seasonal series matching, thematic point generation, and summary assembly — including **non-deterministic output** (`mt_rand(1, 100) <= 30` decides whether series is null, [MockSermonAnalysisService.php:213](../../../app/Services/Sermon/MockSermonAnalysisService.php); same for reference at line 273 and a random conclusion sentence at line 439). It then has its own 219-line test suite (`MockSermonAnalysisServiceTest`) — tests verifying the behaviour of a test double.

The doctrine (#2) wants a mock adapter so CI never touches the API. A deterministic stub returning a fixed `SermonAnalysis` (optionally keyed off a transcript marker for the few tests that need variation) satisfies that in ~40 lines. The heuristic body is exactly the kind of multi-step approximation cluster the doctrine replaces with one typed call — here it survives *inside the mock* of that typed call.

**Direction:** replace with a fixture-returning stub; delete `MockSermonAnalysisServiceTest` or reduce it to a single shape assertion. `.env.example` and CI default `ANALYSIS_SERVICE=mock`, so determinism here directly de-flakes the suite. ~600 lines removed.

### F4. `SermonAnalysisService` carries dead public API and vestigial retry scaffolding around an otherwise doctrine-exemplary seam

The seam itself is the pattern the review doctrine codifies: one interface method (`analyzeSermon(transcript, existingSeries, processingId): SermonAnalysis`), an immutable DTO, mock/real bound via `match(config(...))` in `AiServiceProvider`. Good. Around it:

- Four public methods — `generateTitle()`, `identifySeries()`, `extractBiblePassage()`, `extractSermonPoints()` ([SermonAnalysisService.php:404-503](../../../app/Services/Sermon/SermonAnalysisService.php)) — are called **only by tests**. Each re-runs the *entire* analysis to return one field. ~100 lines of dead surface (they are not on the interface, so nothing polymorphic needs them).
- Retry scaffolding without a retry: `runAnalysisAttempt(..., int $attempt)` is always called with `1`, failure logging reports `'total_attempts' => 1`, and an over-long title throws "retrying" without any retry loop (lines 128-203).
- `executeAiRequest()` walks the exception stack trace looking for `Chat.php:35` inside the OpenAI SDK to log a diagnosis (lines 357-365) — debugging residue coupled to a vendor file's line number.
- The April 2026 remaining-work item #2 (thread `processingId` through instead of `'unknown'` defaults) is partially done — the interface now accepts `?string $processingId` — but the `'unknown'` fallback remains at line 72.

**Direction:** trim the service to the interface: delete the four per-field methods, flatten the single-attempt scaffolding, drop the stack-walk. ~150 lines out of 504, with no behaviour change on the live path.

### F5. `SermonRepository` is a read model that has absorbed a second job: write-side utilities and a hand-maintained cache-invalidation registry

Answering the critical-friend question directly: it is **both**. Three responsibilities live in its 646 lines:

1. **Public read model (legitimate core):** `basePublicSermonQuery()` column-limited base query, the listing getters with flexible caching + request memoization, scripture book/chapter filter lookups. This is the read path the whole public site and the semantic-search plan build on. Keep.
2. **Write-side helpers:** `findByDateAndServiceAndContentType()` and `generateUniqueSlug()` exist for `SermonCreationService`'s upsert; `normalizeArchiveFilters()` is Livewire input sanitisation for `BrowseSermons`. These aren't read-model concerns — the creation-side helpers belong with creation (or a small `SermonIdentity` support class), and filter normalisation belongs to the component/`BibleCanon` side.
3. **Cache invalidation registry (~150 lines):** `clearListingCaches()`/`clearScriptureChapterCaches()` reconstruct every cache key that *might* exist — looping current+original preacher IDs × series slugs × Bible books, re-parsing references through `SermonScriptureFilterIndexService`, and re-querying `Preacher` models to rebuild slug keys ([SermonRepository.php:456-610](../../../app/Services/Public/SermonRepository.php)). Every new cached dimension must be mirrored here by hand; a missed combination is a stale-cache bug (the code's own comment inventory of edge cases shows how hard this is to hold).

**Direction:** this is a single-church site where every listing cache already expires in 24h (flexible TTL). Sermon edits are low-frequency (a handful per week). Replace the combinatorial registry with a coarse flush — either a shared key prefix/version stamp ("sermon listings generation N", bump N on any sermon/preacher save) or, on Redis, cache tags. Over-invalidation costs one rebuild of a few cached queries; the registry costs 150 lines and a standing correctness risk. The repository then settles at ~400 lines of genuine read model.

### F6. `SermonCreationService` (747) is mostly earning its keep; its remaining fat is pre-LLM filename heuristics

The richness upsert matrix (Livestream > Video > Audio; enrich/replace/reject with manual-edit preservation, [SermonCreationService.php:34-315](../../../app/Services/Sermon/SermonCreationService.php)) is real business logic that protects operator edits from pipeline re-runs — proportionate and clearly written, including the in-file `RichnessLevel`/`UpsertAction` enums. Two accretion notes:

- ~130 lines of filename-title heuristics (`cleanFilenameForTitle`, `looksLikeFilenameFragment`, date/service fallback assembly) defend against the case where neither ID3 nor AI supplies a title. Since every automated sermon now passes through transcription + analysis, the AI title is nearly always present; the heuristics remain the load-bearing path only for analysis failures. Worth keeping *some* fallback, but it could shrink to "Service label + date" without the fragment-detection regexes.
- `incomingMediaType(MediaProcessingLog $processingLog, SermonCreationOptions $options)` ignores `$options` entirely (line 142) — leftover signature from an earlier fallback.

**Direction:** low priority. Trim the fallback title path and the unused parameter when the file is next touched; no structural change needed.

### F7. Processing-step granularity: answered — it is consumed, keep it (with one caveat)

The critical-friend question was whether `SermonProcessingStep` rows serve operators or are accumulated instrumentation. Prior art already litigated this: the June backlog's "PR 6: Delete `SermonProcessingStep`" was **rescinded** because the church-service review timeline (`ProcessingRunTimelineBuilder` → `ChurchServiceShowPresenter`) renders per-step progress to operators, and `CancellationChecker` uses step boundaries to abort runs between steps. The model itself is lean (106 lines; the April item to move `markAs*()` off the model was completed — transitions live in `SermonProcessingStepTransitions`).

Caveat parked for Phase 1/Phase 8: the *write* side of steps is part of the three-parallel-logging-paths problem flagged in the Phase 1 brief (`ProcessingLogService`, `SermonProcessingLogger`, `app/Logging`), and the model is misnamed for what it does (it logs steps for **all** media runs via `processing_id`, not just sermons). Consolidation belongs to the media-pipeline findings, not here.

### F8. The podcast feed is healthy and small — with one comic duplication

`PodcastFeedService` (166) + `PodcastFeedController` (45) + `config/podcast.php` (91) + typed `PodcastFeedItemReadModel` is proportionate, cached sensibly (flexible cache with explicit invalidation), and column-limited. But `resources/views/rss/morningFeed.blade.php` and `eveningFeed.blade.php` are **byte-identical** (61 lines each — `diff` is empty); the controller `match`es the service enum to choose between two identical templates. All feed-specific text already flows in via `$metadata` from config.

**Direction:** one `rss/feed.blade.php`, delete the `match`. Quick win; also the precondition for cheap feed variants (see Opportunities).

### F9. Namespace boundary: four livestream-pipeline services live in `Services/Sermon`

`LivestreamSegmentationService` (310), `SermonExtractionPlanResolver` (355), `SermonCandidateConfidenceService` (159), and `LivestreamCreateSermonService` (92) orchestrate the *media pipeline's* livestream segmentation/extraction (depending on `VideoSegmentationService`, `ProcessingRunOrchestrator`, `LivestreamSegment`), not the sermon domain's public/admin lifecycle. They were reviewed for content in Phase 1's media-pipeline session context; their location blurs what "the sermons domain" owns and inflates its apparent size by ~920 lines.

**Direction:** move to `App\Services\Media\...` (or a `Processing` namespace) during the Phase 8 consolidation — a `git mv` + namespace edit, following the Phase 22 precedent. No behaviour change.

### F10. `SermonExposurePolicy` branches on `app()->environment('testing')` — the pattern Phase 18 eliminated elsewhere

Three getters short-circuit to fresh `config()` reads when running tests ([SermonExposurePolicy.php:41-46, 237-245](../../../app/Services/Sermon/SermonExposurePolicy.php)) because the constructor memoizes config for performance. Plan Phase 18's exit criterion was "no project code branches on `app()->runningUnitTests()` for logging" — this is the same smell with a different predicate. The fix is structural, not a test hack — but note the policy is **already** registered as a scoped binding (`$this->app->scoped(SermonExposurePolicy::class)`, [AppServiceProvider.php:64](../../../app/Providers/AppServiceProvider.php), pinned by `SingletonRegistrationTest`), so "re-bind it as scoped so tests get fresh instances" is a no-op and would leave the `environment('testing')` branches intact. The actual remaining fix is to **read config lazily** — compute each getter on access with plain per-request memoization — so a test that mutates `config([...])` mid-request sees the new value without a special-cased predicate; where a test genuinely needs a fresh instance, reset the scoped instance explicitly (`app()->forgetInstance(SermonExposurePolicy::class)`) rather than baking the test predicate into production code.

### F11. Tests: heavily covered, with duplication and ~2,300 lines pinned to spent code

This domain owns roughly a quarter of the suite by volume. Coverage of the seams that matter (creation upsert matrix, exposure policy, asset delivery auth, feed output, repository caching) is genuinely good. Disproportion shows in four places:

- **Spent-path tests (~2,070 lines):** the one-shot import/migration/cutover tools of F1/F2 carry `ImportLegacySermonBatchCommandTest` (470), `LegacySermonImporterTest` (362), `SermonStorageMaintenanceServiceTest` (244), `GenerateProdSermonPatchCommandTest` (213), five migration-command tests (~443), `PreacherCutover*` (124), plus the mock's own suite (219). These run on every CI pass to protect code that will never run again.
- **Duplicate test classes:** `tests/Feature/Livewire/Admin/EditSermonTest.php` (562) and `tests/Feature/Livewire/Admin/Sermons/EditSermonTest.php` (218) are two different classes with the same name testing the same component from two namespaces; `ListSermonsTest` has the same split. One home each.
- **Layer-duplicated suites:** `SermonViewPresenterTest` exists in Integration (739) and Unit (278); `SermonValidationServiceTest` in Integration (442) and Unit (316). Some split is deliberate (DB vs pure), but the pairs overlap on shape assertions.
- **Testing test doubles:** `MockSermonAnalysisServiceTest` (219) — see F3.

**Direction:** deletion of spent code (F1/F2) removes the first bucket for free; merge the duplicate-name classes; the layer-duplicated pairs are a Phase 8 roll-up candidate rather than urgent work here.

## 5. Opportunities unlocked

Weighted equally with removals, per the plan.

1. **Semantic sermon search gets a cleaner substrate.** The [semantic-search plan](../../plans/SEMANTIC-SERMON-SEARCH-AND-QA-2026-06-18.md) needs to re-transcribe ~800 sermons with timestamps and chunk transcripts per sermon. Today that backfill must reason about four storage patterns and a `filetype` legacy branch to locate each audio file; after F1 there is exactly one answer to "where is this sermon's audio", making the Phase-1 backfill command of that plan a straightforward loop over `audio_file_path`. The repository cleanup (F5) similarly sharpens the facet pre-filtering surface that plan builds on (`publicBrowseQuery` becomes the single obvious hook for vector pre-filtering).
2. **Richer podcast metadata becomes a template edit, not a project.** With one RSS template (F8) and the already-typed `PodcastFeedItemReadModel`, adding the *remaining* Podcast 2.0 tags is trivial. `<podcast:transcript>` is **already emitted** today — both feeds render it when `$feedItem->transcriptUrl` is present ([morningFeed.blade.php:54-55](../../../resources/views/rss/morningFeed.blade.php), same in `eveningFeed`, covered by `PodcastFeedTranscriptLinkTest`) — so this is a proof point, not a gap: it shows how cheap the next tags are. Genuinely missing and cheap to add: `<podcast:person>` for the preacher, and per-episode chapter marks once section timestamps are exposed. A combined "all sermons" feed or per-series feeds would be a new config entry + the same template.
3. **Faster, safer publication.** Collapsing storage patterns removes the class of stale-metadata bugs around `Cache::rememberForever` on file size/mtime (the podcast enclosure length), and the coarse cache flush (F5) removes the "edited sermon still shows old preacher on some cached page" failure mode entirely — operators see their corrections live within one request instead of depending on the invalidation registry having predicted the right key.
4. **A deterministic mock (F3) makes the whole pipeline testable end-to-end** without flake: CI currently runs analysis-dependent assertions against a mock that randomises whether a series or reference exists at all. Fixture-stubbing turns several "assert not null-ish" tests into exact-value assertions.

## 6. Removal candidates (needs decision)

| # | Candidate | Cost of keeping | Cost/risk of removing |
|---|---|---|---|
| R1 | `SermonStorageMaintenanceService` + `MigrateSermonStorageCommand`, `VerifySermonStorageCommand`, `MigrateLivestreamAudioFiles`, `MigrateLocalFilesToSpacesCommand` + their tests (~1,540 lines) | Four migration flavours maintained and CI-tested forever; storage service must keep serving their pattern queries | None once the plan-Phase-9 production verification passes; keep a git tag if a re-run is ever needed. **Blocked on:** prod verification run |
| R2 | Legacy-pattern branch + `filetype` runtime dependency + dangling `legacy_disk` read in `SermonStorageService` | Every URL resolution carries dead branching; 4-pattern mental model | Low — 0 legacy rows in 2026-05-29 spot-check; needs the same prod confirmation as R1 (this *is* plan Phase 9's remaining work) |
| R3 | `LegacySermonImporter` + `ImportLegacySermonBatchCommand` + tests (~1,520 lines) | One-shot importer stays runnable and tested indefinitely | If more tapes are ever digitised, re-import needs code restored from git. Already plan Phase 25; needs maintainer confirmation |
| R4 | `GenerateProdSermonPatchCommand` + test (669 lines) | A bespoke SQL-dump parser lives in the codebase for a completed one-time merge | None if the local→prod merge is done; the artefact (`sermon-patch.sql`) was the deliverable |
| R5 | `PreacherCutoverCommand` + `PreacherCutoverService` + tests (387 lines) | One-shot backfill maintained; runtime `PreacherResolutionService` already owns the behaviour | None if all prod sermons carry `preacher_id`; verify with one query before deleting |
| R6 | `MoveChildrensTalksToPrivateStorage` command (64 lines) | Backfill duplicate of observer-dispatched behaviour | None once no children's talk remains on public storage (one query to confirm) |
| R7 | `MockSermonAnalysisService` heuristic body + `MockSermonAnalysisServiceTest` (~640 lines net, replaced by ~40-line stub) | Non-deterministic CI behaviour; a maintained shadow implementation of analysis | Low — a handful of tests may assert on its "realistic" output and need fixture updates |
| R8 | `SermonAnalysisService` test-only methods (`generateTitle`, `identifySeries`, `extractBiblePassage`, `extractSermonPoints`, ~100 lines) + `SermonStorageService::moveFile()` (~45 lines) | Dead public surface invites accidental use (each per-field call costs a full OpenAI analysis) | None — only tests call them; delete the covering test methods too |

## 7. Quick wins (each under an hour)

1. Merge `rss/morningFeed.blade.php` + `rss/eveningFeed.blade.php` into one `rss/feed.blade.php`; drop the view `match` in `PodcastFeedController` (F8).
2. Delete `SermonStorageService::moveFile()` and its two test methods (R8).
3. Delete the four per-field methods on `SermonAnalysisService` and their test coverage (R8).
4. Remove the vestigial single-attempt scaffolding and the `Chat.php:35` stack-walk from `SermonAnalysisService` (F4).
5. Drop the unused `$options` parameter from `SermonCreationService::incomingMediaType()` (F6).
6. Remove the `'unknown'` `$processingId` default inside `SermonAnalysisService::analyzeSermon()` — all callers pass one (closes April item #2).
7. Delete the dangling `config('media-processing.storage.legacy_disk', ...)` read *if* R2 is approved; otherwise re-add the key so config and code agree.

## 8. Open questions for the user

1. **Storage migration (gates R1/R2):** has `sermons:verify-storage` been run against production, and did it confirm all files accessible in canonical locations? (Plan Phase 9's unchecked tasks.)
2. **Tape archive (gates R3):** is the historic tape digitisation finished for good, or are more tape batches expected? (Plan Phase 25's open question.)
3. **Prod patch (gates R4):** was the `sermons:generate-prod-patch` output applied to production, and is local→prod database merging ever expected again?
4. **Preacher cutover (gates R5):** do any production sermons still lack `preacher_id`? (One query: `SELECT COUNT(*) FROM sermons WHERE preacher_id IS NULL`.)
5. **Children's talks backfill (gates R6):** do any production children's talks still have non-`private/` audio paths?
6. **Operator behaviour (informs F7's caveat and Phase 1):** when a processing run fails, do you act on the *individual step* shown in the timeline, or just retry/cancel the run? This calibrates how much per-step persistence the pipeline needs.
7. **Podcast ambitions (weights Opportunity 2):** is there appetite for Podcast 2.0 features (transcripts in-feed, chapters, person tags) or additional feeds? Cheap after the template merge, but only worth queueing if someone wants them.

## 9. Out of scope, noted for later phases

- **Phase 1 (media pipeline):** the step-logging write path (`SermonProcessingLogger` vs `ProcessingLogService` vs `app/Logging`) and `SermonProcessingStep`'s sermon-specific naming for an all-media concern (F7 caveat); `ChildrensTalkSpeakerService` / `ResemblyzerSpeakerIdentificationService` (speaker-ID stack).
- **Phase 5 (public read path):** the sermon presenter constellation (`SermonViewPresenter` + `SermonPresentationAssembler` + `SermonUrlBuilder` + `SermonContentFormatter`, ~760 lines across four files after the Phase 14/22 decompositions) — assess as part of the one-presentation-convention question; `tests/Feature/Repositories/SermonRepositoryTest.php` still lives under a `Repositories` namespace that no longer exists in `app/`.
- **Phase 7 (platform/config):** `config/sermons.php` is one key in its own file — fold into the config-sprawl consolidation; the Mock* family review should include the F3 stub precedent.
- **Phase 8 (roll-up):** F9 namespace moves; duplicate/overlapping test classes (F11) as part of the suite-wide test-architecture roll-up.
- **Phase 9 (code quality):** `SermonExposurePolicy`'s `environment('testing')` branches (F10) if not fixed earlier; enums co-located in `SermonCreationService.php`; `SermonRepository::getExistingSeries()`'s broad `catch (\Exception)` that converts DB failures into silent empty lists.
