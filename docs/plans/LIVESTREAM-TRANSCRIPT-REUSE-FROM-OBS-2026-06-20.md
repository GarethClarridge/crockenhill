# OBS-LocalVocal Transcript Sourcing — Plan (2026-06-20)

> **Gate update (2026-07-24): the re-scope trigger has fired.** Item 1.5 deleted
> `TranscribeSpeechSegments` with the heuristic cluster (`6683a70cf`), and item 1.7a landed
> 2026-07-21: `CreateSermonTranscriptFromService` (`f332427ea`) slices the full-service transcript
> for the sermon, so the pipeline now pays **one** Whisper pass per service, behind
> `ServiceTranscriptionInterface` with `LocalWhisperServiceTranscriptionService`,
> `OpenAiServiceTranscriptionService` and `MockServiceTranscriptionService` as its implementations.
> **Part B is now exactly "one more implementation of that interface" plus ingest and trust-gate
> plumbing** — write it that way. The cost case has halved accordingly; a local whisper.cpp sidecar
> on the prod box (`TRANSCRIPTION_SERVICE_TYPE=local`) is a competing, cheaper-to-build route to the
> same saving and should be compared before building the OBS ingest path.
>
> **Status (2026-07-05): deferred — Part B is stale as drafted; do not implement it from this
> document.** The two Whisper passes this plan saves are changing under
> [JULY-2026-SIMPLIFICATION-BACKLOG-2026-07-05.md](JULY-2026-SIMPLIFICATION-BACKLOG-2026-07-05.md):
> `TranscribeSpeechSegments` is deleted with the heuristic cluster (item 1.5), and item 1.7a
> collapses transcription to **one** Whisper pass behind `ServiceTranscriptionInterface` — which
> the LLM-first work built as exactly the seam a sidecar should plug into (a sidecar-backed
> implementation returning `ChurchServiceTranscript`). After 1.7a lands, re-scope Part B as that
> single adapter plus the ingest/trust-gate plumbing below (Phase 0's offset calibration and the
> trust gate remain valid as designed). **Part A (live subtitles via the OBS plugin) is purely
> operational, unaffected, and can be done any time.** Production currently pays for OpenAI
> Whisper per run, so the cost saving still exists — it just shrinks to one pass.

## Recommendation

