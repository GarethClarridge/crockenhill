# Plan: Song Video Audio Enhancement

## Context

Sermon audio enhancement (noise reduction, dynamic normalisation, loudness normalisation via FFmpeg) was added in March 2026 as a best-effort step in the sermon processing pipeline (`EnhanceAudio` job). The same FFmpeg filter chain — `afftdn`, `dynaudnorm`, `loudnorm` — could improve listener experience on song clips extracted from livestreams.

Unlike sermons, songs have no transcription step, so the Whisper accuracy motivation doesn't apply. The benefit here is purely audio quality for listeners.

---

## What Songs Are

Songs extracted from livestreams are published as `SongVideo` records via `SongPublicationHandler`. They are MP4 video clips (not audio-only files), stored at `sermons/songs/{song_id}/{section_id}.mp4`. The handler takes an `extracted_video_path` and writes the final video to storage — no audio extraction, no transcription.

Relevant file: `app/Services/SectionPublication/SongPublicationHandler.php`

---

## Chosen Approach: Extend `AudioEnhancementService`, call from `SongPublicationHandler`

`AudioEnhancementService` already centralises the FFmpeg filter logic. Rather than adding a new job and new pipeline step (which would require updating `ProcessingPhaseRegistry` offsets again), the service should gain a second method for video files.

### Why not a new job?

Song publication doesn't use the `MediaProcessingLog` state machine that the rest of the processing pipeline does. There's no `current_step` tracking, no retry-from-cursor logic. Wrapping it in a job would add boilerplate with no meaningful benefit.

---

## Implementation Steps

### 1. Add `enhanceVideoAudio()` to `AudioEnhancementService`

```php
/**
 * Enhance the audio track of an MP4 video file in-place (video stream copied unchanged).
 * Returns the output path, or null if enhancement is disabled or fails.
 */
public function enhanceVideoAudio(string $inputPath, string $processingId): ?string
```

- Same filter chain as `enhance()`: `afftdn`, `dynaudnorm`, two-pass `loudnorm`
- FFmpeg flags: `-c:v copy` (preserve video stream, re-encode audio only)
- Output codec: `-c:a aac -b:a 192k` (AAC is standard for MP4 containers; MP3 is not)
- Output path: `storage_path("app/temp/{$processingId}_enhanced_song.mp4")`
- Returns `null` (never throws) on any failure — same best-effort contract as `enhance()`

The two-pass loudnorm measurement pass is identical; only the second-pass output flags change.

### 2. Call from `SongPublicationHandler::publish()`

In `publish()`, after the source video path is resolved and before writing the `SongVideo` record:

```php
// Best-effort audio enhancement — falls back to original if it fails
$enhancedPath = $this->audioEnhancementService->enhanceVideoAudio(
    $sourceVideoPath,
    $processingId
);

$finalVideoPath = $enhancedPath ?? $sourceVideoPath;
```

Inject `AudioEnhancementService` via the constructor (it's already bound in the service container).

### 3. Cleanup

The enhanced temp file (`_enhanced_song.mp4`) is written to `storage/app/temp/`. Add it to the cleanup list in `CleanupTemporaryFiles` — or rely on the existing temp cleanup strategy if it already sweeps that directory generically.

---

## Tests to Add

**Unit — `AudioEnhancementServiceTest`:**
- `enhanceVideoAudio` returns `null` when `audio_enhancement.enabled = false`
- `enhanceVideoAudio` returns `null` when input file does not exist
- Filter chain uses `-c:v copy` and `-c:a aac` (not `-c:a libmp3lame`)
- Output path ends in `_enhanced_song.mp4` (not `.mp3`)

**Feature — `SongPublicationHandlerTest` (or equivalent):**
- When enhancement succeeds, `SongVideo` is created with the enhanced video path
- When enhancement returns `null`, `SongVideo` is created with the original path (no exception)

---

## Notes

- The `AudioEnhancementService` config (`audio_enhancement.enabled`, noise reduction, dynamic norm, loudness norm toggles) applies to both sermon and song enhancement — there's no need for separate config keys unless different behaviour per type is wanted later.
- Songs are often recorded at fairly consistent levels (church PA systems), so the noise reduction and loudness normalisation benefit may be less dramatic than for sermon speech recordings.
- This work is independent of the sermon pipeline changes — no `ProcessingPhaseRegistry` offsets need updating.
