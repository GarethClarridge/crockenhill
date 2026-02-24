# Integrated Plan: Church Service Tracking

## Overview

A unified system for tracking church services, combining up to three data sources:

1. **OpenLP upload** — `.osz` files provide the planned order of service (songs, readings, etc.) before or after the service
2. **Livestream processing** — automated audio/video analysis detects and classifies actual service sections with timestamps
3. **Email import** *(optional enhancement)* — a planned order of service emailed before the service happens, providing an early prior for livestream classification

All sources contribute to a single `ChurchService` record per service date+slot. The order of service feeds into the livestream classification pipeline as a strong prior for identifying sections. Song tracking falls out naturally from the order of service data.

### Operational assumptions and resilience rules

1. **Filename-first identity is the default**. If upload filenames follow `YYYY-MM-DD AM|PM`, use that as the primary date/service signal (high trust in current workflow).
2. **No single signal is treated as infallible**. Parser compares the upload filename and embedded `.osj` filename; mismatches are recorded in metadata for review.
3. **Automatic fallback before manual fallback**. If primary parsing fails, use secondary automatic signals (`.osj` entry name). Manual override remains optional for exceptional cases.
4. **Preserve links on re-upload**. Re-importing a service must update items in place where possible, not delete all rows.
5. **Do not rely on ingest order**. OpenLP uploads will often arrive first (small files), but classification must still work when livestream processing finishes first.

---

## Data Model

### `ChurchService` — one per service date+slot

The canonical record of a service. Created by whichever source arrives first.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | |
| `date` | date | |
| `service` | string | `SermonService` enum value (`morning`, `evening`, `other`) |
| `source` | string | `ServiceItemSource` enum — which source last populated the items |
| `original_filename` | string, nullable | `.osz` filename if uploaded |
| `identity_confidence` | double | 0.0–1.0 confidence for parsed date/service |
| `identity_metadata` | json, nullable | Parser diagnostics (`filename_match`, `osj_name`, mismatch flags) |
| `media_processing_log_id` | bigint FK, nullable | → `media_processing_logs.id`, set null on delete; linked when a livestream is processed for this service |
| `timestamps` | | |

Unique constraint on `(date, service)` — one record per slot.

### `ChurchServiceItem` — ordered items within a service

Populated from OpenLP. Optionally enriched with timing data from the livestream pipeline.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | |
| `church_service_id` | bigint FK | → `church_services.id`, cascade delete |
| `position` | unsignedInteger | Order within the service |
| `type` | string | OpenLP plugin name: `songs`, `bibles`, `presentations`, `custom` |
| `title` | string | Cleaned display title used by app/classifier |
| `source_title` | string, nullable | Raw `serviceitem.header.title` from OpenLP |
| `openlp_search_title` | string, nullable | Song key from `serviceitem.header.data.title` (when plugin is `songs`) |
| `metadata` | json, nullable | Plugin-specific details (footer values, display slide labels, parser notes) |
| `timestamps` | | |

Unique index on `(church_service_id, position)`. Index on `(church_service_id, type)`.

### `ServiceSection` — detected sections from livestream analysis

Created by the classification pipeline. Linked back to `ChurchServiceItem` when alignment succeeds.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | |
| `media_processing_log_id` | bigint FK | → `media_processing_logs.id`, cascade delete |
| `church_service_item_id` | bigint FK, nullable | → `church_service_items.id`, set null on delete; set when a detected section is matched to a planned item |
| `section_type` | string | `ServiceSectionType` enum |
| `section_order` | unsignedInteger | Position in the detected service |
| `title` | string, nullable | Song name, Bible reference, etc. |
| `start_time` | decimal(10,3) | Seconds from start of original video |
| `end_time` | decimal(10,3) | |
| `duration` | decimal(10,3) | |
| `confidence` | double | 0.0–1.0 |
| `confidence_source` | string | `ServiceSectionConfidenceSource` enum |
| `status` | string | `ServiceSectionStatus` enum |
| `extracted_file_path` | string, nullable | Set after extraction |
| `sermon_id` | bigint FK, nullable | → `sermons.id`, set null on delete |
| `source_segment_ids` | json | IDs of contributing `LivestreamSegment` records |
| `metadata` | json, nullable | Speaker, transcript excerpt, notes |
| `timestamps` | | |

