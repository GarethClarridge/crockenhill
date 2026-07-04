# Songs Domain — Simplification Review (Phase 4)

Reviewed 2026-07-02, per `docs/plans/JULY-2026-SIMPLIFICATION-REVIEW-PLAN-2026-07-02.md`. Light session, as planned. No code changes; findings only.

Note on prior art: `docs/reviews/july-2026-simplification/` contained no Phase 1–3 findings docs on this branch at review time (they presumably live on unmerged branches), so cross-references to Phases 1–3 point at the plan briefs rather than their findings. Where this doc leans on a Phase 1/2 outcome (heuristic-path retirement), that dependency is stated explicitly.

## 1. Scope reviewed

**Services** — `app/Services/Song/` (11 files, ~2,740 lines) and `app/Services/Song/Sync/` (4 files, ~920 lines):

| File | Lines | Role |
|---|---|---|
| `LegacyPlayDateSongUsageImporter.php` | 425 | One-off backfill of song usage from a legacy SQL dump |
| `Sync/LegacySongReconciler.php` | 419 | Matches OpenLP import groups against pre-import legacy song rows |
| `SongCatalogSyncService.php` | 410 | OpenLP SQLite → canonical catalogue sync coordinator |
| `OpenLpServiceParser.php` | 401 | Parses uploaded `.osz` order-of-service archives |
| `Sync/SongAuthorBookSyncer.php` | 315 | Author/book upserts and pivot links for the sync |
| `SongClusteringService.php` | 281 | Clusters visual-analysis samples into song periods (media pipeline) |
| `OpenLpLyricsParser.php` | 247 | OpenLP lyrics XML → plain text with verse ordering |
| `SongLyricOcrService.php` | 212 | OCRs projected lyrics from video frames via OpenAI vision |
| `SongTitleHintExtractor.php` | 202 | Regex extraction of announced song titles from transcripts |
| `SongLyricsMatchingService.php` | 196 | Canonical-key + fuzzy lyrics matching against the catalogue |
| `SongVideoService.php` | 183 | Song video lifecycle (upload, extraction, feature, delete) |
| `SongLyricSnippetBuilder.php` | 159 | Search-result lyric snippets with `<mark>` highlighting |
| `Sync/OpenLpSongSourceReader.php` | 122 | Reads the OpenLP songs SQLite file |
| `UnmatchedSongReviewApplicator.php` | 70 | Flags unmatched song sections for manual review |
| `Sync/OpenLpRowValue.php` | 61 | Row-value coercion helpers |

**Models**: `Song` (259), `SongVideo` (148), `SongAuthor` (114), `SongBook` (104). **Livewire**: `Admin/ChurchServices/ListSongs` (154), `Admin/ChurchServices/ShowSong` (144), `Church/Songs/BrowseSongs` (137). **Public read path**: `PublicSongCatalogService` (191), `PublicSongUsageService` (152), `PublicSongListController` (71). **Commands**: `SyncSongsCommand` (60), `ImportLegacySongUsageCommand` (55), `LinkSongsCommand` (48). **Job**: `MatchSongsFromTranscript` (583). **Data objects**: `SongCluster`/`SongClusterCollection`/`SongClusterCollectionCast` (203 combined), `SongCatalogSyncReport` (34), `OpenLpSongSourceData` (29). **Adjacent (Phase 2 scope, read for the duplication question)**: `ChurchServiceSongLinker` (170), `SongSectionAligner` (549). **Routes**: admin `services/songs[/{song}]`, public `church/songs[/{song:slug}]`. **Tests**: 44 files, ~9,250 lines.

## 2. What this area is for

The church runs OpenLP for projection. This domain (a) mirrors the OpenLP song database into a canonical catalogue (`songs` + authors + books), (b) links each service's order-of-service items to catalogue songs, (c) identifies which songs were *actually sung* in livestream recordings — via title hints in announcements, OCR of projected lyrics, and transcribed song openings — and (d) publishes the result: a public "songs we sing" catalogue with lyric search, per-song usage history ("last sung", per-year counts), and extracted per-song performance videos, plus admin equivalents.

