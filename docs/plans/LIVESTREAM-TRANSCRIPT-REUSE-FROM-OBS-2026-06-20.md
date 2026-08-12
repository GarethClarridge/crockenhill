# OBS LocalVocal captions and transcript reuse

> **Status (comprehensively re-scoped 2026-08-12): evaluation-ready, app work deferred.** The
> original design targeted jobs and interfaces that have been deleted. The live pipeline now makes
> one timestamped whole-service pass through `ServiceTranscriptionInterface`, stores a durable
> `ChurchServiceTranscript`, and slices that artifact for the published sermon. The code already
> reserves `ChurchServiceTranscript::SOURCE_SIDECAR`, but no ingest, parser, path or resolver exists.
>
> Part A (LocalVocal live captions in OBS) is an independent operational accessibility win and may
> run now. Part B starts only after real recording/sidecar pairs show a stable clock and acceptable
> text quality. The competing baseline is the existing local-whisper full-service implementation,
> not the old two-pass paid pipeline.
>
> **Boundary:** this plan owns future live-service sidecars only. It does not acquire, repair,
> classify or promote historic media, and a sidecar must never bypass the historic-readiness
> manifests, processing fingerprint or Bundle A contract.

## Outcome

1. Broadcast usable live captions from the streaming computer.
2. When the same timed sidecar is trustworthy, reuse it as the service transcript and avoid the
   configured normal full-service transcription call.
3. Missing, malformed, mismatched or low-coverage sidecars automatically use the current
   transcriber. Correctness never depends on the operator remembering a second file.

The optional archive `<track>`/WebVTT feature is a later plan. It is not bundled with ingest.

## Current seam

- `ServiceTranscriptionInterface::transcribeService(path, processingId)` returns a timestamped
  `ChurchServiceTranscript`.
- `TranscribeFullService` stores the normalized transcript through `ServiceArtifactStorage` and
  records the path on `MediaProcessingLog`.
- `DetectServiceStructure`, song matching and `CreateSermonTranscriptFromService` all consume that
  one artifact.
- OpenAI and local-whisper implementations both provide timed cues. Production can therefore save
  API cost already by selecting the local implementation; sidecar reuse must beat that simpler
  option on total operator effort, latency or live-caption value.

This means reuse belongs immediately before the existing interface call. Do not change downstream
jobs or introduce a second transcript model.

## Part A — live captions (operational, independently deliverable)

1. Install/configure LocalVocal on the OBS machine using the operator's normal plugin-management
   process.
2. Select one timed export format for the trial (WebVTT recommended because it can later feed a
   browser `<track>` unchanged).
3. Run a private/unlisted broadcast smoke test covering microphone speech, music, silence and scene
   changes. Verify captions on the actual viewer surface, not only in OBS.
4. Record CPU/GPU headroom, caption delay, dropped-caption behaviour and whether the sidecar closes
   cleanly after OBS stops.

Part A is complete when live captions are usable even if Part B is rejected.

## Part B0 — offline go/no-go (no repository change)

Collect several real OBS recordings and their exact LocalVocal sidecars. Compare each against the
stored normal `ChurchServiceTranscript` from processing the same recording.

Measure:

- constant offset and clock drift from start to end;
- cue duration/coverage versus recording duration;
- sermon and Bible-reading word quality;
- behaviour across music, silence, restart and an abruptly stopped recording; and
- operator failure rate (missing/wrong-week/partial sidecar).

Use scratch outputs outside git; do not add a one-shot Artisan spike. Record aggregate results and
the decision in this section.

**Go** only when timing can be normalised deterministically and quality is acceptable for both
structure detection and the public sermon transcript. If it is adequate only for live captions,
close Part B as rejected: the current pipeline uses the same transcript for both purposes, and
adding two transcript authorities would restore the duplication this programme removed.

Also compare the operational cost with `SERVICE_TRANSCRIPTION_SERVICE=local`. If local-whisper on
the processing host produces the same saving without upload/trust plumbing, keep LocalVocal for
captions and reject app reuse.

## Part B1 — ingest and validation (ships dark)

Only after a go decision:

1. Add an optional sidecar input to the existing class-based admin `MediaUpload` component. Accept
   only the selected trial format initially; add SRT later only if real operator need justifies it.
2. Stage it under the run's existing artifact/storage conventions. Record path, format, byte size
   and SHA-256 in a typed `processing_metadata` structure/accessor rather than adding several
   top-level columns.
3. Add a small parser producing the existing cue shape
   `list<array{start: float, end: float, text: string}>` and then call
   `ChurchServiceTranscript::fromCues(..., SOURCE_SIDECAR)`.
4. Validate before use: parse succeeds; cues are ordered/non-empty; cue span and declared duration
   fit the source recording within the approved tolerance; offset correction is bounded; and the
   sermon-candidate window has speech coverage.
5. Store validation outcome/reason. An invalid sidecar is a warning plus normal fallback, not a
   failed media run.

This release stores and validates the artifact but `TranscribeFullService` still returns the normal
transcriber's output. It is independently deployable and reversible behind
`TRANSCRIPT_SIDECAR_ENABLED=false`.

## Part B2 — shadow comparison

Add `ServiceTranscriptResolver`, injected into `TranscribeFullService`:

```php
public function resolve(
    MediaProcessingLog $log,
    string $localSourcePath,
    ServiceTranscriptionInterface $fallback,
): ChurchServiceTranscript;
```

In shadow mode it parses the validated sidecar, calls the configured fallback exactly as today,
records bounded aggregate comparison metrics and returns the fallback. Do not log transcript text,
email content or cue payloads. Tests use fixture cues and the mock fallback; CI calls no external
service.

Promote only after the maintainer accepts timing, quality and fallback-rate evidence from real
services.

## Part B3 — sidecar primary, normal transcriber fallback

When enabled and not shadowing, the resolver returns a validated sidecar transcript. Otherwise it
delegates to the currently bound OpenAI/local/mock implementation. Downstream consumers remain
unchanged.

Acceptance:

- valid sidecar makes zero fallback transcription calls;
- absent/invalid/partial/mismatched sidecar makes exactly one normal call;
- published sermon text and structure use the same chosen transcript;
- normalized transcript source is recorded as `sidecar`;
- disabling the flag restores today's behaviour without a data migration; and
- cost/latency/fallback rate are compared with the pre-promotion baseline.

## Tests and quality gates

- Pure parser tests: valid/malformed/truncated file, timestamp normalisation and bounded offset.
- Validation tests: missing, hash mismatch, duration mismatch, coverage failure and valid artifact.
- Livewire upload tests: optional file, validation errors, staging metadata and authorization.
- Resolver tests: flag off, shadow, primary, missing/invalid fallback and no transcript-text logs.
- Existing `TranscribeFullService`, structure detection, song matching and sermon-slice suites stay
  green.
- Dusk verifies file selection/upload and fallback messaging only when the UI slice lands.

Every code PR runs focused PHPUnit, PHPStan, Pint, the full parallel suite and Dusk where required.

## Decisions needed after Part B0

1. Go/no-go for reuse versus local-whisper-only processing.
2. The single initially supported sidecar format.
3. Approved offset/drift/duration/coverage thresholds.

These decisions do not block Part A live captions.
