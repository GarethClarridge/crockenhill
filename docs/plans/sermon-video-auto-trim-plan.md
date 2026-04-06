# Sermon Video Auto-Trim Plan

Updated 2026-04-06 after reviewing the current video and livestream processing flow.

## Goal

Allow `Sermon Video` uploads to optionally auto-trim leading and trailing non-sermon content, such as half a song before the sermon or a prayer/song after it, without treating the upload as a full `livestream`.

## Current State

- `app/Services/UnifiedMediaProcessor.php` sends `video` uploads through `processDirectVideo()`, which stores the file and starts the short direct-video pipeline.
- `app/Services/ProcessingPipelineBuilder.php` defines `buildDirectVideoPipeline()` as a whole-video flow. It never creates RMS logs, segments, service sections, or extracted sermon spans.
- `app/Jobs/ExtractSermon.php` and `app/Services/SermonExtractionPlanResolver.php` already contain the trimming logic we want, but they are only reached from the livestream flow today.
- Several reusable jobs are effectively livestream-only because of guards or orchestration assumptions: `GenerateRmsLog`, `TranscribeSpeechSegments`, `ClassifySpeechSections`, `ReclassifyIntroOutroSections`, the manual review flow, and `ProcessingPhaseRegistry` retry/progress mapping.

## Recommendation

Keep these uploads as `MediaType::Video`. Do not introduce a fourth media type just for trimmed sermon videos.

Instead:

- add an opt-in `video auto-trim` mode for `video` uploads
- persist that mode in `processing_metadata`
- derive a separate pipeline profile from `MediaType::Video + video mode`

This is the least disruptive approach because:

- the upload is still conceptually a sermon video, not a livestream
- it avoids polluting livestream-specific review lists, reconciliation, and source-type semantics
- it lets us reuse only the livestream pieces that help trim sermon bounds

## Proposed Pipeline

### Standard video

Keep the current direct-video pipeline unchanged:

`ValidateVideoFile -> ExtractAudioFromVideo -> EnhanceAudio -> CreateSermonRecord -> IdentifySpeaker -> TranscribeAudio -> ProcessTranscriptWithAI -> GenerateThumbnail -> SendCompletionNotification -> CleanupTemporaryFiles`

### Auto-trim video

Add a dedicated video profile that reuses only the trimming-related pieces:

`ValidateVideoFile -> GenerateRmsLog -> AnalyzeSegments -> ClassifyServiceSections -> TranscribeSpeechSegments -> ClassifySpeechSections -> ReclassifyIntroOutroSections -> ExtractSermon -> EnhanceAudio -> CreateSermonRecord -> IdentifySpeaker -> TranscribeAudio -> ProcessTranscriptWithAI -> GenerateThumbnail -> SendCompletionNotification -> CleanupTemporaryFiles`

Deliberately skip these livestream-only stages in the first version:

- `PerformVisualAnalysis`
- `ProjectLivestreamServiceStructure`
- `AlignWithOos`
- `MatchSongsFromTranscript`
- `PrepareSectionPublicationCandidates`
- `SubmitToProcessing`

The auto-trimmed video flow should keep the original upload in `source_file_path` and write trimmed assets into `audio_file_path` and `video_file_path`.

## Implementation Phases

### Phase 1: Add an explicit video processing mode

Target files:

- `app/Services/UnifiedMediaProcessor.php`
- `app/Services/ProcessingInitiator.php`
- `app/Models/MediaProcessingLog.php`
- `app/Livewire/MediaUpload/Form.php`
- `app/Livewire/Traits/WithUploadLifecycle.php`
- `resources/views/livewire/media-upload/form.blade.php`
- `app/Http/Controllers/Api/MediaController.php`
- `app/Http/Requests/ProcessMediaRequest.php` if it is still part of the active upload path

Tasks:

- Add an opt-in request field for `video` uploads, for example `auto_trim=true` or `video_processing_mode=auto_trim`.
- Persist the choice into `processing_metadata`, for example:
  - `video_processing_mode: full_video|auto_trim`
  - `trim_requested: true|false`
- Add explicit helpers on `MediaProcessingLog` or a dedicated resolver so the app can ask:
  - “is this an auto-trim video run?”
  - “does this run use the segmentation-style pipeline?”
- Use that shared predicate everywhere instead of teaching individual jobs to read raw JSON metadata.
- Keep the existing `video` behaviour unchanged when the new field is absent.

Exit criteria:

- Existing sermon video uploads still behave exactly as they do now.
- A sermon video can opt into auto-trim without becoming `MediaType::Livestream`.
- The codebase has one canonical predicate for segmentation-style runs, reused by all affected jobs.

### Phase 2: Introduce a dedicated pipeline profile for auto-trimmed videos

Target files:

- `app/Services/ProcessingPipelineBuilder.php`
- `app/Services/ProcessingRunOrchestrator.php`
- `app/Services/ProcessingPhaseRegistry.php`
- `app/Services/ProcessingRunFailureHandler.php`
- `app/Enums/ProcessingStep.php` only if a genuinely new step name is needed

Tasks:

