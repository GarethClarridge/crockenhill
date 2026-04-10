# Song Video Audio Enhancement Plan

Updated 2026-04-09.

## Status

Ready to implement.

## Current Reality

- Song clips are published through `SongPublicationHandler`, which works in terms of Laravel storage disks and promoted files rather than a permanently local working file.
- `AudioEnhancementService` already contains the FFmpeg-based audio enhancement logic used for sermon audio, but it currently targets audio-file output (`.mp3`) rather than video containers.
- The current song publication flow is the right integration point because songs do not go through the same processing-log/state-machine pipeline used for sermons.
- The handler is already storage-aware: `extractedAssetDisk()` resolves the source disk, and `promoteExtractedVideo()` handles cross-disk streaming and cleanup.

## Recommended Approach

### 1. Add a video-aware enhancement method to `AudioEnhancementService`

Add an `enhanceVideo()` method that:

- Accepts a local MP4 input path plus a processing identifier.
- Reuses the existing `buildFilterChain()` and config toggles — the filter stack is codec-agnostic.
- Copies the video stream unchanged (`-c:v copy`) and re-encodes only the audio stream to AAC (`-c:a aac -b:a 128k`).
- Outputs to a temp `.mp4` file in `storage/app/temp/`.
- Returns the enhanced file path, or `null` on failure — same non-throwing contract as `enhance()`.
- Uses the existing `audio_enhancement.enabled` config toggle (no separate song toggle needed).

FFmpeg command structure:

```
ffmpeg -y -i {input} -af {filterChain} -c:v copy -c:a aac -b:a 128k {output.mp4}
```

Key difference from `enhance()`: video stream is passed through with `-c:v copy`, and the audio codec is AAC (MP4-compatible) rather than libmp3lame.

### 2. Insert enhancement into `SongPublicationHandler::publish()`

The handler already owns storage resolution and promotion. The only change needed is to slot enhancement between the file-existence check and `promoteExtractedVideo()`.

Insertion point — after the existing file validation (line ~77) and before `promoteExtractedVideo()` (line ~86):

```
validate file exists on source disk
                                        ← NEW: download to local temp if remote disk
                                        ← NEW: run enhanceVideo() on local file
                                        ← NEW: if enhanced, use enhanced file as promotion source
promoteExtractedVideo(section, path)
```

Tasks:

- [ ] If the source disk is S3/remote, download the extracted clip to a local temp file using the same `StorageAdapterHelper::downloadToTemp()` pattern used by `EnhanceAudio`.
- [ ] If the source disk is local, resolve its real filesystem path directly.
- [ ] Run `enhanceVideo()` against the local file — best-effort.
- [ ] When enhancement succeeds, promote the enhanced MP4 instead of the original clip.
- [ ] When enhancement returns `null`, fall back to promoting the original clip unchanged.
- [ ] Keep the final persisted output path, public/private storage behaviour, and `SongVideo` creation unchanged.

### 3. Clean up temp files inside the handler

Do not depend on the general processing cleanup job for this feature.

Tasks:

- [ ] Use a `try`/`finally` structure in `publish()` to delete any temp download and temp enhanced-video files created during publication — matching the pattern in `EnhanceAudio` (lines 128–131).
- [ ] Preserve fallback behaviour if cleanup fails silently (`@unlink`).

Rationale:

- Song publication is not part of the same cleanup lifecycle as sermon processing jobs.
- The handler knows exactly which temp files it created.

## Not Recommended from the Earlier Draft

- Do not treat song enhancement as a new queued processing phase with `ProcessingPhaseRegistry` changes.
- Do not assume the source clip is always a stable local path that can be enhanced in place.
- Do not rely on broad temp-directory sweeping as the primary cleanup strategy for this feature.
- Do not add a separate `song_enhancement_enabled` config toggle — the existing `audio_enhancement.enabled` flag controls both paths.

## Tests to Add

### AudioEnhancementService — `enhanceVideo()` method

- [ ] Returns `null` when enhancement is disabled via config.
- [ ] Returns `null` when input file does not exist.
- [ ] Constructs correct FFmpeg command: `-c:v copy`, `-c:a aac`, `-b:a 128k`, `.mp4` output extension.
- [ ] Reuses `buildFilterChain()` for the `-af` filter string (existing filter-chain tests cover the filter logic itself).

### SongPublicationHandler — enhancement integration

- [ ] Local-disk source: enhancement succeeds → enhanced video is promoted.
- [ ] Local-disk source: enhancement returns `null` → original clip is promoted unchanged.
- [ ] Remote-disk source: temp download occurs before enhancement.
- [ ] Temp files (download + enhanced output) are cleaned up in both success and fallback flows.
- [ ] Existing publish behaviour is unchanged when `audio_enhancement.enabled` is `false`.

## Definition of Done

- [ ] `enhanceVideo()` method added to `AudioEnhancementService` with video-stream copy and AAC audio.
- [ ] `SongPublicationHandler::publish()` runs best-effort enhancement before promotion.
- [ ] The implementation works for both local and remote storage disks.
- [ ] Failed enhancement never blocks song publication.
- [ ] Temp files are cleaned up by the publication flow itself.
- [ ] All new tests pass; PHPStan stays at 0 errors.