---

## New Enums

### `ServiceSectionType`

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

### `ServiceSectionStatus`

```php
enum ServiceSectionStatus: string
{
    case Identified = 'identified';
    case Extracted = 'extracted';
    case Published = 'published';
    case Skipped = 'skipped';
}
```

### `ServiceSectionConfidenceSource`

```php
enum ServiceSectionConfidenceSource: string
{
    case Heuristic = 'heuristic';
    case AiTranscript = 'ai_transcript';
    case OrderOfService = 'order_of_service';
}
```

### `ServiceItemSource`

Tracks which source last populated the `ChurchServiceItem` records on a `ChurchService`. Higher-priority sources overwrite lower-priority ones; lower-priority sources do not overwrite higher.

```php
enum ServiceItemSource: string
{
    case Email = 'email';     // priority 1 — planned order, least precise
    case OpenLp = 'openlp';   // priority 2 — what the operator actually ran
    case Manual = 'manual';   // priority 3 — human-corrected
}
```

| Priority | Source | Typical timing | Reliability |
|---|---|---|---|
| 1 (lowest) | Email | Days before the service | Planned, may change on the day |
| 2 | OpenLP | After the service | What the operator actually ran |
| 3 (highest) | Manual | Any time | Human-corrected |

---

## New Files

| File | Purpose |
|---|---|
| `app/Models/ChurchService.php` | Parent model — one per service date+slot |
| `app/Models/ChurchServiceItem.php` | Individual items within a service |
| `app/Models/ServiceSection.php` | Detected section from livestream analysis |
| `app/Enums/ServiceSectionType.php` | Section type enum |
| `app/Enums/ServiceSectionStatus.php` | Section status enum |
| `app/Enums/ServiceSectionConfidenceSource.php` | Confidence source enum |
| `app/Enums/ServiceItemSource.php` | Tracks which source populated service items |
| `app/Http/Controllers/Api/ChurchServiceController.php` | API controller for .osz upload |
| `app/Http/Requests/UploadChurchServiceRequest.php` | Validates .osz file upload |
| `app/Services/OpenLpServiceParser.php` | Parses .osz → structured array |
| `app/Services/ChurchServiceItemSyncService.php` | In-place sync of service items on re-upload (preserves IDs/links) |
| `app/Services/ClassificationBackfillService.php` | Re-run classification when OpenLP arrives after livestream |
| `app/Jobs/ClassifyServiceSections.php` | Heuristic section classification and optional sermon-time refinement |
| `app/Http/Resources/ChurchServiceResource.php` | API response shape |
| `database/factories/ChurchServiceFactory.php` | Test factory |
| `database/factories/ChurchServiceItemFactory.php` | Test factory |
| `database/factories/ServiceSectionFactory.php` | Test factory |
| `tests/Feature/ChurchServiceApiTest.php` | Feature tests for .osz upload |
| `tests/Feature/ServiceSectionTest.php` | Feature tests for section classification |

## Modified Files

| File | Change |
|---|---|
| `app/Enums/ApiTokenAbility.php` | Add `SERVICE_UPLOAD = 'service:upload'` case |
| `routes/api.php` | Add `POST /api/services` route |
| `config/media-processing.php` | Add `section_classification` config block |
| `app/Services/ProcessingPipelineBuilder.php` | Wire classification jobs into livestream pipeline (Phase 3+) |
| `app/Jobs/ExtractSermon.php` | Respect classifier-refined sermon time bounds (if present) |

---

## Phase 1 — OpenLP Upload & Service Record

Self-contained. Delivers the order of service data and the unified `ChurchService` record.

