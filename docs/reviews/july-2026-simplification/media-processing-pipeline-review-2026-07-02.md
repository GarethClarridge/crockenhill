# Media Processing Pipeline — Simplification Review (Phase 1)

Date: 2026-07-02. Phase 1 of the July 2026 simplification review, following the doctrine, ground
rules, and template in
[JULY-2026-SIMPLIFICATION-REVIEW-PLAN-2026-07-02.md](../../archived-plans/JULY-2026-SIMPLIFICATION-REVIEW-PLAN-2026-07-02.md).
Findings only — no code changes. Removals are flagged, never decided; sign-off happens in Phase 8.

Prior art checked and referenced rather than re-derived:
[SIMPLIFICATION-PLAN.md](../../archived-plans/SIMPLIFICATION-PLAN.md) (Phases 9, 14, 25),
[simplification-backlog.md](../../architecture/simplification-backlog.md) (PRs 6, 16–19, 21, parking lot),
`media-processing-architecture-and-observability-review-2026-04-16.md` (deleted 2026-07-05 — in git history),
`thumbnail-system-redesign-review-2026-04-01.md` (deleted 2026-07-05 — in git history),
[TECHNICAL-DEBT-REMEDIATION-2026-06-18.md](../../archived-plans/TECHNICAL-DEBT-REMEDIATION-2026-06-18.md),
`2026-07-01-dead-sermon-validation-service-audit.md` (folded into `docs/issues/README.md`, source deleted),
and the exemplar plan
[LLM-FIRST-SERVICE-STRUCTURE-PIPELINE-2026-07-01.md](../../archived-plans/LLM-FIRST-SERVICE-STRUCTURE-PIPELINE-2026-07-01.md).

---

## 1. Scope reviewed

- `app/Services/Media/` — `Audio/` (16 files, ~3,900 lines), `Video/` (10 files, ~4,900 lines),
  `Thumbnail/` (6 files, ~2,200 lines), `TempDiskSpace.php`.
- `app/Services/Processing/` — 18 files, ~6,700 lines (orchestrator, pipeline builder, phase
  registry, transitions, loggers, metadata/validation/identity services).
- `app/Jobs/` — 37 files, ~9,200 lines (the pipeline chains; the church-service classification jobs
  are inventoried here but their retirement map belongs to Phase 2).
