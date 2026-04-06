# Sermon Video Quality Gating Plan

Updated 2026-04-06 after reviewing the current sermon video, livestream, presenter, and thumbnail flow.

## Goal

Only expose a sermon video publicly when it is good enough to improve the sermon page over audio-only playback.

In the first version, the system should reliably catch obvious failures such as:

- blank or near-blank video
- camera-disabled output
- frozen video for most of the sermon
- extremely low-detail / unusably blurred footage

It should not try to solve every visual-quality problem immediately. Borderline cases such as “too zoomed out to be helpful” should start as review-oriented signals rather than aggressive automatic hides.

## Current State

- Direct sermon video uploads are handled through the `video` processing flow and eventually persist a `video_file_path` via `CreateSermonRecord`.
- Livestream sermon extraction persists a sermon clip, then `SubmitToProcessing` and `SermonMetadataIntegrationService` link the final video to the sermon.
- Public sermon rendering relies on `SermonViewPresenter::videoUrl()`, which currently returns a URL whenever the sermon `hasVideo()`.
- Public exposure is only partly centralised today. The presenter drives API payloads, meta tags, schema output, and some page rendering, but several public Blade templates still branch directly on raw `video_file_path`:
  - `resources/views/sermons/sermon.blade.php`
  - `resources/views/childrens-corner/show.blade.php`
  - `resources/views/components/childrens-talk-card.blade.php`
- Thumbnail generation assumes that any sermon with a `video_file_path` is a valid candidate for public thumbnails.
- The codebase already contains two partially useful implementation references:
  - `FrameExtractionService`, which is the most relevant reusable piece because it already handles timestamp-based frame extraction and storage-aware local-path resolution
  - `ThumbnailGenerationService`, which contains detail-oriented frame scoring logic that may be reused or adapted, though its current weights are tuned for thumbnail selection rather than public video gating
- `VisualAnalysisService` is useful mainly as prior art for FFmpeg metric extraction patterns. Its current API and classification logic are livestream song / speech specific and should not become the primary dependency of the new assessor.

## Main Recommendation

Treat “video file exists” and “video should be shown publicly” as two separate concerns.

Do not reuse `video_file_path` as a visibility flag and do not redefine `Sermon::hasVideo()` to mean “publicly usable”. The file should still exist for:

- admin inspection
- manual override
- future reprocessing
- debugging false positives

Instead:

- keep storing the video file as normal
- add a separate video-quality assessment result
- gate public exposure through that result

This is the least disruptive design because it preserves the current storage model and lets admin features continue to treat `video_file_path` as “there is a video asset available”.

## Proposed Data Model

### Sermon-level fields

Add sermon-level fields for the public-facing outcome of video assessment.

Recommended shape:

- `video_quality_status`
  - enum-like string: `unassessed`, `approved`, `rejected`, `needs_review`
- `video_quality_reason`
  - nullable short code such as `blank_screen`, `mostly_black`, `frozen_frames`, `very_low_detail`, `analysis_failed`
- `video_visibility_override`
  - enum-like string: `default`, `force_show`, `force_hide`
- `video_quality_assessed_at`
  - nullable timestamp

Rationale:

- `video_quality_status` represents the automatic system verdict.
- `video_visibility_override` represents editorial intent.
- keeping them separate avoids losing the original machine decision after a manual override
- sermon-level columns make it easy for presenters, admin filters, and future reports to query without joining through processing logs
- the migration should add an index on `video_quality_status` because both admin filtering and backfill queries will want it

### Processing-run metadata

Store the detailed raw assessment in `MediaProcessingLog->processing_metadata` under a dedicated key such as `video_quality`.

Recommended structure:

```php
[
    'video_quality' => [
        'status' => 'rejected',
        'reason' => 'frozen_frames',
        'sample_count' => 8,
        'sample_timestamps' => [420.0, 600.0, 780.0],
        'blank_frame_ratio' => 0.875,
        'frozen_pair_ratio' => 0.714,
        'low_detail_ratio' => 0.875,
        'aggregate_score' => 0.12,
        'metrics' => [
            'avg_brightness' => 0.03,
            'avg_detail_score' => 0.05,
        ],
    ],
]
```