What operators get: members can find a song's lyrics and watch a recent performance; the admin can see usage frequency (useful for CCLI reporting and service planning); song videos are curated (feature/unfeature/delete).

## 3. Complexity inventory

- **~5,000 lines of domain production code** (services + models + Livewire + public read path + commands + job), **~9,250 lines of tests** — a 1.8:1 test:code ratio.
- **Two legacy one-off/transition artefacts**: `LegacyPlayDateSongUsageImporter` (425) and `LegacySongReconciler` (419) — together 844 lines, ~19% of the service layer. Only the importer is in the trackers; the reconciler is newly identified here and is *not* tracked (see Finding 4).
- **The song-usage eligibility query exists four times** (Finding 2).
- **Five distinct song-matching mechanisms** across two domains (Finding 5).
- **One misfiled subsystem**: `SongClusteringService` + the `SongCluster*` Data trio + the `media_processing_logs.song_clusters` column belong to the media-pipeline heuristic path, not the songs domain (Finding 1).
- **11 config keys** under `media-processing.song_matching`, 5 of them for local-Whisper transcription of 30-second song openings alone.
- **Legacy schema residue**: `play_date` table and `songs.praise_number`/`songs.alternative_title` columns still in `database/schema/mysql-schema.sql`, read only by the two legacy artefacts.
- Duplicate test files: `PublicSongUsageServiceTest` in both `tests/Feature/Services/` (215 lines) and `tests/Integration/Services/` (383); `SongLyricSnippetBuilderTest` in both `tests/Unit/` (166) and `tests/Unit/Services/` (146).

## 4. Findings

### F1. "Song clustering" is not usage analytics — it is heuristic-pipeline residue misfiled into the songs domain

The critical-friend question "is song clustering / usage analytics consumed?" conflates two unrelated things, and the namespace is why. `SongClusteringService` has nothing to do with the song catalogue: it smooths/groups/merges *visual-analysis samples* from livestream frames into candidate song periods. Its only consumer chain is `PerformVisualAnalysis` (app/Jobs/PerformVisualAnalysis.php:156) → `media_processing_logs.song_clusters` → `AnalyzeSegments` (app/Jobs/AnalyzeSegments.php:296) — the RMS/visual heuristic segmentation stack that the LLM-first structure pipeline (`SERVICE_STRUCTURE_MODE`, default `off` in `config/media-processing.php:254`) is designed to replace.

**Direction** (doctrine 6, "promoted but not retired" hunting): when Phase 2's retirement of the heuristic path lands, `SongClusteringService`, `SongCluster`, `SongClusterCollection`, `SongClusterCollectionCast`, the `song_clusters` column, and `SongClusteringServiceTest`/`SongClusterDataTest` all go with it (~550 lines + a JSON column the rollup query already has to exclude for size, per `app/Queries/ChurchServiceRollupQuery.php:27`). Until then, no action here — but the Phase 8 backlog should list this deletion under the heuristic-retirement dependency, and it should be counted as *media-pipeline* scope, not songs scope.

**Gate carefully — flipping to primary mode is not the trigger.** This producer/consumer chain is *not* gated on `SERVICE_STRUCTURE_MODE`: `ProcessingPipelineBuilder::buildLivestreamParallelJobs()` dispatches `PerformVisualAnalysis` whenever `media-processing.visual_analysis.enabled` is true (the config default), regardless of mode, and the primary livestream chain retains `AnalyzeSegments` — which reads `media_processing_logs.song_clusters` (`app/Jobs/AnalyzeSegments.php:296`). So under a primary-mode run today the column is still written and read. The concrete precondition for this deletion is therefore removing the visual-analysis producer/consumer path itself — mode-gate/disable `PerformVisualAnalysis` and stop `AnalyzeSegments` consuming `song_clusters` (media-pipeline review F1/R3, quick win 6) — **before** dropping the service, the DTO/cast, or the column. If this note is copied into the retirement backlog without that precondition, deleting these would break livestream processing rather than merely retiring a dark heuristic.

