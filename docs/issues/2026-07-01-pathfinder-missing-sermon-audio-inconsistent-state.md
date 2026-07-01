# 🔗 Pathfinder: Inconsistent state and missing audio for "The Prodigal Son" sermon

## Summary
Diagnostic crawl has identified an inconsistency between a `MediaProcessingLog` and its associated `Sermon` record, resulting in a missing audio asset on the public sermon page.

## Findings

### 1. Inconsistent Database State & Missing Asset
**Surface:** `/christ/sermons/2024/11/the-prodigal-son`
**Affected item:** Sermon "The Prodigal Son" (slug: `the-prodigal-son`, ID: 32)
**Verification:**
- **Processing Log:** `MediaProcessingLog` (processing_id: `seed-prodigal-son-processing`) has status `completed` and `audio_file_path` set to `sermons/seed/2024-11-24.mp3`.
- **Sermon Record:** The associated `Sermon` record has `audio_file_path` as `NULL`.
- **Storage Check:** The file `sermons/seed/2024-11-24.mp3` does not exist on the `public` disk (as confirmed by `Storage::disk('public')->exists()`).

**Results:**
The sermon detail page renders, but the audio player is non-functional because the `Sermon` model has no audio path, and even if it did, the underlying file referenced in the processing logs is missing from storage.

## Likely Cause
- **Seeding Inconsistency:** The `SermonSeeder` creates the `MediaProcessingLog` with a hardcoded audio path but does not update the `Sermon` record's `audio_file_path` to match.
- **Missing Mock Asset:** The referenced file `sermons/seed/2024-11-24.mp3` appears to be a mock asset that was not included in the repository or was deleted.

## Suggested Action
- Update `SermonSeeder.php` to ensure the `Sermon` record's `audio_file_path` is correctly set when the processing log is "completed".
- Provide a mock audio file at `storage/app/public/sermons/seed/2024-11-24.mp3` to satisfy the reference, or update the seeder to use a valid test file.
- Alternatively, if the file is genuinely missing, the processing log status should be updated to `failed` to reflect reality and trigger appropriate UI states.

## Risk Note
- This inconsistency can cause confusion for developers testing the media processing pipeline.
- If this pattern exists in production (e.g., successful logs but null sermon paths), it indicates a bug in the completion transition of the `SermonProcessingService`.