Rationale:

- the processing log is the right home for diagnostic detail
- it avoids adding many one-off columns to `media_processing_logs`
- it gives us evidence for threshold tuning later

## Public Exposure Rules

Add a new public video exposure predicate and use it consistently.

Recommended rule:

- `force_hide` always hides the video
- `force_show` always shows the video if a `video_file_path` exists
- otherwise:
  - `approved` shows the video
  - `rejected` hides the video
  - `needs_review` is rollout-dependent
  - `unassessed` is rollout-dependent

Recommended rollout default:

- in observation mode, no visibility changes are made; verdicts are stored and reviewed only
- once enforcement is enabled:
  - `approved`: visible
  - `rejected`: hidden
  - `needs_review`: hidden unless `force_show`, unless a minimal notification / review mechanism is added first
  - `unassessed`: visible during the migration / backfill window, then reviewable separately if the project later wants stricter enforcement

This keeps the rollout safe while avoiding a state where borderline bad videos remain publicly visible indefinitely with no review queue.

## Pipeline Recommendation

### New job: `AssessSermonVideoQuality`

Introduce a dedicated queued job that assesses sermon video quality after the sermon record and final sermon video path are available.

This job should be:

- idempotent
- non-fatal
- narrow in scope
- cheap enough to run in the normal processing queue

### Why place it after sermon creation

The cleanest insertion point is after the sermon record and final `video_file_path` have been established, not while the clip is still in temporary extraction state.

Benefits:

- the job can operate uniformly on direct video uploads and livestream-derived sermon clips
- it assesses the exact asset that public users would see
- it keeps `CreateSermonRecord` and `SubmitToProcessing` focused on creation/linking concerns
- it allows thumbnail generation to consult the assessment before producing public thumbnails

### Proposed pipeline changes

There are six existing processing chains that currently pass through `GenerateThumbnail` and therefore need the new job inserted before it:

- `buildDirectVideoPipeline()`
- `buildAutoTrimVideoPipeline()`
- `buildLivestreamChainJobs()`
- `buildLivestreamPostReviewChainJobs()`
- `buildAutoTrimVideoPostReviewChainJobs()`
- `buildSectionReclassificationChainJobs()`

The reclassification chain deserves explicit attention because it rebuilds sermon-derived media but does not currently end with `SendCompletionNotification` or `CleanupTemporaryFiles`.

#### Direct video pipeline

Current:

`ValidateVideoFile -> ExtractAudioFromVideo -> EnhanceAudio -> CreateSermonRecord -> IdentifySpeaker -> TranscribeAudio -> ProcessTranscriptWithAI -> GenerateThumbnail -> SendCompletionNotification -> CleanupTemporaryFiles`

Proposed:

`ValidateVideoFile -> ExtractAudioFromVideo -> EnhanceAudio -> CreateSermonRecord -> IdentifySpeaker -> TranscribeAudio -> ProcessTranscriptWithAI -> AssessSermonVideoQuality -> GenerateThumbnail -> SendCompletionNotification -> CleanupTemporaryFiles`

#### Auto-trim video pipeline

Proposed:

`... -> CreateSermonRecord -> IdentifySpeaker -> TranscribeAudio -> ProcessTranscriptWithAI -> AssessSermonVideoQuality -> GenerateThumbnail -> SendCompletionNotification -> CleanupTemporaryFiles`

#### Livestream pipeline

Current relevant tail:

`... -> SubmitToProcessing -> EnhanceAudio -> IdentifySpeaker -> TranscribeAudio -> ProcessTranscriptWithAI -> GenerateThumbnail -> PrepareSectionPublicationCandidates -> SendCompletionNotification -> CleanupTemporaryFiles`

Proposed tail:

`... -> SubmitToProcessing -> EnhanceAudio -> IdentifySpeaker -> TranscribeAudio -> ProcessTranscriptWithAI -> AssessSermonVideoQuality -> GenerateThumbnail -> PrepareSectionPublicationCandidates -> SendCompletionNotification -> CleanupTemporaryFiles`

#### Post-review resume chains

Add the same job before `GenerateThumbnail` in:

- livestream post-review resume
- auto-trim video post-review resume
- section reclassification / rebuild chains that recreate sermon media