### F2. Song *usage* analytics is genuinely consumed — but the eligibility query is written four times

Usage data drives real pages: the public catalogue (`BrowseSongs` → `PublicSongCatalogService`), the public song detail page (`PublicSongListController::show` → `PublicSongUsageService::statsForSong`/`usageHistoryForSong`), and both admin screens (usage counts, last-used dates, per-year histogram in `ShowSong`). This earns its keep.

But the "which service items count as usage" query is independently implemented in:

1. `PublicSongCatalogService::qualifyingUsageSubquery()` (~40 lines, includes the "Phase 6.1 policy" livestream-confirmation eligibility rule, 3-year "recent" range);
2. `PublicSongUsageService::baseQualifyingUsageItemsQuery()` (~40 lines, same Phase 6.1 policy duplicated verbatim, calendar-year range);
3. `Admin ListSongs::usageBaseQuery()` (no eligibility policy, service/date filters);
4. `Admin ShowSong::usageBaseQuery()` (no eligibility policy).

The two public copies must stay in lockstep — the Phase 6.1 comment block is copy-pasted — and the admin/public divergence (policy vs no policy) is undocumented: it may be intentional (admin sees raw data) but nothing says so.

**Direction** (doctrine 4, one seam): one `SongUsageQuery` (or scope set on `ChurchServiceItem`) owning the join, the soft-delete/type filters, the eligibility policy as an explicit toggle, and the range windows. All four consumers become thin. This also collapses `PublicSongUsageService` and `PublicSongCatalogService` into one obvious home.

### F3. `PublicSongUsageService::query()` appears to be dead production code

Only `statsForSong()` and `usageHistoryForSong()` are called from production code (`PublicSongListController::show`). The `query()` listing builder — nearly the same shape as `PublicSongCatalogService::query()` minus search — is exercised only by its tests (`tests/Feature/Services/PublicSongUsageServiceTest.php`, `tests/Integration/Services/PublicSongUsageServiceTest.php`). It looks like the pre-search predecessor of the catalogue service that was superseded but not retired (doctrine 6). Fold the two surviving methods into the consolidated usage seam from F2 and delete `query()` plus its duplicated test coverage.

### F4. The legacy importer and reconciler are transition artefacts; the trackers already know about one of them

- **`LegacyPlayDateSongUsageImporter` + `ImportLegacySongUsageCommand`** (~480 lines + 2 test files): backfills usage from a SQL dump whose default path (`prod-20260326.sql`) does not exist in the repo. `docs/plans/SIMPLIFICATION-PLAN.md` (lines ~330–350) already carries the tasks: confirm the historic import is complete, delete the pair + tests, and drop the `play_date` table. **Status unchanged since 2026-06-03** — this review re-confirms the code is untouched and the dump absent. Nothing new to discover; it needs the maintainer decision, not more analysis.
- **`LegacySongReconciler`** (419 lines + 1 test) is subtler and *not* in the trackers. It runs on **every** catalogue sync, but only does work while songs with missing/placeholder canonical keys (`legacy-song-%`) exist. Once the pre-import rows have all been claimed (a one-time convergence), every future sync carries 419 lines of two-phase, bidirectional-uniqueness matching that always short-circuits on an empty legacy set. Its `Schema::hasColumn()` probes span three columns (LegacySongReconciler.php:187–196), but only two are legacy — **do not conflate them.** `praise_number` and `alternative_title` are genuine 2016-era transition residue: `alternative_title` comes from the `2016_02_20_163912_add_alternative_title_to_songs_table` migration (both columns survive only in the squashed baseline, `database/schema/mysql-schema.sql:921,928`) and are read nowhere but this matcher. `alternate_title` (note the one-letter difference — `alternate_`, not `alternative_`) is **not** legacy: it is the live catalogue column, defined by the modern `create_songs_table` migration, exposed on `Song` (fillable + validation), and selected/searched by both the public catalogue (`PublicSongCatalogService.php:44,114`) and the admin listing (`ListSongs.php:94,104`) — it must stay. If production has zero unreconciled legacy rows, the reconciler, the `reconciledSongId` thread through `SongCatalogSyncService`, the schema probes, and eventually the two *legacy* columns — `praise_number` and `alternative_title` only — can all go.

