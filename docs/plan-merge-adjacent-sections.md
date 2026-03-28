# Implementation Plan: Merge Adjacent Same-Type Sections

## Overview

Add a `mergeAdjacentSameTypeSections` post-processing pass to `ClassifySpeechSections`, following the same pattern as the existing `foldShortSongsIntoSermon` and `demoteSecondarySermons` methods. This handles all three observed split patterns from a single well-placed pass over the assembled `$rewrittenSections` array.

### Problem

Three recurring split patterns produce adjacent sections of the **same type** that should be a single section. In all three observed examples the sections are immediately adjacent in array order and temporally contiguous:

| Sections | Type | Root cause |
|---|---|---|
| 7s Song + 2m 4s Song | Both `audio_only` Song | RMS segmenter creates a tiny segment for the congregation rising/finding the page |
| 15s Bible Reading + 2m 58s Bible Reading | Both `Bible Reading` | Verbal transition ("I'll ask Nemi to come and do the Bible reading") classified by AI as Bible Reading — the same type as the reading that follows |
| 5m 24s Children's Talk + 3m 2s Children's Talk | Both `Children's Talk` | Slide-transition pause causes the AI to emit two sub-sections from a single speech segment |

> **Out of scope:** cases where a short transition section is classified as a *different* type from the section that follows (e.g. an `Other` intro before a `Bible Reading`). That pattern requires a separate approach and is not addressed here.

---

## 1. Config (`config/media-processing.php`)

Add two new keys to the `section_classification` array:

```php
'adjacent_merge_min_duration_seconds' => (int) env('SERVICE_SECTION_ADJACENT_MERGE_MIN_DURATION_SECONDS', 30),
'adjacent_merge_max_gap_seconds' => (int) env('SERVICE_SECTION_ADJACENT_MERGE_MAX_GAP_SECONDS', 2),
```

- `adjacent_merge_min_duration_seconds`: sections below this threshold trigger a merge with their same-type neighbour.
- `adjacent_merge_max_gap_seconds`: maximum time gap between sections for them to be considered contiguous and eligible for merging. Prevents merging sections separated by a real silence.
- `childrens_talk` sections are **always** merged regardless of duration (no threshold applies).
- Both env vars allow tuning without a deploy.

---

## 2. `ClassifySpeechSections` (`app/Jobs/ClassifySpeechSections.php`)

### Step A — Reorder `handle()` passes

Run `songTitleHintExtractor` **before** the new merge pass so title hints captured from short `ai_transcript` song announcements are written into the following `audio_only` section's metadata before that pair can be collapsed. The longer (audio-only) section becomes the merge primary, and because the hint was already written into it, it survives.

```php
$rewrittenSections = $this->foldShortSongsIntoSermon($rewrittenSections);
$rewrittenSections = $this->demoteSecondarySermons($rewrittenSections);
$rewrittenSections = $songTitleHintExtractor->extract($rewrittenSections); // moved up
$rewrittenSections = $this->mergeAdjacentSameTypeSections($rewrittenSections); // ← new, runs last
```

### Step B — Add the private method

```php
private function mergeAdjacentSameTypeSections(array $sections): array
```

**Adjacency definition:** Two sections are considered adjacent for this pass only when they are consecutive in array order (positions `i` and `i+1`) **and** temporally contiguous — the gap between `end_time` of section `i` and `start_time` of section `i+1` is ≤ `adjacent_merge_max_gap_seconds` (see config below, default 2s). This prevents collapsing sections separated by a meaningful silence.

**Logic:**

- Walk `$sections` with a while loop (same idiom as `foldShortSongsIntoSermon`).
- At each position, peek at the next section. If same type, temporally contiguous, **and** merge conditions are met, call a helper `mergeTwoSections($primary, $secondary)` that:
  - Takes the **longer** section as primary (carries its `church_service_item_id`, `title`, `confidence`, `metadata`)
  - **ORs `needs_manual_review`** — true if either side is true
  - Appends secondary's `review_reason` to primary's metadata when present (e.g. `merged_review_reason`)
  - Sets `start_time` = `min(primary.start_time, secondary.start_time)`
  - Sets `end_time` = `max(primary.end_time, secondary.end_time)`
  - Recalculates `duration`
  - Unions `source_segment_ids` (deduped)
  - Adds to `metadata`: `merged_adjacent_section: true`, `merged_duration_seconds: <secondary duration>`
- After merging, **stays at the same position** and checks again (handles chains: A+B→AB, then AB+C if applicable).

**Merge conditions:**

