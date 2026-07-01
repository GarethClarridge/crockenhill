# LLM-First Service Structure Pipeline — Implementation Plan (2026-07-01)

## Progress log

| Phase | Status | PR | Notes |
|-------|--------|----|-------|
| 1 — Full-service transcript | ✅ Done (2026-07-01) | `claude/llm-first-service-structure-48b00v` | See implementation notes below. |
| 2 — Structure detection | Not started | — | |
| 3 — Deterministic gate | Not started | — | |
| 4 — Pipeline wiring | Not started | — | |
| 5 — Eval + shadow tooling | Not started | — | |
| 6 — Promote and retire | Blocked on maintainer go | — | |

### Phase 1 implementation notes (deviations from plan text)

- The `service_structure` config block landed early with only the transcription knobs
  (`transcription_service`, `transcription_model`); `mode`/`detector`/thresholds arrive with
  Phase 4 wiring so no dead knobs exist in between. `transcription_model` defaults to `whisper-1` —
  the only OpenAI model that returns `verbose_json` segment timestamps.
- Oversized-audio chunking reuses `AudioChunkingService` (fixed 6-min windows, 15 s overlap)
  rather than bespoke RMS-silence chunking: cue times are re-offset per chunk and cues inside the
  repeated overlap window are dropped, unit-tested against faked verbose_json responses. Silence
  data still shapes boundaries later via Phase 3 snapping, where it actually matters.
- The transcript is stored as `temp/service_transcript_{processing_id}.json` on the temp disk
  (idempotent overwrite), recorded in `processing_metadata['service_transcript_path']`, and added
  to `MediaProcessingLog::temporaryFilePaths()` so run cleanup removes it.
- `TranscribeFullService` currently propagates failures (retry → failed run) like its siblings;
  the shadow-mode "never fail the run" guard belongs to Phase 4, where `mode` exists to consult.
- `MockServiceTranscriptionService::useTranscript()` is static per-test fixture state; tests that
  set it reset it in tearDown.

## Goal

Make sermon/talk/song extraction from livestream recordings reliable by restructuring the middle of
the pipeline around the correct division of labour:

- **LLM** owns everything that is *understanding what was said*: section types, boundary proposals,
  order-of-service (OoS) anchoring, reading references, sung-song title identification.
- **Deterministic code** owns everything that is *time, media, and safety*: ffmpeg, silence-snapping,
  structural validation, confidence gates, persistence, publication.
- **Humans** stay exactly where they are: the existing manual-review and publication-approval
  workflow is the safety net that makes heavy LLM reliance acceptable.