## Assessment Service Design

Create a dedicated service, for example `SermonVideoQualityAssessmentService`.

### Responsibilities

- resolve the local path of the final sermon video
- sample a small set of representative frames
- compute simple, explainable heuristics
- return a structured verdict
- avoid throwing fatal exceptions into the wider sermon-processing flow

### Suggested collaborators

- `FrameExtractionService`
  - primary dependency for timestamp-based extraction and storage-aware local path handling
- `StorageAdapterHelper` or an equivalent storage-aware helper
  - for any cases where the assessment service needs to work directly with remotely stored sermon videos during backfill or reruns
- a small new internal helper for frame comparison
  - for frozen-frame detection
- optionally, a future extracted FFmpeg metrics helper
  - only if signalstats-style export becomes genuinely useful outside the current livestream song-detection flow

### Sampling strategy

Do not sample only the very beginning or end of the video.

Recommended first-pass approach:

- run a coarse sampling pass for blank / low-detail checks:
  - sample 6 to 8 timestamps
  - spread them across the middle 60 percent of the sermon clip
  - skip the first and last 20 percent to avoid intros, fades, camera switching, and outro slides
- run a separate burst-sampling pass for frozen detection:
  - choose 2 short windows in the middle half of the sermon
  - sample 4 to 5 frames per window
  - keep frames in each burst only 1 to 2 seconds apart

For example:

- for a 40 minute sermon clip, use coarse samples within roughly minutes 8 to 32, plus two short burst windows inside that region

This matters because many bad sermon videos still have a legitimate opening slide or fade but become useless later, and frozen-frame detection cannot be trusted if it only compares frames that are minutes apart.

## Recommended Heuristics

The first version should favour obvious, explainable checks rather than ambitious computer vision.

### 1. Blank or near-blank video

Signal candidates:

- average brightness very close to black or very close to white for most samples
- extremely low luminance variance
- extremely low detail score

Recommended outcome:

- auto-reject if a high percentage of sampled frames are effectively blank

### 2. Frozen-frame detection

Signal candidates:

- only use burst-sampled frames for this check, not the coarse 3-to-4-minute spread
- downscale each frame to a small grayscale matrix such as `16x16`
- compute a normalized mean absolute pixel difference between adjacent burst frames
- treat a pair as “effectively identical” only when the normalized diff is below a tuned threshold, for example `~0.01` as an initial calibration point
- count the identical-pair ratio per burst window
- require a very high identical-pair ratio across both burst windows before auto-rejecting
- if the signal proves noisy during calibration, keep it as `needs_review` or observation-only instead of shipping it as an automatic rejection immediately

Recommended outcome:

- auto-reject only when the dense burst windows strongly indicate a broken frozen feed, not merely a legitimately static lectern shot

### 3. Very low-detail / extreme blur

Signal candidates:

- reuse the thumbnail candidate detail scoring approach already in `ThumbnailGenerationService`
- optionally add a stronger local-edge / gradient variance measure if the existing score proves too weak

Recommended outcome:

- start as `needs_review` unless the detail score is catastrophically low across almost every sample
- once thresholds are proven, promote only the worst cases to auto-reject

### 4. “Too zoomed out”

This should not be an automatic rejection in v1.

Reasons:

- the current stack has no face/person detection dependency
- a wide shot can still be acceptable in some services
- naive heuristics are likely to create false positives

Recommended v1 treatment:

- optionally record a weak “small_subject_suspected” signal for admin review
- do not auto-hide on this signal in the first release

## Explicit Non-Goals for v1

- face detection
- preacher identity / subject localisation from video frames
- ML-based blur detection or CV models
- automatic “too zoomed out” hiding
- trying to infer worship-style acceptability from camera composition

Keeping these out of scope will make the first implementation much safer.

## Thumbnail Behaviour

Thumbnail generation should respect the video-quality verdict.

Recommended behaviour:

- if the sermon video is `rejected` and there is no `force_show` override:
  - skip public thumbnail generation from the video
  - keep or fall back to the standard non-video sermon image
- if the sermon video is `approved`:
  - generate thumbnails as normal
