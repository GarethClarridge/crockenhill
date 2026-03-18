# Media Processing and Church Service Observability Audit

Date: 2026-03-17

## Scope

This audit reviewed observability, queue tracing, logging, failure diagnosis, and existing test coverage across the media-processing and church-service domains.

Primary code paths reviewed:

- Media processing orchestration and status surfaces:
  - `app/Services/UnifiedMediaProcessor.php`
  - `app/Services/LivestreamSegmentationService.php`
  - `app/Services/SermonJobPipelineService.php`
  - `app/Services/ProcessingLogService.php`
  - `app/Services/SermonProcessingLogger.php`
  - `app/Data/StandardProcessingResponse.php`
  - `app/Enums/ProcessingStep.php`
  - `app/Models/MediaProcessingLog.php`
  - representative jobs across audio, video, livestream, notification, and cleanup
- Church-service ingestion, reconciliation, and review surfaces:
  - `app/Services/ImportChurchServiceFromOpenLp.php`
  - `app/Services/InboundEmailImportService.php`
  - `app/Services/OosEmailParserService.php`
  - `app/Services/OpenAiOosEmailItemExtractor.php`
  - `app/Services/ChurchServiceCanonicalUpdateService.php`
  - `app/Services/ChurchServiceReviewStateService.php`
  - `app/Services/ChurchServiceReconciliationDispatcher.php`
  - `app/Jobs/ProcessInboundOosEmail.php`
  - `app/Jobs/ReconcileServiceSections.php`
  - `app/Support/ChurchServiceProcessingTimeline.php`
  - `app/Support/ServiceRecordTimeline.php`

Representative unit, feature, API, and Livewire tests were also reviewed.

## What This Audit Did Not Do

This audit was code-first. It did not review incident history, alert history, production logs, or operator postmortems.

That matters for prioritization:

- some gaps below are immediate operator-facing problems
- some are latent landmines that would matter during refactor or feature expansion
- some are structural improvements that may only be worth doing if the team is already feeling operational pain

## Current Baseline

The project already has useful observability foundations.

- `media_processing_logs` is the main persisted run-state record.
- `sermon_processing_steps` gives durable per-step history for jobs that extend `ProcessingJob`.
- `InboundEmail.processing_metadata` and `ChurchService.import_metadata` preserve parser, review, and reconciliation context.
- Operators already have usable diagnosis surfaces:
  - API and Livewire processing-log views backed by `ProcessingLogService`
  - church-service timelines backed by `ServiceRecordTimeline`
  - inbound email review backed by `ReviewInboundEmails`
- Existing tests already cover many business behaviors and status transitions.

This is not a "no observability" system. The core issue is unevenness: some stages are easy to diagnose, while others depend on ad hoc log lines, transient queue runtime state, or metadata that is too thin to explain failures cleanly.

## Operational Context and Risk Framing

Based on the code as it exists today, the findings fall into three buckets.

### Immediate operator impact today

- media runs can lose evidence of degraded completion because `markAsCompleted()` clears `error_message`
- status/progress output can be misleading for real current steps because unmapped steps default to `50`

### Latent or conditional landmines

- the dedicated `sermon-processing` formatter and the current log parser are incompatible
- per-step timing metrics are only approximate and can drift on long-lived queue workers

### Structural improvements that may or may not be worth the cost right now

- full queue lifecycle tracing
- broader structured business-event logging
- widespread job tags and richer cross-domain correlation metadata

For queue-worker context:

- local Sail runs `php artisan queue:work redis --tries=3 --timeout=7200`
- production supervisor runs `php artisan queue:work redis --tries=3 --timeout=7200 --max-time=86400 --max-jobs=500`

So workers are long-lived enough that queue-runtime drift is real, but not so unbounded that every metric is meaningless. That is why timing accuracy is a lower-priority diagnosis issue, not the top operational risk.

## Prioritized Findings

### 1. Completed runs can silently lose evidence of degraded outcomes

This is the highest-risk current issue.

- `SendCompletionNotification` catches exceptions, sets `current_step` to `notification_failed`, and stores an `error_message`.
- `CleanupTemporaryFiles` later calls `MediaProcessingLog::markAsCompleted()`.
- `MediaProcessingLog::markAsCompleted()` sets `current_step` to `completed` and clears `error_message`.

The same pattern affects cleanup failures:

- `CleanupTemporaryFiles` logs a warning when cleanup fails
- it still calls `markAsCompleted()`
- no durable warning or partial-failure marker survives on the run record

Operational effect:

- a run that completed with a notification failure or cleanup problem can appear fully healthy in the final persisted record
- operators may only discover the problem by manually reading raw logs

