# Livestream Manual Review - Implementation Plan

## Overview

When livestream processing reaches `ExtractSermon`, the pipeline can already stop and mark a run as `failed` with `current_step = manual_review_required` when sermon selection confidence is too low. The missing piece is the human resolution path: admins cannot currently see flagged runs, choose the correct segment, or resume processing from that decision.

This plan adds that missing workflow in a way that fits the current architecture and avoids restarting the full livestream pipeline.

---

## Goals

- Give admins a clear review queue for livestream runs waiting on sermon confirmation.
- Let an admin inspect detected segments and choose the sermon segment explicitly.
- Resume processing from `ExtractSermon` onward without re-running segmentation.
- Preserve an audit trail of why the run was flagged and who confirmed the sermon.
- Fix notification links so the email points to a real review screen.

## Non-goals

- Rebuild the full livestream retry flow.
- Re-run RMS generation, visual analysis, or segment analysis after confirmation.
- Generalize this UI for every possible manual-review state in the system.

---

## Key Design Decisions

### 1. Use an admin review surface, not a members-area page

Although the original proposal suggested `/church/members/...`, the actual review tooling in this repository lives under admin routes and uses the admin layout. This feature should therefore live under `/admin/services/...` so it matches existing service-review workflows and stays behind admin authorization.

Recommended routes:

- `GET /admin/services/processing/review`
- `GET /admin/services/processing/{processingLog}/review`

Optional API route for programmatic use:

- `POST /api/media/processing/{processingId}/confirm-segment`

### 2. Manual confirmation must become the extraction source of truth

The selected segment should not merely bypass the confidence check. It must become the extraction plan used by `ExtractSermon`, otherwise the job could still fall back to stale baseline sermon bounds.

Recommended approach:

- Store manual review state in `processing_metadata.manual_review`.
- Make `SermonExtractionPlanResolver` prefer a confirmed segment before any existing baseline or section-derived logic.
- Keep `ExtractSermon::guardAutoExtractionPolicy()` as the policy gate for automatic selection only.

### 3. Resume from `ExtractSermon` onward with a dedicated chain

The existing livestream retry flow wipes segments and restarts the whole pipeline. That is the wrong tool for manual sermon confirmation.

Instead, introduce a dedicated resume path that dispatches only the downstream jobs:

- `ExtractSermon`
- `SubmitToProcessing`
- `IdentifySpeaker`
- `TranscribeAudio`
- `ProcessTranscriptWithAI`
- `GenerateThumbnail`
- `PrepareSectionPublicationCandidates`
- `SendCompletionNotification`
- `CleanupTemporaryFiles`

### 4. Record structured review metadata

Do not rely on `error_message` alone. Persist structured data for the review workflow under `processing_metadata.manual_review`, for example:

```php
[
    'status' => 'required'|'confirmed',
    'reason_code' => 'multiple_qualifying_speech_blocks'|'no_qualifying_speech_block'|'ratio_below_threshold',
    'reason_message' => 'The longest speech block was not at least 1.5x longer...',
    'flagged_at' => '2026-03-14T10:15:00+00:00',
    'speech_segments' => [
        ['segment_id' => 10, 'start_time' => 0.0, 'end_time' => 1320.0, 'duration' => 1320.0],
    ],
    'confirmed_segment_id' => 10,
    'confirmed_by_user_id' => 1,
    'confirmed_at' => '2026-03-14T11:02:00+00:00',
]
```

This gives the UI everything it needs and creates a proper audit trail.

---

## Proposed Delivery Phases

## Phase 1 - Domain and Pipeline Support

### 1.1 `MediaProcessingLog` manual-review helpers

Add explicit helper methods on `MediaProcessingLog`:

- `markForManualReview(string $reasonCode, string $reasonMessage, array $speechSegments = []): bool`

