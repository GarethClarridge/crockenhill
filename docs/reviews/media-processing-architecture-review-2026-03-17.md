# Media Processing Architecture Review

Date: 2026-03-17  
Author: Codex

## Scope

This review covers the media-processing lifecycle across the main upload entrypoints, orchestration services, queued jobs, state models, and the tests that exercise them.

Primary areas reviewed:

- API and admin upload entrypoints
- `UnifiedMediaProcessor`, `LivestreamSegmentationService`, `ProcessingInitiator`, `ProcessingPipelineBuilder`, and `SermonJobPipelineService`
- Livestream section/reconciliation services, especially `ServiceSectionSyncService`, `OosAlignmentService`, and `ChurchServiceReconciliationDispatcher`
- Processing jobs from validation through cleanup
- Runtime state models: `MediaProcessingLog`, `Sermon`, `ServiceSection`, `ChurchService`, `LivestreamSegment`, and `SermonProcessingStep`
- Feature and unit tests around upload, orchestration, retry, status, alignment, and publication

## Executive Summary

The codebase has a clear intent: one unified upload surface, typed processing modes, and a richer livestream pipeline that can end in both a `Sermon` and derived `ServiceSection` publication candidates. There are also some solid building blocks already in place, especially `ProcessingPipelineBuilder`, `ServiceSectionSyncService`, and the late-arrival reconciliation flow for OpenLP imports.

The main architectural problem is not missing functionality. It is that orchestration, workflow state, and recovery behavior are spread across too many places:

- multiple services dispatch or resume chains
- several aggregates can each claim ownership of the same state
- retry and cancellation behavior are only partly aligned with the current pipelines
- workflow names and rules are duplicated across raw step writes, retry switches, and UI adapters

The safest path forward is not a rewrite. It is to introduce one run orchestrator around the existing jobs, make phase/state vocabulary canonical, then harden retry, cancellation, and idempotency before reducing duplicated state.

## What Exists Today

### Entry Points

Uploads enter through a small number of surfaces:

- API media upload endpoints
- admin sermon upload controller actions
- Livewire upload components for general media and church-service imports
- manual-review confirmation and admin reclassification actions for livestream runs

All media types eventually create a `MediaProcessingLog`, but they do not all create or resume runs through the same path.

Historical note:

- no active `ProcessingRouter` class was found under `app/`; the concept only appears in older migration docs, so `UnifiedMediaProcessor` appears to have absorbed that routing role in the current codebase

### Core Runtime Aggregates

- `MediaProcessingLog` acts as the runtime processing record and also stores many extracted artifacts and review metadata.
- `Sermon` acts as the published sermon record and duplicates some artifact and identity data from the run.
- `ServiceSection` acts as a derived per-section record, plus publication state machine, plus extracted section assets.
- `ChurchService` acts as the imported order-of-service aggregate and also owns review state for service-level reconciliation.
- `SermonProcessingStep` is a secondary per-phase audit log.

### Good Existing Building Blocks

- `ProcessingPipelineBuilder` already centralizes the current job ordering for audio, direct video, livestream, post-review resume, and reclassification.
- `ServiceSectionSyncService` is one of the stronger parts of the design. It updates sections by `section_order`, preserves existing metadata where possible, cleans up superseded assets, and deletes stale rows.
- `ChurchServiceReconciliationDispatcher` plus `ReconcileServiceSections` gives the system a way to reconcile completed livestream runs after OpenLP service data arrives later.

## Current Lifecycle Map

### 1. Audio Upload to Final Sermon State

Entry:

- API/admin/Livewire upload hands an audio file to `UnifiedMediaProcessor`

Startup:

- `UnifiedMediaProcessor::processAudio()` validates the uploaded file
- the audio file is stored immediately on the sermon disk
- a `MediaProcessingLog` is created directly in `UnifiedMediaProcessor`

Pipeline:

1. `ValidateAudioFile`
2. `CreateSermonRecord`
3. `IdentifySpeaker`
4. `TranscribeAudio`
5. `ProcessTranscriptWithAI`
6. `SendCompletionNotification`
7. `CleanupTemporaryFiles`

Final state:

- `Sermon` exists and is linked from `MediaProcessingLog`
- sermon audio and transcript paths are stored on the sermon
- AI-derived fields may be written onto the sermon
- `MediaProcessingLog` is marked completed by `CleanupTemporaryFiles`

### 2. Direct Video Upload to Final Sermon State

Entry:

