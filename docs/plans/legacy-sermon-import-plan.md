# Legacy Sermon Import Plan

Updated 2026-04-06.

## Status

This plan is still needed, but the original draft was written against an older processing setup.

There is still no import command for legacy sermon MP3 batches. The implementation should now align with the current unified processing pipeline and the app's configurable transcription backends.

## What Changed Since The Original Draft

- Audio processing now includes `EnhanceAudio` before sermon creation and transcription.
- Transcription is no longer an OpenAI-only decision. The app already supports `openai`, `local`, and `mock` transcription service bindings via `TRANSCRIPTION_SERVICE_TYPE`.
- The app does not currently have a transcript-aware audio pipeline. If `TranscribeAudio` is dispatched, it will still transcribe.
- Video and livestream startup now go through shared orchestration helpers; this import should follow the same orchestration style instead of dispatching a bespoke `Bus::chain(...)` directly from the command.

## Recommended Scope

### Phase 1: CSV-Backed Import Command

Create an Artisan command that:

- scans a directory of legacy MP3 files
- matches filenames to `Tape Index.csv`
- performs a dry run when requested
- stores each file on the configured sermon disk
- creates a `MediaProcessingLog` with the same metadata shape used by the current upload flow
- starts processing through `ProcessingRunOrchestrator`

Suggested signature:

```text
sermons:import-legacy
    {--dir= : Directory containing MP3 files to import}
    {--csv= : Path to Tape Index CSV (defaults to storage/app/Tape Index.csv)}
    {--dry-run : Preview matches without creating logs}
    {--delay=0 : Seconds to pause between imports}
    {--force : Re-import files already seen by original filename}
```

### Phase 2: Use The Existing Transcription Backend Choice

Do not build separate "Plan A" and "Plan B" import paths.

Instead:

- if OpenAI transcription is desired, set `TRANSCRIPTION_SERVICE_TYPE=openai`
- if local Whisper is desired, set `TRANSCRIPTION_SERVICE_TYPE=local`
- if tests or dry environments are being used, keep `TRANSCRIPTION_SERVICE_TYPE=mock`

That keeps the import command focused on ingestion rather than duplicating runtime transcription strategy inside the plan.

### Phase 3: Optional Pre-Supplied Transcript Support

Only add this if a fully offline import remains necessary after Phase 1.

If we need to ingest pre-generated transcripts later, do it as a separate slice:

- either add a dedicated `buildAudioPipelineWithTranscript(...)` path that still preserves `EnhanceAudio`
- or teach `TranscribeAudio` to no-op when a trusted `transcript_file_path` is already present

This is optional work, not the default implementation path.

## Metadata Mapping

The CSV mapping from the original draft is still useful and can be retained.

Recommended fields:

- `Title` -> `processing_metadata.id3_metadata.title`
- `Preacher` -> `processing_metadata.id3_metadata.preacher`
- `Series` -> `processing_metadata.id3_metadata.series`
- `Book` + `Reference` -> `processing_metadata.id3_metadata.reference`
- `Date` -> `processing_metadata.extracted_date`
- `AM/PM` -> `processing_metadata.extracted_service`
- `Duration` -> `media_processing_logs.duration`

Keep the existing tape ID cleanup rule:

- strip any `#...#` filename annotation from the CSV tape ID before lookup

## Implementation Notes

- Create logs in the same shape as the normal audio upload path so downstream jobs behave consistently.
- Prefer the configured sermon disk and current `sermons/YYYY/MM` path conventions.
- Use the existing duplicate guard on `original_filename` unless `--force` is passed.
- If the CSV is missing or fields are blank, continue the import and let the normal pipeline fill the gaps.

## Not Recommended From The Original Draft

- hard-coded cost estimates tied to specific models
- a separate local-Whisper-only import path as the primary recommendation
- direct queue-chain dispatch from the command instead of using the current orchestration layer

## Tests

Create focused tests for:

- CSV match -> processing log created with mapped metadata and processing started
- no CSV match -> file still imported and processing started
- dry run -> no files stored and no logs created
- duplicate detection -> existing imports skipped unless `--force` is set
- missing `--dir` -> command fails cleanly
- missing CSV -> import still proceeds with warnings
- empty CSV fields -> only non-empty metadata fields are written

If transcript-aware import is added later, give it its own tests rather than mixing that complexity into the initial command test suite.