> **Breaking change note:** `ExtractSermon` already calls `markForManualReview()` with the current single-string signature. This new signature adds `$reasonCode` and `$speechSegments` parameters. All callers must be updated as part of this phase, and existing tests for `ExtractSermon` and `MediaProcessingLog` that call or assert on this method must be updated accordingly.
- `confirmSermonSegment(int $segmentId, int $userId): bool`
- `manualReviewMetadata(): array`
- `manuallyConfirmedSegmentId(): ?int`
- `requiresManualSermonReview(): bool`

Implementation notes:

- Keep data under `processing_metadata.manual_review`.
- Preserve the current external state contract:
  - `status = failed`
  - `current_step = manual_review_required`
- On confirmation:
  - clear `error_message`
  - set `status = pending`
  - set `current_step = manual_review_confirmed` (add this value to the `ProcessingStep` enum)
  - keep this step until the resume chain picks it up and advances to `extract_sermon`

### 1.2 `ExtractSermon` and resolver changes

Update the extraction flow so manual confirmation is first-class:

- `SermonExtractionPlanResolver` — **this is a new class**, not an existing one. The extraction plan logic currently lives inline inside `ExtractSermon`. PR 1 should extract it into a dedicated resolver class as part of this phase, then add the manual confirmation branch.
  - add a `manualReviewPlan()` branch before section-based or baseline logic
  - load the confirmed `LivestreamSegment`
  - build a `single_span` extraction plan from that segment
  - annotate metadata with:
    - `strategy = 'manual_review_confirmed_segment'`
    - `sermon_segment_id`
    - `manual_confirmation = true`

- `ExtractSermon::guardAutoExtractionPolicy()`
  - if a confirmed segment exists, return the plan unchanged
  - only evaluate confidence when the plan is still being auto-selected from processing-log bounds
  - when flagging for review, write both `reason_code` and `reason_message`

### 1.3 Dedicated resume pipeline builder method

Add a new method to `ProcessingPipelineBuilder`, for example:

- `buildLivestreamPostReviewChainJobs(MediaProcessingLog $log): array`

This should return the downstream jobs starting at `ExtractSermon`.

---

## Phase 2 - Confirmation Action and Endpoint

### 2.1 Create a dedicated action/service

Introduce an application service, for example:

- `App\Actions\ConfirmLivestreamSermonSegment`

Responsibilities:

- load the processing log
- verify it is a livestream run
- verify it is currently awaiting manual sermon review
- verify the chosen segment belongs to the processing log
- verify the chosen segment is a speech segment
- ensure the source video still exists
- persist manual review confirmation metadata
- dispatch the resume chain

This action should be the single place where the confirmation workflow lives.

### 2.2 Optional API endpoint

If the API endpoint is still wanted, keep it thin:

- `POST /api/media/processing/{processingId}/confirm-segment`

Request body:

```json
{
  "segment_id": 123
}
```

Behavior:

- delegate to `ConfirmLivestreamSermonSegment`
- return `202 Accepted` when resume dispatch succeeds
- expose a status URL so the frontend can redirect to the normal processing status page if needed

### 2.3 Idempotency and concurrency

Protect against double-confirmation:

- use a transaction with row locking on `media_processing_logs`
- if already confirmed, either:
  - return success with the existing confirmed segment, or
  - reject unless the same segment was chosen
- prevent dispatching duplicate resume chains

Recommended guard:

- only allow confirmation when `manual_review.status = 'required'`
- only allow confirmation for speech segments

---

## Phase 3 - Admin Review UI

### 3.1 Queue page

Create an admin page listing all livestream processing runs where:

- `processing_type = livestream`
- `status = failed`
- `current_step = manual_review_required`
- `processing_metadata.manual_review.reason_code` exists

For v1, the queue should include sermon-review cases only. It should not attempt to surface every other possible manual-review state in failed livestream processing.

Recommended route:

- `GET /admin/services/processing/review`

