# Plan: Children's Talk End-to-End Extraction

## Summary

The children's talk extraction and publication pipeline is fully built. The only code change
needed is in `demoteSecondarySermons()`: demoted sections never receive
`confidence_level = 'high'`, so `SermonPublicationHandler::isEligible()` rejects them before
extraction begins.

Fixing that gate is sufficient to make the pipeline fire end-to-end — provided the runtime
environment has speaker identification enabled and active speaker profiles configured. Both
are already the case in production. The config default (`enabled => false`) is a separate
ops concern tracked in the simplification backlog and is out of scope here.

---

## What Already Works

These components are complete and tested. No changes needed:

- **Extraction**: `PrepareSectionPublicationCandidates` extracts video and audio for
  `childrens_talk` sections.
- **Speaker detection**: `SermonPublicationHandler::afterExtraction()` calls
  `ChildrensTalkSpeakerService::detectAndStore()`. Matched speakers are auto-accepted;
  ambiguous/no-match results set `needs_manual_review = true` and flag for admin review.
- **Admin UI**: `ServiceReviewDashboard` already shows children's talk speaker info, audio/video
  previews, manual speaker override controls, and approve/reject actions
  (`service-review-dashboard.blade.php:184`, `:298`).
- **Approval validation**: `ApproveSectionForPublication::approvalBlocker()` blocks approval
  until `hasResolvedSpeaker()` returns true — the admin must assign a speaker before approving.
- **Publishing**: `SermonPublicationHandler::publish()` creates a `Sermon` with
  `content_type = ChildrensTalk`.
- **Private storage**: `SermonObserver` dispatches `MoveSermonToPrivateStorage` on creation.
- **AI classification prompt**: `SpeechSectionClassificationService` already includes
  children's-talk-specific cues and service context (`:149`, `:359`).

---

## How Children's Talks Are Created

A `ServiceSection` gets `section_type = childrens_talk` via two paths:

**Path A — AI direct classification** (`SpeechSectionClassificationService`)
The AI classifies a speech section as `childrens_talk`. Confidence maps to `confidence_level`
via `applyConfidencePolicy()` — high confidence (≥0.85, no anomalies) → `'high'`.

**Path B — Secondary sermon demotion** (`ClassifySpeechSections::demoteSecondarySermons()`)
After the primary sermon is identified, shorter secondary sermon sections (under
`childrens_talk_max_duration_seconds`, default 900s) are demoted to `childrens_talk`. This is
the common path. Demotion currently sets `needs_manual_review = true` and preserves the
original AI confidence metadata — it does not set `confidence_level = 'high'`.

---

## What Blocks the Pipeline

### Gate 1 — `SermonPublicationHandler::isEligible()`

```php
$requireHighConfidence = config('media-processing.section_publishing.require_high_confidence', true);
$confidence = $section->metadata['confidence_level'] ?? 'none';
return !$requireHighConfidence || $confidence === 'high';
```

Demoted sections inherit the AI's original `confidence_level`. If the AI gave moderate
confidence to the original sermon classification, the demoted children's talk has
`confidence_level = 'low'` and fails here.

### Gate 2 — `PrepareSectionPublicationCandidates` line 136

```php
$eligibleByStatus = $section->status === ServiceSectionStatus::IDENTIFIED
    && !$section->needs_manual_review;
```

Demotion currently sets `needs_manual_review = true`. Even if gate 1 passes and extraction +
speaker detection run, the detection outcome must be `'matched'` to clear the flag. If speaker
identification is disabled or has no active profiles, the outcome is `'skipped'` or
`'no_profiles'`, which sets `needs_manual_review = true` — blocking the section again.

### Runtime preconditions

Even after the code changes below, the pipeline requires:

1. **Speaker identification enabled** — `SPEAKER_IDENTIFICATION_ENABLED=true` in the
   environment. Already set in production. The config default (`false`) is tracked separately
   in `docs/architecture/simplification-backlog.md` (PR 19: "Make speaker identification
   always-on") and is out of scope for this plan.
2. **Active speaker profiles exist** — `ChildrensTalkSpeakerService::detectAndStore()` returns
   `'no_profiles'` when no `SpeakerProfile` records match the configured provider/version
   (`ChildrensTalkSpeakerService.php:152`), which sets `needs_manual_review = true`. This is
   correct behaviour — the system cannot auto-accept a speaker it cannot identify.

If either precondition is unmet, the section gets extracted media but lands in manual review
(NOT_APPLICABLE) rather than PENDING_APPROVAL. The admin can still approve it individually
via the dashboard after manual speaker assignment.

---

## Changes Required

### 1. Set high confidence on demoted children's talk sections

**File:** `app/Jobs/ClassifySpeechSections.php` — `demoteSecondarySermons()`

The demotion heuristic is the confidence signal for children's talks: the system has identified
a primary sermon, and this section is a shorter secondary under 15 minutes. Set
`confidence_level = 'high'` and clear `needs_manual_review` so the section enters the
publication pipeline.

Add a `heuristic_demotion` review flag to the section's `review_flags` array. This flag is
recognised by the dashboard query (see change 2) and keeps demoted sections out of batch
approval — they must be individually reviewed and approved. Without this, a demoted section
with a matched speaker would silently enter batch approval, since
`batchApprovalSkipReason()` only blocks on review reasons beyond `pending_approval`.

```php
$sections[$index]['section_type'] = ServiceSectionType::CHILDRENS_TALK->value;
$sections[$index]['needs_manual_review'] = false;
$sections[$index]['metadata'] = array_merge($sections[$index]['metadata'], [
    'confidence_level' => 'high',
    'review_reason' => 'demoted_secondary_sermon_to_childrens_talk',
    'review_flags' => ['heuristic_demotion'],
    'original_ai_classification' => ServiceSectionType::SERMON->value,
]);
```

### 2. Surface heuristic demotion as a review reason in the dashboard query

**File:** `app/Queries/ServiceReviewDashboardQuery.php` — `reviewReasons()`

Add a check for the `heuristic_demotion` review flag so these sections are visible as
needing individual attention and are excluded from batch approval:

```php
$reviewFlags = $section->metadata['review_flags'] ?? [];
if (is_array($reviewFlags) && in_array('heuristic_demotion', $reviewFlags, true)) {
    $reasons[] = [
        'key' => 'heuristic_demotion',
        'label' => 'Heuristic classification',
        'classes' => 'bg-indigo-100 text-indigo-800',
    ];
}
```

This means `batchApprovalSkipReason()` will return `'blocked by other review flags'` for
these sections. The admin must individually approve them via the existing approve button.

### 3. Update existing demotion tests

**File:** `tests/Unit/Jobs/ClassifySpeechSectionsTest.php`

Two existing tests assert `assertTrue($demoted->needs_manual_review)`:

- `it_demotes_a_short_secondary_sermon_to_childrens_talk_after_classification` (line 559)
- `it_folds_before_demoting_so_sermon_song_sermon_clusters_are_not_incorrectly_demoted`
  (line 727)

Update both to:
- Assert `assertFalse($demoted->needs_manual_review)`
- Assert `assertSame('high', $demoted->metadata['confidence_level'])`
- Assert `assertContains('heuristic_demotion', $demoted->metadata['review_flags'])`

### 4. Add integration test for the demotion-to-PENDING_APPROVAL path

**File:** `tests/Unit/Jobs/PrepareSectionPublicationCandidatesTest.php`

Add one test that covers the realistic end-to-end path:

1. Create a `childrens_talk` section with the new demotion metadata
   (`confidence_level = 'high'`, `needs_manual_review = false`,
   `review_flags = ['heuristic_demotion']`)
2. Enable speaker identification with a matching speaker profile
3. Run `PrepareSectionPublicationCandidates`
4. Assert the section reaches `PENDING_APPROVAL` with extracted media and a resolved speaker

### 5. Add test for heuristic demotion batch approval exclusion

**File:** `tests/Feature/Queries/ServiceReviewDashboardQueryTest.php`

Add a test confirming that a section with `review_flags = ['heuristic_demotion']` in
PENDING_APPROVAL status returns a non-null `batchApprovalSkipReason()`.

---

## Implementation Order

1. Change 1 + Change 3 — demotion metadata + update existing tests.
   Run `ClassifySpeechSectionsTest`.
2. Change 2 + Change 5 — dashboard query + batch exclusion test.
   Run `ServiceReviewDashboardQueryTest`.
3. Change 4 — integration test.
   Run `PrepareSectionPublicationCandidatesTest`.

---

## What Does NOT Need to Be Built

- Extraction logic, speaker detection, admin UI, approval validation, publishing, or private
  storage — all already implemented and tested.
- AI prompt improvements — children's talk cues and service context already added to
  `SpeechSectionClassificationService`.
- Per-type confidence overrides in config — unnecessary once demotion sets `confidence_level`.
- New admin dashboard components — the review surface already handles children's talks.
- Config default flip for `speaker_identification.enabled` — tracked in the simplification
  backlog as a separate ops decision (PR 19).