Target shape (replaces the ~20-job livestream chain's heuristic middle):

```
Upload video
  → ValidateVideoFile + GenerateRmsLog            (existing, unchanged)
  → TranscribeFullService                         (NEW: one timestamped Whisper pass, whole recording)
  → DetectServiceStructure                        (NEW: one LLM call — transcript + OoS items →
                                                   typed sections with times, confidence,
                                                   oos_item_id, reading refs, song titles)
  → deterministic gate                            (NEW: schema/chronology/overlap/coverage/
                                                   single-sermon/duration/oos-uniqueness checks;
                                                   snap boundaries to RMS silence)
  → ServiceSectionSyncService::sync()             (existing seam, unchanged)
  → MatchSongsFromTranscript                      (existing, adapted: DB-confirms LLM titles)
  → ExtractSermon → … → publication → approval    (existing tail, unchanged)
```

## Relationship to prior plans

- **Supersedes** `LLM-SERVICE-SECTION-CLASSIFICATION-SPIKE-2026-06-19.md` in scope and shape. Three
  deliberate departures from that plan and its 2026-06-21 corrections:
  1. **The LLM owns OoS anchoring.** The spike kept `AlignWithOos`/`StructuralSectionAligner`
     downstream because its schema had no OoS item id. This plan puts `ChurchServiceItem` ids in the
     prompt and requires the model to return `oos_item_id` per section, validated deterministically
     (exists, used at most once, type-compatible). A transcript-grounded model reading both the OoS
     text and the actual words spoken does this alignment natively — and solves F15 (generic
     "Andrew Talk.pptx" items) for free.
  2. **Full-service transcript comes from one whole-recording Whisper pass**, not from per-section
     extraction and not (for now) from the OBS sidecar. At 1–2 services/week a 90-minute Whisper
     pass is ~£0.45/run — the sidecar plan's trust gates, offset calibration, and operator dependency
     are not worth that saving. `LIVESTREAM-TRANSCRIPT-REUSE-FROM-OBS-2026-06-20.md` remains valid
     for live captions, and its resolver seam can later feed `TranscribeFullService` — but nothing
     here depends on it.
  3. **The heuristic cluster is a bridge, not a permanent fallback.** Once the LLM path has soaked,
     the cluster is deleted (Phase 6). The permanent fallback for a failed/invalid LLM run is
     `needs_manual_review` + the existing manual segment-confirmation flow
     (`buildLivestreamPostReviewChainJobs`) — which is a *better* fallback than a classifier we
     already know emits wrong sections.
- **Absorbs** the classification-cluster findings from
  `docs/operations/section-extraction-findings-2026-06-20.md` (F1, F7, F11, F16 structurally; F3/F5
  become irrelevant once readings are whole and correctly anchored) and Workstream D (F15) from
  `SERMON-SECTION-EXTRACTION-REMAINING-FIXES-2026-06-21.md`.

## Constraint: no local access — how this plan is built and proven

All development happens without access to real recordings, the production database, or a local
media environment. The plan is structured so that **no phase blocks on local access**:

- Every component is developed against **fixtures**: hand-written timestamped transcripts, RMS-log
  fixtures, recorded (faked) OpenAI HTTP responses, and factory-built `ChurchServiceItem`/OoS data.
  CI never calls an external API — the `mock` binding stays the test default throughout.
- The **evaluation and shadow tooling ships as artisan commands** in this work. The maintainer runs
  them against real data (staging/production console) whenever convenient; their output gates
  *promotion* (a config flip), never *implementation*. Until promotion, everything lands dark:
  production behaviour is byte-for-byte unchanged with the default config.
- The nine regression scenarios in `scripts/section-extraction/` and the stored transcripts they
  reference are **not required** for any phase here. Where this plan would have used them
  (calibration, eval), it instead ships the harness and lets real-data runs happen asynchronously.

Rollout is therefore controlled entirely by config, in this order, each step reversible:

| Step | `service_structure.mode` | Behaviour |
|------|--------------------------|-----------|
| 0 (default) | `off` | Nothing new runs. Pipeline unchanged. |
| 1 | `shadow` | Heuristic chain runs and remains authoritative; `TranscribeFullService` + `DetectServiceStructure` also run, persist their proposal to run metadata only, and log a structured diff. |
| 2 | `primary` | LLM chain is authoritative; heuristic cluster no longer runs. Validation failure or low confidence routes to manual review (existing flow). |
| 3 | (Phase 6) | Heuristic cluster deleted; `mode` collapses to `shadow|primary`. |

## Reused seams (source of truth — verify against code when implementing)

- `ProcessingPipelineBuilder::buildLivestreamChainJobs()` / `buildSectionReclassificationChainJobs()`
  — the two chains that contain the heuristic middle.
- `ServiceSectionSyncService::sync(MediaProcessingLog, array $classifiedSections)` — the persistence
  seam; the LLM path must emit exactly the `ClassifiedSection` shape documented on
  `ServiceSectionClassifier` (incl. `source_segment_ids`, `metadata.confidence_level`).
- `App\Support\ServiceSectionConfidence` (`HIGH_THRESHOLD = 0.85`, `scoreForLevel()`) and
  `SermonExtractionPlanResolver::resolve()` — the existing confidence gate the LLM output must clear
  to drive auto-extraction.
- `AiServiceProvider` `match(config(...))` binding pattern over `mock|openai|…`; the OpenAI call
  pattern in `App\Services\ChurchService\SpeechSectionClassificationService` /
  `App\Contracts\OosEmailItemExtractor` (json_schema response format, low temperature, config-driven
  model, `RuntimeException` on invalid response).
- `GenerateRmsLog` → `processing_metadata['rms_log_path']` — silence data for snapping; also the
  storage precedent for the new transcript artifact.
- `ChurchServiceItem` (`semanticSectionType()`, song/service relations) — the OoS items passed to
  the prompt.
- `SermonCandidateConfidenceService`, `AssessSermonVideoQuality`, the publication approval workflow —
  unchanged safety layers.

---

## Phase 1 — Full-service timestamped transcript

**New contract** `App\Contracts\ServiceTranscriptionInterface`:

```php
public function transcribeService(string $audioOrVideoPath, string $processingId): ChurchServiceTranscript;
```

**New data object** `App\Data\ChurchServiceTranscript`: ordered timed cues
`array<{start: float, end: float, text: string}>` + total duration + source label
(`whisper_api|local_whisper|mock|sidecar`), with `toPromptText()` (compact `[mm:ss] text` lines for
the LLM) and `sliceText(float $start, float $end): string`. JSON-serialisable.

**Implementations + binding** (in `AiServiceProvider`, keyed by
`config('media-processing.service_structure.transcription_service')`, default `mock`, **throw** on
unknown value):

- `OpenAiServiceTranscriptionService` — Whisper `verbose_json` (segment timestamps) on the extracted
  service audio. Handle the 25 MB API limit by extracting mono low-bitrate audio via the existing
  ffmpeg tooling and chunking on RMS silences when still oversized, re-offsetting cue times.
- `LocalWhisperServiceTranscriptionService` — mirrors `LocalWhisperTranscriptionService`, requesting
  segment output.
- `MockServiceTranscriptionService` — returns a fixture transcript (settable per-test), CI default.

**New job** `App\Jobs\TranscribeFullService` — runs after `GenerateRmsLog`; stores the transcript
JSON on the working disk and records `processing_metadata['service_transcript_path']` (mirror the
`rms_log_path` precedent). Idempotent on re-run (overwrite by processing id).

Existing `TranscribeSpeechSegments` and `TranscribeAudio` are untouched in this phase.

**Tests (all fixture/mock):** data-object round-trip + `sliceText` unit tests; job feature test with
`Storage::fake` + mock binding; OpenAI implementation unit test with a faked HTTP `verbose_json`
response including the chunk re-offset path; binding test (mode `mock` in CI, exception on unknown).

## Phase 2 — Structure detection contract + LLM adapter

**New contract** `App\Contracts\ServiceStructureInterface`:

```php
public function detect(
    ChurchServiceTranscript $transcript,
    array $oosItems,              // list<array{id:int, position:int, type:string, title:?string, song_id:?int}>
    ?string $processingId = null,
): ServiceStructure;
```

**New data object** `App\Data\ServiceStructure` — ordered `ServiceStructureSection[]`:

```
type            ServiceSectionType value
title           ?string  (British English)
start_time      float    seconds into the recording
end_time        float
confidence      float 0–1
oos_item_id     ?int     matched ChurchServiceItem id, null when nothing matches
song_title      ?string  only for type=song — the sung title as heard, for DB confirmation
reading_reference ?string only for type=bible_reading — e.g. "Joshua 1:1-9"
notes           list<string>
```

plus run-level `notes[]` and `model` metadata, and `toClassifiedSections(...)` (Phase 3 mapper).

**Implementations + binding** (`service_structure.detector` = `mock|openai`, throw on unknown;
model knob `service_structure.model`, default `gpt-5` — this owns the sermon-vs-children's-talk
judgement, same reasoning as the existing classification model knob):

- `OpenAiServiceStructureService` — one chat call, strict `json_schema` response format,
  temperature 0.1. Prompt rules: preserve running order; cover the whole recording (gaps allowed
  only for silence/no-speech); **do not invent sections**; whole readings and whole songs as single
  sections even across pauses (kills F1/F16); no sections shorter than 15 s unless the OoS demands a
  discrete item (kills F7); `childrens_talk` requires structural cues — children addressed/dismissed
  (F11); exactly one primary `sermon` unless genuinely absent — flag low confidence rather than
  guess; each `oos_item_id` used at most once, `null` when unsure; timestamps must come from the
  supplied cue timings, never estimated.
- `MockServiceStructureService` — deterministic, fixture/settable output; CI default.

**Tests:** OpenAI adapter unit tests against faked HTTP responses (valid; malformed JSON → throws;
unknown enum type → normalised to `other` + review flag); mock determinism; prompt-builder snapshot
test asserting OoS ids and cue timestamps are present and the invariant rules are stated.

## Phase 3 — Deterministic gate: validation, snapping, mapping

All pure/near-pure classes, fully unit-testable with fixtures. This is the layer that makes LLM
reliance safe — **no LLM output reaches `sync()` without passing it.**

- `App\Services\ChurchService\Structure\ServiceStructureValidator::validate(ServiceStructure, ValidationContext): ValidationResult`
  — hard failures (route run to manual review): non-chronological or overlapping sections; coverage
  below a configurable floor of the recording's speech time; more than one primary sermon; sermon
  duration outside bounds (share `max_sermon_duration_seconds` from the F10 work); any
  `oos_item_id` unknown, duplicated, or type-incompatible with the section type
  (via `ChurchServiceItem::semanticSectionType()`); timestamps outside the recording. Soft failures
  (per-section review flags, run continues): section-level low confidence; unmatched OoS items;
  micro-sections. New review flags **must** be registered in
  `SectionAlignmentBaselineRestorer::OOS_REVIEW_FLAGS`/`OOS_REVIEW_REASONS` so re-runs clear them
  (the F18 trap).
- `App\Services\ChurchService\Structure\SilenceSnapService` — parse the `rms_log_path` artifact;
  snap each boundary to the nearest silence within `snap_window_seconds` (default 30); leave
  unsnapped (and note it in metadata) when no silence is in range. Never snap a boundary across
  another section's midpoint.
- `ServiceStructure::toClassifiedSections(MediaProcessingLog): array` — emit the exact
  `ClassifiedSection` shape `sync()` expects: `section_order` by start time,
  `confidence_level` derived via `ServiceSectionConfidence`, `status = identified`,
  `church_service_item_id` from the validated `oos_item_id`, `source_segment_ids` resolved by time
  overlap with `LivestreamSegment` rows (empty array + metadata note when segments are absent —
  confirm `sync()` tolerates this; if not, synthesise a single covering segment, decided at
  implementation time against `ServiceSection::validationRules()`), and `metadata`
  (`classification_mode = 'llm_structure'`, model, notes, snap deltas, reading_reference,
  song_title).

**Tests:** validator matrix (each hard/soft rule, one fixture per rule); snapping against a fixture
RMS log (snaps within window, refuses out-of-window, never crosses midpoint); mapper shape test
asserted against `ServiceSection::validationRules()` and a golden `sync()` round-trip feature test
(persist → resolver sees sections above/below `HIGH_THRESHOLD` behaving as today).

## Phase 4 — Pipeline wiring: `off` / `shadow` / `primary`

**Config** `config/media-processing.php` → new `service_structure` block:

```php
'service_structure' => [
    'mode'                  => env('SERVICE_STRUCTURE_MODE', 'off'),      // off|shadow|primary
    'detector'              => env('SERVICE_STRUCTURE_DETECTOR', 'mock'), // mock|openai
    'model'                 => env('SERVICE_STRUCTURE_MODEL', 'gpt-5'),
    'transcription_service' => env('SERVICE_TRANSCRIPTION_SERVICE', 'mock'), // mock|openai|local
    'snap_window_seconds'   => (int) env('SERVICE_STRUCTURE_SNAP_WINDOW', 30),
    'min_section_seconds'   => (int) env('SERVICE_STRUCTURE_MIN_SECTION', 15),
    'coverage_floor'        => (float) env('SERVICE_STRUCTURE_COVERAGE_FLOOR', 0.7),
],
```

Unknown `mode`/`detector`/`transcription_service` values throw at resolution time (no silent
fallback).

**New job** `App\Jobs\DetectServiceStructure` — orchestrates: load transcript artifact → load OoS
items for the run's `ChurchService` → `detect()` → snap → validate → then:

- **shadow:** persist the mapped sections + validation result to
  `processing_metadata['service_structure_shadow']` and emit one structured log line diffing against
  the authoritative `service_sections` (type sequence match, per-boundary deltas, sermon
  start/end delta, OoS-anchoring agreement). No writes to `service_sections`. Any exception is
  caught, recorded, and never fails the run.
- **primary:** hard validation failure → mark run `manual_review_required` with the reasons (the
  operator lands in the existing segment-confirmation flow); otherwise
  `ServiceSectionSyncService::sync()` with the mapped sections.

**`ProcessingPipelineBuilder` changes** (mirrored in `buildSectionReclassificationChainJobs`):

- `off`: chains unchanged.
- `shadow`: insert `TranscribeFullService` + `DetectServiceStructure` immediately after the existing
  classification cluster (so the diff compares final heuristic output).
- `primary`: livestream chain becomes
  `TranscribeFullService → DetectServiceStructure → MatchSongsFromTranscript → ExtractSermon → …`
  (heuristic cluster jobs `AnalyzeSegments`\*, `ClassifyServiceSections`, `TranscribeSpeechSegments`,
  `ClassifySpeechSections`, `ProjectLivestreamServiceStructure`, `AlignWithOos`,
  `ResolveReadingReferences`, `ReclassifyIntroOutroSections` are omitted from the chain).
  \*`AnalyzeSegments` stays **only if** Phase 3 settles on real `source_segment_ids`; otherwise
  omitted. `ProjectLivestreamServiceStructure`'s church-service projection responsibilities must be
  audited before omission — anything load-bearing beyond section classification moves into
  `DetectServiceStructure` or stays as a retained job.

**Adaptations of retained jobs (primary mode only):**

- `MatchSongsFromTranscript` — new first-choice input: the LLM's `song_title` per song section,
  confirmed against the songs table via the existing normalised matching
  (`Song::matchKeyVariants()`, the F13/F18 corroboration rules). Existing transcript/OCR paths
  remain as fallback for sections without a proposed title.
- Reading references: carried on section metadata from the LLM (validated format), so
  `ResolveReadingReferences` does not run; `SermonExtractionPlanResolver`'s ranked-evidence reading
  selection reads the same metadata keys it does today (verify key parity — F12 benediction guard
  logic moves into the validator as a position/duration check on `bible_reading` metadata).

**Tests:** builder tests asserting exact job lists per mode ×
(livestream|reclassification|post-review) — post-review chains are mode-independent; shadow-mode
feature test (mock detector): heuristic sections untouched, shadow metadata written, diff logged,
detector exception swallowed; primary-mode feature tests: happy path end-to-end to `ExtractSermon`
readiness with factory data; hard-validation-failure path lands in `manual_review_required`;
low-confidence sermon path routes to review via the existing gate; song-title confirmation and
reading-reference metadata parity tests.

## Phase 5 — Evaluation + shadow-report tooling (ships now, runs on real data later)

Console-only; never queued. These are the maintainer's go/no-go instruments — building them does not
require real data, only running them does.

- `php artisan structure:evaluate {--manifest=} {--processing-id=*} {--detector=openai} {--report=}`
  — for each entry: load the stored full-service transcript (or a transcript file given in the
  manifest), run detect+snap+validate, compare against expectations, write JSON + console table.
  **Manifest format** (documented in the command + a committed example fixture):
  `docs/operations/structure-eval-manifest.example.json` — per service: expected typed intervals
  with tolerances, sermon start/end ±s, expected song titles, expected reading references, expected
  OoS anchorings. The maintainer fills this from real services (the nine regression scenarios are a
  natural seed but are *observed* baselines — the manifest is human-reviewed truth).
  **Metrics:** sermon |Δstart|/|Δend| (% within 15 s/30 s), section-type accuracy and ordering,
  OoS-anchoring precision/recall, song-title match rate, reading-reference accuracy, hard/soft
  validation trigger rate, cost + latency per call.
- `php artisan structure:shadow-report {--since=} {--processing-id=*}` — aggregates the
  `service_structure_shadow` metadata across past runs into the same report shape: agreement rates
  vs the heuristic path, boundary deltas, would-have-flagged counts. This is the zero-effort data
  source: once the maintainer sets `mode=shadow` in production, every Sunday accumulates evidence.
- **Suggested promotion gate** (maintainer confirms against real numbers): sermon start & end
  within 30 s on ≥90% of manifest services; zero catastrophic misses (sermon mislabelled/wrong
  block); hard-validation failure rate low enough that manual review stays occasional.

**Tests:** both commands run in CI against the mock detector + committed fixture
manifest/transcripts and assert report shape and metric arithmetic (including a deliberately-wrong
fixture producing non-zero deltas).

## Phase 6 — Promote and retire (config flip + deletion; gated on Phase 5 evidence)

1. Maintainer flips production to `mode=primary`, `detector=openai`,
   `transcription_service=openai`. Instant rollback = revert env.
2. After an agreed soak (suggested: ~8 consecutive services with no catastrophic miss and an
   acceptable review rate), delete the bridge:
   - **Jobs:** `ClassifyServiceSections`, `ClassifySpeechSections`, `TranscribeSpeechSegments`,
     `ProjectLivestreamServiceStructure`, `AlignWithOos`, `ReclassifyIntroOutroSections`,
     `ResolveReadingReferences`; `AnalyzeSegments` per the Phase 4 decision.
   - **Services:** `ServiceSectionClassifier`, `SpeechSectionClassificationService`,
     `StructuralSectionAligner`, `OosAlignmentService`, `SectionItemAlignmentScorer`,
     `AlignmentTriggerCalculator`, `SongSectionAligner` (keep the DB-matching core reused by
     `MatchSongsFromTranscript`), `StructureMergePolicy`/`ChurchServiceStructureMergeService`,
     `LivestreamChurchServiceProjectionService`, `ReadingReferenceExtractor`,
     `PresentationItemClassifier`, `LivestreamSectionToServiceItemMapper` — audit each for
     non-cluster consumers before deletion.
   - Collapse `mode` to `shadow|primary`; delete heuristic-path builder branches and their tests;
     retire `scripts/section-extraction/` scenario scripts in favour of the eval harness.
3. **Keep permanently:** `GenerateRmsLog` (snapping), `SermonCandidateConfidenceService` +
   `SermonExtractionPlanResolver` gates, the entire extraction/enhancement/publication/review tail,
   the eval harness (it is the regression suite for every future prompt/model change — model
   upgrades are an eval run, not a config flip).

Each deletion lands as its own commit with a full green suite; this phase only starts on the
maintainer's explicit go.

## What is explicitly *not* in scope

- OBS-LocalVocal sidecar ingestion (separate plan; later it can simply become another
  `ServiceTranscriptionInterface` implementation).
- Captions on the archive; semantic sermon search (separate plans).
- Any change to audio-only / direct-video / auto-trim pipelines beyond what the builder tests pin
  (auto-trim keeps the heuristic path until `primary` proves out on livestreams, then follows).
- Changing the published-sermon transcript source (`TranscribeAudio` still transcribes the enhanced
  extracted sermon — quality for the public archive is a separate decision).

## Quality gates (every phase)

- `vendor/bin/sail bin pint --dirty --format agent`
- `vendor/bin/sail composer phpstan` (0 errors)
- `vendor/bin/sail artisan test --compact` with focused filters per change, full
  `--parallel` before finishing a phase (known sermon-identity ordering flake: rerun once)
- `vendor/bin/sail artisan dusk` only for phases touching upload/review UI (none planned)
- CI never calls an external API: `mock` bindings are the test default; OpenAI adapters are tested
  against faked HTTP responses only.
- British English in all user-facing strings, prompts, flags, and assertions.

## Risks and mitigations

- **LLM timestamp drift** → boundaries are proposals: silence-snapping + validator + confidence
  gate; measured by the shadow diff before promotion.
- **Hallucinated/missing sections** → strict json_schema, "do not invent" prompt rules, hard
  validator (coverage floor, single sermon, OoS uniqueness), manual-review routing. A bad call costs
  one flagged run, never a bad published sermon.
- **Building against fixtures ≠ real-world fit** → the design cost of being wrong is contained:
  everything lands dark behind `off`, shadow mode gathers real evidence with zero behaviour change,
  and prompts/thresholds are config/data, not architecture. Expect prompt iteration after the first
  shadow reports; the harness makes each iteration measurable.
- **Whisper 25 MB / long-service limits** → chunk-on-silence with re-offset, unit-tested against
  synthetic fixtures.
- **Regression during the bridge period** → heuristic path stays byte-identical until `primary`;
  builder tests pin every mode's job list; post-review chains are mode-independent so the operator
  escape hatch always works.
- **Model/vendor dependence** → interface-bound, model pinned in config, eval harness re-run on any
  model change.

## Suggested order of work

1. Phase 1 (transcript) + Phase 2 (detector) — independent of each other after the data object
   lands; can be parallel PRs.
2. Phase 3 (gate) — depends on Phases 1–2 data objects.
3. Phase 4 (wiring) — depends on Phase 3; ships with `mode=off`.
4. Phase 5 (tooling) — depends on Phase 3; can overlap Phase 4.
5. Maintainer: enable `shadow` in production; fill the manifest; run the reports over a few weeks.
6. Phase 6 — gated on 5.

Rough effort: Phases 1–2 ≈ 1 day each; Phase 3 ≈ 1 day; Phase 4 ≈ 1–1.5 days; Phase 5 ≈ 0.5–1 day;
Phase 6 small but audit-heavy. All of 1–5 is implementable in this repository with no local access.
