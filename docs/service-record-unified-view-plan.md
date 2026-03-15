# Plan: Unified Service Record View

## Context

The service record admin page (`/admin/services/{id}`) currently shows two disconnected tables side by side:

1. **Service Items** — the planned order of service from email/OpenLP/manual entry (no timestamps)
2. **Classified Livestream Runs** — detected sections from the livestream (timestamps in raw seconds, no link back to the plan)

The backend reconciliation is thorough: `OosAlignmentService` links each `ServiceSection` to a `ChurchServiceItem` via `church_service_item_id`, stores mismatch reasons, confidence scores, and review triggers. None of this is surfaced in the UI — the two tables are visually and conceptually disconnected.

The goal is a **single unified timeline** per processing run that merges planned items and detected sections into one chronological view, making it clear at a glance what happened, what matched the plan, what was extra, and what was missing.

---

## Design Principles

- **Livestream is the source of truth for order and timing** — the timeline is sorted by `section_order` / `start_time`
- **Planned items annotate the timeline** — each detected section shows what the plan expected
- **Unplanned sections appear in their correct chronological position** — they are not hidden or pushed to a separate list
- **Missing planned items appear at the end** (or at their expected relative position) with a clear "not detected" indicator
- **When there is no livestream run**, the view falls back to a simple ordered list of planned items
- **Source badges** show where each planned item came from (email / OpenLP / manual)
- **Timestamps in mm:ss** format, not raw seconds

---

## Data Model Reminder

```
ChurchServiceItem           ServiceSection
─────────────────           ──────────────
id                          id
church_service_id           media_processing_log_id
position                    church_service_item_id  ← the alignment FK
type                        section_type
title                       section_order
source (EMAIL/OPENLP/MANUAL)start_time / end_time
song_id                     confidence
                            needs_manual_review
                            metadata['oos_alignment']['mismatch_reason']
                            publication_status
                            published_sermon_id
```

---

## Timeline Row Types

Each row in the unified timeline falls into one of three types:

| Type | Condition | What to show |
|------|-----------|--------------|
| `matched` | Section has `church_service_item_id` → linked item found | Full row: planned title + timestamps + alignment badges |
| `unplanned` | Section has no `church_service_item_id` (or mismatch_reason = `unexpected_detected_section`) | Section data only, "Not in plan" badge, still shows timestamps |
| `planned_only` | ChurchServiceItem has no matching section | Item data only, "Not detected" badge, no timestamps |

---

## New Files

| File | Purpose |
|------|---------|
| `app/Support/ServiceRecordTimeline.php` | Builds the merged timeline data structure for a given service + one processing run |
| `resources/views/livewire/admin/church-services/partials/unified-timeline.blade.php` | Renders the merged timeline table |
| `resources/views/livewire/admin/church-services/partials/planned-only-list.blade.php` | Fallback view when no livestream run is present |

---

## Modified Files

| File | Change |
|------|--------|
| `app/Livewire/Admin/ChurchServices/ShowChurchService.php` | Pass timeline data to view; remove the old items/sections data prep |
| `resources/views/livewire/admin/church-services/show-church-service.blade.php` | Replace the two disconnected tables with the new partials |

---

## Implementation Steps

### 1. `ServiceRecordTimeline` Support Class

```php
app/Support/ServiceRecordTimeline.php
```

**Input**: `ChurchService $service`, `MediaProcessingLog $processingLog`

**Output**: `array<int, array<string, mixed>>` — an ordered list of timeline rows

**Algorithm**:

```
1. Load $service->items ordered by position (with song relationship)
2. Load $processingLog->serviceSections ordered by section_order (with publishedSermon)
3. Index sections by church_service_item_id for quick lookup
4. Collect unplanned sections: sections where church_service_item_id is null
   OR where metadata['oos_alignment']['mismatch_reason'] === 'unexpected_detected_section'
5. Walk sections in section_order:
   a. If section has a matched item → emit 'matched' row
   b. If section is unplanned → emit 'unplanned' row
6. Collect planned-only items: items with no matched section
   → emit 'planned_only' rows, appended after the timestamped rows
7. Return merged array
```

**Row shape**:
```php
[
    'row_type'           => 'matched' | 'unplanned' | 'planned_only',
    // Planned item fields (null for 'unplanned' rows)
    'item_id'            => int|null,
    'position'           => int|null,
    'item_type'          => string|null,       // songs, bibles, custom, presentations
    'planned_title'      => string|null,
    'item_source'        => ChurchServiceItemSource|null,
    'song_id'            => int|null,
    'song_title'         => string|null,       // from song relationship
    // Detected section fields (null for 'planned_only' rows)
    'section_id'         => int|null,
    'section_type'       => ServiceSectionType|null,
    'section_title'      => string|null,
    'start_time'         => float|null,
    'end_time'           => float|null,
    'confidence'         => float|null,
    'confidence_level'   => string|null,       // high/medium/low/unknown
    'needs_review'       => bool,
    'review_reason'      => string|null,
    'mismatch_reason'    => string|null,       // from metadata['oos_alignment']['mismatch_reason']
    'publication_status' => ServiceSectionPublicationStatus|null,
    'published_sermon'   => Sermon|null,
]
```

**Timestamp formatting** — provide a static helper `formatTimestamp(float $seconds): string` that returns `mm:ss`:
```php
public static function formatTimestamp(float $seconds): string
{
    $total = (int) round($seconds);
    return sprintf('%d:%02d', intdiv($total, 60), $total % 60);
}
```