**JSON querying note:** The queue filter on `processing_metadata.manual_review.reason_code` must use Laravel's `whereJsonPath()` or `whereJsonContains()` — never a raw `LIKE` string match. For example:

```php
MediaProcessingLog::query()
    ->where('processing_type', ProcessingType::Livestream)
    ->where('status', ProcessingStatus::Failed)
    ->where('current_step', 'manual_review_required')
    ->whereJsonPath('processing_metadata->manual_review->reason_code', '!=', null);
```

Recommended columns:

- flagged date/time
- original filename
- extracted date/service
- review reason
- number of speech segments considered
- reviewer state (`Awaiting review` / `Confirmed`)
- action link to review detail

This can be server-rendered Blade or Livewire. A simple Livewire list fits well if filtering/search is desirable.

### 3.2 Review detail page

Create a dedicated Livewire component, for example:

- `App\Livewire\Admin\ChurchServices\ProcessingReview`

Recommended route:

- `GET /admin/services/processing/{processingLog}/review`

Data to show:

- original filename
- processing ID
- extracted date/service identity
- current status and flagged timestamp
- manual review reason
- source video availability
- detected segment timeline

Segment presentation:

- order by `start_time`
- color-code by classification (`speech`, `song`, `silence`)
- show start/end/duration
- highlight:
  - `is_sermon_candidate`
  - manually confirmed segment, once chosen

Actions:

- `This is the sermon` on eligible speech segments
- disabled state while confirmation is in flight
- clear success/error feedback
- after success, redirect to the review queue with a success toast and a link to the normal processing status page
- redirect after success to either:
  - the queue page, or
  - the standard processing status/detail page

### 3.3 UI implementation notes

- Use the admin layout and shared components.
- Reuse table/card patterns from existing admin church-service pages.
- Keep the timeline view lightweight; no charting library is needed.
- Include empty, error, and unavailable-source states.
- Ensure all internal links use `wire:navigate`.
- Do not embed a playable original-video preview in v1; segment metadata plus timestamps are sufficient to unblock review.

---

## Phase 4 - Notifications and Navigation

### 4.1 Fix the manual review email

Update `ManualReviewRequired` mail content so the CTA points to the actual admin review page instead of the current nonexistent path.

Recommended CTA target:

- `/admin/services/processing/{processingId}/review`

If route-model binding uses the numeric log ID rather than `processing_id`, resolve the URL through a named route in the mail class instead of hardcoding the path in Blade.

### 4.2 Add entry points from existing admin surfaces

Recommended links:

- add a "Livestream review" button to the members dashboard sermon tools
- optionally add a badge/count if flagged runs exist
- optionally link flagged runs from the existing service review dashboard when a matching `ChurchService` can be inferred

---

## Implementation Details by Area

## Backend

### New/updated classes

- `app/Actions/ConfirmLivestreamSermonSegment.php`
- `app/Models/MediaProcessingLog.php`
- `app/Jobs/ExtractSermon.php`
- `app/Services/SermonExtractionPlanResolver.php`
- `app/Services/ProcessingPipelineBuilder.php`
- `app/Http/Controllers/Api/MediaController.php` (optional endpoint)

### New/updated routes

- `routes/web.php`
  - queue page
  - detail page
- `routes/api.php`
  - optional confirm endpoint

## Frontend / Livewire

### New components/views

- `app/Livewire/Admin/ChurchServices/ProcessingReviewList.php` or Blade controller/view
- `app/Livewire/Admin/ChurchServices/ProcessingReview.php`
- `resources/views/livewire/admin/church-services/processing-review-list.blade.php`
- `resources/views/livewire/admin/church-services/processing-review.blade.php`

## Mail

- `app/Mail/ManualReviewRequired.php`
- `resources/views/emails/manual-review-required.blade.php`

---

## Validation Rules

Confirmation should fail when:

- the processing log does not exist
- the run is not a livestream
- the run is not currently awaiting manual sermon review
- the selected segment does not belong to the run
- the selected segment is not classified as speech
- the source video no longer exists

