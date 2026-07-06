# LLM Service-Structure Detection — Spike Plan (2026-06-19)

> **ARCHIVED 2026-07-05 — superseded in full by
> [LLM-FIRST-SERVICE-STRUCTURE-PIPELINE-2026-07-01.md](LLM-FIRST-SERVICE-STRUCTURE-PIPELINE-2026-07-01.md)**
> (itself now archived with phases 1–5 shipped; the remaining retirement work is Workstream 1 of
> [../plans/JULY-2026-SIMPLIFICATION-BACKLOG-2026-07-05.md](../plans/JULY-2026-SIMPLIFICATION-BACKLOG-2026-07-05.md)).
> The successor plan deliberately departs from this spike in three ways (LLM owns OoS anchoring;
> one whole-recording Whisper pass; the heuristic cluster is a bridge to delete, not a permanent
> fallback). Do not implement anything from this document.

## Recommendation

Do **not** replace the media-processing pipeline with "one AI call". The YouTube/Gemini demo
reproduces only the *descriptive* output of the pipeline's brittle heuristic middle
(segmentation + section classification). It produces none of the pipeline's actual deliverables:
the trimmed sermon `.mp4`/`.mp3`, enhanced audio, thumbnail, stored transcript, the `Sermon`
record that powers the public archive/SEO/sitemap/preacher index, song matches, the confidence
safety-net, or retryable/observable processing.

Instead, run a **bounded spike** that tests whether a single structured LLM call over the
already-available timestamped transcript can replace the heuristic *classification* cluster
(`ClassifyServiceSections` → `ClassifySpeechSections` → `ProjectLivestreamServiceStructure` →
`ReclassifyIntroOutroSections`, with `AlignWithOos` folded into the prompt), feeding its output
into the **unchanged** extraction + persistence layers via the existing
`ServiceSectionSyncService::sync()` seam.

The spike is **offline-first**: it evaluates LLM section structure against past, already-processed
services *before* any pipeline rewiring, directly answering "is the demo real on our own data?"
with zero production risk. Only on a passing evaluation do we wire it in behind a config flag,
keeping the heuristic classifier as a fallback.

## Why this is the right shape

- **The demo's magic is transcript reading, not video.** Gemini produced the order of service from
  the auto-transcript. Any strong text LLM does this — we are **not** locked to Gemini, and we need
  **no multimodal/video input**. This lets the spike reuse the already-installed OpenAI SDK with
  **no new dependency** (mirrors `OpenAiOosEmailItemExtractor`).
- **We already have the input mid-pipeline.** `TranscribeSpeechSegments` produces a timestamped
  transcript of the speech segments *before* the classification cluster runs. The LLM consumes
  exactly what `ClassifySpeechSections` consumes today.
- **One seam, not a rewrite.** Downstream only reads the `service_sections` table. Emit the same
  array shape, call the same `sync()`, and `ExtractSermon` + confidence guards keep working.

## Background — what already exists (reuse, don't rebuild)

- **Pipeline definition:** `App\Services\Processing\ProcessingPipelineBuilder::buildLivestreamChainJobs()`.
- **The classification seam:**
  - `App\Services\ChurchService\ServiceSectionClassifier::classify(MediaProcessingLog): array`
    — current heuristic (RMS/visual, confidence scoring). Returns `['skipped','skip_reason','sections'=>[...]]`.
  - `App\Services\ChurchService\ServiceSectionSyncService::sync(MediaProcessingLog, array $classifiedSections): void`
    — persists rows to `service_sections` (validates, supersedes changed rows, cleans up extracted assets).
- **Downstream consumer:** `App\Services\Sermon\SermonExtractionPlanResolver::resolve()` reads
  `ServiceSection` rows where `status = Identified`, `needs_manual_review = false`,
  `confidence >= ServiceSectionConfidence::HIGH_THRESHOLD`. The LLM output must clear that bar to
  drive auto-extraction; otherwise `ExtractSermon`'s existing guard
  (`SermonCandidateConfidenceService`, the 20-min / 1.5× ratio rule) routes to manual review.
