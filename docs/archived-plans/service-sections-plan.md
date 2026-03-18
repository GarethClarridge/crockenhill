# Service Section Identification — Implementation Plan

## Overview

Extend the livestream processing pipeline to identify and classify all sections of a
church service (prayer, notices, songs, children's talk, bible reading, sermon, etc.),
not just the sermon. Classified sections can then be extracted and acted on individually
— for example, prepending the bible reading to the sermon recording, or publishing the
children's talk as a separate video.

---

## New Data Model: `ServiceSection`

`LivestreamSegment` is a processing artefact — it holds raw RMS/visual data consumed by
the pipeline. `ServiceSection` is the business output: a named, typed, publishable unit
of the service. The relationship is not 1:1 — one `LivestreamSegment` may map to multiple
sections (when a long speech segment is split internally by transcript analysis), or
several segments may map to one section.

### `service_sections` table

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | |
| `media_processing_log_id` | bigint FK | → `media_processing_logs.id`, cascade delete |
| `section_type` | enum | `ServiceSectionType` (see below) |
| `section_order` | int | Position in the service |
| `title` | varchar nullable | Song name, bible reference, etc. |
| `start_time` | decimal | Seconds from start of original video |
| `end_time` | decimal | |
| `duration` | decimal | |
| `confidence` | double | 0.0–1.0 |
| `confidence_source` | enum | `heuristic`, `ai_transcript`, `order_of_service` |
| `status` | enum | `Identified`, `Extracted`, `Published`, `Skipped` |
| `extracted_file_path` | varchar nullable | Set after extraction |
| `sermon_id` | int FK nullable | → `sermons.id`, set null on delete |
| `source_segment_ids` | json | IDs of contributing `LivestreamSegment` records |
| `metadata` | json | Speaker, notes, etc. |
| `timestamps` | | |

### `ServiceSectionType` enum

```php
enum ServiceSectionType: string
{
    case Welcome = 'welcome';
    case Prayer = 'prayer';
    case Notices = 'notices';
    case Song = 'song';
    case ChildrensTalk = 'childrens_talk';
    case BibleReading = 'bible_reading';
    case Sermon = 'sermon';
    case Other = 'other';
}
```

### `ServiceSectionStatus` enum

```php
enum ServiceSectionStatus: string
{
    case Identified = 'identified';
    case Extracted = 'extracted';
    case Published = 'published';
    case Skipped = 'skipped';
}
```

---

## Order of Service Integration

The order of service for a given service will be available in the system as structured
data (processed separately before the livestream is uploaded). It is an ordered list of:

```php
// Each item:
[
    'type'  => ServiceSectionType,   // expected section type
    'title' => ?string,              // song name, bible reference, etc.
]
```

The livestream processing pipeline will look up the relevant order of service using
the service date (and morning/evening indicator where needed). The exact model and lookup
mechanism is determined by the separate order-of-service work, but the classification job
requires this interface to be stable.

The order of service is treated as a **strong prior, not ground truth**. If audio
evidence clearly contradicts the expected sequence (e.g., a segment has song-like
characteristics where "Notices" is expected), the discrepancy is flagged for review
rather than silently overridden.

---

## Revised Pipeline

```
[existing]
AnalyzeSegments
    ↓
[new]
ClassifyServiceSections    ← applies heuristics + order of service alignment
    ↓
TranscribeSplitCandidates  ← targeted transcription of ambiguous/adjacent segments
    ↓
AiClassifySections         ← AI classifies + finds internal split points
    ↓
[modified]
ExtractSermon              ← uses ServiceSection data; optionally prepends bible reading
    ↓
[new]
ExtractAdditionalSections  ← extracts children's talk and any other flagged sections
    ↓
[existing, unchanged]
SubmitToProcessing
IdentifySpeaker
TranscribeAudio
ProcessTranscriptWithAI
GenerateThumbnail
    ↓
[new]
PublishAdditionalSections  ← creates Sermon records for extracted sections
    ↓
[existing]
CleanupTemporaryFiles
```

`TranscribeSplitCandidates` and `AiClassifySections` can be disabled via config
(`section_classification.use_ai: false`) to run heuristics-only at lower cost.

---

## Classification Logic

### Step 1 — `ClassifyServiceSections` (heuristics + order of service)

1. Load the `LivestreamSegment` records for this processing run, ordered by position.
2. Songs are already well-identified. Map them to `ServiceSectionType::Song` and
   populate `title` from the order of service where available.
3. For speech segments, use the order of service sequence to align remaining sections
   positionally — the expected sequence of speech section types, matched in order to the
   detected speech segments.
4. Where the order of service predicts two section types between the same pair of songs
   but only one speech segment is detected there, mark that segment as a **split
   candidate** — it likely contains both sections and needs transcript analysis to find
   the internal boundary.
5. Set `confidence_source = 'order_of_service'` where alignment was used, or
   `'heuristic'` where duration/position rules were applied without an order of service.

### Step 2 — `TranscribeSplitCandidates` (targeted transcription)

For each segment flagged as a split candidate, transcribe only that segment using the
existing transcription service. This is cheaper than transcribing the whole video.
Store the transcript in the section's `metadata` for the AI step.

This step is skipped entirely if AI classification is disabled.

### Step 3 — `AiClassifySections` (transcript-based splitting)

For each split candidate with a transcript:

1. Send the transcript plus the expected section types (from the order of service) to
   the AI.
2. The AI identifies the boundary point and returns a timestamp split.
3. The original `ServiceSection` record is replaced with two records, each with corrected
   `start_time`/`end_time` and `confidence_source = 'ai_transcript'`.

For any section with low overall confidence (below a configurable threshold), flag for
manual review using the existing `ManualReviewRequired` mailable.

---

## Handling Adjacent Speech Segments

This is the core challenge: two consecutive speech sections (e.g., Children's Talk
followed by Prayer) may produce either a single audio segment (no gap) or two segments
(small pause). The heuristics alone cannot reliably assign types in either case.

The solution relies on the combination of:

- The **order of service** telling us that two section types are expected between songs
  at that position (triggering a split candidate flag)
- **Transcript analysis** finding the internal boundary (e.g., "Let us pray…" or "Now
  [name] is going to come and tell us about…")

Without an order of service, the fallback is duration heuristics: the longest adjacent
speech segment becomes the sermon, very short ones near the start become Prayer or
Welcome, moderate ones near the end become Closing Prayer. This works reasonably well
for Crockenhill's typical service structure but will miss splits within single segments.

---

## Specific Use Cases

### Bible Reading + Sermon Combined

In `ExtractSermon`, after `ClassifyServiceSections` has run:

1. Check whether the `BibleReading` section immediately precedes the `Sermon` section.
2. If so, and if `include_bible_reading_in_sermon` is enabled (config or per-upload flag):
   - Extend `sermon_start_time` back to the start of the `BibleReading` section.
   - The existing FFmpeg stream copy extracts the combined segment in a single pass —
     no concatenation required.
3. If the bible reading is *not* immediately adjacent, use FFmpeg concat to join
   the two extracted segments:
   ```
   ffmpeg -i bible.mp4 -i sermon.mp4 \
     -filter_complex "[0:v][0:a][1:v][1:a]concat=n=2:v=1:a=1" combined.mp4
   ```

### Children's Talk as Separate Video

In `PublishAdditionalSections`, for any section flagged for publishing:

1. The audio/video file was already extracted by `ExtractAdditionalSections`.
2. Create a new `Sermon` record with:
   - `source_type` matching the parent livestream
   - `service = SermonService::Other`
   - `title` populated from the section title or a date-based default
   - `segment_start_time` / `segment_end_time` from the `ServiceSection`
3. Run `GenerateThumbnail` against the extracted video.
4. Optionally skip AI analysis (configurable) — the children's talk doesn't need sermon
   points and a summary.
5. Link `ServiceSection.sermon_id` to the new `Sermon` record.

This reuses all existing publishing infrastructure without modification.

---

## Configuration (`config/media-processing.php`)

New section under `section_classification`:

```php
'section_classification' => [
    'enabled' => true,
    'use_ai' => true,               // enables Steps 2 and 3
    'ai_confidence_threshold' => 0.6, // below this → manual review
    'extract' => [
        ServiceSectionType::BibleReading->value   => false, // extract as standalone
        ServiceSectionType::ChildrensTalk->value  => true,  // extract as standalone
        ServiceSectionType::Sermon->value         => true,  // existing behaviour
    ],
    'publish' => [
        ServiceSectionType::ChildrensTalk->value  => true,  // create Sermon record
    ],
    'include_bible_reading_in_sermon' => true,
],
```

---

## Implementation Phases

### Phase 1 — Data foundation
- `ServiceSectionType` and `ServiceSectionStatus` enums
- `ServiceSection` model + migration + factory
- `service_sections` table with FK to `media_processing_logs`

### Phase 2 — Heuristic classification (useful immediately)
- `ClassifyServiceSections` job with positional/duration heuristics
- Order of service lookup interface (stub implementation until the OoS work lands)
- Wire into pipeline after `AnalyzeSegments`
- Full test coverage

### Phase 3 — Bible reading + sermon combination
- Modify `ExtractSermon` to prepend bible reading when adjacent
- Config flag `include_bible_reading_in_sermon`
- Tests covering adjacent and non-adjacent cases

### Phase 4 — Children's talk extraction and publishing
- `ExtractAdditionalSections` job
- `PublishAdditionalSections` job
- Creates `Sermon` records for flagged sections
- Tests for the full extraction → publish path

### Phase 5 — AI transcript splitting (fill in the gaps)
- `TranscribeSplitCandidates` job (targeted transcription)
- `AiClassifySections` job with split-point detection
- Low-confidence → `ManualReviewRequired` notification
- Tests with mocked transcription and AI responses

### Phase 6 — Order of service integration (once OoS work lands)
- Replace stub lookup with real model query
- Sequence alignment between OoS items and detected segments
- End-to-end integration tests