- API/admin/Livewire upload hands a video file to `UnifiedMediaProcessor`

Startup:

- `UnifiedMediaProcessor::processDirectVideo()` stores the upload in temporary storage
- `ProcessingInitiator` creates the `MediaProcessingLog` with extracted date/service metadata

Pipeline:

1. `ValidateVideoFile`
2. `ExtractAudioFromVideo`
3. `CreateSermonRecord`
4. `IdentifySpeaker`
5. `TranscribeAudio`
6. `ProcessTranscriptWithAI`
7. `GenerateThumbnail`
8. `SendCompletionNotification`
9. `CleanupTemporaryFiles`

Final state:

- `Sermon` exists with permanent audio and video paths
- transcript and thumbnail may be attached to the sermon
- `MediaProcessingLog` again reaches completion via cleanup

### 3. Livestream Upload to Final Sermon and Service Section State

Entry:

- API/admin/Livewire upload hands a livestream video to `UnifiedMediaProcessor`
- `UnifiedMediaProcessor` delegates startup to `LivestreamSegmentationService`

Startup:

- `VideoStorageService` stores the uploaded video in temp storage
- `VideoSegmentationService` validates and inspects metadata
- `ProcessingInitiator` creates the `MediaProcessingLog`

Parallel phase:

- `PerformVisualAnalysis`
- `GenerateRmsLog`

Sequential phase:

1. `AnalyzeSegments`
2. `ClassifyServiceSections`
3. `TranscribeSpeechSegments`
4. `ClassifySpeechSections`
5. `AlignWithOos`
6. `ExtractSermon`
7. `SubmitToProcessing`
8. `IdentifySpeaker`
9. `TranscribeAudio`
10. `ProcessTranscriptWithAI`
11. `GenerateThumbnail`
12. `PrepareSectionPublicationCandidates`
13. `SendCompletionNotification`
14. `CleanupTemporaryFiles`

Manual-review branch:

- `ExtractSermon` can halt the remaining chain when sermon-candidate confidence is unclear
- the run is marked `manual_review_required`
- an admin/API confirmation uses `ConfirmLivestreamSermonSegment`
- that action dispatches a shorter post-review chain beginning at `ExtractSermon`

Final state:

- `MediaProcessingLog` may end as completed, failed, cancelled, or `manual_review_required`
- `Sermon` may be created from the extracted sermon media
- `ServiceSection` rows exist for classified sections
- eligible sections can move to `pending_approval` and have extracted section assets attached

### 4. Church Service Import to Reconciled Service Section State

Entry:

- OpenLP import enters through controller or Livewire upload and calls `ImportChurchServiceFromOpenLp`

Import path:

- parser extracts date, service, review metadata, and items
- service record is created or updated
- items are synced
- songs are linked
- canonical/review state is finalized

Reconciliation path:

- `ChurchServiceObserver` and `DispatchChurchServiceReconciliation` trigger reconciliation when the service record changes
- `ChurchServiceReconciliationDispatcher` finds completed livestream runs whose extracted identity matches the imported service
- `ReconcileServiceSections` calls `OosAlignmentService`

Final state:

- `ServiceSection` rows may gain `church_service_item_id`, stronger titles, confidence updates, metadata review flags, and manual-review markers
- `ChurchService` review metadata and `needs_review` are updated from the section-level reconciliation result

## Main Findings

### 1. Orchestration Is Split Across Too Many Layers

Current orchestration is spread across:

- `UnifiedMediaProcessor`
- `LivestreamSegmentationService`
- `ProcessingPipelineBuilder`
- `SermonJobPipelineService`
- `ConfirmLivestreamSermonSegment`
- admin reclassification in `ShowChurchService`
- a private `dispatchProcessingJobs()` method inside `LivestreamSegmentationService` whose name collides with the public `dispatchProcessingJobs()` method in `SermonJobPipelineService`

That creates three problems.

First, startup is not actually unified. Audio creates its own `MediaProcessingLog` in `UnifiedMediaProcessor`, while direct video and livestream use `ProcessingInitiator`.

Second, resume paths are inconsistent. Initial audio/video runs dispatch with chain-level failure handlers, initial livestream runs dispatch with batch-plus-chain failure handling, but manual-review resume and admin reclassification dispatch direct `Bus::chain(...)` calls without the same shared failure wrapper.

Third, there are now orchestration helpers that look important but are drifting away from the actual runtime. `SermonJobPipelineService::dispatchProcessingJobs()` and `createProcessingLogWithLivestreamContext()` are mostly exercised by tests rather than by the active upload flow. That is a classic sign that orchestration has started to fork.