### 2. Status progress is misleading for some real pipeline states

`StandardProcessingResponse` uses `ProcessingStep::progressForStep()`, and unmapped steps default to `50`.

Real steps currently falling through to that default include:

- `initiated_from_livestream`
- `restarting_from_beginning`
- `sending_notification`
- `notification_sent`
- `notification_skipped`
- `notification_failed`
- `notification_failed_permanently`

Operational effect:

- the API and UI can show a mid-progress percentage for steps that are actually late-stage or terminal-ish
- this is not catastrophic, but it is directly user-facing and easy to misread during diagnosis

### 3. The dedicated logging channel is a latent compatibility trap

`config/logging.php` defines a `sermon-processing` channel, but it is not currently used anywhere in app code.

If someone starts using it, there is an immediate compatibility problem:

- `app/Logging/SermonProcessingLogFormatter.php` emits multi-line human-readable output with `Context: ...`
- `app/Services/ProcessingLogService.php` expects single-line Laravel log entries ending in JSON context

Operational effect:

- this is not hurting the app today because the channel is unused
- it is a real refactor landmine because enabling the dedicated channel would likely break the existing processing-log viewer

### 4. Queue retry behavior exists, but queue failure context is not persisted

The app is not devoid of queue semantics:

- many jobs define retries and/or backoff rules
- examples include `TranscribeAudio`, `GenerateRmsLog`, `PerformVisualAnalysis`, `ExtractSermon`, `ProcessTranscriptWithAI`, `SendCompletionNotification`, and `UpdateSermonRecord`

What is missing is persistence of queue diagnosis data on the affected records.

No shared queue lifecycle instrumentation was found:

- no `Queue::before`, `Queue::after`, or `Queue::failing`
- no `JobProcessing`, `JobProcessed`, or `JobFailed` listeners

And no standard queue correlation metadata is persisted on the run records:

- no queue job UUID
- no attempt number
- no queue name
- no chain or batch identifier
- no worker identity or dequeue timestamp

Operational effect:

- the queue system knows how to retry work, but the domain records do not explain which attempt failed or where the worker was when it failed
- diagnosis still depends on grepping `processing_id` in `storage/logs/laravel.log`

### 5. Durable step history covers only part of the media pipeline

Jobs extending `ProcessingJob` write durable rows to `sermon_processing_steps`. That includes later-stage jobs such as:

- `CreateSermonRecord`
- `TranscribeAudio`
- `ProcessTranscriptWithAI`
- `ClassifyServiceSections`
- `TranscribeSpeechSegments`
- `ClassifySpeechSections`
- `AlignWithOos`
- `ExtractSermon`
- `PrepareSectionPublicationCandidates`

Important jobs that do not write `sermon_processing_steps` include:

- `ValidateAudioFile`
- `ValidateVideoFile`
- `GenerateRmsLog`
- `PerformVisualAnalysis`
- `AnalyzeSegments`
- `SubmitToProcessing`
- `GenerateThumbnail`
- `SendCompletionNotification`
- `CleanupTemporaryFiles`
- `UpdateSermonRecord`

Operational effect:

- early and late stages still rely mostly on `media_processing_logs.current_step` plus ad hoc log lines
- the durable run history is partial rather than end-to-end

This is worth knowing before refactor, but it is less urgent than the silent-outcome-loss issue above.

### 6. Church-service queued failures are visible, but not richly diagnosable

`ProcessInboundOosEmail::failed()` persists:

- failure message
- failed timestamp

It does not persist:

- exception class
- attempt number
- queue job UUID
- queue name
- parser/import stage that failed
- whether parse metadata had already been written before failure

Operational effect:

- the admin review queue can show that an inbound email failed
- it cannot explain repeated failures or distinguish parser-stage failures from import-stage failures without raw-log inspection

### 7. Church-service business logic is comparatively quiet in logs

The church-service domain relies more on resulting database state than on structured runtime logs.

Services with little or no logging include:

- `ImportChurchServiceFromOpenLp`
- `InboundEmailImportService`
- `OosEmailParserService`
- `OpenAiOosEmailItemExtractor`
- `ChurchServiceCanonicalUpdateService`
- `ChurchServiceReviewStateService`
- `ChurchServiceItemSyncService`
- `ChurchServiceSongLinker`
- `ServiceSectionReviewTriggerEvaluator`

Operational effect:

- it is usually possible to reconstruct what changed
- it is harder to reconstruct why it changed, especially around confidence thresholds, canonical conflicts, review reopening, and song-linking decisions

This is a real gap, but it reads more like missing explanatory instrumentation than an acute production defect.