### F5. Song matching is five mechanisms layered by accretion; the LLM-first world needs two

Current inventory of "which song is this?" logic:

| Mechanism | Where | Approach |
|---|---|---|
| Canonical key (+ O/Oh variants) | `Song::canonicalizeKey`/`matchKeyVariants` | Deterministic normalisation — the shared foundation |
| OoS item → catalogue linking | `ChurchServiceSongLinker` (170) | Canonical-key lookup over item titles |
| Legacy reconciliation | `LegacySongReconciler` (419) | Praise-number + title matching with its **own** title normaliser, distinct from `canonicalizeKey` |
| Transcript/OCR → catalogue | `SongLyricsMatchingService` (196) | Canonical key on first line, then `similar_text` over sliding windows across **every** song's full lyrics in PHP |
| Announcement mining | `SongTitleHintExtractor` (202) | Nine hand-tuned regexes over speech transcripts, feeding the above |

Plus the orchestration: `MatchSongsFromTranscript` (583 lines) runs title-hint → OCR → opening-transcription strategies in sequence, then branches on `ServiceStructureMode` for post-processing. And on the Phase 2 side, `SongSectionAligner` (549) does positional OoS↔section alignment with its own introduction-cue word lists.

This answers the critical-friend question directly: yes, the matching logic here overlaps the Phase 2 alignment work, and the overlap is exactly the kind of multi-step heuristic cluster doctrine 1 targets. A single typed call — "given this OCR text / opening transcript / announcement snippet and the catalogue's titles + first lines, which song is this?" — behind a narrow contract (mock/local/real adapters, doctrine 2) would replace the regex farm, the fuzzy windowing, and the O/Oh special-casing, and would very likely match better (misheard titles, paraphrased announcements, OCR noise). What survives as deterministic bedrock: `canonicalizeKey` for exact linking (`ChurchServiceSongLinker` stays trivial), and the validator-style confidence gate around the probabilistic call (doctrine 5).

**Direction**: don't build this inside Phase 4 — it should be one workstream with Phase 2's retirement plan, since the consumers (`MatchSongsFromTranscript`, aligners, `UnmatchedSongReviewApplicator`) are shared. Flag for Phase 8 sequencing: *one song matcher* is gated on *structure LLM-first promotion*.

### F6. `MatchSongsFromTranscript` is a mini-pipeline with configurability nobody plausibly configures

The job carries three matching strategies, per-strategy enable flags, a dual-path postlude (primary vs heuristic mode), and a local-Whisper sub-configuration of five keys (`config/media-processing.php:303–316`) used only to transcribe 30-second song openings in local dev. That last block exists so local runs avoid API costs — legitimate — but it re-implements service selection inline (`transcribeSongOpening()`, 27 lines of config plumbing) instead of using the existing `TranscriptionServiceInterface` binding, which is already environment-switched. Critical-friend: has anyone ever set `SONG_MATCHING_OPENING_SECONDS`, `SONG_MATCHING_LOCAL_WHISPER_TRANSCRIPTION_PATH`, or `SONG_MATCHING_LYRICS_THRESHOLD` away from default? Each unused knob is a branch to test and reason about. The F5 consolidation naturally shrinks this job to: gather evidence → one matcher call → apply; most of the config dies with it.

### F7. The OpenLP sync boundary is in decent shape — prior backlog item effectively done — but the dry-run path is a parallel implementation

`SongCatalogSyncService` was 879 lines when `docs/architecture/simplification-backlog.md` PR 23 flagged it; it is now 410 with responsibilities split into `Sync/` collaborators and a clear coordinator docblock. **PR 23 should be marked done/superseded.** `OpenLpServiceParser` (zip-bomb guards, date inference) and `OpenLpLyricsParser` (verse ordering with warnings) are single-purpose and proportionate.