### 2. Update `ShowChurchService::render()`

The component already loads `serviceSections` and `processingRuns`. Extend it to build timeline data:

```php
'processingTimelines' => $this->buildProcessingTimelines($processingRuns),
'serviceTimelines'    => $this->buildServiceTimelines($processingRuns),
```

Where `buildServiceTimelines()` calls `ServiceRecordTimeline::build($this->churchService, $run)` for each run and keys the result by `$run->id`.

Also remove the existing `$this->churchService->items` eager-load from `mount()` — the timeline builder handles its own queries. Keep items loaded for the fallback (no-livestream) case.

### 3. Update `show-church-service.blade.php`

**Replace** the "Service Items" card and "Classified Livestream Runs" card with:

```blade
@if($processingRuns->isEmpty())
    @include('livewire.admin.church-services.partials.planned-only-list', [
        'items' => $churchService->items,
    ])
@else
    @foreach($processingRuns as $run)
        @include('livewire.admin.church-services.partials.unified-timeline', [
            'run'      => $run,
            'timeline' => $serviceTimelines[$run->id] ?? [],
            'timeline' => $processingTimelines[$run->id] ?? [],
        ])
    @endforeach
@endif
```

**Keep** the Import Metadata card and Warnings card in the sidebar — these are still useful.

**Keep** the Processing Timeline (step-by-step pipeline progress) — fold it into the unified timeline card as a `<details>` element so it doesn't dominate the layout.

### 4. `unified-timeline.blade.php` Partial

A single table with columns:

| # | Type | Planned | Source | Detected | Timing | Status | Publication |
|---|------|---------|--------|----------|--------|--------|-------------|

Row styling by `row_type`:
- `matched` — normal row; green alignment badge if no mismatch; amber if `needs_review`
- `unplanned` — rose-tinted row; "Not in plan" badge in the Planned column; amber if `needs_review`
- `planned_only` — slate-tinted row; "Not detected" badge in the Detected column; no timing cells

**Source badge** — small pill on the Planned column showing `EMAIL` / `OPENLP` / `MANUAL` (use the project's existing pill pattern from other tables).

**Timing** — `mm:ss – mm:ss` format; blank for `planned_only` rows.

**Status column**:
- Aligned, no issues → green "Aligned" pill
- `needs_review` → amber "Needs review" pill + the `review_reason` in small text below (replace underscores with spaces)
- `unplanned` rows → rose "Unplanned" pill
- `planned_only` rows → slate "Not detected" pill

**Publication column** — mirrors the existing publication status pill; only meaningful for rows with a section.

### 5. `planned-only-list.blade.php` Partial

Simple ordered list for when no livestream run exists. Shows: position, type badge, title, source badge. No timestamps, no alignment state. This replaces the current "Service Items" table for the no-livestream case.

---

## What This Does NOT Change

- The `ProcessingReview` component (`/admin/services/processing/{id}/review`) remains the dedicated deep-review screen for confirming sermon segments and managing publication. The unified timeline links to it ("Go to detailed review →") rather than replacing it.
- The `ServiceReviewDashboard` and `ReviewInboundEmails` components are unaffected.
- No changes to any backend service, job, or model. This is purely a display layer change.

---

## Edge Cases to Handle

| Scenario | Behaviour |
|----------|-----------|
| Multiple processing runs for one service | One timeline card per run, each collapsible, most recent open by default |
| Processing run still in progress (no sections yet) | Show processing timeline only; timeline table shows "Sections not yet available" |
| Service has no planned items (sections only) | All rows are `unplanned`; show a warning banner explaining no OoS has been uploaded |
| Section's `church_service_item_id` points to a soft-deleted item | Treat as `unplanned` (soft-deleted items are excluded from the items query) |
| Song sections — inferred vs confirmed match | Show `song_match_type` from metadata: "Confirmed" (green) vs "Inferred" (amber) next to the song title |

---

## Testing

**Unit tests** — `ServiceRecordTimeline`:
- All matched rows correctly linked to their items
- Unplanned sections (null `church_service_item_id`) appear as `unplanned` rows
- Planned items with no section appear as `planned_only` rows at the end
- `formatTimestamp` correctly formats seconds to `mm:ss`
- Empty sections collection → all rows are `planned_only`
- Empty items collection → all rows are `unplanned`

**Feature tests** — `ShowChurchService`:
- Page renders with no livestream runs → shows planned-only list
- Page renders with a run that has matched sections → shows unified timeline
- Page renders with a run that has unplanned sections → those rows appear
- Reclassify action still works

---

## Verification Checklist

1. `vendor/bin/sail artisan test --compact tests/Unit/Support/ServiceRecordTimelineTest.php`
2. `vendor/bin/sail artisan test --compact tests/Feature/ChurchServices/ShowChurchServiceTest.php`
3. `vendor/bin/sail bin pint --dirty`
4. `vendor/bin/sail composer phpstan`
5. Manually visit a service with a completed livestream run — confirm the unified timeline renders correctly with timestamps in mm:ss and source badges visible
6. Manually visit a service with no livestream run — confirm the planned-only fallback renders

---

## Phasing Suggestion

This is safe to implement in two passes:

**Pass 1 (core)**: Build `ServiceRecordTimeline`, update `ShowChurchService`, implement `unified-timeline.blade.php` with the full table. The `planned-only-list.blade.php` fallback can reuse the existing items table markup temporarily.

**Pass 2 (polish)**: Add source badges, song match type indicators, collapsible processing timeline inside the run card, "Go to detailed review" link.