### 8. Job tags are too sparse for strong filtering

Only two jobs currently expose tags:

- `GenerateThumbnail::tags()`
- `ProcessInboundOosEmail::tags()`

Operational effect:

- if the team later adopts richer queue inspection tooling, most jobs will still be hard to filter by processing run, church service, sermon, or inbound email

This is useful future-proofing work, not a first response item.

### 9. Performance metrics are approximate, not step-accurate

`SermonProcessingLogger::getExecutionTime()` is based on `LARAVEL_START` or request-start time.

Given the current worker setup:

- local workers can be long-lived
- production workers can process up to 500 jobs or run for up to 24 hours before recycle

That means the logged "execution_time" is not a trustworthy per-step timer for queued work. `ProcessingLogService::getPerformanceMetrics()` then aggregates those values as if they were step timings.

Operational effect:

- the numbers are directionally interesting at best
- they should not be treated as precise step-duration telemetry

This matters for correctness of performance reporting, but it is lower priority than fixing end-state diagnosis.

### 10. Dedicated logging channels exist, but all current diagnosis still depends on `laravel.log`

This is closely related to Finding 3, but operationally distinct.

- `sermon-processing` and `performance` channels are defined in `config/logging.php`
- no `Log::channel()` usage was found in the application
- current processing-log diagnosis is therefore tightly coupled to the default log sink and its format

Operational effect:

- retention, filtering, and future routing are constrained by the default app log
- there is no clean separation between media-processing logs and the rest of the application

This is mostly architectural debt today, not an urgent defect.

## Existing Coverage vs Remaining Gaps

The first draft did not connect the findings tightly enough to the tests that already exist. This is the more accurate picture.

### Notification and cleanup

Existing coverage:

- `tests/Unit/Jobs/SendCompletionNotificationTest.php` covers sent, skipped, and failure handling in isolation
- `tests/Unit/Jobs/CleanupTemporaryFilesTest.php` covers cleanup success and cleanup failure in isolation

Remaining gap:

- there is no characterization of the sequence where notification failure is followed by cleanup and the final `MediaProcessingLog` state is asserted end to end

### Progress and status mapping

Existing coverage:

- `tests/Feature/MediaProcessingStatusTransitionsTest.php` covers many mapped steps across audio, video, and livestream flows
- `tests/Unit/Services/SermonJobPipelineServiceTest.php` already verifies that `initiated_from_livestream` is written to the processing log

Remaining gap:

- current tests do not cover API/response behavior for the unmapped notification and restart states that now fall back to `50`

### Queue catch-handler persistence

Existing coverage:

- `tests/Feature/SermonProcessingErrorHandlingTest.php` and `tests/Feature/SermonProcessingJobChainTest.php` cover many failure paths and chain behaviors

Remaining gap:

- there is still value in direct characterization of the final persisted state produced by the chain and batch `catch()` handlers in `UnifiedMediaProcessor` and `LivestreamSegmentationService`

### Reconciliation triggers

Existing coverage:

- `tests/Feature/Livewire/AdminChurchServiceTest.php` already proves that one UI-driven manual-edit path appends `processing_metadata['reconciliation_triggers']`

Remaining gap:

- there is no direct service-level test for append behavior, empty-context no-op behavior, or dispatching across multiple matching runs in `ChurchServiceReconciliationDispatcher`

### Dedicated logging channel compatibility

Existing coverage:

- `tests/Unit/Services/ProcessingLogServiceTest.php` covers the current default `laravel.log` parsing behavior

Remaining gap:

- there is no direct coverage of `SermonProcessingLogFormatter`
- there is no test that proves parser compatibility if the dedicated channel is ever turned on

### Church-service review and canonical-conflict logic

Existing coverage:

- `tests/Feature/Api/ChurchServiceControllerTest.php` and `tests/Unit/Services/OosAlignmentServiceTest.php` cover much of this behavior indirectly

Remaining gap:

- there is still no direct unit coverage for `ChurchServiceCanonicalUpdateService`, `ChurchServiceReviewStateService`, or `ChurchServiceReconciliationDispatcher`

### Inbound email failure metadata

Existing coverage:

- `tests/Feature/Jobs/ProcessInboundOosEmailTest.php` already verifies that failure metadata stores `message` and `failed_at`

Remaining gap:

- only worth expanding if the team decides to enrich the failure payload or make queue diagnosis around inbound mail more important

## Characterization Tests To Add Before Refactor

The first draft over-prescribed P0 coverage. This is the leaner plan I would use now.

### P0: Add before any meaningful refactor

#### 1. Notification plus cleanup end-state characterization