- `app/Logging/SermonProcessingLogFormatter.php` (99 lines).
- `app/Services/Preacher/` speaker-identification stack + `app/Services/Sermon/LivestreamSegmentationService.php`
  (livestream upload entry, lives outside `Media/` but is this domain's front door).
- Config: `config/media-processing.php` (426 lines), `config/thumbnail-generation.php` (42 lines),
  the `sermon-processing`/`performance` channels in `config/logging.php`.
- Models: `MediaProcessingLog` (722), `LivestreamSegment` (307), `SermonProcessingStep` (106),
  `SpeakerProfile` (126), `SpeakerSample` (107).
- Routes: the unified `/api/media/{type}` upload endpoint and the five processing-management routes
  in `routes/api.php`.
- Tests: ~180 of the suite's 729 test files match this domain's class names (~38,000 lines under
  the narrower media filters).

Total in-scope production code: **~27,000 lines** — comfortably the largest subsystem in the app.

## 2. What this area is for

An operator uploads a recording — a raw livestream of a whole service, a pre-trimmed sermon video,
or a bare audio file — and the church gets, without further effort: a published sermon (audio,
video, transcript, AI summary/title/scripture references), a branded thumbnail, per-section records
of the service (songs, readings, children's talk) feeding the church-service pages, and an email
telling the operator it worked or that something needs review. The pipeline exists so that one
volunteer's Sunday-afternoon upload replaces what used to be hours of manual editing, tagging, and
publishing.

Everything in this domain should be judged against that outcome: the operator's decisions are
(1) upload, (2) occasionally confirm a sermon segment or approve a section for publication,
(3) rarely, retry or cancel a run.

## 3. Complexity inventory

| Cluster | Lines (approx) | Notes |
|---|---:|---|
| Heuristic segmentation/visual stack (`VisualAnalysisService` 881, `VideoSegmentationService` 757, `RmsAnalysisService` 482, `AnalyzeSegments` 647, `PerformVisualAnalysis` 326, `GenerateRmsLog` 158, `FrameQualityScorer`/`FrameExtraction` shared) | ~3,300 | The pre-LLM understanding layer |
| Orchestration (`ProcessingPhaseRegistry` 1,097, `ProcessingPipelineBuilder` 322, `ProcessingRunOrchestrator` 428, transition/failure/reset services ~650) | ~2,500 | Four hand-maintained phase tables; see F3 |
| Observability (`SermonProcessingLogger` 645, `ProcessingLogService` 468, `SermonProcessingLogFormatter` 99, `SermonProcessingStepTransitions` 150, `SermonProcessingStep` model 106) | ~1,470 | Four parallel paths; see F2 |
| Thumbnails (`ThumbnailGenerationService` 804, `ThumbnailCanvasComposer` 954, text helper 196, foreground extraction 151, `PixianClient` 124, `FrameExtractionService` 240, `FrameQualityScorer` 118, `GenerateThumbnail` job 307) | ~2,900 | Multi-candidate, 3 canvas styles, external background-removal API, admin picker |
| Speaker identification (`IdentifySpeaker` 326, `ResemblyzerSpeakerIdentificationService` 327, null impl 44, `ChildrensTalkSpeakerService` 313, two models 233, bootstrap command 240, data objects, python script) | ~1,700 | Dark by default (`enabled=false`, `provider=null`, `mode=shadow`); see F6 |
| Transcription (two interfaces × three implementations each, `AudioChunkingService` 437, `TranscriptStorageService` 308, `TranscriptFormatterService` 188) | ~2,600 | Parallel per-sermon and full-service families; see F5 |
| One-shot import (`HistoricVideoImporter` 1,141 + `ImportHistoricVideoBatchCommand`) | ~1,400 | Single consumer is the command; see R1 |
| Confirmed dead (`SermonValidationService` 380, `UpdateSermonRecord` job 268, dead logger half ~300, `ProcessingReport`, formatter 99) | ~1,100 | See F4 |

Parallel implementations found: 4 × "compress audio for transcription", 2 × `getVideoMetadata`,
2 × transcription interface families, 4 × observability paths, 2 × offset-resolution strategies
inside one registry.

## 4. Findings

### F1 — The RMS/visual stack is exactly the heuristic cluster the LLM path replaces, but the current retirement plan leaves most of it alive

The critical-friend question "do RMS and visual analysis survive an LLM-first world?" has a
concrete answer in the wiring:

- `ProcessingPipelineBuilder::buildLivestreamParallelJobs()` (`app/Services/Processing/ProcessingPipelineBuilder.php:128`)
  dispatches `PerformVisualAnalysis` **regardless of `service_structure.mode`** — visual song
  detection runs even in primary mode, where nothing downstream consumes song clusters *for
  classification* (the LLM owns section types and song titles; `MatchSongsFromTranscript` prefers
  `song_title_hint`). But note the clusters are still consumed for *segmentation* —
  `AnalyzeSegments::getVisualClusters()` reads `song_clusters` and, when present, runs
  `analyzeWithVisualGuidance()` instead of RMS-only segmentation (`AnalyzeSegments.php:67-82`); the
  resulting `LivestreamSegment` boundaries are what `ServiceStructure` resolves `source_segment_ids`
  against by time overlap (`ServiceStructure.php:130-166`). So the producer is not consumer-free in
  primary mode — see the risk note on quick win 6.
- In primary mode `AnalyzeSegments` (647 lines) is retained partly so `LivestreamSegment` rows
  exist to back `source_segment_ids` and the manual segment-confirmation flow — and for that role
  the Phase 3 mapper can already **synthesise** a covering segment when none overlaps
  (`synthesised_from_structure`). But it is **not** retained *solely* for segment rows, and the
  builder runs it *before* `TranscribeFullService`/`DetectServiceStructure` in the primary chain
  (`buildLivestreamChainJobs()`: `AnalyzeSegments` → `TranscribeFullService` →
  `DetectServiceStructure`, `ProcessingPipelineBuilder.php:150-159`), so two pre-LLM
  responsibilities have to be re-homed before it can be dropped:
  (a) it is a **gate** — `handle()` calls `findSermonCandidate()` and `markAsFailed()`s the whole
  run ("No sermon candidate found…") when the longest speech segment misses the minimum duration
  (`AnalyzeSegments.php:108-143`), so primary-mode uploads with no heuristic candidate fail here
  before the LLM ever sees the transcript; and
  (b) it writes `sermon_start_time`/`sermon_end_time` on the run (`AnalyzeSegments.php:115-119`),
  which `SermonExtractionPlanResolver::baselinePlan()` reads as the extraction fallback
  (`SermonExtractionPlanResolver.php:261-262`). Any deletion plan built only around
  `ServiceStructure::synthesiseCoveringSegment()` must also replace this failure gate and the
  baseline time source (e.g. derive both from the LLM structure) or primary-mode runs with a weak
  heuristic candidate break. The job also drags the whole visual-guided machinery with it:
  per-song threshold calibration (`VideoSegmentationService::calibratePerSongThreshold`),
  cluster boundary detection (`detectBoundariesForCluster`), visual/RMS merge logic
  (`AnalyzeSegments::mergeVisualAndRmsSongSegments`), and gap-filling.
- What genuinely survives an LLM-first world is small: `GenerateRmsLog` + the silence-parsing
  half of `RmsAnalysisService` (the exemplar plan keeps them for boundary snapping via
  `SilenceSnapService`), and a way to show speech blocks in the manual-review UI.

**Direction (doctrine rule 6 — actually retire the old path):** when Phase 6 of the LLM plan is
executed, the deletion list should include the media-side heuristics, not just the church-service
classifiers: `VisualAnalysisService` (881), the visual/cluster half of `VideoSegmentationService`
(~400 of 757), `PerformVisualAnalysis` (326), `ExportVisualMetricsCommand`, the visual-merge half
of `AnalyzeSegments` (~350 of 647), and the `song_clusters`/`visual_confidence`/
`visual_sample_count`/`calibration_method` columns and their `LivestreamSegment` model surface.
Interim step available now: mode-gate `PerformVisualAnalysis` out of the primary chain the way the
classification cluster already is — but this is *not* byte-identical for primary runs and must be
treated as a segmentation migration, not a free deletion. Removing the `song_clusters` producer
makes `AnalyzeSegments` fall back to RMS-only segmentation in primary mode, which changes the
`LivestreamSegment` boundaries and therefore the `source_segment_ids` that `DetectServiceStructure`
maps LLM sections onto (and the speech blocks shown in manual review). The interim change must
either accept and characterise that shift (the Phase 3 mapper can already `synthesise` a covering
segment, so the practical impact may be small — but it needs a test), or remove/replace the
visual-guided branch in `AnalyzeSegments` in the same change. Off/shadow mode stay byte-identical
regardless. This is still the single largest simplification in the codebase (~2,000+ lines plus
tests) and it is already sanctioned in spirit by the exemplar plan — it just isn't on that plan's
Phase 6 deletion list, which names only church-service jobs and services.

*Cross-phase note:* the heuristic classification jobs themselves (`ClassifyServiceSections`,
`ClassifySpeechSections`, `TranscribeSpeechSegments`, `AlignWithOos`, `ResolveReadingReferences`,
`ReclassifyIntroOutroSections` — ~2,000 job lines in `app/Jobs/`) are Phase 2's retirement map;
this finding covers only the media-side stack beneath them.

### F2 — Observability runs four parallel paths; two are load-bearing, one is fragile, one is dead

1. **`SermonProcessingStep` table** (via `SermonProcessingStepTransitions` and `ProcessingJob`'s
   `logStepStart/Complete/Failed/Skipped`) — durable, structured, powers the admin timeline.
   Load-bearing (backlog PR 6 already concluded this).
2. **Log-line writing** — `SermonProcessingLogger` plus plain `Log::` calls. The logger's live
   surface is `logProcessingStep` (34 call sites), `logApiCall` (14), `logError` (6),
   `logFileOperation` (2). Its other **seven public methods have zero production callers**:
   `logProcessingStart`, `logProcessingComplete`, `logPerformanceMetrics`, `logHealthCheck`,
   `generateProcessingStatistics`, `generateProcessingReport`, `getRecentProcessingActivity` —
   roughly 300 lines including a private log-file parser, kept alive by
   `SermonProcessingLoggerTest` (636 lines), `SermonProcessingLoggerSecurityTest` (calls
   `logProcessingStart`/`logProcessingComplete`/`logHealthCheck`), and one assertion in
   `LivestreamProcessingIntegrationTest`. `App\Data\ProcessingReport` exists only for the dead
   report method and is pinned directly by `ProcessingReportTest`. The parking-lot verdict that
   logger and log service are "complementary writer/reader" misses that the writer contains its own
   second reader, and both are unused.
3. **Log-line re-parsing** — `ProcessingLogService` (468 lines) streams the whole of
   `storage/logs/laravel.log` on every logs-included status request
   (`getResolvedLogPath()`/`parseLogsFromFile()`), regex-parses lines back into
   `ProcessingLogEntry` objects for `ProcessingLogsViewer` and the status API. This is the April
   review's P1 diagnosis still standing: operator diagnostics reconstructed from a rotating text
   file. Since April, the run record gained `queue_name`/`job_id`/`attempt_count`
   (`ProcessingJob::captureQueueCorrelation`) — the structured alternative exists and is populated.
4. **A likely-dead log channel** — `config/logging.php` defines a `sermon-processing` daily channel
   with the 99-line `SermonProcessingLogFormatter` tap, but **no code explicitly writes to it**:
   there is no `Log::channel('sermon-processing')` call anywhere in `app/`. One caveat before
   deleting: the default channel is `env('LOG_CHANNEL', 'stack')` (`config/logging.php:18`) and the
   `stack` channel lists only `['single']` (`:35-38`), so the *only* way plain `Log::…` calls reach
   this formatter is a production `LOG_CHANNEL=sermon-processing`. That would route the entire app's
   logs through it — an unusual setting — but it must be checked against the production/staging env
   before removal, since the absence of an explicit `Log::channel()` call doesn't prove the channel
   is unreachable. (The backlog's PR 7 note "not dead: registered as tap" is true but vacuous — the
   channel it taps has no explicit writer.)

**Direction (one seam):** the durable pair — `SermonProcessingStep` + `processing_metadata` +
queue-correlation columns — becomes the *only* operator-facing read path. Delete the dead logger
half and `ProcessingReport`; delete the `sermon-processing` channel and formatter; then retire
`ProcessingLogService` by pointing `ProcessingLogsViewer`/status-with-logs at steps + metadata
(the one UI consumer is the only real migration work). Plain `Log::` lines remain for developer
debugging, freed from the constraint of being machine-re-parseable.

### F3 — `ProcessingPhaseRegistry` is 1,100 lines because it is four hand-maintained tables, with two different consistency mechanisms and a ghost phase

The registry is almost entirely declarative data — four pipeline tables mapping step strings →
progress % → retry offsets. Its size is not algorithmic complexity, but the data encodes three
kinds of accumulated fragility:

- **Two offset-resolution strategies.** The livestream table anchors phases to job classes and
  resolves offsets against the chain the builder actually produces
  (`livestreamJobOffset()` → `ProcessingPipelineBuilder::livestreamChainJobClasses()`), so a mode
  change can never point retries at the wrong job. The audio/video/auto-trim tables still carry
  **hard-coded integer offsets** (`'job_offset' => 4`) that silently break if anyone reorders
  `buildAudioPipeline()`. The safe mechanism already exists in the same file; three tables don't
  use it.
- **Step-name aliasing.** `ProcessingStep` has 60 cases; phases carry alias lists like
  `sermon_creation` / `creating_sermon` / `creating_sermon_record` / `sermon_record_created` and
  `transcription` / `transcribing_audio` / `transcription_completed` — historical drift in what
  jobs write, preserved forever in lookup tables instead of normalised at the write site.
- **A ghost phase.** The audio table's `update_sermon_record` phase maps steps that only the
  orphaned `UpdateSermonRecord` job ever wrote (see F4).

**How many phases does it really need?** One per resumable job per pipeline — which is exactly
what anchoring to job classes expresses. Hand-tuned progress percentages could be derived from
chain position (offset ÷ chain length, with a fixed tail reserve), collapsing most of the table.

**Direction:** extend the anchor-job pattern to all four pipelines; derive progress from chain
position; normalise step names at the write site and delete the alias lists. The registry then
shrinks to the genuinely irregular data — rerun strategies and reset scopes — and "add a new
processing output" becomes a one-line chain edit (see O3).

### F4 — Confirmed dead code (grep-verified this session)

| Item | Size | Evidence |
|---|---:|---|
| `SermonValidationService` | 380 + 758 test lines | Zero production callers; full audit already exists at `docs/issues/2026-07-01-dead-sermon-validation-service-audit.md`. The stale comment naming it survives at `config/media-processing.php:60`. |
| `UpdateSermonRecord` job | 268 + 2 dedicated test files | No dispatch site anywhere in `app/`; referenced only by tests, the registry's ghost phase, and two `ProcessingStep` enum cases (`updating_sermon_record`, `updating_sermon_record_failed`). Beyond the two dedicated files, seven more test files instantiate the job or pin its step strings — see quick win 2 for the full fixture list. |
| Dead half of `SermonProcessingLogger` + `App\Data\ProcessingReport` | ~350 | Seven zero-caller public methods (F2); `ProcessingReport` referenced only by the dead method. |
| `sermon-processing` log channel + `SermonProcessingLogFormatter` | 99 + config | No explicit `Log::channel('sermon-processing')` call exists (F2). **Verify prod `LOG_CHANNEL` first** — it is the only way plain `Log::` calls could still reach this formatter. |
| `VideoStorageService` orphan methods (`cleanupExpiredFiles`, `storeTemporary`, `moveToPermanent`, `getAudioUrl`) | ~80 | Zero callers outside the class. |
| `VideoExtractionService::extractOptimizedAudioFromSegment` | ~20 | Self-described `@deprecated` alias. |

Roughly 1,200 production lines plus ~1,900 test lines deletable with no behaviour change.

### F5 — Duplicated media utilities: four audio-compression paths and two transcription families

- **"Compress/optimise audio for transcription" exists four times**:
  `AudioChunkingService::compressAudioForTranscription` (used by both transcription families),
  `AudioCompressionService::extractOptimizedAudio` (used via `VideoExtractionService`),
  `AudioExtractionService::compressForTranscription`, and `AudioCompressionService`'s internal
  fallback re-compression. All four express the same ffmpeg intent (mono, 16 kHz, low bitrate,
  under the 25 MB Whisper cap) with separately maintained parameters —
  `config('media-processing.audio_extraction.*')` exists precisely to be that single source but
  only some paths read it.
- **Two transcription interface families**, three implementations each:
  `TranscriptionServiceInterface` (`AudioTranscriptionService` 561 / `LocalWhisperTranscriptionService`
  492 / mock) for extracted sermon audio, and `ServiceTranscriptionInterface`
  (`OpenAiServiceTranscriptionService` / `LocalWhisperServiceTranscriptionService` / mock) for the
  timestamped full-service pass. Both families share chunking via `AudioChunkingService`, but
  retry/backoff, size-guard, and API-call logging logic is written twice. This duplication is a
  deliberate bridge artifact of the LLM plan — fine for now, but it should not survive promotion
  (see O2, which would let one family absorb the other).
- Smaller doubles: `getVideoMetadata` implemented in both `VideoSegmentationService` and
  `FrameExtractionService`; `VideoStorageService` re-exports three `VideoExtractionService`
  methods as pass-throughs.

**Direction:** one ffmpeg audio-preparation helper owning the transcription-target profile; after
promotion, one transcription interface. The pass-through wrappers go when their callers are
touched anyway.

### F6 — Speaker identification: ~1,700 lines of scaffold for a feature that defaults to off, in shadow, with a null provider

The stack: `IdentifySpeaker` job (326, five gates), `ResemblyzerSpeakerIdentificationService`
(327, shells out to a Python embedding script), `NullSpeakerIdentificationService`,
`SpeakerProfile`/`SpeakerSample` models, `BootstrapSpeakerProfilesCommand` (240),
`ChildrensTalkSpeakerService` (313), data objects, config block, a dedicated queue, and ten test
files. Defaults: `SPEAKER_IDENTIFICATION_ENABLED=false`, `provider=null`, `mode=shadow` — three
independent switches all of which must be flipped before it does anything.

**What operator decision does it inform?** In `enforce` mode it auto-fills the preacher on a
sermon (or assigns "Visiting Speaker" + review flag); in `shadow` it writes a confidence score
nobody surfaces. The same datum arrives today from ID3 tags (gate 3 skips when present), from the
OoS import, and from the operator's existing edit screen. The children's-talk path
(`ChildrensTalkSpeakerService`) is the more recent motivation (backlog PR 19 deferred
simplification for its sake).

**Critical-friend verdict:** complexity is not currently proportionate — it's a voice-biometrics
subsystem (Python interop, embeddings, per-provider model versioning, sample curation UI in
`EditPreacher`) guarding a dropdown the operator rarely gets wrong, and it has an LLM-shaped
alternative: the full-service transcript that primary mode already produces contains the service
leader *introducing the speaker by name* more Sundays than not; a field in the existing
`DetectServiceStructure` schema (`speaker_name` per sermon/talk section) would deliver the same
assignment with zero new infrastructure (see O4). Decision needed (R2): promote it to enforce and
keep it, or delete the stack and add the transcript field.

### F7 — Thumbnail subsystem: ~2,900 lines is high for thumbnails, but it is live, operator-facing, and stable — audit the styles, don't rebuild it

`ThumbnailCanvasComposer` (954) is deterministic layout mathematics: three canvas styles
(main/card/centered), font fitting, line wrapping, foreground placement over brand colours. The
generation service (804, down from 1,442 at the April redesign review) orchestrates 5-candidate
extraction, `FrameQualityScorer` ranking, Pixian background removal, and admin candidate
selection (`EditSermonThumbnails`, `SermonThumbnailCandidateController`). This *is* proportionate
to a real outcome — branded thumbnails on every sermon page and podcast/social surfaces with an
operator override — and the June technical-debt review's "do not refactor stable complexity"
verdict (1–3 commits/90d) is a fair counterweight to its size.

Two genuine questions remain. The first is bounded to **`main` vs `centered`**, not all three
styles: the `card` layout is *live* — `ThumbnailGenerationService` calls
`ThumbnailCanvasComposer::buildCardThumbnailCanvas()` unconditionally for every generated thumbnail
(`ThumbnailGenerationService.php:557`), stores `card_thumbnail_path` (`:189,:491`), and sermon card
surfaces serve it. Only `main` is a candidate for "no consumer" (config default is `centered`); if
`main` has no live surface, a smaller slice of composer lines and their tests go. Deleting `card`
would break card thumbnails, so any audit must scope to `main` or first plan a replacement for the
card variant. The second question is whether the **Pixian** paid API's foreground cut-out visibly
earns its keep on the finished thumbnails. Both are operator/product questions, not code questions —
parked in §8. Test weight is also top-heavy here: 17 test files including a `Performance` suite
(see §"Tests" below).

### F8 — April's upload-boundary findings are materially resolved; record and close them

Worth stating so Phase 8 doesn't re-litigate: the April P1 dedupe finding is fixed —
`UnifiedMediaProcessor` now builds a scoped `dedup_key` (hash + media type + video mode + owner)
backed by a unique constraint, with the race handled via `UniqueConstraintViolationException`
reuse. The P1 correlation finding is half-fixed: `queue_name`/`job_id`/`attempt_count` persist on
the run. The remaining April residue is the P2 item — `SermonAnalysisService` and
`AudioTranscriptionService::transcribe(..., $processingId = 'unknown')` still default their run
context — a Phase 9 line-level fix, noted in §9.

## 5. Opportunities unlocked

Weighted equally with removals, per the plan.

- **O1 — Content-aware chaptering and highlights for free.** Once the visual/RMS song-detection
  stack is retired (F1) and structure comes from the one typed LLM call, the same
  `ServiceStructure` payload can carry chapter markers, per-section summaries, or highlight
  candidates as *schema fields*, not new services. The current stack can never do this — it knows
  loudness and slide-colour, not content.
- **O2 — One Whisper pass per service.** In primary mode the pipeline transcribes the whole
  service (`TranscribeFullService`) and then re-transcribes the extracted sermon audio
  (`TranscribeAudio`). The full-service transcript already has cue-level timestamps and a
  `sliceText()` method; slicing it for the sermon transcript would halve transcription cost and
  latency, delete the second transcription family (F5), and make the public transcript available
  minutes after upload instead of after extraction+enhancement+re-transcription. (The exemplar
  plan deferred this as a quality decision — it belongs on the Phase 8 backlog as the follow-up.)
- **O3 — New processing outputs become one-line changes.** With the registry anchored to job
  classes and progress derived from chain position (F3), adding e.g. a "generate captions" or
  "publish chapter markers" job is: write the job, insert it in one builder list. Today it also
  means hand-editing up to four phase tables, inventing step strings, and updating offsets.
- **O4 — Speaker attribution from the transcript.** A `speaker_name` field in the
  `DetectServiceStructure` JSON schema (validated against the preacher/alias tables the way song
  titles are validated against the song catalogue) replaces the embedding stack's core value and
  works retroactively on every archived transcript (F6/R2).
- **O5 — Operator diagnostics that survive log rotation.** Collapsing observability onto
  steps+metadata (F2) makes the processing timeline complete and queryable (per-step durations,
  attempt counts, failure reasons) without a text-file parse, and unblocks small wins like a
  "slowest step this month" admin panel — currently impossible once `laravel.log` rotates.

## 6. Removal candidates (needs decision)

| # | Candidate | Cost of keeping | Cost/risk of removing |
|---|---|---|---|
| R1 | `HistoricVideoImporter` (1,141) + `ImportHistoricVideoBatchCommand` + 2 test files (~1,500 lines total) | Largest file in the domain, maintained and PHPStan-checked forever for a one-shot import (275 GB drive, plan archived 2026-06). Same category as the Phase 25 legacy importers. | If the drive import is unfinished or may re-run, deletion forces a git-archaeology restore. Zero runtime risk — nothing else references it. |
| R2 | Speaker-identification stack (~1,700 lines: job, two providers, two models, bootstrap command, python script, config, queue; `ChildrensTalkSpeakerService` assessed separately since publication flows call it) | A dark voice-biometrics subsystem with three enable switches; PR 19 already deferred it once. Every pipeline run pays a no-op job dispatch. | If children's-talk speaker naming is imminent and voice-matching genuinely outperforms transcript inference, deleting now means rebuilding. **`ChildrensTalkSpeakerService` is a hard transitive blocker on part of this stack:** it is injected live by `SermonPublicationHandler`, `ApproveSectionForPublication`, and `SaveServiceSection`, and it depends on the `SpeakerIdentificationInterface` binding (constructor), the `media-processing.speaker_identification.*` config keys, and the `SpeakerProfile` model/table (`ChildrensTalkSpeakerService.php:19-20,137-203`). So the "two providers, two models, config" cannot all be swept in the same commit while this service is kept — the interface binding, that config block, and `SpeakerProfile` must survive, or children's-talk speaker resolution must be migrated to O4/transcript metadata *first*. Mitigation: O4 covers the mainline case; models/tables dropped last, after the children's-talk migration. |
| R3 | Visual song-detection stack (~2,000+ lines, F1) | Runs on every livestream in all modes; its output is unused *for classification* in primary mode; it is precisely the heuristic cluster the doctrine says to retire. | **Not behaviour-free even in primary mode** (see F1): `song_clusters` is still consumed for *segmentation* — `AnalyzeSegments::getVisualClusters()` switches to visual-guided segmentation when clusters exist, and those `LivestreamSegment` boundaries back `source_segment_ids`. So this is a segmentation migration, not a free deletion. Gated on LLM promotion (Phase 6 of the exemplar plan). Until `mode=primary` soaks, it is the authoritative path — interim mitigation is mode-gating `PerformVisualAnalysis`, which must characterise the boundary shift (F1). |
| R4 | `ProcessingLogService` + log-parsing read path (468 lines + `ProcessingLogEntry`/`ProcessingLogCollection` + viewer wiring) | Fragile whole-file log parse per status request; duplicated step-extraction regexes; breaks silently on log rotation/format change. | Needs the one consumer (`ProcessingLogsViewer` / status-with-logs API) re-pointed at `SermonProcessingStep` + metadata first — small but real UI work. |
| R5 | Dead code batch (F4): `SermonValidationService`, `UpdateSermonRecord`, dead logger half + `ProcessingReport`, `sermon-processing` channel + formatter, `VideoStorageService` orphans (~1,200 + ~1,900 test lines) | Pure carrying cost; misleads readers (ghost registry phase, stale config comment). | Effectively none for every item **except** the `sermon-processing` channel + formatter: those are only "dead" if production isn't running `LOG_CHANNEL=sermon-processing` (F2/F4 caveat — the channel needs no explicit `Log::channel()` call to be selected). Do the §8 Q5 env check before deleting the channel; the rest is grep-verified zero-caller. Needs only the standing approval to delete tests with their subjects. |

## 7. Quick wins (under an hour each, low risk)

1. Delete `SermonValidationService` + its two test files + the stale comment at
   `config/media-processing.php:60` (pre-audited in the 2026-07-01 mortician doc).
2. Delete `UpdateSermonRecord` job, its two dedicated test files, the `update_sermon_record` phase
   in `ProcessingPhaseRegistry::audioPhases()`, and the two orphaned `ProcessingStep` cases. The
   same commit must also sweep the seven other test files that instantiate the job or pin its step
   strings, or the suite fails / keeps stale coverage: `SermonProcessingJobChainTest` (imports the
   job, drives it via a helper, pins `updating_sermon_record` in five places),
   `SermonProcessingErrorHandlingTest` (instantiates the job), `UnifiedMediaProcessorTest`
   (instantiates it in three fake chains), `ProcessingPhaseRegistryTest` (asserts the ghost phase
   and its progress value), `StandardProcessingResponseTest`, `MediaProcessingStatusTransitionsTest`
   (two data-provider rows), and `CompletionOutcomePreservationTest` (fixture `current_step`).
3. Delete the seven dead `SermonProcessingLogger` methods, `App\Data\ProcessingReport`, and the
   corresponding test sections. The same commit must sweep every fixture that pins the deleted
   surface, or the suite fails: the dead-method sections of `SermonProcessingLoggerTest`, the
   whole of `SermonProcessingLoggerSecurityTest` (built entirely around `logProcessingStart`/
   `logProcessingComplete`/`logHealthCheck`), `ProcessingReportTest` (instantiates
   `App\Data\ProcessingReport`), and the `LivestreamProcessingIntegrationTest` assertion.
4. Delete the `sermon-processing` channel from `config/logging.php` and
   `app/Logging/SermonProcessingLogFormatter.php` (after confirming production `LOG_CHANNEL`, §8 Q5).
5. Delete `VideoStorageService::{cleanupExpiredFiles, storeTemporary, moveToPermanent, getAudioUrl}`
   and the `@deprecated` `VideoExtractionService::extractOptimizedAudioFromSegment` alias. The same
   commit must update or delete the fixtures that exercise those surfaces, or the suite fails:
   `VideoStorageServiceTest` (calls `cleanupExpiredFiles()` and `storeTemporary()`),
   `VideoStorageServiceCompressionTest` (calls `extractOptimizedAudioFromSegment()`), and the
   `LivestreamAudioCompressionTest` assertion that the alias `method_exists`.
6. Mode-gate `PerformVisualAnalysis` in `buildLivestreamParallelJobs()` so primary mode stops
   paying for visual analysis it doesn't consume *for classification* (keeps off/shadow
   byte-identical). **Not zero-risk for primary, and not a one-hour win as stated above:** the
   clusters are still consumed for *segmentation*, so dropping the producer flips `AnalyzeSegments`
   to RMS-only, changing `LivestreamSegment` boundaries and the `source_segment_ids` the LLM sections
   map onto (F1). Land it with a characterisation test on primary-mode segments, or remove the
   `AnalyzeSegments` visual branch in the same change — do not treat it as a pure no-op deletion.

## 8. Open questions for the user

1. **Speaker identification** — has `SPEAKER_IDENTIFICATION_ENABLED=true` ever been set in
   production, and are there curated `SpeakerProfile`/`SpeakerSample` rows there? Is
   children's-talk speaker naming still a near-term goal? (Decides R2.)
2. **Historic video import** — is the 275 GB drive import finished for good? (Decides R1; same
   sign-off batch as SIMPLIFICATION-PLAN Phase 25.)
3. **Thumbnail styles** — production uses `THUMBNAIL_STYLE=centered`? Is the `main` composer style
   rendered anywhere operators or visitors actually see? (`card` is already known-live — it is built
   unconditionally and served as `card_thumbnail_path`, so it is *not* in scope for removal.) And
   does the Pixian foreground cut-out visibly earn its per-image cost? (Scopes F7.)
4. **Processing logs viewer** — do you actually open `ProcessingLogsViewer` when a run misbehaves,
   or is the church-service timeline (steps table) the working surface? (Decides how much R4 must
   preserve.)
5. **Production `LOG_CHANNEL`** — confirm nothing sets it to `sermon-processing` (quick win 4).
6. **LLM rollout state** — where is `SERVICE_STRUCTURE_MODE` in production today (off/shadow), and
   is there an expected promotion date? R3 and O1/O2 sequencing hang off this.

## 9. Out of scope, noted for later phases

- **Phase 2 (church service structure):** the retirement dependency map for the heuristic
  classification jobs that live in `app/Jobs/` (`ClassifyServiceSections`, `ClassifySpeechSections`,
  `TranscribeSpeechSegments`, `AlignWithOos`, `ResolveReadingReferences`,
  `ReclassifyIntroOutroSections`, ~2,000 lines) and their service dependencies; whether
  `ProjectLivestreamServiceStructure` can shrink once LLM-primary is the only writer. F1's
  media-side deletions should land in the same Phase 6 batch Phase 2 plans.
- **Phase 3 (sermons):** `SermonMetadataIntegrationService` (554 lines here but half of it is
  sermon read-model helpers — `getVideoInfo`/`getVideoPreviewData` belong with the storage-service
  consolidation), `MoveSermonToPrivateStorage`, `MockSermonAnalysisService`, transcript storage
  (`TranscriptStorageService` multi-disk read fallbacks tie into the Phase 9 legacy-storage
  migration).
- **Phase 7 (platform/ops):** `ExportVisualMetricsCommand` and `BootstrapSpeakerProfilesCommand`
  in the spent-command sweep (both fall out automatically if R2/R3 proceed); the unused
  `performance` log channel; Horizon queue inventory including the `sermon-processing` and
  `speaker-identification` queues; `.env.example` keys orphaned by any removals here.
- **Phase 9 (code quality):** `processingId = 'unknown'` defaults in
  `AudioTranscriptionService`/`SermonAnalysisService` (April P2 residue); `PerformVisualAnalysis`'s
  `sleep(2)` file-wait loop; step-name normalisation idioms; `AnalyzeSegments` duplicating
  `RmsAnalysisService`'s pts_time parsing in `getTotalDurationFromLog()`.

### Tests (proportionality assessment, per ground rules)

Coverage of the orchestration seams is exactly where it should be:
`ProcessingPipelineBuilderModeTest` pins every mode's job list, `ProcessingPhaseRegistryTest`,
transition/failure-handler/orchestrator tests, and the upload-dedupe feature tests all guard real
contracts. Three disproportions stand out. (1) **~1,900 lines of tests pin dead code** —
`SermonValidationServiceTest` ×2, the dead-method sections of `SermonProcessingLoggerTest` (636
lines), `UpdateSermonRecordTest`/`UpdateSermonRecordFinalizerTest` — these are not safety nets but
preservatives, and they go with their subjects (R5). (2) **Thumbnails carry 17 test files**
including pixel-level composer assertions and a `Performance` suite, heavier per line of subject
than any other cluster; if the style audit (F7) trims composer styles, trim the matrix with it.
(3) **The heuristic stack's tests are the future cost of R3** — `VisualAnalysisServiceTest` was
hardened as recently as PR #1059; further investment there should stop pending the promotion
decision, since the eval harness (`structure:evaluate`) is the designated regression suite for the
replacement path.