Impact:

- behavior changes must be made in multiple places
- new entrypoints can accidentally bypass failure, cancellation, or telemetry rules
- tests can give confidence in helpers that are no longer on the hot path

### 2. Retry Logic Still Reflects an Older Pipeline Shape

`SermonJobPipelineService::retryProcessing()` resets the log and then restarts processing from `current_step`. The problem is that its restart logic dispatches a single job for steps like:

- `transcribing_audio`
- `analyzing_transcript`
- `updating_sermon_record`
- `sending_notification`

That model only works if each restarted job is responsible for dispatching the rest of the pipeline. The current architecture does not work that way. The active pipelines are defined up front in `ProcessingPipelineBuilder` and executed as chains.

The retry layer also still references legacy steps and jobs such as:

- `preparing`
- `retry_initiated`
- `updating_sermon_record`
- `UpdateSermonRecord`

Those names still exist in tests and enums, but they are not part of the active audio/video/livestream chains built by `ProcessingPipelineBuilder`.

Impact:

- audio/video retries can restart a single job and then stop prematurely
- retry semantics depend on stale step names rather than the actual active pipeline definition
- the codebase now has a mismatch between "what the orchestrator says is retryable" and "what the runtime actually dispatches"

### 3. Manual-Review Resume and Reclassification Bypass Shared Failure Handling

Initial livestream dispatch has explicit failure handling:

- batch failure goes through `handleProcessingFailure()`
- downstream chain failure also goes through `handleProcessingFailure()`

Manual-review resume does not. `ConfirmLivestreamSermonSegment` builds post-review jobs and dispatches them directly. The admin `reclassify()` action does the same for reclassification.

That means these resumed paths can miss:

- centralized log failure marking
- temp-file cleanup
- admin failure notification
- any future shared telemetry or compensating behavior

Impact:

- identical logical workflows can fail differently depending on where they were resumed
- manual intervention paths are more fragile than initial ingest

### 4. Runtime State Ownership Is Diffuse

The same facts are stored in more than one aggregate.

#### Processing/runtime facts

`MediaProcessingLog` owns:

- upload metadata
- extracted date/service identity
- current step and status
- audio/video/transcript paths
- sermon timing
- AI analysis
- manual-review state
- reconciliation trigger history

#### Published sermon facts

`Sermon` also owns:

- audio/video/transcript paths
- livestream processing link
- segment start/end
- preacher review and AI-derived sermon metadata

#### Section/publication facts

`ServiceSection` owns:

- classified section identity
- section review state
- publication workflow state
- extracted section assets
- OoS alignment metadata and review flags

#### Service-level review facts

`ChurchService` owns:

- `needs_review`
- canonical conflict history
- import review triggers
- manual-review reopening metadata

This is not just denormalization for performance. In several places these records can each be treated as the source of truth for review state, media identity, and artifact location.

Impact:

- invariants are hard to reason about
- retries and reprocessing can refresh one aggregate while leaving another stale
- there is no clear answer to "which record owns runtime truth" versus "which record owns published truth"

### 5. Cancellation Is Cooperative, Not Enforced

Cancellation currently means "mark the run cancelled and hope later jobs notice."

What works:

- `MediaProcessingLog::markAsProcessing()` refuses to move a cancelled run back to processing
- `ProcessingJob` provides a shared `isCancelled()` helper plus step logging, so there is already a natural place to centralize more of the cancellation policy
- 11 concrete media-processing jobs currently check cancellation early and exit

What does not work consistently:

- many important jobs still do not guard against cancellation at all, including `ValidateAudioFile`, `ValidateVideoFile`, `ExtractAudioFromVideo`, `AnalyzeSegments`, `GenerateThumbnail`, `SendCompletionNotification`, `CleanupTemporaryFiles`, `SubmitToProcessing`, and `PrepareSectionPublicationCandidates`
- a small but important cluster of jobs still writes raw string statuses directly instead of using guarded helpers, especially `ValidateAudioFile`, `ValidateVideoFile`, `ExtractAudioFromVideo`, and `SubmitToProcessing`
- `SubmitToProcessing` is the most dangerous of those raw-status writes because it can overwrite a cancelled run with `failed` late in the livestream pipeline
- `CleanupTemporaryFiles` always marks the run completed, even if the run was cancelled first

Impact:

- a cancelled run can still be moved back to `processing`, `failed`, or `completed` by later queued work
- cancel behavior depends on which job happens to run next
- there is no queue-level cancellation token or orchestration barrier

### 6. Idempotency Boundaries Are Inconsistent

Some parts of the system are deliberately idempotent. Others are not.

Strongest example:

- `ServiceSectionSyncService` updates existing rows by `section_order`, cleans superseded assets, and deletes stale sections in a transaction

Riskier examples:

- `AnalyzeSegments` blindly creates `LivestreamSegment` rows, but the table has a unique index on `(media_processing_log_id, segment_index)`
- livestream retry deletes `segments()` and restarts, but it does not reset every downstream artifact and metadata field that may have been written by a partial prior run
- `SubmitToProcessing` refreshes an existing sermon in some cases, which is useful for reprocessing, but also means reruns can mix old published state with newly extracted run artifacts
- `PrepareSectionPublicationCandidates` skips re-extraction only when both extracted files still exist, which is only a partial idempotency check

Impact:

- partial retries can fail with duplicate-key errors
- restarts can leave mixed old/new state in logs, sermons, or section assets
- "rerun from here" is not the same as "rebuild this phase safely"

### 7. Queue-Level and Application-Level Retries Can Compound

Retries currently exist at more than one layer:

- many jobs declare their own `tries`
- several jobs define queue backoff schedules
- `config/media-processing.php` defines retry settings for processing, transcription, analysis, and S3 upload flows
- `SermonJobPipelineService::retryProcessing()` adds a separate application-level retry path

That means a non-idempotent phase can be retried by Laravel's queue worker and then be retried again by the application-level restart flow.

Impact:

- side effects can multiply before an operator explicitly retries the run
- queue retry tuning and application retry tuning can drift independently
- idempotency work is more urgent for the phases that both write files and mutate database state

### 8. Failure Recovery Is Incomplete

There is failure handling, but it is uneven.

Gaps:

- resumed chains bypass the shared livestream failure handler
- notification failures are recorded but can be followed by cleanup marking the run completed anyway
- livestream retry clears segments, but not all downstream derived artifacts
- reconciliation only aligns existing classified sections; it does not reconstruct missing upstream section classification
- external side effects such as stored media files are not uniformly paired with compensating cleanup when a later phase fails

Impact:

- the system recovers best from "clean fail before write"
- it recovers much less well from "fail after some side effects succeeded"

### 9. Workflow Vocabulary and Rules Are Not Consistently Enforced

There is already an intended canonical runtime step vocabulary: `ProcessingStep`.
The problem is that it is not enforced consistently across run-state writes, retry logic, and UI adapters.

#### Canonical phase source

- `ProcessingStep` declares itself the canonical step source for `current_step` values and progress mapping
- many jobs and services still write raw string step values directly rather than depending on the enum
- retry logic still switches over raw string values, including legacy ones that are no longer in active pipelines

#### Audit projection

- `SermonProcessingStep` is not a competing state machine; it is a useful audit projection that records step plus status (`STARTED`, `COMPLETED`, `FAILED`, `SKIPPED`, `CANCELLED`)
- the risk here is not that the audit log defines a rival vocabulary, but that step strings are still passed around loosely enough that the projection can drift from the intended canonical enum

#### UI grouping adapter

- `ChurchServiceProcessingTimeline` is also not authoritative state; it is a UI grouping layer that maps fine-grained `current_step` values to six coarse display stages
- the real risk is adapter drift, because `fromCurrentStep()` only maps a subset of the active run steps

#### Review-state duplication

Review state is spread across:

- `needs_manual_review`
- `publication_status`
- `metadata.review_reason`
- `metadata.review_flags`
- `processing_metadata.manual_review`
- `ChurchService.needs_review`
- `ChurchService.import_metadata.review_triggers`

#### Identity vocabulary

Extracted service identity is duplicated in both columns and metadata:

- `ProcessingInitiator` writes `extracted_date`, `extracted_datetime`, and `extracted_service` into `processing_metadata`
- `MediaProcessingIdentityResolver` reads both dedicated columns and metadata fallbacks
- the backfill command for historical logs still restores the dedicated columns from metadata when needed

Impact:

- changing a workflow name becomes a cross-cutting change
- raw step writes, retry switches, and UI adapters can drift from the intended canonical enum
- business rules are duplicated in multiple services and metadata shapes

### 10. Test Coverage Is Helpful but Drifting

Coverage is strongest around:

- pipeline ordering
- upload/status response behavior
- service section sync behavior
- OoS alignment behavior
- manual-review confirmation preconditions