Optional stricter rule:

- only allow segments that were included in the stored `speech_segments` review payload

---

## Testing Plan

Every code change should be covered with focused automated tests.

### Unit tests

- `MediaProcessingLogTest`
  - stores manual review metadata correctly
  - confirms a segment and records reviewer/audit data

- `ExtractSermonTest`
  - flagged ambiguous run stores structured manual review metadata
  - manually confirmed segment bypasses auto-confidence gating
  - manually confirmed segment drives extraction bounds

- `ProcessingPipelineBuilderTest` or equivalent
  - post-review chain starts at `ExtractSermon`
  - post-review chain excludes upstream segmentation jobs

- `ConfirmLivestreamSermonSegmentTest`
  - confirms valid speech segment
  - rejects non-speech segment
  - rejects segment from another processing log
  - is idempotent on repeated confirmation

### Feature tests

- API feature test for `confirm-segment` endpoint, if implemented
  - success case
  - malformed processing ID
  - unauthorized access
  - invalid segment selection

- Livewire/admin feature tests
  - review list shows flagged livestream runs only
  - review detail renders segments and reason
  - clicking confirm dispatches resume chain and redirects

- Mail tests
  - manual review email includes correct review URL

### Suggested command set before merge

```bash
vendor/bin/sail artisan test --compact --filter=ExtractSermon
vendor/bin/sail artisan test --compact --filter=ConfirmLivestreamSermonSegment
vendor/bin/sail artisan test --compact tests/Feature
vendor/bin/sail composer phpstan
vendor/bin/sail bin pint --dirty
```

---

## Rollout Notes

### Migration impact

No database migration is required if all state is stored in `processing_metadata`.

If querying review state directly from JSON proves awkward or slow, a later follow-up can introduce dedicated columns such as:

- `manual_review_reason_code`
- `manual_review_confirmed_at`
- `manual_review_confirmed_by_user_id`

For v1, JSON storage is sufficient and keeps the change smaller.

### Operational behavior

- Existing flagged runs should become reviewable as soon as the UI and action ship.
- Historical rows will only show detailed reason data if that metadata already exists or is backfilled from `error_message`.
- Source-video availability should be surfaced clearly; if the original video is gone, admins need a message explaining that the run cannot be resumed from manual review.

---

## Recommended PR Breakdown

### PR 1 - Domain + pipeline support

- `MediaProcessingLog` manual-review metadata helpers
- resolver support for confirmed segment plans
- `ExtractSermon` updates
- downstream post-review pipeline builder method
- unit tests

### PR 2 - Confirmation workflow

- `ConfirmLivestreamSermonSegment` action
- optional API endpoint
- authorization and validation
- queue dispatch
- feature/unit tests

### PR 3 - Admin review UI

- queue page
- review detail Livewire component
- navigation entry points
- **fix manual review email CTA** — the route now exists, so the email must be updated in this PR so the admin notification links to a real page immediately
- Livewire feature tests
- mail CTA test

### PR 4 - Polish

- add small dashboard count/badge if desired
- polish empty/error states

---

## Confirmed V1 Decisions

1. Admins may choose speech segments only.

2. After confirmation, the UI redirects to the review queue with a success toast and a link to the normal processing status page.

3. The queue includes sermon-review cases only for v1, filtered by livestream type and manual-review metadata.

4. The review detail page does not embed a playable preview of the original video in v1. Segment metadata plus timestamps are sufficient.

---

## Summary

The safest implementation is:

- admin review UI under `/admin/services/...`
- structured manual-review metadata on `MediaProcessingLog`
- manual selection treated as the highest-priority extraction plan
- dedicated resume chain starting at `ExtractSermon`
- full test coverage around confirmation, extraction, and UI flow

This closes the current functional gap without disturbing the existing upstream livestream analysis pipeline.
