# Song Video Audio Enhancement Plan

Updated 2026-04-06.

## Status

This feature is still implementable, but the original draft assumed a simpler local-file flow than the current song publication path actually uses.

## Current Reality

- Song clips are published through `SongPublicationHandler`, which works in terms of Laravel storage disks and promoted files rather than a permanently local working file.
- `AudioEnhancementService` already contains the FFmpeg-based audio enhancement logic used for sermon audio, but it currently targets audio-file output (`.mp3`) rather than video containers.
- The current song publication flow is the right integration point because songs do not go through the same processing-log/state-machine pipeline used for sermons.

## Recommended Approach

### 1. Extend `AudioEnhancementService` for video containers

Add a video-aware enhancement method that:

- Accepts a local MP4 input path plus a processing identifier.
- Reuses the existing filter stack and configuration toggles.
- Preserves the video stream while re-encoding the audio stream to an MP4-compatible codec.
- Returns `null` on failure so publication can fall back to the original clip.

Expected behaviour:

- Video stream is copied unchanged.
- Audio stream is enhanced best-effort and written to a temp MP4.
- The method follows the same non-throwing contract as the existing sermon enhancement path.

### 2. Make `SongPublicationHandler` storage-aware

Update publication to work for both local and remote disks.

Tasks:

- [ ] Resolve the current extracted clip from its configured storage disk.
- [ ] If the source file is not already available as a local filesystem path, download it to a temp working file first.
- [ ] Run best-effort audio enhancement against that local temp file.
- [ ] Promote the enhanced MP4 when enhancement succeeds; otherwise promote the original clip.
- [ ] Keep the final persisted output path and public/private storage behaviour unchanged.

Why this matters:

- The handler already owns storage promotion semantics.
- The enhancement service should focus on FFmpeg work, not disk-specific storage policy.
- This keeps song publication robust for local disks and S3-compatible disks alike.

### 3. Clean up temp files inside the handler

Do not depend on the general processing cleanup job for this feature.

Tasks:

- [ ] Use a `try`/`finally` structure in the handler to delete any temp download and temp enhanced-video files created during publication.
- [ ] Preserve fallback behaviour if cleanup fails silently.

Rationale:

- Song publication is not part of the same cleanup lifecycle as sermon processing jobs.
- The handler already knows exactly which temp files it created.

## Not Recommended from the Earlier Draft

- Do not treat song enhancement as a new queued processing phase with `ProcessingPhaseRegistry` changes.
- Do not assume the source clip is always a stable local path that can be enhanced in place.
- Do not rely on broad temp-directory sweeping as the primary cleanup strategy for this feature.

## Tests to Add

- [ ] Unit coverage for the new video enhancement method: disabled config, missing input, success path, and expected FFmpeg/output settings.
- [ ] Handler-level test for a local-disk source where enhancement succeeds and the enhanced video is promoted.
- [ ] Handler-level test for a remote/private disk source that requires temp download before enhancement.
- [ ] Handler-level test confirming fallback to the original clip when enhancement returns `null`.
- [ ] Handler-level test confirming temp files are cleaned up in both success and fallback flows.

## Definition of Done

- [ ] Song publication can enhance clip audio without changing existing publication semantics.
- [ ] The implementation works for both local and remote storage disks.
- [ ] Failed enhancement never blocks song publication.
- [ ] Temp files are cleaned up by the publication flow itself.