### 1a. Migrations

**`create_church_services_table`**

- `id`, `date` (date), `service` (string, non-null, default `other`), `source` (string), `original_filename` (string, nullable)
- `identity_confidence` (double, default `1.0`), `identity_metadata` (json nullable)
- `media_processing_log_id` (bigint FK nullable, set null on delete), `timestamps`
- Unique index on `(date, service)`

**`create_church_service_items_table`**

- `id`, `church_service_id` (FK → cascade delete), `position` (unsignedInteger), `type` (string), `title` (string)
- `source_title` (string nullable), `openlp_search_title` (string nullable), `metadata` (json nullable), `timestamps`
- Unique index on `(church_service_id, position)`
- Index on `(church_service_id, type)`

### 1b. Models

**`ChurchService`**
- Casts: `date` → `'date'`, `service` → `SermonService::class`, `source` → `ServiceItemSource::class`, `identity_metadata` → `'array'`
- Relationships: `hasMany(ChurchServiceItem)` ordered by `position`, `belongsTo(MediaProcessingLog)`, `hasMany(ServiceSection)` through `MediaProcessingLog` (or via accessor)
- Fillable: `date`, `service`, `source`, `original_filename`, `identity_confidence`, `identity_metadata`, `media_processing_log_id`

**`ChurchServiceItem`**
- Fillable: `church_service_id`, `position`, `type`, `title`, `source_title`, `openlp_search_title`, `metadata`
- Casts: `metadata` → `'array'`
- Relationships: `belongsTo(ChurchService)`, `hasOne(ServiceSection)` (nullable back-link)

### 1c. `OpenLpServiceParser`

```php
public function parse(UploadedFile $file): array
```

1. Open the `.osz` file as a `ZipArchive`
2. Find the `.osj` entry (iterate entries, match extension)
3. `json_decode()` the contents
4. Determine `date` + `service` using a resilience policy:
   - Primary: uploaded filename (`YYYY-MM-DD AM|PM`)
   - Secondary: embedded `.osj` filename
   - On mismatch: keep upload filename result (trusted default), store mismatch in `identity_metadata`, lower `identity_confidence` (for example `0.95`)
   - If slot cannot be inferred, set `service = SermonService::OTHER`
   - If date cannot be inferred from either signal, fail validation with a clear error
5. Skip the `openlp_core` config entry
6. For each `serviceitem`, extract plugin-specific values:
   - `songs`: `title = header.title`, `openlp_search_title = header.data.title` (exact OpenLP song key), `source_title = header.title`
   - `bibles`: `title = header.footer[0]` when present (clean reference), fallback to `header.title`; keep full original in `source_title`
   - `presentations`: prefer first meaningful `data[*].display_title` (for example “Welcome”) else fallback to `header.title` filename
   - `custom`: `title = header.title`, with first slide/body hint in `metadata`
7. Return:
   - `date`, `service`, `identity_confidence`, `identity_metadata`
   - `items` as `position`, `type`, `title`, `source_title`, `openlp_search_title`, `metadata`

### 1d. `UploadChurchServiceRequest`

- `authorize()`: `$this->user()->is_admin`
- Rules: `['file' => ['required', 'file', 'mimes:zip', 'extensions:osz,zip', 'max:102400']]`
- Custom message for unsupported file type
- Optional emergency override fields for rare parser failures: `date` and `service` (not required in normal flow)

### 1e. `ChurchServiceController`