One residue: dry-run is a *second pipeline* — `previewSync()` + `previewAuthorUpserts()` + `previewBookUpserts()` (~150 lines) mirror the apply path with identity ID-maps so the metrics come out the same. `LegacyPlayDateSongUsageImporter` demonstrates the simpler idiom in the same domain: run the real path inside a transaction and roll back (LegacyPlayDateSongUsageImporter.php:50–63). Adopting it would delete the entire preview mirror and remove the risk of the two paths drifting.

### F8. `SongLyricOcrService` calls OpenAI directly instead of going through an adapter

Transcription has `TranscriptionServiceInterface`; service structure has `Mock/OpenAi` adapters bound in a provider. Lyric OCR calls the `OpenAI` facade inline (SongLyricOcrService.php:151) with protected-method seams for tests. This is the one external-API call in the domain not behind a swappable contract (doctrine 2). Low urgency; if F5's single matcher happens, OCR becomes evidence-gathering for it and should ride the same adapter pattern then.

### F9. Tests: solid coverage with pockets of duplication and integrity-test overlap

~9,250 test lines against ~5,000 production lines. Proportionality is mostly fine — the sync, reconciler, and matching services are the risky seams and are well covered. Specific excess:

- **Straight duplicates**: `SongLyricSnippetBuilderTest` exists in `tests/Unit/` (166 lines) *and* `tests/Unit/Services/` (146) — same class under test, overlapping cases, both run in CI. `PublicSongUsageServiceTest` exists in `tests/Feature/Services/` (215) *and* `tests/Integration/Services/` (383), largely covering the possibly-dead `query()` (F3).
- **Integrity-test sprawl**: song schema/model invariants are asserted across `Feature/Warden/` (3 files), `Feature/DataIntegrity/` (2), `Feature/Models/` (2), `Feature/Database/SongCatalogSchemaTest`, and `Integration/Models/` (4) — five directories for one concern. Some (slug regex validation) are re-tested in multiple layers. A suite-wide pattern for Phase 8's roll-up rather than a songs-only fix.

## 5. Opportunities unlocked

1. **One usage seam → usage features get cheap** (F2). The per-year histogram already built for admin `ShowSong` becomes a one-liner on the public song page. "Songs we haven't sung in over a year" / "most sung this quarter" lists for service planning are single queries once eligibility lives in one place. This is the concrete answer to the brief's "usage-informed song suggestions" — the data is already there; only the query fragmentation makes each new view cost a re-implementation.
2. **One LLM matcher → better "songs we sang" data** (F5). Higher match rates on OCR/transcript evidence mean fewer `unmatched_song_section` review flags, less manual admin review, and more complete public usage history — the pages in F2 get better for free. It also unlocks planned-vs-actually-sung comparison per service, which the current per-strategy pipeline can't express cleanly.
3. **Transaction-rollback dry-run → sync changes get safer to make** (F7). Deleting the preview mirror means future sync-behaviour changes are written once, not twice, and dry-run is guaranteed truthful.

## 6. Removal candidates (needs decision)