- **The existing LLM pattern to mirror exactly:** `App\Services\Email\OpenAiOosEmailItemExtractor`
  — `OpenAI::chat()->create([... 'response_format' => ['type'=>'json_schema', ...], 'temperature'=>0.1])`,
  config-driven model, JSON decode + normalisation, `RuntimeException` on empty/invalid response.
- **The interface + binding pattern to mirror:** `App\Contracts\SermonAnalysisInterface`
  (+ `App\Data\SermonAnalysis`) bound in `App\Providers\AiServiceProvider` via
  `match(config('media-processing.<domain>.service'))` over `mock | local | openai`. CI defaults to
  `mock` so the suite never calls an external API.
- **Boundary signal for snapping:** `GenerateRmsLog` output (silence/loudness) — used to snap LLM
  boundaries to the nearest real silence.
- **Section taxonomy:** `App\Enums\ServiceSectionType` =
  `welcome | prayer | notices | song | childrens_talk | bible_reading | sermon | other`.
- **OOS context (optional prompt input):** `OosEmailItemExtractor` already yields the ordered
  service list from the weekly email — pass it to the LLM to anchor labels (replaces `AlignWithOos`).

## The exact persistence contract (LLM adapter must emit this)

Each element of the `array $classifiedSections` passed to `ServiceSectionSyncService::sync()`:

```php
[
    'church_service_item_id' => null,            // or matched OOS item id
    'section_type'           => 'sermon',        // ServiceSectionType value
    'section_order'          => 0,               // int, ascending by start_time
    'title'                  => null,            // or LLM-derived human title
    'start_time'             => 1234.5,          // float seconds
    'end_time'               => 2890.0,          // float seconds
    'duration'               => 1655.5,          // float seconds
    'confidence'             => 0.92,            // float; gate is ServiceSectionConfidence::HIGH_THRESHOLD
    'status'                 => 'identified',     // ServiceSectionStatus value
    'needs_manual_review'    => false,
    'source_segment_ids'     => [12, 13, 14],     // LivestreamSegment ids this section spans
    'metadata'               => [
        'confidence_level'   => 'high',          // required by ClassifyServiceSections counters
        'classification_mode'=> 'llm',
        'model'              => 'gpt-4o-mini',
        'llm_notes'          => '...',
    ],
]
```

> Match this against `ServiceSectionClassifier`'s current return value when implementing — it is the
> source of truth for required keys (`source_segment_ids`, `metadata.confidence_level`, etc.).

## New code (all small, all behind interfaces)

- `App\Contracts\ServiceStructureInterface`
  ```php
  public function detect(
      ChurchServiceTranscript $transcript,   // timestamped speech segments
      array $orderOfServiceItems = [],        // optional OOS context
      ?string $processingId = null
  ): ServiceStructure;
  ```
- `App\Data\ServiceStructure` — ordered `ServiceStructureSection[]` (type, title, start, end,
  confidence) + `notes[]` + overall `confidence`. Includes a `toClassifiedSections(): array` mapper
  that produces the `sync()` shape above (incl. `source_segment_ids` resolved by overlap with the
  source `LivestreamSegment`s, and `confidence_level` derived from the float).
- `App\Services\ChurchService\Structure\OpenAiServiceStructureService implements ServiceStructureInterface`
  — mirrors `OpenAiOosEmailItemExtractor` (json_schema below, `temperature` 0.1, config model).
- `App\Services\ChurchService\Structure\MockServiceStructureService` — deterministic; CI default.
- `App\Services\ChurchService\Structure\HeuristicServiceStructureAdapter` — wraps the existing
  `ServiceSectionClassifier` so the *current* behaviour is reachable through the same interface
  (this is the fallback, and keeps a single call-site in the job).