Capture a **real-time local-Whisper transcript during the livestream** with
[obs-localvocal](https://github.com/royshil/obs-localvocal), and **reuse that transcript inside the
processing pipeline** instead of paying for OpenAI Whisper on every run. This delivers two things:

1. **Live subtitles** on the broadcast (accessibility) — an operational OBS-side win.
2. **Cost + latency savings** in the pipeline: the sidecar transcript replaces the **two paid Whisper
   passes** that run per livestream today (see Background), sliced by time range onto each speech
   section and onto the published sermon.

Do this **behind a fallback**, never load-bearing: when a valid sidecar is present, use it; otherwise
fall through to the existing Whisper path unchanged. The pipeline's most critical inputs must keep
working when the operator forgets the sidecar, the plugin misbehaves, or the upload is a YouTube backup.

This plan is **decoupled from** `docs/archived-plans/LLM-SERVICE-SECTION-CLASSIFICATION-SPIKE-2026-06-19.md`. The spike
consumes a timestamped transcript regardless of its source; this plan only changes *where the transcript
comes from*. They share the transcription seam but have no dependency, and ship in either order.

## Scope — two parts, different surfaces

- **Part A — Live subtitles (operational, ~no app code).** localvocal runs on the streaming machine,
  renders captions, and emits embedded/streamed captions to YouTube. This is OBS plugin configuration,
  not a Laravel change. In scope here only as the thing that *also produces the sidecar file*.
- **Part B — Transcript reuse (the app work).** Ingest the sidecar with the upload, and let the pipeline
  prefer it over Whisper. **This plan is primarily about Part B.**
- **Optional follow-on — captions on the archive.** Store the sidecar (as WebVTT) against the `Sermon`
  and serve it as a `<track>` on the public video player. Separable; out of scope unless prioritised.

## Background — what already exists (reuse, don't rebuild)

- **The transcription seam:** `App\Contracts\TranscriptionServiceInterface` — `transcribe(string
  $audioFilePath, string $processingId, ?string $disk): string` plus store/get/exists/delete/cleanup.
  Bound in `App\Providers\AiServiceProvider` via `match(config('media-processing.transcription.service'))`
  over `mock | local | openai` → `MockTranscriptionService | LocalWhisperTranscriptionService |
  AudioTranscriptionService`.
- **Prod runs the paid path.** `.env.example` documents `TRANSCRIPTION_SERVICE_TYPE` as *"mock for dev,
  openai for prod, local for local Whisper."* So in production the following both call paid OpenAI Whisper:
  - `App\Jobs\TranscribeSpeechSegments` — extracts audio **per speech section** and transcribes each
    (`N` calls), writing text to `service_sections.metadata['transcript']`. This is the classifier / LLM
    spike input.
  - `App\Jobs\TranscribeAudio` — runs **after** `ExtractSermon` + `EnhanceAudio`, transcribes the
    extracted (enhanced) sermon, stores text to `transcript_file_path` on the log and `Sermon`.
  - Pipeline order (`ProcessingPipelineBuilder::buildLivestreamChainJobs`):
    `… → TranscribeSpeechSegments → [classification] → ExtractSermon → EnhanceAudio → TranscribeAudio →
    ProcessTranscriptWithAI → …`. An OBS sidecar can remove **N + 1** paid passes.
- **Ingestion is an operator file upload.** `App\Livewire\MediaUpload` has a single `mediaFile` input,
  plus `serviceOverride` and `autoTrimVideo`; there is **no** `yt-dlp`/URL download anywhere in `app/`,
  and no provenance column on `media_processing_logs`. The usual upload is the **untrimmed OBS recording**;
  a **manually downloaded YouTube VOD** is the backup when the OBS recording failed (also untrimmed).
- **Operator-hint precedent:** the morning↔evening `serviceOverride` already proves the form carries
  operator-supplied processing hints into the run — the sidecar is the same kind of addition.

## The design

### Why this is not a clean binding swap

`TranscriptionServiceInterface::transcribe()` is **audio → text**: it receives an *audio path* and a
`processingId`, not a time range. A sidecar is a *pre-existing whole-service transcript* that must be
**sliced by time range** to serve a section or the sermon span — and by the time `TranscribeAudio` runs,
the audio is already the extracted sermon starting at `0`, so the original master-timeline span lives in
the DB (`service_sections.start_time/end_time`; sermon trim in `processing_metadata.trim`), not in the
audio path. Therefore reuse belongs at the **job level**, where the time range is known — not purely
behind `transcribe()`.

### The seam to add

`App\Services\Media\Audio\ServiceTranscriptResolver` (small, injectable):

```php
public function textForRange(
    MediaProcessingLog $log,
    float $startTime,
    float $endTime,
    string $audioFilePathForFallback,   // existing per-section / sermon audio path
): string;
```

- If the log has a **valid** sidecar (present + passes the sanity check below): return the master
  transcript **sliced** to `[startTime, endTime]` (with the calibrated offset applied — see Phase 0).
- Otherwise: delegate to the bound `TranscriptionServiceInterface::transcribe($audioFilePathForFallback,
  …)` — i.e. **exactly today's behaviour**. Whisper stays the fallback, in one place.

`TranscribeSpeechSegments` calls it per section (skipping per-section audio extraction when the sidecar is
used); `TranscribeAudio` calls it once for the sermon span. Neither job's contract or downstream changes.

### Sidecar ingest + trust gate

- **Form:** add an optional `transcriptSidecar` file input to `MediaUpload` (accept `.srt`/`.vtt`/`.json`),
  staged like the source file and recorded on the run.
- **Schema:** migration adds nullable `transcript_sidecar_path` and `transcript_sidecar_format` to
  `media_processing_logs`.
- **Trust gate (sidecar-presence self-selects):** the YouTube backup physically has no matching sidecar,
  so it takes the fallback with no operator decision. When a sidecar *is* present, validate before trusting:
  - parse cleanly to timed cues;
  - last cue end-time within tolerance of the video duration (guards a mismatched/wrong-week file —
    `file_hash` on the log is the precedent for upload integrity);
  - non-empty coverage of the sermon span.
  Any failure → log a warning and fall back to Whisper.
- **Parsing:** a tiny in-repo `SubtitleCueParser` (SRT/VTT → `array<{start,end,text}>`). Avoid a new
  composer dependency (parsing is trivial; CLAUDE.md requires approval for dependency changes).

### Config

`config/media-processing.php` → `transcription`:

```php
'sidecar' => [
    'enabled'              => env('TRANSCRIPT_SIDECAR_ENABLED', false), // master flag
    'offset_seconds'       => (float) env('TRANSCRIPT_SIDECAR_OFFSET', 0.0), // calibrated in Phase 0
    'duration_tolerance'   => (int) env('TRANSCRIPT_SIDECAR_DURATION_TOLERANCE', 30),
    'shadow'               => env('TRANSCRIPT_SIDECAR_SHADOW', true), // compare-only, don't use
],
```

## Phases

### Phase 0 — Offline evaluation (do first; decides go/no-go)

Mirror the spike's offline-first discipline: prove the sidecar on real recordings before wiring anything.

- Collect a handful of **real OBS recordings + their localvocal sidecars** for past services.
- `php artisan spike:transcript-sidecar {--processing-id=*}` (console-only): for each, run today's Whisper
  passes **and** the sidecar slices, then report:
  - **Offset:** diff sidecar cue timings against offline Whisper word timings → is the offset a stable
    constant (0-based vs wall-clock origin)? Set `offset_seconds` from this. (Drift, not just offset,
    is a no-go for boundary use; the spike's silence-snapping + confidence gate absorb a few seconds.)
  - **Quality:** WER/spot-check of the sidecar text vs the offline transcript over the **enhanced** sermon
    audio — separately for (a) section-detection adequacy and (b) the **published** transcript bar.
- **Go/no-go:** stable offset within snap tolerance, and quality acceptable for section detection. The
  published-transcript decision is *independent* — it may stay on offline Whisper even if the sidecar is
  adopted for captions + section detection.

### Phase 1 — Sidecar ingest plumbing (behind `enabled`, no behaviour change)

- Form input, migration, staging/storage, `SubtitleCueParser`, validation/trust gate.
- Sidecar is **stored and validated but not yet consumed**. Tests: parser unit tests (SRT + VTT +
  malformed), trust-gate tests (missing / duration-mismatch / valid), upload-wiring feature test.

### Phase 2 — Wire reuse in shadow mode

- Add `ServiceTranscriptResolver`; call it from both jobs. With `shadow = true`, **compute** the sidecar
  slice, **log the diff** against the Whisper result, but **return Whisper's output** — zero behaviour
  change while gathering live comparison data. Tests use `mock` transcription + a fixture sidecar.

### Phase 3 — Promote (sidecar primary, Whisper fallback)

- Flip `shadow = false`: valid sidecar wins, Whisper remains the automatic fallback. Roll out with the
  master `enabled` flag; monitor fallback rate (how often a sidecar is absent/invalid) and cost delta.
- **Optional:** decide per Phase 0 whether `TranscribeAudio` (published transcript) uses the sidecar or
  stays on offline Whisper for quality. Optionally begin the captions-on-archive follow-on.

## Testing & quality gates

- CI never calls an external API: transcription binding stays `mock`; the resolver is exercised with a
  fixture sidecar and the mock fallback. Parser/trust-gate are pure unit tests.
- New/updated tests via `vendor/bin/sail artisan test --compact --filter=...`.
- Before finalising any phase, run the four gates: `pint --dirty`, `composer phpstan`,
  `artisan test --compact --parallel`, `artisan dusk`.

## Risks & mitigations

- **Timestamp offset / drift** → Phase 0 measures it; constant offset is calibrated out; silence-snapping
  + the confidence gate absorb residuals; drift is a no-go for boundary use.
- **Realtime transcript quality < offline-over-enhanced-audio** → fine for captions/section detection;
  keep offline Whisper for the published transcript if Phase 0 shows a gap. Decisions are independent.
- **Operator forgets the sidecar / plugin failed / YouTube backup** → sidecar-presence gate falls back to
  Whisper automatically. Graceful degradation: lose the saving that run, never correctness.
- **Wrong/mismatched sidecar** → duration + coverage validation before trust; warn-and-fall-back on failure.
- **New dependency** → obs-localvocal is an OBS plugin on the streaming machine, **not** a composer/npm
  dependency of this app; SRT/VTT parsing is in-repo. No app dependency change.

## Open decisions (settle with maintainer)

1. **Published transcript source:** sidecar vs keep offline Whisper for SEO-indexed quality. Default:
   decide on Phase 0 numbers; lean to keeping offline Whisper for the published transcript initially.
2. **Sidecar format to standardise on** from localvocal (`.srt` / `.vtt` / `.json`). Default: WebVTT
   (also reusable for the captions-on-archive follow-on).
3. **Captions-on-archive follow-on:** now or defer. Default: defer.

## Effort (rough)

- Phase 0 harness + first eval: ~1 day (gated on collecting real OBS recordings + sidecars).
- Phase 1 ingest plumbing + parser/tests: ~1 day.
- Phase 2 shadow resolver + tests: ~0.5 day.
- Phase 3 promote: small once shadow data holds. Captions-on-archive: separate, ~0.5–1 day if taken.