- Add a distinct pipeline/profile concept such as `video_auto_trim`, derived from `MediaType::Video + processing mode`.
- Route auto-trimmed video runs to a dedicated builder path instead of the current short direct-video chain.
- Treat these files as one atomic unit for the new profile:
  - `ProcessingPipelineBuilder`
  - `ProcessingRunOrchestrator`
  - `ProcessingPhaseRegistry`
  - `ProcessingRunFailureHandler`
- Add progress mapping, retry mapping, and failure-profile handling for the new profile.
- Update the retry path explicitly, including:
  - the orchestrator’s pipeline selection
  - `retryWithChain()`
  - `pipelineForMediaType()` or an equivalent profile resolver
  - `phasesForPipeline()`
- Keep queue usage aligned with current conventions. The run can start on the video queue, while jobs that already self-route to the audio queue can continue to do that.

Why this phase matters:

- `ProcessingPhaseRegistry` currently resolves retry plans from `processing_type`.
- A `Video` run that reaches steps like `classifying_sections`, `manual_review_required`, or `extraction` would not currently get the right retry behaviour unless the registry learns about the auto-trim profile.
- The hardest part of this feature is not the happy-path chain. It is making sure retry, reset scope, job offsets, and failure handling all point at the same pipeline profile.

Exit criteria:

- Auto-trimmed video runs show sensible progress.
- Retry logic can resume them from trim-specific failure points.
- Failure handling can distinguish standard `video` failures from `video_auto_trim` failures without misrouting cleanup or notifications.

### Phase 3: Reuse only the trimming-related livestream jobs

Target files:

- `app/Jobs/GenerateRmsLog.php`
- `app/Jobs/AnalyzeSegments.php`
- `app/Jobs/ClassifyServiceSections.php`
- `app/Jobs/TranscribeSpeechSegments.php`
- `app/Jobs/ClassifySpeechSections.php`
- `app/Jobs/ReclassifyIntroOutroSections.php`
- `app/Jobs/ExtractSermon.php`
- `app/Services/SermonExtractionPlanResolver.php`
- `config/media-processing.php`

Tasks:

- Make the relevant jobs use the shared segmentation-style predicate instead of hard-coding `processing_type === Livestream` where that is no longer correct.
- Audit and update the exact jobs that currently guard on livestream-only execution but are part of the new profile:
  - `GenerateRmsLog`
  - `TranscribeSpeechSegments`
  - `ClassifySpeechSections`
  - `ReclassifyIntroOutroSections`
- Verify the surrounding jobs that remain in the auto-trim chain still behave correctly once those guards are relaxed:
  - `AnalyzeSegments`
  - `ClassifyServiceSections`
  - `ExtractSermon`
- Keep `AnalyzeSegments` and `SermonExtractionPlanResolver` working from the same source-file assumptions they already use.
- Reuse the existing `ClassifyServiceSections -> ClassifySpeechSections -> ReclassifyIntroOutroSections` chain to improve sermon boundary quality before extraction.
- Skip service-structure and publication work that only makes sense for genuine livestreams.
- Document the intentional behavioural difference from skipping `MatchSongsFromTranscript`:
  - in auto-trim mode, leading/trailing song-like sections are treated as trim candidates without song-catalog matching
  - this is desirable for roughly clipped sermon videos, but it should be an explicit design choice rather than a side-effect
- Add a small `media-processing.video_auto_trim` config section for mode-specific thresholds or fallback policy rather than overloading livestream defaults.
- Bring `GenerateRmsLog` file-size validation into scope so auto-trimmed videos are not silently validated against the livestream upload limit.

Exit criteria:

- An auto-trimmed sermon video can resolve sermon boundaries and extract a shorter sermon asset.
- No fake service structure is created for these runs.
- The changed intro/outro behaviour is deliberate, documented, and test-covered.

### Phase 4: Make sermon creation and storage trim-aware

Target files:

- `app/Jobs/CreateSermonRecord.php`
- `app/Data/SermonCreationOptions.php`
- `app/Services/SermonCreationService.php` only if metadata propagation needs adjustment
- `app/Jobs/GenerateThumbnail.php` if any assumptions break

Tasks:

- Teach `CreateSermonRecord` to prefer the extracted video when it exists:
  - use `video_file_path` when `ExtractSermon` populated it
  - fall back to `source_file_path` when it did not
- Do not key that decision only off auto-trim mode. The precedence should follow the data actually available on the processing log.
- Keep `source_type = VideoUpload`. This is still a sermon video upload, just a trimmed one.
- Persist trim metadata for audit/debugging, for example:
  - original duration
  - final trimmed duration
  - trim start and end
  - extraction source (`service_sections`, `processing_log`, or `manual_review`)
  - extraction strategy (`sermon_only`, `adjacent_bible_plus_sermon`, `dominant_speech_segment`, etc.)
- Ensure downstream jobs operate on the trimmed audio and video, not the original upload.

Exit criteria:

- The final sermon record points at the trimmed assets.
- The original uploaded file remains only as the processing source, not the published sermon video.

### Phase 5: Decide and implement the low-confidence path