- Binding in `AiServiceProvider`:
  ```php
  $this->app->bind(ServiceStructureInterface::class, fn ($app) => match (
      config('media-processing.section_structure.service', 'mock')
  ) {
      'openai'    => $app->make(OpenAiServiceStructureService::class),
      'heuristic' => $app->make(HeuristicServiceStructureAdapter::class),
      default     => new MockServiceStructureService(),
  });
  ```
- Config block `config/media-processing.php` → `section_structure`:
  ```php
  'section_structure' => [
      'service'             => env('SECTION_STRUCTURE_SERVICE', 'mock'),   // mock|openai|heuristic
      'model'               => env('SECTION_STRUCTURE_MODEL', 'gpt-4o-mini'),
      'snap_to_silence'     => env('SECTION_STRUCTURE_SNAP_TO_SILENCE', true),
      'snap_window_seconds' => env('SECTION_STRUCTURE_SNAP_WINDOW', 30),
      'pass_oos_context'    => env('SECTION_STRUCTURE_PASS_OOS', true),
  ],
  ```

### LLM JSON schema (structured output)

```json
{
  "sections": [
    {
      "type": "welcome|prayer|notices|song|childrens_talk|bible_reading|sermon|other",
      "title": "string",
      "start_time": 0.0,
      "end_time": 0.0,
      "confidence": 0.0
    }
  ],
  "sermon": { "start_time": 0.0, "end_time": 0.0, "confidence": 0.0 },
  "notes": ["string"]
}
```