Coverage is weaker around the most architecture-sensitive risks:

- cancellation after jobs have already been queued
- retries that must continue the remainder of a chain
- idempotency under partial writes and retries
- failure behavior of post-review resume chains
- cleanup overriding cancelled or failed terminal states
- reclassification failure handling

There is also a drift problem: some tests still heavily exercise legacy orchestration concepts like `UpdateSermonRecord` and older retry step names that are no longer part of the active chain definitions.

Impact:

- the suite gives good local confidence but incomplete lifecycle confidence
- architecture refactors will be riskier until characterization coverage is added around the current hot paths

## Duplicate Rules and State Names Worth Consolidating First

The highest-value duplication to remove first is:

1. processing phase names
2. retry eligibility rules
3. cancellation semantics
4. manual-review state
5. service identity storage

Recommended canonical sources:

- one enforced canonical phase enum for all runtime phase names
- one retry policy map keyed by canonical phase
- one cancellation policy owned by the orchestrator
- one runtime manual-review state on the processing run
- dedicated run identity columns for date/service, with metadata only for provenance

## Target Architecture

### Design Principles

1. Keep `MediaProcessingLog` as the runtime aggregate, but narrow its responsibility to run state and staging artifacts.
2. Treat `Sermon` and `ServiceSection` as published or derived domain records, not as alternate owners of runtime progress.
3. Put every start, retry, resume, cancel, and reclassify action behind one orchestrator.
4. Make each processing phase resumable from a canonical cursor, not from raw string comparisons.
5. Require every write-heavy phase either to be idempotent or to declare a reset strategy.

### Proposed Shape

#### 1. Processing Run Orchestrator

Introduce a single orchestration service, for example `ProcessingRunOrchestrator`, responsible for:

- creating runs
- dispatching initial pipelines
- resuming post-review pipelines
- retrying from a phase cursor
- cancelling runs
- owning shared catch/failure handling

Every entrypoint should call this service instead of dispatching `Bus::chain(...)` directly.

#### 2. Phase Registry

Replace scattered switch statements and raw strings with a phase registry that defines, for each media type:

- ordered phases
- the job class for that phase
- whether the phase is retryable
- whether the phase is idempotent
- whether the phase writes staging artifacts or published artifacts
- what cleanup/reset is needed before retry

That registry should not force every media type into the same dispatch primitive. It needs to support both:

- pure chain pipelines for audio and direct video
- batch-then-chain pipelines for livestream processing

It can still become the source of truth for:

- initial dispatch
- retry from phase N
- UI progress mapping
- allowed resume points

#### 3. Clear Aggregate Ownership

Target ownership should look like this:

| Concern | Target owner |
| --- | --- |
| runtime status, current phase, last error, cancellation, manual-review state | `MediaProcessingLog` |
| staged extracted artifacts used during processing | `MediaProcessingLog` |
| published sermon media and sermon metadata | `Sermon` |
| classified section identity and publication workflow | `ServiceSection` |
| imported order-of-service and service-level review summary | `ChurchService` |
| per-phase audit trail for operator UI | `SermonProcessingStep` as projection/log only |

Key rule: if a field is only needed while a run is active or resumable, it belongs on the processing run. If it is part of the published record shown to users, it belongs on the published aggregate.

#### 4. Typed Runtime Review State

Manual-review behavior should be expressed as explicit run state, not as a mixture of status, current step, and metadata structure. For example:

- `review_state = none | required | confirmed | dismissed`
- `review_reason_code`
- `review_payload`

Section review flags and church-service review flags can remain separate because they are domain review states, but they should not also be used to infer the run's runtime manual-review status.

#### 5. Cancellation Token Semantics

Cancellation should become a first-class orchestration rule:

- all jobs check a shared cancellation guard
- terminal state setters must not overwrite `cancelled`
- cleanup must be able to run without changing the final status
- the orchestrator should stop dispatching downstream phases once a run is cancelled

#### 6. Idempotent Phase Contract

Each phase should declare one of:

- safe to rerun without reset
- requires targeted reset before rerun
- requires full-run restart

For example:

- segment analysis should upsert or replace by `(processing_log_id, segment_index)`
- sermon submission should distinguish "refresh existing published sermon" from "first publish"
- section publication preparation should track extraction provenance and reuse rules more explicitly

## Safest Refactor Sequence

### 1. Add Characterization Tests Around the Current Hot Paths

Do this before behavior changes.

Add focused tests for:

- retry from `transcribing_audio` and `analyzing_transcript` continuing the remainder of the chain
- cancellation after downstream jobs are already queued
- cleanup not overwriting cancelled or failed terminal states
- post-review resume chain failures using the same failure handling as initial livestream ingest
- `AnalyzeSegments` rerun behavior after partial writes
- admin reclassification failure behavior

This creates safety rails for the refactor without requiring any schema change.

### 2. Enforce the Existing Canonical Phase Vocabulary

Keep storage backward-compatible at first.

Steps:

- keep `ProcessingStep` as the canonical runtime phase enum
- add adapters from legacy raw strings to canonical phases
- make jobs, progress mapping, retry policy, and UI adapters depend on that canonical phase map
- leave old string values readable during transition

This is still low risk, but it is smaller than inventing a new vocabulary because the canonical enum already exists.

### 3. Introduce One Orchestrator Without Rewriting Jobs

Create a new orchestrator that wraps existing jobs and existing `ProcessingPipelineBuilder` ordering.
It should unify orchestration policy, not flatten all pipelines into the same Laravel dispatch shape.

Initial migration target:

- upload start
- post-review resume
- reclassify
- retry
- cancel

Goal:

- every path uses the same dispatch wrapper
- every path gets the same catch/failure logic
- both chain-only and batch-then-chain pipelines stay supported
- no direct `Bus::chain(...)` calls remain in controllers, actions, or Livewire components

### 4. Replace Retry Switches With Phase-Cursor Rebuild

Once the orchestrator exists, move retry logic from "dispatch a single job based on a string" to:

- resolve canonical current phase
- optionally reset artifacts owned by that phase
- rebuild the remaining chain from that phase onward
- dispatch through the same orchestrator wrapper

This is the highest-value correctness fix for audio/video processing.

### 5. Harden Cancellation and Terminal-State Rules

Next, make terminal-state semantics safe:

- add cancellation checks to all remaining jobs
- route all status transitions through guarded helpers
- make `markAsCompleted()` and cleanup logic respect existing cancelled terminal state
- ensure resumed or delayed jobs cannot revive a cancelled run

This step reduces race-condition behavior without changing the business workflow itself.

### 6. Make Write-Heavy Phases Explicitly Idempotent

Focus first on:

- `AnalyzeSegments`
- `SubmitToProcessing`
- `PrepareSectionPublicationCandidates`
- any phase that writes files and database rows in the same operation

At this stage, add targeted reset helpers where idempotent reruns are not realistic.

### 7. Clarify Runtime vs Published Ownership

After orchestration is stable, reduce duplicated state:

- keep staging-only fields on `MediaProcessingLog`
- keep published media on `Sermon`
- keep publication candidate state on `ServiceSection`
- stop duplicating extracted identity in both columns and metadata except for migration/provenance

This is where schema cleanup should happen, because by this point retry/cancel behavior is already safer.

### 8. Prune Legacy Abstractions and Outdated Tests

Only after the new orchestrator and phase model are proven:

- remove or shrink legacy orchestration helpers that are no longer on the hot path
- retire old step names that exist only for compatibility
- rewrite tests that currently center legacy runtime concepts instead of the active pipeline definitions

## Immediate Low-Risk Fixes Before the Broader Refactor

If the team wants a short correctness pass before the larger orchestration work, the safest concrete fixes are:

1. make `CleanupTemporaryFiles` preserve existing cancelled and failed terminal states
2. replace raw string failure writes with guarded helpers or enum-backed updates in `ValidateAudioFile`, `ValidateVideoFile`, `ExtractAudioFromVideo`, and `SubmitToProcessing`
3. add shared `.catch()` handling to post-review resume and admin reclassification dispatch
4. widen cancellation coverage by building on the existing `ProcessingJob` helper and adding characterization tests around the unguarded jobs

## Recommended Near-Term Decisions

If only a small amount of refactor time is available, the highest-leverage sequence is:

1. add characterization tests
2. centralize orchestration for all dispatch paths
3. fix retry to rebuild the remaining chain
4. make cancellation terminal-state-safe

That sequence gives the biggest reliability gain without forcing early schema changes.

## Bottom Line

The architecture does not need a wholesale replacement. It needs a firmer center.

That center should be:

- one orchestrator
- one canonical phase model
- one runtime state owner
- explicit retry, cancel, and idempotency rules

Once those are in place, the rest of the system can keep most of its current jobs and domain logic while becoming much easier to reason about and much safer to evolve.