- if the sermon video is `needs_review`:
  - in observation mode, current behaviour may continue while verdicts are being tuned
  - once enforcement is enabled, withhold public thumbnails unless the sermon is explicitly `force_show`

This avoids the bad outcome where the site hides the video player but still shows a blank poster frame as the primary sermon image.

## Public Surface Changes

### Presenter layer

The main public gate should live in the existing `SermonExposurePolicy`, which is already injected into `SermonViewPresenter`.

Recommended approach:

- keep `Sermon::hasVideo()` as-is
- add a method such as `SermonExposurePolicy::shouldExposeVideo(Sermon $sermon): bool`
- update `SermonViewPresenter::videoUrl()` to use that method
- if a model helper is later useful for ergonomics, have it delegate to the policy or mirror the same rule rather than creating a second parallel exposure concept

This centralises the main decision, but the current public Blade templates also need to stop branching directly on `video_file_path`. In particular:

- `resources/views/sermons/sermon.blade.php`
- `resources/views/childrens-corner/show.blade.php`
- `resources/views/components/childrens-talk-card.blade.php`
- schema output
- API resource preparation through `SermonApiController`

Recommended template rule:

- derive `hasPublicVideo` from `filled($sermonView['video_url'])` or from an explicit presenter / policy value passed to the view
- do not branch the public UI directly on raw `video_file_path`

### Sitemap and structured data

Audit and update places that currently infer “video exists” from `hasVideo()` rather than from the presenter / exposure rule, especially:

- `SermonSitemapPresenter`
- schema components

The public rule should be consistent across HTML, JSON-LD, and SEO metadata.

## Admin UX

Add lightweight override controls to the sermon admin surface.

Recommended first location:

- `app/Livewire/Admin/Sermons/EditSermon.php`
- corresponding Blade view

### Minimum admin features

- show the automatic status
- show the primary reason code
- show the last assessment timestamp
- offer `Force show video`
- offer `Force hide video`
- offer `Re-run assessment`

### Nice-to-have later

- show representative sampled frames
- show raw assessment metrics
- add filters for `rejected` and `needs_review`

The minimum version should avoid creating a large new review UI before the core assessment is working.

## Backfill Strategy

Existing sermons with video files will need assessment too.

Add a console command, for example `sermons:assess-video-quality`, that can:

- assess one sermon
- assess one date range
- assess only sermons with video and `video_quality_status = unassessed`
- queue large backfills in batches

Important requirement:

- the backfill command must work from the sermon’s current `video_file_path`
- it must not depend on a historical `MediaProcessingLog` still being present
- each assessment requires an FFmpeg-readable local copy of the video; for remote sermon disks this means downloading temporarily, so the command should:
  - reuse `FrameExtractionService::ensureLocalVideoPath()` or an equivalent path-resolution helper
  - clean up each temporary local copy immediately after assessment
  - default to sequential or tightly batch-limited processing to avoid filling the temp disk
  - surface temp-disk and concurrency guidance in the command help / docs

This will be important for old sermon videos and imported data.

## Rollout Plan

### Phase 1: Storage and exposure model

Target files:

- new migration(s) for sermon fields
- `app/Models/Sermon.php`
- `app/Models/MediaProcessingLog.php`
- `app/Data/ProcessingMetadata.php` if a typed accessor is worthwhile

Tasks:

- add sermon columns for status, reason, override, and assessed timestamp
- add an index on `video_quality_status`
- add casts / helpers on `Sermon`
- add helpers on `MediaProcessingLog` for reading / writing the `video_quality` metadata block
- keep all default values non-breaking for existing sermons

Exit criteria:

- the data model can represent assessment outcome without affecting current public behaviour

### Phase 2: Assessment service and job

Target files:

- `app/Services/FrameExtractionService.php`
- `app/Services/StorageAdapterHelper.php` if shared local-copy helpers need to be surfaced more cleanly
- new `app/Services/SermonVideoQualityAssessmentService.php`
- new `app/Jobs/AssessSermonVideoQuality.php`
- `config/media-processing.php`

Tasks:

- add a new config section, for example `media-processing.video_quality`
- implement coarse frame sampling for blank / low-detail heuristics
- implement burst sampling for frozen detection, or keep frozen verdicts observation-only until thresholds are proven
- implement blank / low-detail heuristics
- return a structured assessment result
- make the job persist:
  - sermon-level summary fields
  - processing-log diagnostic metadata when a processing run exists
- ensure the job is non-fatal on failure

Recommended failure behaviour:

- record `analysis_failed`
- do not fail the processing chain
- do not auto-hide solely because the assessor itself errored

Exit criteria:

- the app can assess a sermon video and persist a stable verdict without breaking processing

### Phase 3: Pipeline integration

Target files:

- `app/Services/ProcessingPipelineBuilder.php`
- `app/Jobs/CreateSermonRecord.php`
- `app/Jobs/SubmitToProcessing.php`
- any post-review or reclassification chain definitions

Tasks:

- insert `AssessSermonVideoQuality` before `GenerateThumbnail` in each relevant pipeline:
  - `buildDirectVideoPipeline()`
  - `buildAutoTrimVideoPipeline()`
  - `buildLivestreamChainJobs()`
  - `buildLivestreamPostReviewChainJobs()`
  - `buildAutoTrimVideoPostReviewChainJobs()`
  - `buildSectionReclassificationChainJobs()`
- ensure the job can find the sermon id and final `video_file_path`
- ensure direct video, livestream, and auto-trim flows all reach the same assessment behaviour

Exit criteria:

- every newly processed sermon video is assessed before thumbnail generation

### Phase 4: Public gating and thumbnail gating

Target files:

- `app/Services/SermonExposurePolicy.php`
- `app/Presenters/SermonViewPresenter.php`
- `app/Presenters/SermonSitemapPresenter.php`
- `app/Http/Controllers/Api/SermonApiController.php` only if extra preloading is needed
- `resources/views/sermons/sermon.blade.php`
- `resources/views/childrens-corner/show.blade.php`
- `resources/views/components/childrens-talk-card.blade.php`
- `app/Jobs/GenerateThumbnail.php`
- `app/Services/ThumbnailGenerationService.php`

Tasks:

- add the public exposure predicate on `SermonExposurePolicy`
- use it in `videoUrl()`
- remove public-template branching on raw `video_file_path`
- update sitemap / schema-adjacent consumers if they still inspect `hasVideo()` directly
- make thumbnail generation respect the verdict

Exit criteria:

- rejected videos disappear from public presentation
- thumbnail generation no longer produces public poster frames for known-bad videos

### Phase 5: Admin override and rerun

Target files:

- `app/Livewire/Admin/Sermons/EditSermon.php`
- relevant admin Blade view
- optional admin actions / policy helpers

Tasks:

- show status and reason
- add override controls
- add a rerun action
- audit any admin preview logic that should still use `hasVideo()` rather than public exposure

Exit criteria:

- staff can correct false positives without touching the database directly

### Phase 6: Backfill and rollout

Target files:

- new console command for backfill
- optional lightweight report command or extension to the current visual export command

Tasks:

- create a queueable backfill command
- run the feature initially in observation mode
- review recent sermon outputs and tune thresholds
- before leaving observation mode, choose and implement one enforcement rule for `needs_review`:
  - hidden by default, which this plan recommends
  - or minimal notification / review signalling if the team wants `needs_review` to remain public temporarily
- switch on auto-hide for hard rejects only first, then decide separately whether frozen and very-low-detail should remain hard rejects or review-only

Exit criteria:

- new and historic sermon videos can be assessed consistently
- obvious failures are hidden without widespread false positives

## Calibration Plan

Before enforcing hard rejection broadly, collect a labelled set of recent sermons:

- clearly good video
- blank / disabled camera
- frozen output
- clearly unusable blur
- acceptable but wide shots

Use that set to tune:

- blank-frame threshold
- burst-window identical-pair threshold
- low-detail threshold
- minimum sample count

The existing `media:export-visual-metrics` command is a useful starting point, but a sermon-quality-specific export or debug command may be worth adding if tuning needs more direct visibility into:

- detail score per sample
- frame fingerprint / diff score
- aggregate reject ratios

## Testing Plan

### New unit tests

Add dedicated unit coverage for the new assessment service.

Recommended new test file:

- `tests/Unit/Services/SermonVideoQualityAssessmentServiceTest.php`

Core test cases:

- obviously blank frames are rejected
- mostly frozen burst windows are rejected or flagged for review, depending on the rollout flag in force
- healthy varied frames are approved
- very low-detail frames become `needs_review` or `rejected`, depending on chosen threshold
- service errors return a safe `analysis_failed` style result

### Pipeline and job tests

Likely updates:

- `tests/Unit/Services/ProcessingPipelineBuilderTest.php`
- `tests/Unit/Jobs/CreateSermonRecordTest.php`
- `tests/Unit/Jobs/SubmitToProcessingTest.php`

Core assertions:

- the new job is inserted in all relevant chains
- direct video processing persists an assessment result
- livestream sermon creation persists an assessment result

### Presenter and public tests

Likely updates:

- `tests/Unit/Presenters/SermonViewPresenterTest.php`
- `tests/Unit/Services/SermonExposurePolicyTest.php`
- `tests/Unit/Http/Resources/SermonResourceTest.php` if the view payload changes

Core assertions:

- approved video still returns `video_url`
- rejected video returns `video_url = null`
- `force_show` can expose a rejected video
- `force_hide` can hide an approved video

### Thumbnail tests

Likely updates:

- `tests/Unit/GenerateThumbnailJobTest.php`
- `tests/Unit/Services/ThumbnailGenerationServiceCandidateTest.php`
- `tests/Unit/Services/ThumbnailGenerationServiceStorageTest.php`

Core assertions:

- thumbnail generation is skipped or suppressed for rejected video
- approved video still generates thumbnails normally

### Integration tests

Likely updates:

- `tests/Feature/UnifiedMediaProcessingTest.php`
- `tests/Feature/LivestreamProcessingIntegrationTest.php`
- `tests/Feature/ChildrensCornerPagesTest.php`
- admin sermon edit tests

Core assertions:

- bad sermon video still produces a usable sermon with audio
- public page omits the video player
- children's corner cards and detail pages do not show a public video badge or player for rejected video
- admin can override and restore visibility

## Observability

Add lightweight logging around the new job.

Recommended log fields:

- sermon id
- processing id, if present
- video path
- verdict
- reason
- sample count
- aggregate score
- runtime in milliseconds

This will help tune thresholds and diagnose misclassifications without digging directly into stored JSON.

## Risks and Mitigations

### Risk: false positives hide valid videos

Mitigation:

- ship hard rejects only first
- keep `needs_review` observation-only at the start, then hide it by default once enforcement begins unless a review notification path is added
- add admin override
- backfill in observation mode before turning on enforcement

### Risk: assessment adds too much processing time

Mitigation:

- sample a small number of frames
- keep the job near thumbnail generation, where a small extra delay is acceptable
- avoid full-video scans in v1

### Risk: old sermons never get assessed

Mitigation:

- provide a backfill command
- make it work from sermon storage, not only from processing logs

### Risk: public and admin semantics diverge accidentally

Mitigation:

- keep `hasVideo()` as the storage predicate
- introduce a separate public exposure predicate and use it consistently

## Recommended First Slice

If this work needs to be staged into the smallest useful implementation, the first slice should be:

1. add sermon-level status / reason / override fields
2. add `AssessSermonVideoQuality`
3. implement blank-screen detection first, plus observation-only metric capture for low-detail and frozen signals
4. insert the job before `GenerateThumbnail`
5. gate `SermonExposurePolicy::shouldExposeVideo()` and `SermonViewPresenter::videoUrl()`
6. update the public Blade templates that currently branch on raw `video_file_path`
7. skip thumbnails for rejected videos
8. add a minimal admin override on the sermon edit screen

That slice solves the clearest user problem first without committing the project to an overly ambitious or under-calibrated computer-vision feature.

## Definition of Done

- newly processed sermon videos receive an automatic quality verdict
- obvious bad videos no longer appear publicly
- audio remains available when video is hidden
- thumbnail generation no longer produces public assets for rejected videos
- admins can override the automatic decision
- historic sermon videos can be backfilled through a command
- the rollout can begin in observation mode and move to enforcement without schema redesign