| Type | Condition |
|---|---|
| `childrens_talk` | Always merge adjacent (two consecutive children's talks are never intentional) |
| All others | Merge only if `min(current.duration, next.duration) < adjacent_merge_min_duration_seconds` |

Limiting non-`childrens_talk` merging to cases where at least one section is short prevents two legitimate back-to-back hymns (each 2–4 minutes) from being collapsed.

---

## 3. Tests (`tests/Unit/Jobs/ClassifySpeechSectionsTest.php`)

All tests use the subclassed `SpeechSectionClassificationService` stub pattern already established in the file. Add both config keys to `setUp()`:

```php
config([
    'media-processing.section_classification.adjacent_merge_min_duration_seconds' => 30,
    'media-processing.section_classification.adjacent_merge_max_gap_seconds' => 2,
]);
```

**New test cases:**

| Test | Scenario | Expected outcome |
|---|---|---|
| `it_merges_a_short_song_section_into_the_following_song_section` | 7s Song + 120s Song | One Song, `start_time` = start of 7s section |
| `it_merges_a_short_speech_section_into_the_following_same_type_section` | 15s Bible Reading + 180s Bible Reading | One Bible Reading spanning both |
| `it_always_merges_adjacent_childrens_talk_sections_regardless_of_duration` | 324s Children's Talk + 182s Children's Talk | One merged Children's Talk |
| `it_chains_merges_across_three_adjacent_same_type_sections` | 10s + 20s + 180s Children's Talk | Single merged section across all three |
| `it_does_not_merge_two_substantial_adjacent_songs` | 150s Song + 210s Song | Two separate sections preserved |
| `it_does_not_merge_adjacent_sections_of_different_types` | 15s Prayer + 15s Bible Reading | Two separate sections preserved |
| `it_keeps_the_longer_sections_metadata_and_confidence_as_primary` | 10s (high confidence) + 180s (low confidence) same type | Primary = 180s section, low confidence retained |
| `it_ors_needs_manual_review_from_both_sides` | 180s Children's Talk (no review) + 60s Children's Talk (needs review) | Merged section has `needs_manual_review = true` |
| `it_preserves_review_reason_from_secondary_in_merged_metadata` | 180s + 60s (review_reason = 'oos_mismatch') | `merged_review_reason` present in metadata of result |
| `it_does_not_merge_sections_with_a_gap_above_the_threshold` | 15s Bible Reading, then 5s gap, then 180s Bible Reading | Two separate sections when gap > `adjacent_merge_max_gap_seconds` |
| `it_merges_sections_within_the_allowed_gap_tolerance` | 15s Bible Reading, then 1s gap, then 180s Bible Reading | Merged into one section |
| `it_preserves_song_title_hint_when_short_song_announcement_is_merged` | 20s ai_transcript Song (with song_title_hint already set on audio-only section) + 180s audio_only Song | Merged section retains `song_title_hint` |

---

## 4. Manual Merge in the Service Review UI

This mirrors the automatic pass but allows the admin to merge any two same-type adjacent sections that the pipeline missed or that appeared after re-classification.

### Action — `MergeAdjacentServiceSections` (`app/Actions/ServiceReview/MergeAdjacentServiceSections.php`)

**Signature:** `execute(ServiceSection $primary, ServiceSection $secondary, int $userId): ?string`
Returns `null` on success, or a human-readable error string on failure.

**Validation (return error string):**
- Both sections must share the same `media_processing_log_id`
- Both must have the same `section_type`
- Neither must be `PUBLISHED`
- Time gap between them must be ≤ `adjacent_merge_max_gap_seconds` (same config key as the automatic pass)

**Merge logic** (primary = the longer section by duration):
- Extend primary's `start_time` / `end_time` to span both; recalculate `duration`
- Union `source_segment_ids` (deduped)
- OR `needs_manual_review` — true if either side is true
- If secondary has a `review_reason` in metadata, copy it to primary as `merged_review_reason`
- Add `manually_merged: true` and `manually_merged_at` to primary's metadata
- If primary has extracted media (video/audio paths set): clear paths and `extracted_at`; reset `publication_status` to `NOT_APPLICABLE`
- Delete secondary section
- Save primary

**Auto re-extraction after merge:**

`PrepareSectionPublicationCandidates` is idempotent by design — it checks a `classification_signature` for each section and only re-extracts when the signature has changed or media is missing. Clearing the merged section's paths causes its signature check to fail, so only the merged section gets re-cut; all other sections in the run are skipped.

After saving, the action should:
1. Load `$primary->processingLog` and check whether the source video still exists using `VideoStorageService::sourceVideoExistsForPath()`
2. **Source available** → dispatch `PrepareSectionPublicationCandidates::dispatch($processingLog)`; the job re-extracts the merged section at the new boundaries and transitions it to `PENDING_APPROVAL` automatically
3. **Source unavailable** → leave status as `NOT_APPLICABLE`; the component flashes a notice that the source has been cleaned up and re-extraction must be triggered manually if the recording is re-uploaded

This means the common case (source still on disk) requires zero further admin action after confirming the merge.

### Livewire Component — `ServiceReviewDashboard`

Add state:

```php
/** @var array{primary_id: int, secondary_id: int}|null */
public ?array $pendingMerge = null;
```

Add three methods:

| Method | Purpose |
|---|---|
| `initiateMerge(int $sectionIdA, int $sectionIdB): void` | Stores both IDs in `$pendingMerge`; no action runs yet |
| `confirmMerge(): void` | Validates IDs, runs action, clears `$pendingMerge`, flashes success or error |
| `cancelMerge(): void` | Clears `$pendingMerge` |

Boot `MergeAdjacentServiceSections` via `boot()` (following the existing pattern for injected action classes).

### View — `service-review-dashboard.blade.php`

In each group's section loop, compute adjacency from `$loop->index`:

```blade
@php
    $nextEntry = $group['sections'][$loop->index + 1] ?? null;
    $nextSection = $nextEntry ? $nextEntry['section'] : null;
    $isMergeCandidate = $nextSection !== null
        && $nextSection->section_type === $section->section_type
        && abs($nextSection->start_time - $section->end_time) <= 2
        && $section->publication_status !== \App\Enums\ServiceSectionPublicationStatus::PUBLISHED
        && $nextSection->publication_status !== \App\Enums\ServiceSectionPublicationStatus::PUBLISHED;
@endphp
```

After each section card, render the appropriate merge state:

```blade
@if($isMergeCandidate)
    @if($pendingMerge
        && $pendingMerge['primary_id'] === $section->id
        && $pendingMerge['secondary_id'] === $nextSection->id)
        {{-- Confirmation panel --}}
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm">
            <p class="font-medium text-amber-900">Merge these two {{ $section->section_type->label() }} sections into one?</p>
            <p class="mt-1 text-amber-700">
                Spans {{ gmdate('G:i:s', (int) $section->start_time) }}
                – {{ gmdate('G:i:s', (int) $nextSection->end_time) }}.
                Any extracted media will be cleared and will need re-extraction.
            </p>
            <div class="mt-3 flex gap-2">
                <x-form-button variant="primary" size="sm" wire:click="confirmMerge"
                    wire:target="confirmMerge" wire:loading.attr="disabled">
                    Confirm merge
                </x-form-button>
                <x-form-button variant="outline" size="sm" wire:click="cancelMerge">
                    Cancel
                </x-form-button>
            </div>
        </div>
    @else
        {{-- Merge trigger --}}
        <div class="flex justify-center py-1">
            <x-form-button
                variant="ghost"
                size="xs"
                wire:click="initiateMerge({{ $section->id }}, {{ $nextSection->id }})"
            >
                ⇕ Merge these {{ $section->section_type->label() }} sections
            </x-form-button>
        </div>
    @endif
@endif
```

**Note:** only one pending merge can be active at a time — initiating a second merge replaces the first.

### New Tests (add to `AdminServiceReviewDashboardTest.php`)

| Test | Scenario | Assertion |
|---|---|---|
| `it_shows_a_merge_button_between_adjacent_same_type_sections` | Two adjacent same-type flagged sections in one group | Merge button visible |
| `it_does_not_show_merge_button_for_adjacent_different_type_sections` | Adjacent flagged sections of different types | No merge button |
| `it_shows_confirmation_panel_when_merge_is_initiated` | Call `initiateMerge(A, B)` | Confirmation panel rendered |
| `it_cancels_merge_on_cancel` | Initiate then `cancelMerge` | State cleared, normal UI restored |
| `it_merges_two_adjacent_sections_on_confirm` | Call `confirmMerge` | Primary section spans both; secondary deleted |
| `it_ors_review_flags_when_merging` | Primary has no review flag; secondary does | Merged section has `needs_manual_review = true` |
| `it_clears_extracted_media_and_dispatches_re_extraction_when_source_is_available` | Primary has extracted media; source file exists | Paths cleared; `PrepareSectionPublicationCandidates` dispatched for processing log |
| `it_clears_extracted_media_without_dispatching_when_source_is_unavailable` | Primary has extracted media; source file missing | Paths cleared, `publication_status` reset to `not_applicable`; no job dispatched |
| `it_rejects_merge_when_sections_are_from_different_processing_runs` | Two sections from different runs | Error flashed, no deletion |
| `it_rejects_merge_when_sections_are_of_different_types` | Song + Bible Reading | Error flashed, no deletion |
| `it_rejects_merge_when_a_section_is_published` | One section is `PUBLISHED` | Error flashed, no deletion |

---

## 5. Acceptance Check

```bash
# Automatic merge pass
vendor/bin/sail artisan test --compact tests/Unit/Jobs/ClassifySpeechSectionsTest.php

# Manual merge UI
vendor/bin/sail artisan test --compact tests/Feature/Livewire/AdminServiceReviewDashboardTest.php

# Static analysis and formatting
vendor/bin/sail composer phpstan
vendor/bin/sail bin pint --dirty
```

---

## What This Does Not Fix

- **Song splits from the audio segmenter** where both halves are above the 30s threshold (two legitimate back-to-back hymns with a very brief silence) — this is correct behaviour and should not be merged.
- **The OOS mismatch on section 92** (Bible Reading where a Sermon was expected) — that is a separate OOS alignment concern.
- Existing persisted sections — the fix only applies to future processing runs.