Recommended default:

- reuse the existing manual sermon review flow for ambiguous auto-trimmed video runs

Target files:

- `app/Models/MediaProcessingLog.php`
- `app/Actions/ConfirmLivestreamSermonSegment.php` and likely its naming
- `app/Livewire/Admin/ChurchServices/ProcessingReview.php`
- `resources/views/livewire/admin/church-services/processing-review.blade.php`
- `app/Services/MediaProcessingRunTransitionService.php`
- `app/Http/Controllers/Api/MediaController.php`
- review list/query code that currently assumes “livestream only”

Tasks:

- Generalise manual review eligibility so it allows:
  - livestream runs
  - video runs with auto-trim enabled
- Reuse the same segment-confirmation mechanism to resume from `ExtractSermon`.
- Adjust review page copy so it no longer assumes every manual-review run is a full livestream.

Fallback if this phase is deferred:

- fail safely back to full-video processing for ambiguous auto-trim runs
- do not silently publish a potentially mis-trimmed sermon

Exit criteria:

- Low-confidence auto-trim runs are safe.
- Review UX and resume logic no longer hard-code “livestream only” where that is no longer true.

### Phase 6: Upload UX

Target files:

- `resources/views/livewire/media-upload/form.blade.php`
- `app/Livewire/MediaUpload/Form.php`
- `app/Livewire/Traits/WithUploadLifecycle.php`
- API validation/controller files for the same option

Tasks:

- Add an `Auto-trim to sermon` control that only appears when `mediaType === video`.
- Explain the tradeoff clearly: use it for roughly clipped sermon videos; leave it off for already-clean clips.
- If manual review is implemented, warn that ambiguous clips may pause for review rather than trimming automatically.
- Preserve the existing `Full Livestream` messaging so the user still understands when the full service pipeline is the better choice.

Exit criteria:

- Users have a middle option between “whole sermon video” and “full livestream”.
- The default path remains familiar and safe.

## Test Plan

### Unit

- `tests/Unit/Services/UnifiedMediaProcessorTest.php`
  - standard video stays on the existing pipeline
  - auto-trimmed video persists the new mode metadata
- `tests/Unit/Services/ProcessingPipelineBuilderTest.php`
  - assert the new auto-trimmed video sequence
- `tests/Unit/Services/ProcessingRunOrchestratorTest.php`
  - dispatch, retry, and cancellation behaviour for auto-trimmed video runs
  - an auto-trim video that fails mid-pipeline retries from the correct offset and job list
- `tests/Unit/Jobs/ExtractSermonTest.php`
  - confirm video-mode runs can use extracted section and baseline plans
- `tests/Unit/Jobs/ReclassifyIntroOutroSectionsTest.php`
  - confirm intro/outro edge heuristics still behave as expected when enabled for auto-trimmed videos
- `tests/Unit/Models/MediaProcessingLogTest.php`
  - mode helpers and manual-review eligibility for auto-trimmed video runs

### Feature

- `tests/Feature/Api/MediaUploadTest.php`
  - `/api/media/video` accepts the new trim option
- `tests/Feature/DirectSermonVideoUploadTest.php`
  - default video behaviour is unchanged
  - auto-trimmed video requests are accepted
- Add a focused integration test for:
  - intro song + sermon + outro prayer -> extracted sermon span excludes the non-sermon edges
- If manual review is implemented:
  - extend `tests/Feature/Api/ConfirmSegmentApiTest.php`
  - extend `tests/Feature/Livewire/ProcessingReviewTest.php`
  - extend `tests/Feature/Livewire/MediaUploadTest.php`

### Quality gates

- `vendor/bin/sail artisan test --compact <focused test paths>`
- `vendor/bin/sail composer phpstan`
- `vendor/bin/sail bin pint --dirty`

## Rollout Notes

- Ship with the new checkbox off by default.
- Keep trim metadata on the processing log so a few real uploads can be audited before considering any broader default.
- Do not change the existing livestream pipeline in the first pass beyond generalising the shared/manual-review pieces that the new mode needs.

## Rollback Notes

- Add a feature flag for accepting new auto-trim uploads, but do not make the runtime pipeline depend on that flag for already-created runs.
- If the feature needs to be turned off, disable it only for new uploads.
- In-flight runs should continue to resolve their persisted profile from processing metadata and finish on the code path they started with.
- Do not remove the `video_auto_trim` pipeline branch from orchestrator/phase/failure code until there are no active or retryable runs that still reference it.

## Suggested Delivery Order

This sequence intentionally defers the current manual-review phase until the happy path is stable.

1. Phase 1 plus Phase 2: add the video mode contract and the `video_auto_trim` pipeline/retry/failure profile.
2. Phase 3: reuse segmentation/classification/extraction jobs for the new profile.
3. Phase 4: make `CreateSermonRecord` store trimmed assets with `video_file_path ?? source_file_path` precedence.
4. Phase 6: add the upload UI toggle.
5. Phase 5: generalise manual review if ambiguous clips need human confirmation.
6. Run focused tests, then `phpstan`, then `pint`.