```php
public function store(UploadChurchServiceRequest $request): JsonResponse
{
    $parsed = $this->parser->parse($request->file('file'));
    $incomingSource = ServiceItemSource::OpenLp;

    $service = ChurchService::firstOrNew(
        ['date' => $parsed['date'], 'service' => $parsed['service']]
    );

    // Only replace items if the incoming source is at least as authoritative
    if (! $service->exists || $incomingSource->priority() >= $service->source->priority()) {
        $service->fill([
            'date' => $parsed['date'],
            'service' => $parsed['service'],
            'source' => $incomingSource,
            'original_filename' => $request->file('file')->getClientOriginalName(),
            'identity_confidence' => $parsed['identity_confidence'],
            'identity_metadata' => $parsed['identity_metadata'],
        ]);
        $service->save();

        $this->itemSync->sync($service, $parsed['items']);
        $this->classificationBackfill->dispatchForService($service); // Phase 3+
    } else {
        $service->update([
            'original_filename' => $request->file('file')->getClientOriginalName(),
            'identity_confidence' => $parsed['identity_confidence'],
            'identity_metadata' => $parsed['identity_metadata'],
        ]);
    }

    return (new ChurchServiceResource($service->load('items')))
        ->response()
        ->setStatusCode(201);
}
```

Uses `firstOrNew` + source priority check so a higher-authority source overwrites a lower one (OpenLP overwrites email), but not vice versa. The `priority()` method on `ServiceItemSource` returns the numeric priority (1, 2, or 3).

`ChurchServiceItemSyncService` performs in-place updates to preserve existing item IDs where possible:

1. Match incoming items to existing rows by stable content key (`type + openlp_search_title/source_title`) first
2. Fallback match by `position` when content-key match is unavailable
3. Update matched rows in place, create unmatched incoming rows, delete only unmatched stale rows
4. Wrap in a DB transaction to avoid partial sync

`ClassificationBackfillService` keeps ingest order resilient:

1. Find recent livestream `MediaProcessingLog` rows for the same date/service via `processing_metadata.extracted_date` + `processing_metadata.extracted_service`
2. For runs already segmented/classified, re-dispatch `ClassifyServiceSections` in idempotent "refresh" mode
3. Skip if existing `ServiceSection` rows are already high-confidence and linked

### 1f. `ChurchServiceResource`

```json
{
    "id": 1,
    "date": "2024-11-17",
    "service": "morning",
    "original_filename": "2024-11-17 AM.osz",
    "identity_confidence": 0.95,
    "identity_metadata": {
        "upload_filename": "2024-11-17 AM.osz",
        "embedded_osj_filename": "2024-11-17 PM.osj",
        "filename_mismatch": true
    },
    "items": [
        {
            "position": 1,
            "type": "songs",
            "title": "Jesus Shall Reign #491(i)",
            "openlp_search_title": "jesus shall reign 491 i @ 491 i jesus shall reign"
        },
        {
            "position": 2,
            "type": "presentations",
            "title": "Welcome",
            "source_title": "Notices2024Looped.pptx"
        },
        {
            "position": 3,
            "type": "bibles",
            "title": "Luke 15:1-32",
            "source_title": "Luke 15:1-32 New International Version (Anglicised)..."
        }
    ]
}
```

### 1g. Route

```php
Route::post('services', [ChurchServiceController::class, 'store'])
    ->middleware([
        'auth:sanctum',
        'ability:' . ApiTokenAbility::SERVICE_UPLOAD->value,
        'throttle:api',
    ])
    ->name('api.services.store');
```

### 1h. `ApiTokenAbility` Enum

Add: `case SERVICE_UPLOAD = 'service:upload';`

### 1i. Tests

- Happy path: valid AM and PM `.osz` files parsed and stored correctly
- Returns 201 with correct JSON structure
- Returns 401 without authentication
- Returns 403 without `service:upload` ability
- Returns 422 for non-`.osz` files
- Items stored in correct order with plugin-aware title extraction (song/bible/presentation/custom)
- Filename/embedded `.osj` mismatch is recorded in metadata, while filename-derived slot is kept as primary
- Re-upload for same date+service syncs in place (preserves `ChurchServiceItem` IDs where content still matches)
- Existing `ServiceSection.church_service_item_id` links survive re-upload when items still match
- Songs with `openlp_search_title` are stored for deterministic later matching

### 1j. Verification