Prompt rules (mirror the OOS extractor's tone): preserve running order; do not invent sections;
British English titles; `start_time`/`end_time` are seconds into the recording taken from the
supplied segment timestamps; flag low confidence rather than guessing; use the OOS list (when
supplied) to anchor labels and ordering.

### Timestamp precision — snap to silence

LLM boundaries derived from transcript text drift by seconds. When `snap_to_silence` is on, snap
each `start_time`/`end_time` to the nearest silence in the `GenerateRmsLog` output within
`snap_window_seconds`; otherwise keep the LLM value. This is the hybrid: **LLM proposes, RMS
refines** — more robust than either alone, and it reuses a signal we already compute.

## Phases

### Phase 0 — Offline evaluation harness (do this first; decides everything)
- `php artisan spike:section-structure {--limit=} {--processing-id=*} {--service=openai}`
  (console-only; never wired into queues).
- For each chosen *already-processed* run: load its timestamped speech transcript, call the bound
  `ServiceStructureInterface`, map to sections, and write a comparison report (JSON + console table)
  against (a) the persisted heuristic `service_sections` and (b) ground truth where known — the
  actual published sermon's start/end from past extractions
  (`processing_metadata.trim` / the linked `Sermon`).
- **Metrics:**
  - Sermon boundary: |Δstart| and |Δend| in seconds vs ground truth; % within 15s / 30s.
  - Section-type accuracy + ordering correctness vs heuristic and (spot-checked) human truth.
  - Manual-review trigger rate (would the confidence gate have fired?).
  - Cost/latency per service.
- **Go/no-go gate (proposed):** sermon start & end within 30s on ≥90% of sampled services, with no
  catastrophic misses (sermon mislabelled / wrong block) — tune with the maintainer on real numbers.
  Run against the back-catalogue, not one example.

### Phase 1 — Interface + adapters + tests (only if Phase 0 passes)
- Add `ServiceStructureInterface`, `ServiceStructure` data + mapper, `Mock`/`OpenAi`/`Heuristic`
  implementations, config block, `AiServiceProvider` binding.
- Tests: Mock-bound unit tests for the mapper (`toClassifiedSections` shape, `source_segment_ids`
  overlap, `confidence_level` derivation); OpenAI impl unit test with a **faked** OpenAI HTTP
  response (mirror the OOS extractor test); silence-snapping unit test against a fixture RMS log.

### Phase 2 — Wire behind a flag (shadow first)
- Add an `LlmClassifyServiceSections` job (or a branch inside `ClassifyServiceSections`) selected by
  `section_structure.service`. Default stays `mock` in tests, `heuristic` in production until proven.
- **Shadow mode:** run the LLM detector alongside the heuristic, persist its output to
  `metadata` only (not to `service_sections`), and log the diff — gathers live comparison data with
  zero behaviour change. Reuse the existing reclassification chain
  (`buildSectionReclassificationChainJobs`) to backfill/compare on demand.

### Phase 3 — Promote + retire (only after shadow data is convincing)
- Flip production `section_structure.service = openai`, heuristic remains the fallback path.
- Jobs that become **retirable** once the LLM path owns classification (delete only after a full
  green suite + a real-data soak):
  - `ClassifySpeechSections`
  - `ProjectLivestreamServiceStructure`
  - `ReclassifyIntroOutroSections`
  - `AlignWithOos` (folded into the LLM prompt via OOS context)
  - `ClassifyServiceSections` heuristic body (kept as the `heuristic` fallback adapter, not deleted)
- Jobs that **stay** regardless: `GenerateRmsLog` (needed for silence-snapping),
  `TranscribeSpeechSegments` (LLM input), and the entire artifact/persistence/safety layer
  (`ExtractSermon`, `EnhanceAudio`, `CreateSermonRecord`, `IdentifySpeaker`, `TranscribeAudio`,
  `ProcessTranscriptWithAI`, `AssessSermonVideoQuality`, `GenerateThumbnail`,
  `PrepareSectionPublicationCandidates`, `SendCompletionNotification`, `CleanupTemporaryFiles`).
- `AnalyzeSegments` / `PerformVisualAnalysis`: keep for now (Option A). Only revisit if a later,
  separate spike has the LLM own boundary *detection* end-to-end (Option B) rather than labelling
  RMS-derived boundaries.

## Testing & quality gates

- CI never calls an external API: binding defaults to `mock`; OpenAI impl tested with a faked HTTP
  response only.
- New/updated tests run via `vendor/bin/sail artisan test --compact --filter=...`.
- Before finalising any phase, run the project's four gates: `pint --dirty`, `composer phpstan`,
  `artisan test --compact --parallel`, `artisan dusk`.

## Risks & mitigations

- **Timestamp drift** → silence-snapping (Phase 1) + the existing confidence gate catching bad spans.
- **"Perfect on one example" ≠ robust** → Phase 0 evaluates the back-catalogue before any wiring.
- **Hallucinated/duplicated sections** → strict json_schema, "do not invent", `sync()` validation
  (`ServiceSection::validationRules()`), and the HIGH_THRESHOLD gate before auto-extraction.
- **Cost/latency** → one cheap chat call per service replacing several jobs; measured in Phase 0.
  Negligible next to the unchanged ffmpeg/Whisper steps.
- **Vendor coupling** → interface-bound; `mock`/`heuristic`/`openai` swappable; a Gemini adapter is
  a future drop-in **behind the same interface** (would need dependency approval per CLAUDE.md).
- **Edge cases the heuristics encode** (children's-talk vs sermon polymorphism, morning↔evening
  service slotting, intro/outro) → explicitly represented in the schema/prompt and verified in
  Phase 0; heuristic fallback remains until parity is proven.

## Open decisions (settle with maintainer)

1. **Provider for the spike:** OpenAI (zero new deps, mirrors existing code) — recommended — vs
   adding a Gemini SDK (needs dependency approval). Default: OpenAI.
2. **Go/no-go thresholds:** confirm the 30s / 90% gate against real Phase-0 numbers.
3. **Option B (LLM owns boundary detection + RMS snapping, retiring `AnalyzeSegments`):** defer to a
   follow-up spike, or fold in now? Default: defer.

## Effort (rough)

- Phase 0 harness + first eval: ~1 day.
- Phase 1 interface/adapters/tests: ~1 day.
- Phase 2 shadow wiring: ~0.5 day.
- Phase 3 promote/retire: gated on soak data; the deletions are small once parity holds.