| Candidate | Cost of keeping | Cost/risk of removing |
|---|---|---|
| `LegacyPlayDateSongUsageImporter` + command + 2 test files (~700 lines) | Dead one-off carried indefinitely; already tracked in `SIMPLIFICATION-PLAN.md` | None practical — the dump file isn't in the repo; re-import would need re-supplying it. Historical data already lives in `church_service_items.metadata.legacy_*` |
| `LegacySongReconciler` + `reconciledSongId` path in `SongCatalogSyncService` + schema probes + test (~500 lines) | 419 lines re-evaluated on every sync for a one-time convergence; its own parallel title normaliser | **Gated on a prod check**: zero songs with null, blank, or `legacy-song-%` canonical keys (mirror the reconciler's own predicate — see Q2). If any remain, reconcile them first (or accept manual fix-up) |
| `songs.praise_number`, `songs.alternative_title` columns + `play_date` table | Legacy columns only the reconciler/importer read | Drop after the two rows above are decided; migration is trivial |
| `SongClusteringService` + `SongCluster*` Data trio + `song_clusters` column (~550 lines) | Misfiled heuristic-path code; a bulky JSON column queries must dodge | **Gated on Phase 2 heuristic retirement** — still load-bearing while visual analysis runs |
| `PublicSongUsageService::query()` (+ merge remaining two methods per F2) | A second, unsearchable catalogue listing nobody renders | Verify no consumer (grep found none); tests deleted with it |
| Duplicate test files (F9: snippet builder ×2, usage service ×2) | CI time, confusion about which is canonical | Diff, keep the superset, delete the other |

## 7. Quick wins

1. Delete the older of the two `SongLyricSnippetBuilderTest` files after a superset check (~15 min).
2. Mark backlog item **PR 23** (`SongCatalogSyncService` split) done in `docs/architecture/simplification-backlog.md`, referencing the `Sync/` extraction (~5 min).
3. Add the F1 note (song-clusters deletion set) to the Phase 2 retirement dependency list so it isn't rediscovered (~5 min).
4. Delete `PublicSongUsageService::query()` and its Feature-test duplicate once the no-consumer check is confirmed (~30 min).

## 8. Open questions for the user

1. **Is the OpenLP catalogue sync a routine operation?** Does someone periodically re-export the OpenLP SQLite and run `service-tracking:sync-songs`, or was it effectively a one-time import? (Determines how much the sync path's ongoing ergonomics matter, and how urgent F7 is.)
2. **Are there songs in production with null, blank, or `legacy-song-%` canonical keys?** One query decides whether `LegacySongReconciler` is spent (F4) — but it must mirror the reconciler's own predicate, which `fetchLegacySongsForReconciliation()` defines as null **and** empty-string **and** `legacy-song-%` keys (omitting the blank-key case returns 0 while legacy rows still exist, wrongly green-lighting deletion). E.g. `Song::withTrashed()->where(fn ($q) => $q->whereNull('canonical_key')->orWhere('canonical_key', '')->orWhere('canonical_key', 'like', 'legacy-song-%'))->count()`.
3. **Confirm (again) that the play_date backfill is complete** — the `SIMPLIFICATION-PLAN.md` task has awaited this since May.
4. **Do operators actually use** the admin ListSongs service/date-range filters, and the featured-video curation on `ShowSong`? Both are small, but if unused they simplify the admin surface (Phase 6 will want this answer too).
5. **Has anyone ever tuned the `song_matching` env knobs** (opening seconds, lyrics threshold, OCR model)? If not, F6's config can shrink to `enabled` + the local-dev switch.

## 9. Out of scope, noted for other phases

- **Phase 2 / Phase 8**: the dual-path branch in `MatchSongsFromTranscript::handle()` (`ServiceStructureMode::Primary` vs heuristic re-alignment) and `UnmatchedSongReviewApplicator`'s role as the shared review-flag applier are part of the heuristic-retirement decision. The "one song matcher" consolidation (F5) should be sequenced there.
- **Phase 5**: SEO presentation for songs is split across `PublicSongListController::index` (computes title/description/canonical via `SongArchiveSeoPresenter`) *and* `BrowseSongs` (recomputes the same three via the same presenter, plus a `seo-title-updated` browser event) — a live example of the presentation-layer sprawl Phase 5 owns.
- **Phase 7**: `play_date` table and legacy song columns in the schema dump (also listed in §6 since the decision is song-domain); the `Mock*` family membership of `MockServiceStructureService`.
- **Phase 9 (line-level, not egregious)**: `Song::displayVideo()` building an ad-hoc `hasOne` query at call time; `BrowseSongs` `mixed`-typed URL-bound properties; `SongLyricsMatchingService` loading every song's full lyrics into PHP per match call (fine at this catalogue size, worth a note if the catalogue grows).
- **Suite-wide (Phase 8 roll-up)**: the five-directory integrity-test convention (F9) is not songs-specific.