```bash
vendor/bin/sail artisan migrate
vendor/bin/sail artisan test --compact tests/Feature/ChurchServiceApiTest.php
vendor/bin/sail artisan test --parallel --compact
vendor/bin/sail composer phpstan
vendor/bin/sail bin pint --dirty
```

---

## Phase 2 — Service Section Data Foundation

### 2a. Migration

**`create_service_sections_table`**

All columns as defined in the data model above.

### 2b. Enums

`ServiceSectionType`, `ServiceSectionStatus`, `ServiceSectionConfidenceSource` as defined above.

### 2c. `ServiceSection` Model

- Casts: `section_type` → `ServiceSectionType::class`, `status` → `ServiceSectionStatus::class`, `confidence_source` → `ServiceSectionConfidenceSource::class`, `source_segment_ids` → `'array'`, `metadata` → `'array'`
- Relationships: `belongsTo(MediaProcessingLog)`, `belongsTo(ChurchServiceItem)` (nullable), `belongsTo(Sermon)` (nullable)
- Fillable: all columns except `id` and timestamps

### 2d. Factory + Tests

- `ServiceSectionFactory` with states for each section type and status
- Basic model tests: creation, relationships, casts

---

## Phase 3 — Heuristic Classification

Wire `ClassifyServiceSections` into the livestream pipeline after `AnalyzeSegments`.

### 3a. `ClassifyServiceSections` Job

1. Load `LivestreamSegment` records for this processing run, ordered by position
2. Look up the `ChurchService` for the matching date+service (from `MediaProcessingLog` metadata)
3. If found, link `ChurchService.media_processing_log_id` to this processing run
4. Map song segments to `ServiceSectionType::Song`; populate `title` from matched `ChurchServiceItem` where available
5. For speech segments, apply tiered alignment:
   - If order contains explicit speech anchors (Bible/custom/presentation items with semantic labels), align by song-boundary windows + position
   - If order is sparse (for example songs-only), avoid over-labeling and classify non-sermon speech as `Other` unless confidence is strong
6. Where order predicts two speech types between the same song boundaries but one segment is detected, mark as **split candidate** (in `metadata`)
7. Create `ServiceSection` records; set `church_service_item_id` FK where alignment succeeded
8. Set `confidence_source = 'order_of_service'` where aligned, `'heuristic'` where only duration/position rules applied
9. If a `Sermon` section is identified with stronger confidence than the baseline from `AnalyzeSegments`, update `media_processing_logs.sermon_start_time/end_time`; otherwise keep existing values
10. In refresh mode, upsert existing `ServiceSection` rows (`media_processing_log_id + section_order`) instead of insert-only, so reclassification is idempotent

**Fallback without order of service**: duration heuristics only — longest speech segment remains sermon candidate, low-confidence speech defaults to `Other` (do not force welcome/prayer labels).

### 3b. Pipeline Modification

Insert `ClassifyServiceSections` after `AnalyzeSegments` in `ProcessingPipelineBuilder`.

Contract with existing pipeline:

1. `AnalyzeSegments` still provides baseline sermon bounds
2. `ClassifyServiceSections` may refine bounds when confident
3. `ExtractSermon` continues reading bounds from `media_processing_logs.sermon_start_time/end_time` (no double source of truth)

### 3c. Config

```php
// config/media-processing.php
'section_classification' => [
    'enabled' => true,
    'use_ai' => true,
    'ai_confidence_threshold' => 0.6,
    'sermon_time_refinement' => [
        'enabled' => true,
        'min_confidence' => 0.7,
    ],
    'extract' => [
        'bible_reading' => false,
        'childrens_talk' => true,
        'sermon' => true,
    ],
    'publish' => [
        'childrens_talk' => true,
    ],
    'include_bible_reading_in_sermon' => true,
],
```

### 3d. Tests

