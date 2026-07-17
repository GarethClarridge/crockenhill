# July 2026 Simplification Backlog

Created 2026-07-05 as the Phase 8 wrap-up of the July 2026 simplification review
(`docs/archived-plans/JULY-2026-SIMPLIFICATION-REVIEW-PLAN-2026-07-02.md` — archived; its Phase 9
session brief is the one part still live). This document consolidates the seven per-domain
findings docs into one prioritised implementation backlog, records the removal sign-offs, and
supersedes the overlapping items in the older trackers. It is the successor in style to
`docs/archived-plans/APRIL-2026-REVIEW-BACKLOG-2026-04-16.md`.

**This is the single active tracker.** See `docs/plans/README.md` for how the remaining standalone
plans sequence around it, and `docs/issues/README.md` for the open audit-issue register (its
plan-shaped items were folded into items 2.1 and 2.6 on 2026-07-05).

Phase 9 (the technical code-quality review) remains gated on this backlog's structural work
substantially landing; run it from the brief in the archived review plan.

## Review inputs

- `docs/reviews/july-2026-simplification/media-processing-pipeline-review-2026-07-02.md` (Phase 1)
- `docs/reviews/july-2026-simplification/church-service-structure-review-2026-07-02.md` (Phase 2)
- `docs/reviews/july-2026-simplification/sermons-domain-review-2026-07-02.md` (Phase 3)
- `docs/reviews/july-2026-simplification/songs-domain-review-2026-07-02.md` (Phase 4)
- `docs/reviews/july-2026-simplification/public-site-read-path-review-2026-07-02.md` (Phase 5)
- `docs/reviews/july-2026-simplification/admin-livewire-review-2026-07-03.md` (Phase 6)
- `docs/reviews/july-2026-simplification/platform-operations-review-2026-07-05.md` (Phase 7)
- Active trackers reconciled below: `SIMPLIFICATION-PLAN.md` (archived to `docs/archived-plans/`
  2026-07-05), `docs/architecture/simplification-backlog.md`
- The exemplar plan this programme extends:
  `docs/archived-plans/LLM-FIRST-SERVICE-STRUCTURE-PIPELINE-2026-07-01.md` (archived; phases 1–5
  complete, phase 6 "promote and retire" superseded by Workstream 1 here)

## Tracker reconciliation (supersessions)

This backlog is now the single active simplification tracker. Disposition of every open item in the
two older trackers:

### `docs/archived-plans/SIMPLIFICATION-PLAN.md`

| Item | Disposition |
|---|---|
| Phase 9 (legacy storage migration/cleanup) | **Superseded by item 2.3 here** — same work, extended with the full storage-service collapse from the sermons review (F1) |
| Phase 13 (schema hygiene) | Complete; the follow-on squash is item 6.1 here |
| Phase 14 (hotspot decomposition) | **Closed with revised direction.** `ThumbnailGenerationService`: keep as-is (media F7 — stable, operator-facing). `SermonViewPresenter` cluster: direction *reversed* — the Phase 14 decomposition produced a 7-file memoization architecture the Phase 5 review found disproportionate; item 3.3 collapses it. Transcription/audio services: absorbed into items 1.7a/1.7b |
| Phase 25 (legacy one-shot importers) | **Superseded by item 2.4 here** (same candidates plus newly found siblings) |
| All other phases | Already complete |

`SIMPLIFICATION-PLAN.md` was archived to `docs/archived-plans/` on 2026-07-05 (items 2.3 and 2.4
here are self-contained, so nothing waits on it).

### `docs/architecture/simplification-backlog.md`

| Item | Disposition |
|---|---|
| PR 4 (unused authorization code: `@can` gates → `is_admin`, `MeetingPolicy`) | **Carried over** as item 4.5 here — still open, not covered by the July reviews |
| PR 19 (speaker identification always-on) | **Closed as moot (decision D3)** — the feature is already enabled and working in production; the stack is kept |
| PR 20 (slim Sermon model) | Parked to Phase 9 — model is 578 lines and no July finding pushed it |
| PR 23 (`SongCatalogSyncService` split) | **Done** — songs review F7 confirms 879 → 410 lines via `Sync/` collaborators |
| PR 24 (separate anomaly detection from `ServiceSectionClassifier`) | **Superseded** — the classifier is on the item 1.5 deletion list |
| Parking lot: `SermonValidationService` split | **Superseded** — Phase 1 confirmed the whole service is dead (zero production callers); it is deleted outright in item 2.1, not split |
| Parking lot: `SermonJobPipelineService` split, Alpine.js duplication check, `spatie/laravel-data` replacement | Parked to Phase 9 |

Archive `simplification-backlog.md` once item 4.5 lands.

## Prioritisation principles

1. The LLM-first retirement programme is the spine: it is the single largest simplification
   (~20,000+ lines across three domains) and it *gates* the biggest consolidations. Its
   operational steps (shadow soak) start immediately so the calendar time runs concurrently with
   everything else.
2. Grep-verified dead code and spent one-shot tooling go first among the code changes — zero
   behaviour risk, immediate CI and comprehension payoff, and they shrink every later diff.
3. Opportunities unlocked carry equal weight with removals: items 1.7a (one Whisper pass), 3.1
   (one presentation convention), 5.2 (one-call email parsing), and 4.1 (upload consolidation) are
   scheduled on their merit as improvements, not as afterthoughts of deletions.
4. Deletions follow the reference-before-referent rule: every commit lands green; a class is
   deleted only after its last consumer (builder branch, registry row, test, script) went in the
   same or an earlier commit.
5. Tests are deleted with their subjects in the same PR — never orphaned, never kept as
   preservatives.
6. Production data checks gate irreversible deletions; each gated item lists its check. Git tags
   recorded in PR descriptions are the re-run escape hatch for one-shot tooling.

## Quality gates (every item)

- `vendor/bin/sail artisan test --compact --parallel` (focused paths per PR; full suite before merge)
- `vendor/bin/sail composer phpstan` — stays at 0 errors
- `vendor/bin/sail bin pint --dirty`
- `vendor/bin/sail artisan dusk` for anything touching public routes or the upload form

## Implementation protocol (read before picking up any item)

Every item below carries a tag:

- **[mechanical]** — executable as written *plus its cited review sections*. The text here is a
  summary; the file-level and line-level payload lives in the cited findings doc. Read those
  sections in full before writing anything.
- **[design]** — the item states constraints and end-state, not construction. Write a short
  implementation plan first (fresh session, plan mode) and get it approved before coding. Do not
  implement design items straight from this document: several execute months from now, after the
  soak, and the surrounding code will have drifted — a plan written just-in-time against the live
  code beats a spec written today.
- **[operational]** — a production config/verification action, not a code change.

Rules for every item, regardless of tag:

1. Read the cited review sections in full, then **verify each claim against the current code**
   before acting — the reviews are a 2026-07 snapshot, not ground truth.