Add a feature-level test that executes the notification-then-cleanup path and asserts the final persisted `MediaProcessingLog` outcome.

Why this stays P0:

- it covers the highest-risk current issue
- existing tests cover each job individually, but not the sequence that loses diagnostic information

Suggested file:

- `tests/Feature/MediaProcessing/CompletionOutcomePreservationTest.php`

#### 2. Unmapped progress-state characterization

Add explicit status/progress coverage for the currently unmapped but real states:

- `initiated_from_livestream`
- `restarting_from_beginning`
- `sending_notification`
- `notification_sent`
- `notification_skipped`
- `notification_failed`
- `notification_failed_permanently`

Why this stays P0:

- it is user-facing today
- it is easy to change accidentally during cleanup/refactor

Suggested file:

- extend `tests/Feature/MediaProcessingStatusTransitionsTest.php`

#### 3. Queue catch-handler persistence tests

Add direct characterization around the `catch()` behavior in:

- `UnifiedMediaProcessor`
- `LivestreamSegmentationService`

Assert final persisted fields such as:

- `status`
- `current_step`
- `error_message`
- `completed_at`

Why this stays P0:

- these catch paths are central to diagnosing failed processing runs
- existing failure tests cover a lot, but not this boundary explicitly

Suggested file:

- `tests/Feature/MediaProcessing/QueueCatchHandlerStateTest.php`

#### 4. Reconciliation-trigger history tests

Add direct unit coverage for `ChurchServiceReconciliationDispatcher`:

- appends history without clobbering existing entries
- does nothing when trigger context is empty
- dispatches once per matching completed livestream run

Why this stays P0:

- it protects the most visible observability trail in the church-service domain
- it avoids relying only on indirect UI coverage

Suggested file:

- `tests/Unit/Services/ChurchServiceReconciliationDispatcherTest.php`

### P1: Add if the refactor touches these areas

#### Dedicated logging channel adoption

If the refactor plans to activate or rely on `sermon-processing`, add:

- formatter tests for `SermonProcessingLogFormatter`
- parser compatibility tests for dedicated-channel output

#### Step-history normalization

If the refactor plans to standardize step persistence across all jobs, add:

- characterization of which current jobs do and do not write `sermon_processing_steps`

#### Church-service review-state refactor

If the refactor touches canonical-conflict or review-reopen rules, add:

- direct unit tests for `ChurchServiceCanonicalUpdateService`
- direct unit tests for `ChurchServiceReviewStateService`

#### Richer inbound-email failure metadata or queue tags

If the refactor plans to enrich `ProcessInboundOosEmail` failure diagnosis, add:

- tests for the richer failure payload
- direct tests for `ProcessInboundOosEmail::tags()`

## Recommended Next Actions

Instead of a large instrumentation wishlist, these are the three highest-leverage improvements.

### 1. Preserve degraded outcomes on completed runs

Scope: small

Goal:

- stop losing notification and cleanup warnings when the run is later marked completed

Likely shape:

- preserve a warning list or partial-failure metadata in `processing_metadata`
- avoid clearing relevant diagnostic state in `markAsCompleted()` when the run completed with known non-fatal issues

### 2. Complete the current-step progress mapping

Scope: small

Goal:

- map the real step strings already used by the pipeline so status output is internally consistent

Likely shape:

- add the missing step values to `ProcessingStep`
- extend the existing status-transition tests

### 3. Persist minimal queue failure correlation metadata

Scope: medium

Goal:

- improve diagnosis without committing to full platform-grade tracing

Minimum useful payload on failed records:

- job class
- attempt number
- queue name
- exception class
- failed timestamp

Apply first to:

- `MediaProcessingLog.processing_metadata`
- `InboundEmail.processing_metadata['failure']`

## Defer Unless Pain Justifies It

These improvements are valid, but should probably wait unless the team is already feeling the gap:

- enabling the dedicated `sermon-processing` channel and rewriting the parser/viewer around it
- adding broad queue lifecycle tracing or correlation IDs everywhere
- adding structured event logs around every parser, linker, canonical-update, and reconciliation decision
- adding tags to every queue job

## Bottom Line

The first draft was correct on the facts, but it was too even-handed in how it prioritized them.

The sharper conclusion is:

- the only clearly urgent issue is silent loss of degraded completion information
- the next most important fix is inaccurate progress reporting for real current steps
- the formatter/parser mismatch is a real landmine, but only if the dedicated channel is activated
- the rest are mostly structural observability improvements that should be sized against actual operator pain before the team invests heavily

That should make the pre-refactor plan much more proportionate for this project.