- Classification with order of service: sections match expected types and link to `ChurchServiceItem` records
- Classification with sparse order (songs-only `.osz`): songs map correctly; non-sermon speech defaults to `Other` unless high confidence
- Classification without order of service: falls back to heuristics
- Split candidate detection
- `media_processing_logs.sermon_start_time/end_time` refinement only when classifier confidence exceeds threshold
- Idempotent refresh mode updates links/titles without creating duplicate `ServiceSection` rows
- OpenLP uploaded after livestream completion triggers refresh classification/backfill
- Pipeline integration: job runs in correct position

---

## Phase 4 — Bible Reading + Sermon Combination

Modify `ExtractSermon` to optionally prepend the Bible reading.

1. After `ClassifyServiceSections` has run, check whether a `BibleReading` section immediately precedes the `Sermon` section
2. If so and `include_bible_reading_in_sermon` is enabled: extend `sermon_start_time` back to the start of the `BibleReading` section (single FFmpeg stream copy)
3. If not adjacent: use FFmpeg concat to join the two segments

Tests covering adjacent and non-adjacent cases.

---

## Phase 5 — Children's Talk Extraction & Publishing

### 5a. `ExtractAdditionalSections` Job

Extract audio/video for any section flagged for extraction in config.

### 5b. `PublishAdditionalSections` Job

For sections flagged for publishing:

1. Create a `Sermon` record with `source_type` matching the parent livestream, `service = SermonService::OTHER`, title from the section
2. Generate a thumbnail from the extracted video
3. Link `ServiceSection.sermon_id` to the new `Sermon` record
4. Optionally skip AI analysis (children's talk doesn't need sermon points)

---

## Phase 6 — AI Transcript Splitting

For split candidates (where one audio segment contains two expected sections):

### 6a. `TranscribeSplitCandidates` Job

Transcribe only the flagged segments (cheaper than transcribing the whole video). Store transcript in `ServiceSection.metadata`.

### 6b. `AiClassifySections` Job

Send transcript + expected section types to AI. AI identifies the boundary timestamp. Split the original `ServiceSection` into two records with corrected times and `confidence_source = 'ai_transcript'`.

Flag low-confidence sections for manual review via `ManualReviewRequired` mailable.

Both jobs skipped when `section_classification.use_ai` is `false`.

---

## Song Tracking

### Without a Song Model (Phase 1)

`ChurchServiceItem` records with `type = 'songs'` already capture every song per service. Basic queries work immediately:

| Question | Query |
|---|---|
| Songs sung this year | `ChurchServiceItem::where('type', 'songs')->whereHas('churchService', fn ($q) => $q->whereYear('date', 2026))` |
| When did we last sing X? | Filter by `title LIKE '%...'`, order by `churchService.date` desc |
| How often do we sing X? | Group by `title`, count |
| Full order of service for a date | `ChurchService::where('date', ...)->with('items')` |
| Songs with actual timestamps | `ChurchServiceItem::has('serviceSection')->with('serviceSection')` |

### With a Song Model (Optional Enhancement)

A canonical `Song` model enables richer queries, deduplication, and display of lyrics/copyright/author data. The data source is the OpenLP songs SQLite database, which contains 1,166 songs with full metadata.

---

## Revised Livestream Pipeline (after all phases)

```
[existing]
PerformVisualAnalysis + GenerateRmsLog  (parallel)
    ↓
AnalyzeSegments
    ↓
[Phase 3]
ClassifyServiceSections
    ↓
[Phase 6, optional]
TranscribeSplitCandidates
AiClassifySections
    ↓
[Phase 4, modified]
ExtractSermon                           ← optionally prepends bible reading
    ↓
[Phase 5]
ExtractAdditionalSections
    ↓
[existing]
SubmitToProcessing
IdentifySpeaker
TranscribeAudio
ProcessTranscriptWithAI
GenerateThumbnail
    ↓
[Phase 5]
PublishAdditionalSections
    ↓
[existing]
CleanupTemporaryFiles
```

Backfill path when OpenLP arrives after livestream processing:

```
OpenLP upload
    ↓
ClassificationBackfillService
    ↓
ClassifyServiceSections (refresh mode, idempotent upsert)
```

---