2. Honour every "Do not start until" pre-condition verbatim; they encode the traps the reviews
   found. A failed production-check gate **blocks** that deletion — never proceed past one.
3. Delete tests only in the same commit as their subject — never separately, never keep them.
4. Run the quality gates above per PR; in deletion sequences every commit must be independently
   green (references before referents).
5. **Stop rule:** if the code does not match the review's description of it, stop and flag the
   discrepancy instead of improvising around it.

## Decision log

Decisions taken 2026-07-05 in the Phase 8 walkthrough. Production-check gates remain even where a
removal is approved: approval means "delete once the listed check passes".

| # | Candidate (source) | Recommendation | Decision |
|---|---|---|---|
| D1 | Heuristic classification cluster + media visual stack + song-cluster residue (church R1, media R3, songs F1/G-R4) | Accept, per the exemplar plan, gated on shadow soak + the five seams | **Approved — full retirement** |
| D2 | Production `SERVICE_STRUCTURE_MODE` status (sequencing fact, not a removal) | — | **Confirmed `off` as of 2026-07-05 — enable shadow now** (top of delivery order) |
| D3 | Speaker-identification stack + `EditPreacher` profile UI (media R2, admin R4); replace with transcript `speaker_name` field (media O4) | Accept removal via O4; migrate children's-talk resolution first | **Rejected — keep the stack.** The review's dark-by-default premise is wrong for production: the feature is enabled and has been working in prod for a while. Stack, `EditPreacher` profile UI, and `BootstrapSpeakerProfilesCommand` all stay. `speaker_name` (O4) noted as a possible future consolidation only if the stack becomes a burden — not scheduled |
| D4 | Operator diagnostics collapse: `ProcessingLogsViewer` + `ProcessingLogService` log-parsing path → steps + metadata (media R4/F2, admin R1) | Accept — steps timeline becomes the one read path | **Approved — delete both** (UI re-pointed at steps+metadata first) |
| D5 | Cross-domain grep-verified dead-code batch (media R5, public 6.1, songs R5/R6, sermons R7/R8, church R5) | Accept all (two env/row checks listed as gates) | **Approved in full** incl. the deterministic-stub mock replacement |
| D6 | Staged structure-merge workflow (church R2, ~950 lines) | Defer — re-measure after promotion; collapse then if rarely used | **Deferred** — measure fire-rate and operator choices through the primary soak, then decide |
| D7 | Canonical-conflict granular state (church R3: 2 enums, 6 columns, dual storage) | Accept collapse to `needs_review` + one reason string (+ JSON history as audit trail) | **Approved** |
| D8 | Regex date/service extraction in `OosEmailParserService` → one typed LLM call (church R4) | Accept | **Approved** |
| D9 | Spent one-shot tooling sweep — storage-migration commands (sermons R1/R2) | Accept, gated on prod `sermons:verify-storage` | **Approved (gate stands)** |
| D10 | Spent one-shot tooling sweep — legacy importers/cutover/backfills (sermons R3–R6, songs G-R1–R3 incl. `play_date` + legacy columns) | Accept, gated on per-item prod checks | **Approved (gates stand)** |
| D11 | `HistoricVideoImporter` + command (media R1) | Accept, gated on "drive import finished" confirmation | **Approved (gate stands)** |
| D12 | Eight platform one-shot commands (platform P1) | Accept, gated on per-command prod confirmations | **Approved (gates stand)** |
| D13 | Public read path: presentation convention (6.2/4.1), TTL caching (4.2), presenter memo machinery (6.6), sitemap enrichment (6.3) | Accept all four | **Approved — all four** |
| D14 | Meetings/calendar: recurrence fields (6.4), Google write-back (6.5), `CalendarAdminController` (6.8), duplicate Page conversions (6.7) | Accept all four (6.4 depends on operator answer) | **Approved — all four** (operator confirms no reliance on hand-entered recurrence) |
| D15 | `ProcessingReview` standalone page (admin R2) | Accept — repoint the three deep links at the workbench | **Approved** |
| D16 | Legacy flat test suites fold-in (admin R3 + cross-domain duplicate pairs) | Accept — diff-then-delete, Phase 8 sign-off satisfied here | **Approved** |
| D17 | Migration squash `schema:dump --prune` + 11 test-file cleanup + quarterly cadence (platform P2) | Accept | **Approved** (incl. quarterly re-squash cadence) |
| D18 | Stock config deletions (platform P3) + dead keys (P5) | Accept | **Approved** |
| D19 | Behaviour-adopting config updates: `hashing.php`, `auth.php`, `debugbar.php`, `livewire.php` regenerate (platform P4) | Accept — conscious adoption of current defaults incl. password-reset throttle | **Approved** |
| D20 | Config merges: `sermons`+`opening-hours`+`organization` → `church.php`; `monitoring` → `health` (platform P6) | Accept | **Approved** |
| D21 *(added 2026-07-07)* | Jules fleet stand-down for the programme: it was merging ~11 PRs/day and investing in deletion-scheduled code (PRs #1100, #1107, #1124). Pause code-writing personas; retire Warden/Herald/Bolt/Scribe/Steward; keep Mortician/Pathfinder (issue-first); survivors resume weekly under a worth-it gate; do-not-invest list + necessity check added to `AGENTS.md` and the PR-review skill | Accept | **Approved — implemented 2026-07-07** (fleet-status section in `AGENTS.md`; status banners in `.Jules/agents/*.md`; operator still to pause the Jules UI schedules) |

---

## Workstream 1 — The LLM-first retirement programme

The direct continuation of the archived
`docs/archived-plans/LLM-FIRST-SERVICE-STRUCTURE-PIPELINE-2026-07-01.md` phase 6, now with
the audited deletion map (church review §4.1–4.2 corrected the plan's own list in four places) and
extended to the media-side heuristics the plan's deletion list missed (media review F1). Total
retirement payoff: ~5,054 lines of church-service heuristic production code + ~8,900 test lines +
1,123 script lines, plus ~2,000+ media-side visual-stack lines and ~550 song-cluster lines —
replaced by an LLM path a third the size that also does more.

**Calendar time dominates this workstream** (shadow Sundays, then a primary soak of ~8 services).
Start the operational steps immediately; the code steps interleave with other workstreams.

### 1.1 Preparatory seam commits (pre-flip; each lands green independently)

Source: church review §4.2 seams 1–4, §7 quick wins 1–4.

> **Status 2026-07-10 — all four seams implemented, in review.** 1.1a = #1156 (independent),
> 1.1b = #1157, 1.1c = #1158, 1.1d = #1159 (stacked; merge #1157 → #1158 → #1159). Corpus
> evidence that cleared the start gate: test-files 91% / test-set-2 89% type accuracy, all three
> bad runs caught by validators and routed to manual review. Codex review round addressed on the
> branches: review roll-up now precedes the no-projectable-sections early-return (#1158), and
> `structure:shadow-report` surfaces the new `diff.baseline` provenance (#1159). Two findings
> against the *pre-existing* corpus-follow-up code split out: reading-recheck failure hardening
> is PR #1161; whether `custom` OoS items should keep hard-chaining in `out_of_order_oos_items`
> is an open maintainer decision (see #1161's description — the 2024-05-05 case itself is already
> cleared by the raw-type fix, and narrowing further would flip a deliberately-pinned test).
> Implementation notes for 1.5: the reconcile re-run never re-opens completed runs (naked
> re-dispatch would strand them); the transcript artifact survives cleanup but the RMS log does
> not, so reconcile re-detection loses silence snapping gracefully.

- **1.1a [mechanical] — Type/registry/doc moves (quick).** Move the `ClassifiedSection` PHPStan type from
  `ServiceSectionClassifier` to `ServiceSectionSyncService`, repointing both importers
  (`ServiceSectionSyncService`, `ClassifySpeechSections`) in the same commit. Move
  `OOS_REVIEW_FLAGS`/`OOS_REVIEW_REASONS` from `SectionAlignmentBaselineRestorer` into `Structure/`
  (the validator). Drop the dead `AlignmentTriggerCalculator` import from
  `UnmatchedSongReviewApplicator`. (The exemplar plan's phase 6 deletion list was superseded via
  its 2026-07-05 archival header, which points here for the corrected §4.1 list — no further doc
  update needed.)
- **1.1b [design] — Mode-aware reconciliation.** `ReconcileServiceSections` currently re-runs the heuristic
  aligner unconditionally on late OOS arrival (`ReconcileServiceSections.php:67`) — a live
  correctness gap for the primary-mode soak. In primary mode, reconcile by re-running
  `DetectServiceStructure` against the stored transcript artifact with the new OOS items (church
  opportunity 1: better *and* simpler — no media access, one LLM call). Also strip the now-dead
  non-primary branch and the `OosAlignmentService` type-hint from `MatchSongsFromTranscript`
  at flip time.
- **1.1c [design] — OOS-backed review roll-up.** `LivestreamChurchServiceProjectionService::project()`
  early-returns on OOS-backed services before its `needs_review` propagation, so once
  `OosAlignmentService` stops calling `ChurchServiceReviewSynchronizer`, low-confidence LLM runs on
  OOS-backed services would never reach the inbox. Add an explicit service-level roll-up on the
  early-return branch (or lift the roll-up out of the projection path) **before** the flip.
- **1.1d [design] — Shadow baseline redesign.** Shadow mode currently diffs the LLM proposal against the
  heuristic sections (church §4.8); once the cluster is gone there is no baseline. Re-point the
  shadow diff at the currently-bound model's stored output or a curated `structure:evaluate`
  manifest, so shadow mode survives as the permanent model-upgrade mechanism. Also default
  `structure:evaluate --detector` to the bound detector (mock in CI) so a bare run costs nothing.

### 1.2 [operational] Shadow soak (start now — decision D2 sets the clock)

Set `SERVICE_STRUCTURE_MODE=shadow` with the real detector/transcriber in production; accumulate
Sundays; run `structure:shadow-report`; fill a real manifest for `structure:evaluate`. The plan's
suggested promotion gate: clean shadow evidence, then flip, then ~8 clean primary services before
deletion.

> **Status 2026-07-10 — awaiting the prod flip.** All three env lines are required (`detector`
> and `transcription_service` default to `mock`): `SERVICE_STRUCTURE_MODE=shadow`,
> `SERVICE_STRUCTURE_DETECTOR=openai`, `SERVICE_TRANSCRIPTION_SERVICE=openai`. Refresh the config
> cache and restart Horizon while nothing is processing. ~£0.35 + one gpt-5 call per shadowed
> service; shadow failures are swallowed and can never fail a run.

### 1.3 [design] Auto-trim migration (seam 5 — the real gate on deleting the classification jobs)

`buildAutoTrimVideoPipeline()` consumes four classification jobs un-gated, and the LLM path is
livestream-only by guard and only writes sections in primary mode. Scope per church §4.2.5: replace
the four jobs with `TranscribeFullService` → `DetectServiceStructure` **widening only those two
guards** to accept auto-trim `MediaType::Video` runs (do not widen projection/song/publication
guards — they are not in the auto-trim chain). Preferred shape (b): a dedicated auto-trim detector
path that writes sections independently of the global mode, so auto-trim keeps working throughout
the shadow period; fallback shape (a): swap at the flip.

### 1.4 [operational] Flip to primary + soak

Config flip once 1.1b/1.1c and the shadow evidence are in. During the soak, no new investment in
heuristic-path tests (media test note 3).

### 1.5 [mechanical] Delete the church-service heuristic cluster (decision D1)

**Do not start until:** seams 1.1a–1.1d have landed, auto-trim is migrated or retired (1.3), and
the 1.4 soak evidence (~8 clean primary services) is in.

The audited list (church §4.1): 11 services (3,324 lines: `StructuralSectionAligner`,
`SpeechSectionClassificationService`, `SongSectionAligner`, `OosAlignmentService`,
`ServiceSectionClassifier`, `ReadingReferenceExtractor`, `SectionItemAlignmentScorer`,
`SectionAlignmentBaselineRestorer`, `AlignmentTriggerCalculator`, `PresentationItemClassifier`,
`MediaInterludeCueDetector`), 6 jobs (1,730 lines: `ClassifySpeechSections`,
`ResolveReadingReferences`, `TranscribeSpeechSegments`, `ReclassifyIntroOutroSections`,
`ClassifyServiceSections`, `AlignWithOos`), the heuristic branches of `ProcessingPipelineBuilder`,
the `Off` case of `ServiceStructureMode`, `scripts/section-extraction/` (1,123 lines) +
`SectionExtractionScriptsTest`, and ~8,900 lines of heuristic-path tests (named in church §4.9).

Commit discipline (church §4.2 closing sequence): each job-deletion commit bundles its builder
branch, its `ProcessingPhaseRegistry` imports/phase-rows/anchors, its dedicated test file, and
updates to the shared suites (`ProcessingPipelineBuilderTest`, `ProcessingPhaseRegistryTest`, plus
the 14 test files importing job classes) — then the now-unreferenced services, then the mode-enum
collapse, then the scripts. **Not deletable** (corrections): `LivestreamChurchServiceProjectionService`,
`LivestreamSectionToServiceItemMapper`, `StructureMergePolicy`, `ChurchServiceStructureMergeService`.
Also re-check the workbench `reclassify()` affordance and `timeline-alignment-*` partials for dead
branches (admin review §9).

### 1.6 [design] Delete the media-side visual stack + song-cluster residue (decision D1)

**Do not start until:** 1.5 is complete, and the `AnalyzeSegments` failure gate and
extraction-baseline times have been re-homed onto the LLM structure with a characterisation test
on primary-mode segment boundaries (see the "not a free deletion" paragraph below).

Source: media F1/R3, songs F1/G-R4. `VisualAnalysisService` (881), `PerformVisualAnalysis` (326),
the visual/cluster half of `VideoSegmentationService` (~400) and of `AnalyzeSegments` (~350),
`ExportVisualMetricsCommand`, `ExtractVideoFrames`, `SongClusteringService` + `SongCluster` /
`SongClusterCollection` / `SongClusterCollectionCast`, and the `song_clusters` /
`visual_confidence` / `visual_sample_count` / `calibration_method` columns.

**This is a segmentation migration, not a free deletion** (media F1): `song_clusters` still guides
`AnalyzeSegments` segmentation in primary mode, and `AnalyzeSegments` carries two pre-LLM
responsibilities that must be re-homed first — (a) the no-sermon-candidate failure gate, and
(b) the `sermon_start_time`/`sermon_end_time` baseline that `SermonExtractionPlanResolver` reads.
Derive both from the LLM structure, land a characterisation test on primary-mode segment
boundaries, and only then remove the producer. What survives: `GenerateRmsLog` + the
silence-parsing half of `RmsAnalysisService` (boundary snapping) and speech blocks for manual
review.

### 1.7 Post-retirement consolidations (each its own PR; equal billing as improvements)

- **1.7a [design] — One Whisper pass per service** (media O2/F5). Slice the full-service transcript for the
  sermon transcript instead of re-transcribing extracted audio; delete the second transcription
  interface family. Halves transcription cost/latency; public transcript available minutes after
  upload.
- **1.7b [design] — One ffmpeg audio-preparation helper** (media F5) owning the transcription-target
  profile; delete the other three compression paths and the `getVideoMetadata` double.
- **1.7c [design] — One song matcher** (songs F5/F6, church opportunity 4). First shed
  `MatchSongsFromTranscript`'s third tier (per-section extraction + Whisper) in favour of
  `ChurchServiceTranscript::sliceText()`; then consolidate title-hint regexes + fuzzy lyrics
  windowing behind one typed matching call with the canonical-key bedrock kept deterministic;
  prune the 11 `song_matching` config keys to `enabled` + the local-dev switch. OCR retention
  depends on the "lyrics-on-screen-only" answer (church Q4).
- **1.7d — Speaker identification: KEPT (decision D3 rejected the removal).** The media review's
  premise — dark by default, three switches all off — is wrong for production: the feature is
  enabled there and has been working for a while. The stack, the `EditPreacher` profile UI
  (admin R4), and `BootstrapSpeakerProfilesCommand` all stay. The transcript `speaker_name` field
  (media O4) remains a possible future consolidation *only if* the stack becomes a maintenance
  burden; it is not scheduled work. Old backlog PR 19 closes as moot (already always-on in prod).
- **1.7e [design] — Registry rationalisation** (media F3). Extend the anchor-job offset pattern to all four
  pipelines, derive progress from chain position, normalise step names at the write site and
  delete alias lists. Easier after 1.5/1.6 shrink the chains; unlocks media O3 (new processing
  outputs = one-line chain edits).
- **1.7f [design] — Schema-field opportunities** (church opportunities 2/3; media O1). Per-section
  summaries, notices extraction, automatic service summaries, chapter markers — each one schema
  field + prompt change + eval run. Queue behind operator appetite; listed so the cheapness is
  remembered.

---

## Workstream 2 — Spent code and dead weight (independent; start immediately)

### 2.1 [mechanical] Grep-verified dead-code batch (decision D5)

**Complete (2026-07-12):** Implemented in full, including the issue-tracker intake. Both production
gates passed: `LOG_CHANNEL=stack`, and zero `service_sections.status='skipped'` rows. The custom
logging channel/formatter and `ServiceSectionStatus::Skipped` schema path were removed.

One or two PRs, all verified zero-production-caller by the reviews. Media (F4/R5 + quick wins 1–5):
`SermonValidationService` (+ stale config comment), `UpdateSermonRecord` job + ghost registry phase
+ 2 orphaned `ProcessingStep` cases (+ the seven fixture files listed in media quick win 2), the
seven dead `SermonProcessingLogger` methods + `App\Data\ProcessingReport`, the `sermon-processing`
log channel + `SermonProcessingLogFormatter` (**gate: confirm production `LOG_CHANNEL` ≠
`sermon-processing`**), `VideoStorageService` orphan methods + the `@deprecated`
`extractOptimizedAudioFromSegment` alias. Public (6.1 + quick wins): three `SermonRepository`
methods + the `sermons_jsonld_recent_100` invalidation line, two `CalendarService` methods,
`MeetingShowPresenter::layoutData()` + `PageLayoutPresenter::present()`, `Page::hasMeeting()`,
~150 lines of `Meeting` occurrence calculators/accessors/scopes. Songs (F3/R5/R6):
`PublicSongUsageService::query()`, the duplicate `SongLyricSnippetBuilderTest` /
`PublicSongUsageServiceTest` files. Sermons (R8 + quick wins): the four per-field
`SermonAnalysisService` methods, `SermonStorageService::moveFile()`, single-attempt scaffolding +
`Chat.php:35` stack-walk, unused `$options` parameter, `'unknown'` `processingId` default. Church
(R5): `ServiceSectionStatus::Skipped` (**gate: zero `service_sections.status='skipped'` rows in
production**). All covering tests deleted in the same commits (~2,400+ test lines).

**Issue-tracker intake (2026-07-05)** — grep-verified dead items folded in from
`docs/issues/README.md` (Mortician reports; re-verify zero callers before deleting, per the
protocol above):

- `App\Http\Requests\UpdateSermonRequest` — rides the `UpdateSermonRecord` deletion; retire its
  three test files in the same commit (`tests/Unit/UpdateSermonRequestTest.php`,
  `tests/Unit/Security/SermonValidationSecurityTest.php`, `tests/Feature/SermonIntegrityTest.php` —
  the latter two only where they exercise this class; keep any assertions that target
  `Sermon::validationRules()` via the Livewire form instead).
- `PageImagePresenter::headingImageSrcset()` — unused method; delete with its assertions in
  `tests/Integration/Presenters/PageImagePresenterTest.php`.
- `public/images/podcast/EveningArtwork.webp` + `MorningArtwork.webp` — `config/podcast.php`
  deliberately uses the `.jpg` versions (podcast directories require JPEG); **do not touch the
  `.jpg` files**.
- `public/images/headings/{large,small}/*.jpg` + `public/images/headings/links.jpg` (33 files,
  ~2 MB) — the `.webp` siblings are the served versions. **Caution:** the `.webp` files in these
  directories are live (referenced directly via `asset()` in `SitemapService`, the sermon Blade
  views, and the `page-card` default) — prune only files ending `.jpg`.

### 2.2 [mechanical] Deterministic analysis stub (decision D5; sermons R7/F3)

**Complete (2026-07-12):** Replaced the heuristic simulator with a deterministic 33-line fixture
stub that preserves the supplied transcript, and reduced its test suite to one exact shape
assertion. The change removed 649 net lines and the full 6,143-test suite passed.

Replace `MockSermonAnalysisService`'s 463-line non-deterministic heuristic simulator with a ~40-line
fixture-returning stub; delete `MockSermonAnalysisServiceTest` or reduce to one shape assertion.
De-flakes every analysis-dependent CI assertion (sermons opportunity 4). `MockServiceStructureService`
is the template (platform F7).

### 2.3 [mechanical] Storage-service collapse (decision D9; completes and supersedes SIMPLIFICATION-PLAN Phase 9)

**Complete (2026-07-13):** The production gates passed, runtime storage resolution was collapsed
to its two canonical rules, and the spent maintenance service, five commands, and dedicated tests
were removed.

**Production gate results (2026-07-13):**

- The initial migration dry run examined 740 candidates: 737 already on the target, with three
  missing legacy files (`#109 478b.mp3`, `#192 641a.mp3`, `#194 642a.mp3`). Exhaustive searches
  found no copy on `do_spaces`, `public`, `local`, or `public_images`. The operator explicitly
  accepted these as metadata-only sermons; their rows were retained with `audio_file_path = null`.
- Commit `741049b68` fixed the verifier to exclude metadata-only sermons and check private audio on
  its canonical local disk. It was deployed before verification continued.
- Verification then exposed 659 files present on `do_spaces` whose database rows still held bare
  legacy identifiers. Commit `81e87a43b` fixed the migration to canonicalise verified targets,
  use ID-based batching, and reject concurrent row changes. Its dry run reported 659/659 ready;
  the production run canonicalised all 659 with zero missing or failed rows.
- Final verification reported 698/698 referenced audio files accessible (5.63 GB), zero legacy
  paths, 698 canonical storage paths, and zero missing files across 702 sermon records. The four
  remaining records without audio are intentional metadata-only sermons.
- The shared 2.3/2.4 children's-talk dry run reported `No Children's Talk sermons require
  migration.` The backfill command was therefore removed with this item, completing 2.4's R6 row.

**Code change:** stripped the legacy/filetype/config branches from `SermonStorageService`, reduced
file resolution to private-local versus `sermon_disk`, and deleted
`SermonStorageMaintenanceService`, its five commands, and their dedicated tests.

**Gate: run `MigrateSermonStorageCommand --dry-run` then `sermons:verify-storage` against
production; all files accessible in canonical locations.** Then, in order: strip the `legacy`
pattern branch + `filetype` runtime dependency + dangling `legacy_disk` config read from
`SermonStorageService::resolveFileInfo()`; collapse `storage`/`processing` (distinction without a
difference) so file info is two rules — "private → local+guarded, else sermon_disk"; delete
`SermonStorageMaintenanceService` + its five commands (`MigrateSermonStorage`,
`VerifySermonStorage`, `MigrateLivestreamAudioFiles`, `MigrateLocalFilesToSpaces`,
`MoveChildrensTalksToPrivateStorage`) + ~1,540 lines of their tests. One ~350–400-line service then
owns the lifecycle with `MoveSermonToPrivateStorage` as its write-side companion.
**Unlocks:** the semantic-search backfill becomes a loop over `audio_file_path` (sermons
opportunity 1); stale enclosure-metadata bugs disappear (opportunity 3).

### 2.4 [mechanical] Legacy importer / cutover / backfill sweep (decision D10)

Each with its production check, then delete tool + companion + tests; record the git tag in the PR:

| Tool | Check |
|---|---|
| `LegacySermonImporter` + `ImportLegacySermonBatchCommand` (sermons R3, ~1,520 lines) | Tape digitisation finished for good |
| `GenerateProdSermonPatchCommand` (R4, 669) | Prod patch applied; local→prod merges never again |
| `PreacherCutoverCommand` + service (R5, 387) | `SELECT COUNT(*) FROM sermons WHERE preacher_id IS NULL` = 0 |
| ~~`MoveChildrensTalksToPrivateStorage` (R6, 64 — rides 2.3)~~ **Complete 2026-07-13** | Production dry run confirmed no children's talk required migration; removed with 2.3 |
| `LegacyPlayDateSongUsageImporter` + command (songs, ~700) | play_date backfill confirmed complete |
| `LegacySongReconciler` + `reconciledSongId` thread + schema probes (songs, ~500) | Zero songs with null/blank/`legacy-song-%` canonical keys (the reconciler's own three-part predicate — songs Q2) |
| `songs.praise_number`, `songs.alternative_title` columns + `play_date` table | After the two rows above (note: `alternate_title` is live — do not touch) |

### 2.5 [mechanical] `HistoricVideoImporter` + `ImportHistoricVideoBatchCommand` (decision D11, ~1,500 lines)

Gate: the 275 GB drive import is finished for good. Zero runtime risk — nothing else references it.

### 2.6 [mechanical] Platform one-shot command sweep (decision D12; platform P1, ~1,480 lines + tests)

`ConvertJpgToWebp`, `ImportHistoricVideoBatchCommand` (rides 2.5), `ExtractVideoFrames` +
`ExportVisualMetricsCommand` (also die with 1.6 regardless), `ImportOpenLpDirectoryCommand`,
`BackfillMediaProcessingIdentityCommand`, `FixUploadDirectories`, `MeetingMigratePhotosCommand` +
`MeetingPhotoMigrationService` — **and, behind the same production-migration gate, the legacy
meeting photo folders `public/images/meetings/{1150,baby-talk,bible-study,buzz-club,coffee-cup,sunday-services}/`**
(issues tracker O5: meeting photos live in Spatie Media Library now; the import preserved these
originals, so confirm the production import completed before deleting). Per-command prod
confirmation (platform Q1a–f). Plus the
**retirement convention**: every new one-shot command declares its deletion trigger in its
docblock; the weekly tech-debt rollup treats any one-shot older than a quarter as default-delete
(add to `AGENTS.md`).

---

## Workstream 3 — Public read path & presentation convergence

### 3.1 [design] One presentation convention (decision D13; public 4.1 — the central Phase 5 item)

**Complete (2026-07-16):** Merged as PR #1221.

Adopt the rule: *every route's view data is one typed read-model object assembled in the controller
or Livewire component; Blade components receive props; SEO/JSON-LD builders consume the same read
model.* `PublicPageReadModel`/`PublicMeetingReadModel` already are the target. Deletions: `PageShowComposer`,
the three landing composers + `ViewServiceProvider` registrations (landing routes become two-line
controller methods), the `app/View/Presenters/` namespace (fold `PageLinksRepository` into
`RelatedPagePresenter`), inline Blade JSON-LD in `meetings/show|events` (becomes a
`MeetingSeoPresenter` in `app/Seo/`), and decide `Header`'s constructor-fetch (prop or documented
exception). **Unlocks:** one site-wide SEO/metadata implementation (public opportunity 1), a stable
seam for a design-system pass (opportunity 3).

### 3.2 [design] Caching simplification + repository slim (decision D13; public 4.2, sermons F5)

**Complete (2026-07-16):** Merged as PR #1222.

Choose freshness-by-TTL for listings: `Cache::flexible([300, 86400])` everywhere, deleting the
~150-line permutation-invalidation registry in `SermonRepository`, the framework-internal
`illuminate:cache:flexible:created:` key hack (3 files), and most of `SitemapCacheObserver`'s
fan-out. Fixes the stale-upcoming-events `rememberForever` bug in `PublicMeetingReadModelCache`.
Move `SermonRepository`'s write-side helpers to the creation side and `normalizeArchiveFilters()`
to the component/`BibleCanon` side; repository settles at ~400 lines of genuine read model. If
request memoization survives profiling, one shared helper (or `Cache::memo`) replaces the six
hand-rolled copies. **Unlocks:** response/CDN caching becomes a config change (public opportunity
2); operator edits visible immediately (sermons opportunity 3).

### 3.3 [design] Sermon presenter cluster collapse (decision D13; public 4.3/6.6)

**Complete (2026-07-16):** Merged as PR #1223.

Present each sermon once, eagerly, into a typed `SermonView` Data object (the shape
`SermonPresentationAssembler::forFull()` already defines); helpers become pure functions. Deletes
`SermonPresenterCache`, the identity-store half of `SermonIdentityResolver`, the presenter-passback
convention, and `clearInternalCaches()` from every consumer. 7 files → ~3, ~1,240 → ~600 lines,
output shapes unchanged. Sanity-check the 24-item archive page before merging.

### 3.4 [design] Sitemap simplification (decision D13; public 4.6/6.3)

**Complete (2026-07-16):** Merged as PR #1224.

Plain sitemap — detail URLs + lastmod + static landing list; drop representative-image window
queries, `priority`/`changefreq`; generate on the scheduler; controller becomes a file server.
524 → ~150 lines; ~1,176 test lines shrink with it. `whereVisibleInSitemap` logic untouched.

### 3.5 [mechanical] Meetings & calendar decisions (decision D14; public 4.5/4.7, items 6.4/6.5/6.7/6.8)

**Complete (2026-07-17):** Merged as PR #1225. The drop migration is destructive — flag at deploy; recoverable only from backups.

- **Recurrence fields** (`meeting_date`/`is_recurring`/`frequency`): **do not merge a partial
  removal** — strip the
  `ListMeetings` select/filter/sort + badges + `MeetingFormData` picker + Schedule JSON-LD
  together (public 4.5 scope warning), deriving any schedule markup from `CalendarEvent` rows.
  **Unlocks:** one scheduling source of truth (public opportunity 4).
- **Google categorisation write-back** *(this bullet is [design] — it changes sync behaviour)*:
  sync preserves manually-categorised rows instead
  (skip `meeting_slug` when `is_categorized_automatically === false`); delete
  `syncCategorizationToGoogle`/`removeCategorizationFromGoogle` + `CalendarCategorizationResult`
  (~90 lines); service account drops write scope.
- **`CalendarAdminController` + two Blade views**: converge on the Livewire calendar admin.
- **Duplicate `Page` media conversions** (`large`/`small`): delete; fallback chain already serves
  old files.
- Also: decide `/calendar/uncategorized` public exposure (public Q2 — likely make it admin-only).

### 3.6 [mechanical] Podcast feed merge + `SermonExposurePolicy` fix (quick-win adjacent)

Merge the byte-identical `rss/morningFeed`/`eveningFeed` templates into one `rss/feed.blade.php`
(sermons F8); Podcast 2.0 tags (`<podcast:person>`, chapters) become cheap follow-ups if wanted
(sermons opportunity 2, gated on Q7 appetite). Fix `SermonExposurePolicy`'s
`environment('testing')` branches with lazy config reads (sermons F10).

---

## Workstream 4 — Admin & upload flow

### 4.1 [design] Upload consolidation (admin F1/F2/F3 — directions, no removal decision needed)

One PR sequence: collapse `MediaUploadProgress`/`MediaUploadStatus` into Blade partials calling
`$wire` directly (deletes the page-global event relay + singleton contract); replace the loose
string status + four message channels with one backed `UploadState` enum + derived
`statusMessage`/`statusUrl`; move the component under `App\Livewire\Admin\` so the structural
authorization test covers it **and** add explicit `authorizeAdmin()` to each mutating action
(relocation alone is insufficient — admin F3); move `matchedServiceUrl()` beside
`ChurchServiceProcessingRunQuery`; delete the self-re-arming no-op stall timer and debug-era
logging. **Unlocks (admin O1):** retry-without-reselecting, "your last upload" card, honest stall
messaging, multi-instance mounting.

### 4.2 [design] Operator diagnostics: one durable read path (decision D4; media F2/R4, admin R1)

The durable pair — `SermonProcessingStep` + `processing_metadata` + queue-correlation columns —
becomes the only operator-facing read path. Re-point the status-with-logs API and whatever replaces
`ProcessingLogsViewer` at steps + metadata; then delete `ProcessingLogService` (468 lines +
`ProcessingLogEntry`/`ProcessingLogCollection`) and the viewer (304 + 297 view + 294 test — or its
agreed replacement, e.g. a plain "view technical log" admin link). Plain `Log::` lines remain for
developers, freed from machine-re-parseability. **Unlocks (media O5):** per-step durations/attempt
counts queryable after log rotation; "slowest step this month" panels possible.

### 4.3 [mechanical] `ProcessingReview` page retirement (decision D15; admin F6/R2)

Point the three deep links (upload `manualReviewUrl`, `ManualReviewRequired` email, inbox segment
rows) at the workbench; solve the orphan-run case via the inbox's existing "create this service"
affordance; delete component + view + 251-line test.

### 4.4 [mechanical] CRUD consistency pass (admin F4/F5 quick wins + O2/O3)

Route-group middleware for `service-tracking.enabled` (deletes eight `abortIfDisabled()` copies);
`use WithAdminAuthorization` inside `ManagesSectionPublication` (deletes three `method_exists`
guards); shared `markServiceReviewed()`; named eager-load scope for church-service items; align
`ListChurchServices` onto `WithFilterableListing` and `ListUsers` onto the sibling
filter/sort convention; inline or re-document `ReviewsServiceSections`; document the
`AdminListComponent` recipe (or extract the base class) so new screens are near-free. Also extend
`AdminLivewireComponentsUseTraitTest` to assert per-action `authorizeAdmin()` calls (admin F3's
durable fix). Sermon-delete duplication (`ListSermons` vs `SermonAdminController::destroy`) —
converge on one path while touching.

### 4.5 [mechanical] Authorization gates cleanup (carried over from old backlog PR 4)

Replace the 7 `@can('manage-*')` blocks across 5 views with `canAccessAdmin()`-based checks;
delete `MeetingPolicy` after removing its `MeetingController`/`UpdateMeetingRequest` callers;
remove the 3 gates + policy registrations; delete `AuthorizationGatesTest`/`MeetingPolicyTest`.

---

## Workstream 5 — Church-service workflow state

### 5.1 [design] Canonical-conflict state collapse (decision D7; church §4.5/R3)

Written on every import, read by nothing but the synchronizer that flattens it to `needs_review`.
Pick column storage; shrink to `needs_review` + one human-readable reason string; keep
`canonical_conflict_history` in JSON as the audit trail. Deletes one enum, most of another, six
columns' ceremony, and much of the 167-line `ChurchServiceReviewStateService`.

### 5.2 [design] One-call email parsing (decision D8; church §4.7/R4, opportunity 5)

Extend the existing `OpenAiOosEmailItemExtractor` pattern to one typed call returning
`{date, service, items, confidence}`; keep the deterministic validation gate (date parses, service
in enum, future-dated tolerance) and the existing thresholds; delete ~300 lines of date/service
regex from `OosEmailParserService`. Likely *better* on informal formats, not just smaller.

### 5.3 Staged structure-merge workflow (decision D6 — deferred)

~950 lines guarding a collision case promotion makes rarer. Re-measure after 1.4: count
pending-structure-merge occurrences and operator choices over the soak; if it rarely fires and the
operator always accepts the incoming email, collapse to "merge + `needs_review` + diff note".

### 5.4 `ChurchServiceItemSyncService` reassessment (post-promotion; church §4.3)

Don't split while semantics are moving. After the soak: does the `Livestream` source still need
full merge authority? Does cross-source song-title matching still fire? Then policy/mechanics
split if still warranted.

### 5.5 [mechanical] Timeline family relocation (platform F4)

Move `ProcessingRunTimelineBuilder`, `ServiceRecordTimeline`, `ServiceFlowBuilder`,
`ServiceTimelineBuilder` (~930 lines) from `app/Support/` to the church-service domain; merge the
49-line `ServiceTimelineBuilder` pass-through into `ServiceFlowBuilder` while moving. Content
re-proportioning waits for 1.5 (heuristic steps thin the timeline).

---

## Workstream 6 — Platform hygiene

### 6.1 [mechanical] Migration squash (decision D17; platform F3/P2)

`schema:dump --prune`; delete the 3 corrective-migration test classes; strip the
migration-requiring methods from the 8 live schema/integrity suites; **update
`scripts/check-schema-dump-current.sh` in the same PR** (tolerate an empty migrations directory;
drop the "never --prune" message). Adopt quarterly re-squash. Consider tightening Warden's mission
(check existing covering indexes) to slow the ~100/year regrowth. **Unlocks (platform O1):** the
nightly agent fleet stops compounding cost; fresh setup loads one dump.

### 6.2 [mechanical] Config deletions (decision D18; platform P3/P5)

Delete the five stock files (`blade-heroicons`, `media-library`, `schedule-monitor`, `view`,
`broadcasting`; ~590 lines) with a `config:show` spot-check each; remove the `auth.php` `api`
guard block and the two dead `calendar.php` keys.

### 6.3 [mechanical] Behaviour-adopting config updates (decision D19; platform P4)

Consciously adopt current defaults: `hashing.php` (`HASH_VERIFY`, `rehash_on_login`), `auth.php`
(`passwords.users.throttle => 60` — touches live reset flows, Dusk covers them; `password_timeout`),
`debugbar.php` (env-only control), regenerate `livewire.php` to the v4 shape — **do not
regenerate without first diffing the current file against stock v4 and carrying over every
deliberate delta**, not just the two known overrides (`component_layout`, `class_namespace`);
triage `blade-icons.php` drift.

### 6.4 [mechanical] Config merges (decision D20; platform P6)

`sermons.php` + `opening-hours.php` + `organization.php` → one `church.php`; optionally fold
`monitoring.php` into `health.php`. `service-tracking.php` stays separate (platform F2 ruling).

### 6.5 [mechanical] Agent-config fixes (platform F8 quick wins)

Fix `AGENTS.md` Key Services (three named services no longer exist) + the pre-LLM pipeline
narrative — the highest-leverage doc fix in the repo; delete the stray
`.claude/skills/frontend-design.md`; note that `boost:install` refreshes all three guideline
copies; add the one-shot retirement convention line.

> **Status 2026-07-12 — implemented, in review.** Replaced the three removed services and the
> pre-LLM pipeline narrative with the live orchestration and mode-aware pipeline, deleted the
> duplicate skill file, documented the three-copy Boost refresh, and added the one-shot retirement
> convention.

---

## Workstream 7 — Test-suite architecture roll-up (suite-wide patterns)

Four patterns recurred across all seven domains; the conventions below get documented in
`AGENTS.md` so they outlive this backlog.

### The patterns observed

1. **Preservative tests pinning spent or dead code** — ~1,900 lines (media) + ~2,070 (sermons) +
   ~2,000 (platform one-shots) + ~500 (public dead methods) + songs' duplicated `query()` coverage.
   These are not safety nets; they actively defend code the application never runs. They are all
   deleted with their subjects in Workstreams 1–2.
2. **Two test generations, the old one never retired** — the same promoted-but-not-retired residue
   the doctrine hunts in production code, in test form. Named pairs: `EditSermonTest` ×2,
   `ListSermonsTest` ×2, `AdminUserTest` vs `Admin/Users/*`, `AdminMeetingTest` vs
   `Admin/Meetings/*`, `AdminChurchServiceTest` (1,402 lines) vs per-component suites,
   `PublicSongUsageServiceTest` ×2, `SongLyricSnippetBuilderTest` ×2,
   `PublicMeetingReadModelCacheTest` ×2, `SermonViewPresenterTest` Unit+Integration overlap.
   Cross-cutting trait behaviour is then re-asserted a third time per component (sort-sanitisation
   asserted in ≥4 places).
3. **Integrity-test sprawl** — song/schema invariants asserted across five directories
   (`Feature/Warden/`, `Feature/DataIntegrity/`, `Feature/Models/`, `Feature/Database/`,
   `Integration/Models/`); slug validation re-tested at multiple layers.
4. **Characterisation suites for probabilistic/bridge paths** — the ~8,900-line heuristic-cluster
   estate was right for a byte-identical bridge but is a standing CI tax; the eval harness
   (`structure:evaluate` + manifest) is the designated successor regression mechanism for
   LLM-backed seams. Corollary: tests of test doubles (`MockSermonAnalysisServiceTest`) and
   brittle UI-string assertions (`ViewComposerTest` Tailwind classes, stale
   `it_populates_footer_with_latest_sermons`).

### 7.1 [mechanical] Duplicate-suite fold-ins (decision D16)

Diff assertions, keep the superset in the per-component home, delete the flat/legacy file. Order of
attack: the pairs named above (~2,000+ redundant lines, admin O4). Do after Workstreams 1–2 delete
their subjects, so nothing is folded twice.

### 7.2 [mechanical] Conventions to adopt (document in `AGENTS.md`)

- A subject's deletion always deletes its tests in the same commit.
- One suite per component/class; cross-cutting behaviour tested once at the trait level plus the
  structural tests (`AdminLivewireComponentsUseTraitTest` and kin).
- One home for schema/integrity invariants (pick one directory; new invariants go there).
- Probabilistic seams get eval manifests, not characterisation suites.
- `Unit` stays collaborator-level (April item 13's taxonomy holds; no regression observed).

---

## Quick-wins sweep

The ~30 sub-hour items from the seven docs, bundled into 2–3 housekeeping PRs where not already
absorbed above: church quick wins 1–5 (→ 1.1a plus the `ReviewsServiceSections` docblock/
`ServiceReviewDashboardQuery` rename), media quick wins 1–5 (→ 2.1), media quick win 6
(mode-gating `PerformVisualAnalysis` — **absorbed into 1.6 as a migration, not a quick win**, per
its own risk note), sermons quick wins 1–7 (→ 2.1/3.6), songs quick wins 1–4 (→ 2.1 + tracker
edits done with this document), public quick wins 1–8 (→ 2.1/3.1 + the `isAdminOnly()` sweep +
stale test rename), admin quick wins 1–8 (→ 4.4/4.1), platform quick wins 1–6 (→ 6.5 + stock
config pair).

## Suggested delivery order

Dependency-annotated. Items in different tracks parallelise freely; the numbering is a default.

1. **6.5 AGENTS.md fixes** — 30 minutes, improves every subsequent agent session. *(No dependencies.)*
2. **1.1a–1.1d preparatory seams + 1.2 shadow enablement** — starts the soak clock. *(1.1b/c must land before the 1.4 flip, not before shadow.)*
3. **2.1 dead-code batch + 2.2 deterministic stub** — *(no dependencies; de-flakes CI for everything after).*
4. **6.1 migration squash; 6.2/6.3/6.4 config passes** — *(independent).*
5. **2.3 storage collapse** — *(gated on prod verification run)*; then **2.4/2.5/2.6 one-shot sweeps** *(each gated on its prod check)*.
6. **4.1 upload consolidation → 4.2 diagnostics seam → 4.3 ProcessingReview retirement** — *(4.2 depends on 4.1 only for the status-panel surface; 4.3 independent).*
7. **3.1 presentation convention → 3.2 caching → 3.3 presenter collapse → 3.4 sitemap → 3.5 calendar decisions → 3.6 podcast/exposure** — *(3.2 and 3.3 touch `SermonRepository`/presenters; land 3.1 first to fix the conventions they conform to).*
8. **5.1 conflict-state collapse + 5.2 email parsing + 4.4 CRUD pass + 4.5 gates cleanup** — *(independent of each other).*
9. **1.3 auto-trim migration → 1.4 flip → soak** — *(1.3 shape (b) can land during shadow; shape (a) lands at the flip).*
10. **1.5 church-cluster deletion → 1.6 media-stack deletion (+ songs cluster residue)** — *(gated on soak evidence; 1.6's `AnalyzeSegments` re-homing designed before deletion).*
11. **1.7a–c/e/f consolidations** — *(1.7a/b/c after 1.6; 1.7e last; 1.7d is closed — speaker stack kept per D3).*
12. **5.3/5.4 deferred re-measures** — *(post-soak).*
13. **7.1 test fold-ins + 7.2 conventions** — *(after 1.5/1.6/2.x so nothing is folded twice).*
14. **Phase 9 code-quality review** — *(after the above has substantially landed).*

Hard dependency chains, restated:

- retire heuristic classifiers (1.4/1.5) ⟶ delete aligners/jobs/scripts (1.5) ⟶ delete media visual
  stack + song clusters (1.6) ⟶ one transcription family / one song matcher / registry
  rationalisation (1.7a/c/e)
- seams 1.1b + 1.1c + auto-trim (1.3) ⟶ the 1.4 flip is safe
- prod storage verification ⟶ strip legacy fallbacks ⟶ one storage service (2.3) ⟶ semantic-search
  substrate
- steps+metadata read path (4.2) ⟶ delete `ProcessingLogService` + viewer
- TTL caching decision (3.2) ⟶ delete permutation-invalidation registry
- subjects deleted (1.5/1.6/2.x) ⟶ test fold-ins (7.1) ⟶ Phase 9

## Production checks checklist (run before the gated deletions)

- [ ] Enable `SERVICE_STRUCTURE_MODE=shadow` in production (confirmed still `off` as of 2026-07-05 — decision D2; top of the delivery order)
- [x] Production `LOG_CHANNEL` ≠ `sermon-processing` — confirmed `stack` 2026-07-12 (gates 2.1's channel deletion)
- [x] `sermons:verify-storage` clean against production — 698/698 files accessible, zero legacy
      paths, zero missing files (confirmed 2026-07-13; gates 2.3)
- [ ] `SELECT COUNT(*) FROM sermons WHERE preacher_id IS NULL` = 0 (gates PreacherCutover deletion)
- [x] No children's talks on non-`private/` paths — dry run confirmed none require migration
      (2026-07-13; gates `MoveChildrensTalksToPrivateStorage` deletion)
- [ ] `Song::withTrashed()` null/blank/`legacy-song-%` canonical-key count = 0 (gates reconciler deletion)
- [x] `service_sections.status = 'skipped'` count = 0 — confirmed 2026-07-12 (gates enum-case removal)
- [ ] Per-command confirmations for the eight platform one-shots (platform Q1a–f)
- [x] Speaker-identification: confirmed enabled and working in production (2026-07-05) — stack kept, decision D3

## Coverage map

- Media pipeline review: items 1.6, 1.7a–f, 2.1, 2.5, 4.2
- Church service review: items 1.1–1.5, 5.1–5.4, 2.1 (R5)
- Sermons review: items 2.1–2.4, 3.2, 3.3, 3.6
- Songs review: items 1.6 (clusters), 1.7c, 2.1, 2.4
- Public site review: items 3.1–3.6, 2.1
- Admin review: items 4.1–4.4, 7.1
- Platform review: items 2.6, 6.1–6.5, 5.5, 7.x conventions

## Definition of done

- [ ] The heuristic structure path (church services + media visual stack + song clusters) is
      deleted; the LLM path is primary with shadow/eval as the permanent regression mechanism.
- [ ] No spent one-shot tool remains runnable; new one-shots carry deletion triggers.
- [x] One storage service owns the sermon file lifecycle; no legacy path branching at runtime.
- [ ] One presentation convention on the public read path; composers/inline JSON-LD/dead read
      methods gone.
- [ ] Listing freshness is TTL-based; no hand-maintained cache-key registry.
- [ ] The upload flow is one authorized admin component with one state enum.
- [ ] Operator diagnostics read from steps+metadata only; no log-file re-parsing.
- [ ] Migrations are squashed with the drift gate updated and a re-squash cadence adopted.
- [ ] Config contains no stock-copy files; behaviour-relevant defaults consciously adopted.
- [ ] Each remaining removal decision recorded above is either executed or explicitly rejected.
- [ ] Test suite: no preservative tests, one suite per component, one integrity home.
- [ ] Older trackers archived with pointers here.