## Optional Enhancement — Email Import

An emailed order of service can arrive days before the service, giving the livestream pipeline an early prior even when the OpenLP file hasn't been uploaded yet.

### How It Works

The email parser (however implemented) produces the same output shape as the OpenLP parser: `date`, `service`, and ordered items. It feeds into the same `ChurchService::firstOrNew` + `ChurchServiceItemSyncService::sync(...)` flow, just with `source = ServiceItemSource::Email`.

Because email has the lowest source priority, it will never overwrite items that came from OpenLP or manual entry. But if it arrives first, its items are immediately available for the classification pipeline to use.

### Possible Ingestion Approaches

- **Laravel Mailbox route** — incoming email parsed automatically (requires mailbox hosting or a webhook from Mailgun/Postmark)
- **Admin paste form** — a simple textarea where an admin pastes the emailed order of service, parsed with basic pattern matching or AI
- **AI-assisted parsing** — send the email body to an LLM to extract structured items (most flexible for varied email formats)

### What's Needed

| File | Purpose |
|---|---|
| `app/Services/EmailServiceParser.php` | Parses email text → structured items array |
| Import route or mailbox handler | Ingestion endpoint |
| Tests | Parsing accuracy, source priority enforcement |

The `ServiceItemSource` enum and source priority logic are already in place from Phase 1 — the email import just needs a parser and an ingestion mechanism.

### Not Required for Phase 1

The email import is fully optional. The schema supports it from day one (the `source` column exists), but the parser and ingestion mechanism can be built whenever the need arises. The OpenLP upload is the primary source and works independently.

---

## Optional Enhancement — Song Database Import

A canonical `Song` model populated from the OpenLP songs SQLite database. Enables richer song tracking, lyrics display, copyright/CCLI reporting, and deduplication of `ChurchServiceItem` titles.

### Source Data (OpenLP Songs Database)

The OpenLP songs database (`songs.sqlite`) contains:

- **1,166 songs** — title, lyrics (OpenLP XML format with verse labels), copyright, CCLI number
- **588 authors** — with typed relationships: words, music, translation, words+music
- **2 songbooks** — "Praise" (physical hymn book) and "Praise Online" (digital supplement)
- **Songbook entries** — hymn numbers linking songs to songbooks (e.g. song → Praise #491)

For song items, `.osz` payloads also carry OpenLP's internal search key at `serviceitem.header.data.title`. In sample files this key matches `songs.search_title` exactly, making it the most reliable linker.

Not all songs have hymn numbers (especially Praise Online), hymn formatting is inconsistent (`#491(i)` vs entry `491`, `#23B` vs `23`), and `search_title` can be non-unique for duplicate songs. So matching must be deterministic and ambiguity-aware.

### Data Model

**`songs` table**

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | |
| `title` | string | Canonical title |
| `lyrics` | text, nullable | Stored as plain text (converted from OpenLP XML on import) |
| `copyright` | string, nullable | |
| `ccli_number` | string, nullable | CCLI licence number |
| `songbook_entries` | json, nullable | `[{"songbook": "Praise", "entry": "491"}, ...]` |
| `primary_hymn_number` | string, nullable | Optional denormalized primary entry for quick filtering |
| `authors` | json, nullable | `[{"name": "Isaac Watts", "type": "words"}, ...]` |
| `search_title` | string | OpenLP search key (from source DB) |
| `normalized_title` | string | App-level normalized title used only as fallback matcher |
| `openlp_id` | unsignedInteger, nullable | Original ID from OpenLP database, for sync |
| `timestamps` | | |

Unique index on `openlp_id`. Index on `search_title`. Index on `normalized_title`. Index on `primary_hymn_number`.

**`church_service_items` table** — add column:

| Column | Type | Notes |
|---|---|---|
| `song_id` | bigint FK, nullable | → `songs.id`, set null on delete |
| `song_match_status` | string, nullable | `matched`, `ambiguous`, `unmatched` |
| `song_match_method` | string, nullable | `openlp_search_title`, `hymn_disambiguation`, `normalized_title` |

### Linking Strategy

`ChurchServiceItem` song rows should carry both:

- Human title (`title` / `source_title`)
- OpenLP key (`openlp_search_title`)

**Match order**:

1. **Exact OpenLP key match** — `church_service_items.openlp_search_title = songs.search_title`.
   - If exactly one song matches: link directly (`song_match_method = openlp_search_title`).
2. **Disambiguation for non-unique keys**:
   - Parse hymn token from display title (`#491`, `#491(i)`, `#23B`, etc.) and compare against normalized `songbook_entries`
   - Compare `normalized_title`
   - Optionally compare author hints from OpenLP footer text
   - If still non-unique: do **not** auto-link; mark as `song_match_status = ambiguous`.
3. **Fallback when OpenLP key missing**:
   - Use strict `normalized_title` unique match only
4. **No safe match**:
   - Keep `song_id = null`, `song_match_status = unmatched`

No first-match behavior is allowed for ambiguous candidates.

This can run at insert time (in parser/controller sync) and as a batch reconciliation command for historical backfill.

### Import Command

An Artisan command to import/sync from the OpenLP SQLite database:

```
php artisan songs:import {path-to-sqlite}
```

1. Read songs from the SQLite database
2. For each song: upsert by `openlp_id`
3. Convert lyrics from OpenLP XML to plain text
4. Flatten authors into JSON array
5. Import all songbook entries (`songs_songbooks`) into `songbook_entries` JSON and compute `primary_hymn_number`
6. Compute/store `normalized_title` for fallback matching
7. Report: created, updated, skipped, ambiguous-key counts

The command is idempotent — safe to re-run after adding songs in OpenLP. A `--dry-run` flag shows what would change without writing.

### Enriched Queries

| Question | Query |
|---|---|
| Songs sung this year with full metadata | `Song::whereHas('serviceItems.churchService', fn ($q) => $q->whereYear('date', 2026))` |
| CCLI reporting (songs used in period) | `Song::whereHas('serviceItems.churchService', fn ($q) => $q->whereBetween('date', [...]))->withCount('serviceItems')` |
| Song lyrics for display | `Song::where('primary_hymn_number', '491')->first()->lyrics` |
| Songs never sung in a service | `Song::doesntHave('serviceItems')` |

### What's Needed

| File | Purpose |
|---|---|
| `app/Models/Song.php` | Song model |
| `database/factories/SongFactory.php` | Test factory |
| Migration: `create_songs_table` | Songs table |
| Migration: `add_song_link_columns_to_church_service_items_table` | `song_id`, `song_match_status`, `song_match_method` |
| `app/Console/Commands/ImportSongsCommand.php` | Artisan import command |
| Tests | Import command, linking logic |

### Not Required for Phase 1

Song linking remains a progressive enhancement. Phase 1 works with title strings and `openlp_search_title` only. The import command and `Song` model can be added later, then existing `ChurchServiceItem` rows backfilled by re-running the linker.

---

## Implementation Order

| Phase | Dependencies | Delivers |
|---|---|---|
| **1 — OpenLP Upload** | None | `ChurchService`, `ChurchServiceItem`, API endpoint, parser |
| **2 — Section Data Foundation** | Phase 1 schema available (`church_services`, `church_service_items`) | `ServiceSection` model, enums, factory |
| **3 — Heuristic Classification** | Phases 1 + 2 | Sections detected and linked to order of service, plus late-upload reclassification backfill |
| **4 — Bible Reading + Sermon** | Phase 3 | Combined sermon extraction |
| **5 — Children's Talk** | Phase 3 | Additional section extraction and publishing |
| **6 — AI Splitting** | Phase 3 | Transcript-based section boundary detection |

Phase 1 is the natural starting point because it establishes service/order data and identity metadata. Phase 2 should follow once those tables exist so foreign keys and alignment links can be enforced cleanly.
