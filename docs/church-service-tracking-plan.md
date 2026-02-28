# Church Service Tracking Plan (Refactor-Aligned)

## Overview

This plan implements church service tracking as a **separate domain surface** from media processing, while reusing shared internal infrastructure.

- **Service Tracking domain**: order of service ingestion, service/item lifecycle, and review workflow.
- **Media Processing domain**: upload, segmentation, extraction, transcription, AI analysis, and processing status.

The two domains integrate through explicit data contracts (service date/slot and indexed lookup columns), not by merging endpoints or controllers. In Phase 2, an optional nullable FK (`media_processing_logs.church_service_id`) may be used as a resolved-link cache for traceability/performance.

## Confirmed Decisions

1. Use a **separate ability** for service ingestion: `service:upload`.
2. Use a **separate endpoint surface** under `/api/services`.
3. OpenLP single-file upload remains **synchronous** (`201`).
4. Session-authenticated admins are allowed (same behavior pattern as media APIs).
5. A `ChurchService` can be linked to **multiple** livestream processing runs over time (via date/service lookup).
6. Add **indexed extracted date/service columns** on `media_processing_logs` while keeping JSON metadata (Phase 2).
7. Low-confidence parses are still persisted and marked `needs_review`.
8. Unknown slot defaults to `other`.
9. Filename vs embedded `.osj` mismatch is accepted with warning/confidence drop; hard-fail only if date cannot be inferred.
10. Do **not** store raw `.osz` / `.osj` files.
11. Re-upload sync prioritizes item ID preservation.
12. Stale unmatched items are soft-deleted (not hard-deleted immediately).
13. Manual admin editing is out of Phase 1; deferred to Phase 2.
14. Multi-site is out of scope.
15. Historical OpenLP bulk import is deferred.
16. Email order import is deferred.
17. Song DB import is deferred until OpenLP + section matching stabilizes.
18. Rollout is phased + feature-flagged + canary-ready with rollback switches.

---

## Architecture: Separate Surface, Shared Internals

### Service Tracking Surface (new)

- API routes under `/api/services/...`
- Dedicated controller, request, resource, and middleware
- Token ability: `service:upload`

### Media Processing Surface (existing)

- Existing `/api/media/...` endpoints stay focused on media lifecycle
- No church-service-specific payloads or behaviors added to media upload APIs

### Shared Internals (reused in Phase 2+)

- Existing queue infrastructure and job chaining patterns
- Existing `MediaProcessingLog` lifecycle
- Existing segmentation output (`LivestreamSegment`)
- Existing extraction/transcription/thumbnail services where applicable

Integration occurs at the data layer and pipeline extension points, not at endpoint-layer coupling.

---

## Phase 1 Data Model

### 1) `church_services` (new)

One row per service date + slot.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | PK |
| `date` | date | Service date |
| `service` | string | Cast to `SermonService` enum (`morning`, `evening`, `other`) |
| `source` | string | `'openlp'` initially; new sources added when needed |
| `original_filename` | string nullable | Upload filename |
| `needs_review` | boolean default false | Set on low confidence / mismatch scenarios |
| `import_metadata` | json nullable | Parse diagnostics: confidence score, mismatch flags, parse method, warnings |
| `timestamps` | | |

Notes:
- **Reuses existing `SermonService` enum** for the `service` column — no new enum needed.
- `source` is a plain string, not an enum. Only `'openlp'` exists in Phase 1. When email/manual sources arrive, we'll promote to an enum with priority logic at that point.
- Parse diagnostics (confidence, filename mismatch, parse method) are consolidated into `import_metadata` JSON. The `needs_review` boolean is the only workflow-relevant flag and gets its own column for queryability.

Constraints:
- Unique index: `(date, service)`

### 2) `church_service_items` (new)

Ordered service items from OpenLP.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | PK |
| `church_service_id` | bigint FK | Cascade delete |
| `position` | unsignedInteger | Sequence in service |
| `type` | string | OpenLP plugin (`songs`, `bibles`, `presentations`, `custom`) |
| `title` | string | Normalized display title |
| `source_title` | string nullable | Raw OpenLP title |
| `openlp_search_title` | string nullable | OpenLP song key |
| `metadata` | json nullable | Plugin-specific metadata |
| `created_at`/`updated_at` | timestamps | |
| `deleted_at` | soft delete timestamp | Soft-delete stale unmatched rows |

Constraints:
- Index: `(church_service_id, position)` — non-unique; position uniqueness enforced in application code within the sync transaction. MySQL does not support partial unique indexes, and soft-deleted rows would defeat a composite unique index including `deleted_at` (MySQL treats NULLs as distinct).
- Index: `(church_service_id, type)`

### No changes to `media_processing_logs` in Phase 1

The indexed `extracted_date`/`extracted_service` columns and any FK linking are deferred to Phase 2 when the classifier pipeline needs them. In Phase 1, the two domains are completely independent.

---

## Phase 1 API Surface

### Auth & Middleware

Create `EnsureServiceTrackingAccess` middleware following the same pattern as `EnsureMediaProcessingAccess`:
- Require authenticated admin user with verified email
- If PAT is used, require `service:upload` ability
- Session-authenticated admins pass through (no ability check)

Register as middleware alias `service.access` in `bootstrap/app.php`.

**Middleware is the single canonical enforcement point.** The controller does not duplicate auth/ability checks — it relies entirely on route middleware (`auth:sanctum` + `service.access`). This matches the existing `MediaController` pattern and prevents auth logic drift across layers.

### Ability

Add to `App\Enums\ApiTokenAbility`:

```php
case SERVICE_UPLOAD = 'service:upload';
```

### Endpoints (Phase 1)

- `POST /api/services/openlp` — Synchronous upload + parse + upsert. Returns `201` with `ChurchServiceResource`.
- `GET /api/services/{churchService}` — Read endpoint for admin/integration. Returns `ChurchServiceResource`.

### API Resources

- `ChurchServiceResource` — wraps `ChurchService` with nested items.
- `ChurchServiceItemResource` — wraps `ChurchServiceItem`.

Follow the same patterns as the existing `SermonResource`.

No church-service routes are added under `/api/media/*`.

---

## OpenLP Upload Flow (Phase 1)

### Request

`UploadChurchServiceRequest`:
- Authorization: admin user (via middleware)
- Validation: `.osz` upload (`zip` content), max size suitable for embedded presentation assets

### Parser (`OpenLpServiceParser`)

`parse(UploadedFile $file): OpenLpParseResult`

1. Open `.osz` as zip
2. Locate `.osj` entry
3. Parse JSON
4. Infer date/service:
   - Primary: upload filename pattern
   - Secondary: embedded `.osj` filename
   - Unknown slot -> `other` (uses `SermonService::OTHER`)
   - If date cannot be inferred -> validation error
5. Compare filename vs embedded identity:
   - On mismatch: accept, lower confidence, record in import metadata
   - Set `needs_review=true` when confidence below threshold
6. Extract items with plugin-specific normalization
7. Return data object with parsed service + items + confidence + diagnostics

Note: raw `.osz`/`.osj` is not retained.

### OpenLP `.osj` Format Reference

> **Validated against real `.osz` files** from the church's OpenLP install (`2024-11-17 AM.osz`, `2024-11-17 PM.osz`).

The `.osz` file is a zip archive containing:
- One `.osj` file (JSON service data)
- Zero or more embedded presentation files (`.pptx`, etc.)

The `.osj` is a JSON array. The **first element** is always an `openlp_core` metadata object (not a service item — must be skipped). Subsequent elements are service items:

```json
[
  {
    "openlp_core": {
      "lite-service": false,
      "service-theme": ""
    }
  },
  {
    "serviceitem": {
      "header": {
        "name": "songs",
        "plugin": "songs",
        "title": "O How The Grace Of God #749",
        "search": "",
        "data": {
          "title": "o how the grace of god 749@ 749 o how the grace of god",
          "authors": "Emanuel T. Sibomana (c.1910-75)"
        }
      },
      "data": [ ... ]
    }
  }
]
```

**Critical finding: `header.search` is always empty.** The actual song matching key is `header.data.title` (a lowercase composite string like `"o how the grace of god 749@ 749 o how the grace of god"`). The plan's `openlp_search_title` column must be populated from `header.data.title`, not `header.search`.

Field mapping to schema columns:

| Plugin | `title` (display) | `openlp_search_title` (matching key) | `source_title` (raw) | Notes |
|---|---|---|---|---|
| **songs** | Normalized from `header.title` | `header.data.title` | `header.title` | `header.data` is a dict with `title` + `authors` |
| **bibles** | `header.footer[0]` (e.g., "Luke 15:1-32") | — | `header.title` | `header.title` includes full copyright; use `footer[0]` for clean reference |
| **presentations** | `header.title` (filename) | — | `header.title` | `header.data` is an empty string, not a dict |
| **custom** | `header.title` (e.g., "Reading") | — | `header.title` | `header.data` is an empty string |

Notes on real data quirks:
- **`header.data` type varies**: dict for songs (with `title`/`authors`), empty string for presentations/custom/bibles. Parser must check type before accessing.
- **Bible titles are verbose**: `header.title` includes the full version/copyright string. The clean reference is in `header.footer[0]`.
- **Custom items use `header.theme`** for styling context (e.g., `"Reading"`, `"SecretBible"`), which may be useful metadata.
- The `openlp_search_title` field is only populated for songs — it's the key used for stable matching on re-upload. All other plugin types rely on `source_title` for matching.

#### Date and Slot Inference

Filenames use **AM/PM** format, not Morning/Evening:
- `2024-11-17 AM.osz` → date `2024-11-17`, service `morning`
- `2024-11-17 PM.osz` → date `2024-11-17`, service `evening`

Parser must map: `AM` → `SermonService::MORNING`, `PM` → `SermonService::EVENING`, unknown → `SermonService::OTHER`.

**Known mismatch case**: The `2024-11-17 AM.osz` file contains an `.osj` named `2024-11-17 PM.osj` — the embedded filename disagrees with the upload filename. This is a real-world occurrence of the mismatch scenario described in the confidence rules. The parser must:
1. Prefer the `.osz` upload filename for identity
2. Note the mismatch in `import_metadata`
3. Lower confidence accordingly

### Upsert Behavior

`ChurchServiceController@store`:
- Find or create `ChurchService` by `(date, service)`
- On re-upload of same date+slot: update metadata, sync items
- Sync items in-place via `ChurchServiceItemSyncService`
- Return `201` with `ChurchServiceResource`

### Re-upload Sync Rules

`ChurchServiceItemSyncService`:

The sync algorithm handles re-uploads where items may have been reordered, added, or removed. All operations wrapped in a DB transaction.

**Concurrency guard (required):**
- Lock the parent service row at the start of sync (`SELECT ... FOR UPDATE` / Eloquent `lockForUpdate()`) so two concurrent uploads for the same `(date, service)` cannot interleave and produce duplicate active positions.

**Match priority (in order):**
1. **Stable match**: existing item with same `type` AND (`openlp_search_title` match OR `source_title` match)
2. **Position fallback**: existing item at same `position` with same `type`
3. **No match**: treat as new item

**Operations:**
1. For each incoming item, attempt stable match, then position fallback
2. Update matched rows in-place (preserve ID, update position/title/metadata)
3. Create new rows for unmatched incoming items
4. Soft-delete existing rows that weren't matched by any incoming item

**Conflict resolution:**
- If two incoming items would match the same existing row, the first match wins; the second incoming item is treated as new
- If a stable match and position fallback point to different existing rows, stable match wins

---

## Phase 1 Configuration

`config/service-tracking.php`:

```php
return [
    'enabled' => env('SERVICE_TRACKING_ENABLED', true),
    'confidence' => [
        'review_below' => 0.60,
    ],
    'upload' => [
        'max_size_kb' => 600 * 1024, // 600MB — real files can reach ~542MB with embedded presentations
    ],
];
```

---

## Phase 1 Test Plan (TDD)

Tests are written **before** implementation. Each section is split into **must-have** tests (write first, block implementation) and **hardening** tests (write after the core path works). Must-have tests cover the happy path and critical failure modes. Hardening tests cover edge cases and conflict resolution.

### 1. Parser Tests (`tests/Unit/Services/OpenLpServiceParserTest.php`)

Write these first — the parser is the foundation.

**Must-have:**

| Test | Description |
|---|---|
| `test_parses_valid_osz_file` | Valid `.osz` with `.osj` inside returns correct date, service, and items |
| `test_extracts_date_and_morning_from_am_filename` | `2024-11-17 AM.osz` → date `2024-11-17`, service `morning` |
| `test_extracts_date_and_evening_from_pm_filename` | `2024-11-17 PM.osz` → date `2024-11-17`, service `evening` |
| `test_unknown_slot_defaults_to_other` | `2024-01-07.osz` (no slot keyword) → service `other` |
| `test_fails_when_no_date_can_be_inferred` | Neither filename nor `.osj` contains a parseable date → exception |
| `test_extracts_song_items_with_data_title_as_search_key` | Songs parsed with `header.data.title` as `openlp_search_title`, not `header.search` |
| `test_extracts_bible_reference_from_footer` | Bible items use `footer[0]` for clean title, not the verbose `header.title` |
| `test_preserves_item_order` | Items returned in the order they appear in the `.osj` |
| `test_rejects_non_zip_file` | Non-zip file throws validation exception |
| `test_rejects_zip_without_osj` | Valid zip but no `.osj` entry → exception |
| `test_skips_openlp_core_metadata_entry` | First array element (`openlp_core`) is not treated as a service item |

**Hardening:**

| Test | Description |
|---|---|
| `test_falls_back_to_osj_filename_for_date` | Upload filename has no date, but embedded `.osj` name does |
| `test_filename_osj_mismatch_lowers_confidence` | `.osz` named `AM` but contains `PM.osj` → accepted with low confidence + mismatch flag (real-world case) |
| `test_sets_needs_review_when_confidence_below_threshold` | Confidence below config threshold → `needs_review = true` |
| `test_extracts_presentation_items` | Presentation items parsed; handles `header.data` as empty string |
| `test_extracts_custom_items` | Custom slide items parsed; handles `header.data` as empty string |
| `test_handles_empty_service_items` | `.osj` with empty items array → valid parse, zero items |

### 2. Sync Service Tests (`tests/Unit/Services/ChurchServiceItemSyncServiceTest.php`)

**Must-have:**

| Test | Description |
|---|---|
| `test_creates_items_for_new_service` | Fresh service with no existing items → all items created |
| `test_stable_match_by_search_title` | Re-upload with same songs in different positions → matched by `openlp_search_title`, IDs preserved |
| `test_position_fallback_when_no_title_match` | No title match but same type+position → matched by position |
| `test_soft_deletes_unmatched_stale_items` | Existing item not in re-upload → soft-deleted |
| `test_updates_position_on_reorder` | Same items in different order → positions updated, IDs preserved |
| `test_wraps_in_transaction` | If any operation fails, nothing is committed |

**Hardening:**

| Test | Description |
|---|---|
| `test_stable_match_by_source_title` | Item without search title matched by `source_title` |
| `test_stable_match_wins_over_position_fallback` | Stable match and position would match different rows → stable wins |
| `test_two_incoming_items_matching_same_existing` | First match wins, second becomes new row |
| `test_restores_soft_deleted_item_on_rematch` | Previously soft-deleted item matches again → restored |
| `test_handles_empty_incoming_items` | Re-upload with zero items → all existing items soft-deleted |
| `test_enforces_position_uniqueness_for_active_items` | Two active items cannot share the same position within a service |

### 3. Controller / Feature Tests (`tests/Feature/Api/ChurchServiceControllerTest.php`)

**Must-have:**

| Test | Description |
|---|---|
| `test_upload_creates_service_and_items` | Valid `.osz` upload → 201, service and items in DB |
| `test_upload_returns_church_service_resource` | Response shape matches `ChurchServiceResource` structure |
| `test_re_upload_same_date_slot_updates_service` | Second upload for same date+service → updates, not duplicate |
| `test_requires_authentication` | Unauthenticated → 401 |
| `test_requires_admin` | Non-admin authenticated user → 403 |
| `test_rejects_non_osz_file` | `.txt` file → 422 |
| `test_show_returns_service_with_items` | GET endpoint returns service with nested items |

**Hardening:**

| Test | Description |
|---|---|
| `test_requires_verified_email` | Admin with unverified email → 403 |
| `test_pat_requires_service_upload_ability` | PAT without `service:upload` → 403 |
| `test_pat_with_correct_ability_succeeds` | PAT with `service:upload` → 201 |
| `test_session_auth_admin_succeeds` | Session-authenticated admin (no PAT) → 201 |
| `test_rejects_oversized_file` | File exceeding max size → 422 |
| `test_show_requires_authentication` | Unauthenticated GET → 401 |

### 4. Model Tests (`tests/Unit/Models/`)

**Must-have:**

| Test | Description |
|---|---|
| `test_church_service_casts_service_to_sermon_service_enum` | `service` attribute returns `SermonService` instance |
| `test_church_service_has_many_items` | Relationship returns `ChurchServiceItem` collection |
| `test_church_service_unique_date_service` | Duplicate date+service → integrity constraint violation |
| `test_church_service_item_soft_deletes` | Soft-deleted items excluded from default queries, included with `withTrashed` |

**Hardening:**

| Test | Description |
|---|---|
| `test_church_service_item_cascade_on_service_delete` | Deleting service cascades to items |

### 5. Request Validation Tests (`tests/Unit/Http/Requests/UploadChurchServiceRequestTest.php`)

**Must-have:**

| Test | Description |
|---|---|
| `test_file_is_required` | Missing file → validation fails |
| `test_file_must_be_valid_type` | Wrong MIME type → validation fails |
| `test_file_max_size_enforced` | Oversized file → validation fails |

### Running Tests

```bash
# During development — run the specific area you're working on
vendor/bin/sail artisan test --compact --filter=OpenLpServiceParser
vendor/bin/sail artisan test --compact --filter=ChurchServiceItemSync
vendor/bin/sail artisan test --compact --filter=ChurchServiceController

# All church service tests
vendor/bin/sail artisan test --compact --filter=ChurchService

# Full suite before PR
vendor/bin/sail artisan test --parallel --compact
```

### Quality Gates

Every change must pass:
1. `vendor/bin/sail composer phpstan` (0 errors)
2. `vendor/bin/sail bin pint --dirty`

---

## Phase 1 Delivery Summary

### Prerequisites

- ~~Obtain real `.osz` files~~ — **Done.** Two files validated (`2024-11-17 AM.osz`, `2024-11-17 PM.osz`). Format reference and field mappings updated to match actual data. Key corrections: `header.search` is always empty (use `header.data.title` for song matching), filenames use AM/PM not Morning/Evening, bible titles need `footer[0]` extraction, `header.data` type varies by plugin.

### Deliver
- Migration: `church_services` + `church_service_items` tables
- Models: `ChurchService` + `ChurchServiceItem` with factories
- Enum addition: `SERVICE_UPLOAD` case on `ApiTokenAbility`
- Parser: `OpenLpServiceParser` service
- Sync: `ChurchServiceItemSyncService`
- Controller: `ChurchServiceController` (store + show)
- Request: `UploadChurchServiceRequest`
- Resources: `ChurchServiceResource` + `ChurchServiceItemResource`
- Middleware: `EnsureServiceTrackingAccess`
- Config: `config/service-tracking.php`
- Routes: `/api/services/openlp` (POST) + `/api/services/{churchService}` (GET)
- Tests: must-have tier first (blocks implementation), hardening tier before PR

**Explicitly out of scope in Phase 1:**
- `service_sections` table and all section/classifier logic
- Changes to `media_processing_logs`
- Pipeline integration (`ClassifyServiceSections` job)
- Manual editing UI
- Email import
- Song DB import
- Historical bulk import

---

## Phase 2 Design (Draft)

> **This section is a working draft.** Designs here will be revisited and refined after Phase 1 is in production and real-world usage informs requirements.

### Phase 2 Goal

Add section classification to the livestream processing pipeline, linking detected service sections to OpenLP order-of-service data, and provide a review queue for low-confidence results.

### `service_sections` Table (Draft)

Business-level detected sections (classification output).

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | PK |
| `media_processing_log_id` | bigint FK | Cascade delete |
| `church_service_item_id` | bigint FK nullable | Set null on delete |
| `section_type` | string | `ServiceSectionType` enum |
| `section_order` | unsignedInteger | Order in detected service |
| `title` | string nullable | Derived section title |
| `start_time` | float | Seconds — matches existing `LivestreamSegment` convention |
| `end_time` | float | Seconds |
| `duration` | float | Seconds |
| `confidence` | float | 0.0-1.0 |
| `confidence_source` | string | Backed enum cast: `ServiceSectionConfidenceSource` |
| `status` | string | `ServiceSectionStatus` enum |
| `needs_manual_review` | boolean default false | Review queue signal |
| `manual_review_reason` | string nullable | Why flagged |
| `extracted_file_path` | string nullable | Extracted media path |
| `sermon_id` | unsignedInteger nullable FK | Matches current `sermons.id` PK type |
| `source_segment_ids` | json | Source `LivestreamSegment` IDs |
| `metadata` | json nullable | Extra notes/transcript hints |
| `timestamps` | | |

Notes:
- Time columns use `float` to match existing `LivestreamSegment` and `MediaProcessingLog` conventions.
- `confidence_source` uses a backed enum cast (`ServiceSectionConfidenceSource`) — it drives classifier branching and review behavior, and the codebase uses enums consistently for typed string columns.
- Unique composite on `(media_processing_log_id, section_order)` for idempotent refresh upserts.

### `media_processing_logs` Changes (Draft)

Add indexed extracted identity columns for cross-domain lookup:

| Column | Type | Notes |
|---|---|---|
| `extracted_date` | date nullable | Indexed |
| `extracted_service` | string nullable | Indexed; values align with `SermonService` |
| `church_service_id` | bigint nullable FK | Set null on delete; resolved cache, not structural coupling |

Indexes:
- `(extracted_date, extracted_service)` — primary cross-domain lookup
- `church_service_id` — optional resolved link

Cross-domain lookup uses `(extracted_date, extracted_service)` to join against `church_services.(date, service)`. The nullable `church_service_id` FK is a **resolved cache** — set by the classifier after successful lookup, providing traceability and query performance. It is not required for correctness; the `(date, service)` join is the source of truth. This way, if date/service inference is later corrected, the FK can be re-resolved without structural migration.

### Enums (Draft)

#### `ServiceSectionType`

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

#### `ServiceSectionStatus`

```php
enum ServiceSectionStatus: string
{
    case Identified = 'identified';
    case Extracted = 'extracted';
    case Published = 'published';
    case Skipped = 'skipped';
}
```

#### `ServiceSectionConfidenceSource`

```php
enum ServiceSectionConfidenceSource: string
{
    case Heuristic = 'heuristic';
    case OrderOfService = 'order_of_service';
    case AiTranscript = 'ai_transcript';
}
```

### Pipeline Integration (Draft)

Add `ClassifyServiceSections` after `AnalyzeSegments` in livestream chain.

Target chain:
1. `AnalyzeSegments`
2. `ClassifyServiceSections` (new, default-on)
3. `ExtractSermon`
4. Existing downstream jobs

Classifier behavior:
1. Load `LivestreamSegment` rows for run
2. Create baseline `ServiceSection` rows from heuristics (works without OpenLP)
3. Resolve `ChurchService` by `(extracted_date, extracted_service)` lookup
4. If found, align sections to `ChurchServiceItem` order when confidence supports it
5. Mark low-confidence/split candidates with `needs_manual_review=true`
6. Upsert idempotently by `(media_processing_log_id, section_order)` in refresh mode
7. Bound updates:
   - May refine `sermon_start_time/end_time` only when confidence beats baseline
   - Must not auto-update after publish; require explicit manual reclassify endpoint

### Review Queue (Draft)

Low-confidence and split-candidate sections surfaced via:
- `service_sections.needs_manual_review=true`
- `manual_review_reason` and metadata context

Admin review UI/actions:
- List/filter pending reviews
- Approve/relabel/retime sections
- Trigger manual reclassify for a service/run
- Manual admin editing for order-of-service data

Email notifications are optional (digest/alerts), not the primary review mechanism.

### Phase 2 Configuration (Draft)

Add to `config/media-processing.php`:

```php
'section_classification' => [
    'enabled' => env('SERVICE_SECTION_CLASSIFICATION_ENABLED', true),
    'use_ai_for_split_candidates' => env('SERVICE_SECTION_AI_SPLIT_ENABLED', false),
    'confidence' => [
        'sermon_refine_min' => 0.70,
        'manual_review_below' => 0.60,
    ],
],
```

### Phase 2 Endpoints (Draft)

- `POST /api/services/{churchService}/reclassify` — explicit manual refresh mode (required for post-publish bound changes)

---

## Phase 3 Design (Draft)

> **This section is a working draft.** Will be refined after Phase 2.

### Goal

Advanced extraction and publishing: Bible+Sermon combination, children's talk extraction, and retention policies.

### Sermon Extraction Rules

In `ExtractSermon`:
1. Identify `BibleReading` and `Sermon` sections
2. Adjacent case: extend sermon start to bible reading start (single extraction span)
3. Non-adjacent case: extract both spans and concat with FFmpeg
4. Keep existing fallback to baseline sermon bounds when no valid section pairing exists

### Additional Sections (Children's Talk)

**Extraction** — `ExtractAdditionalSections` (new):
- Extract configured section types from source video
- Mark `ServiceSection.status=extracted`

**Publishing** — `PublishAdditionalSections` (new):
- Create `Sermon` with `source_type=livestream`, `service=other`, minimal metadata
- Link `service_sections.sermon_id`
- Full AI analysis remains optional/config-driven

**Retention:**
- Keep extracted assets for published sections
- Auto-clean unpublished extracted assets via TTL policy

### Phase 3 Configuration (Draft)

Add to `config/media-processing.php`:

```php
'section_publishing' => [
    'publish' => [
        'childrens_talk' => true,
    ],
    'extract' => [
        'childrens_talk' => true,
        'bible_reading' => true,
        'sermon' => true,
    ],
    'retain_unpublished_hours' => 48,
],
```

---

## Phase 4: Hardening and Canary Rollout (Draft)

Deliver:
- Rollout controls and canary guardrails
- Observability dashboards/alerts
- Rollback runbook for feature toggles
- Canary user IDs config: `env('SERVICE_TRACKING_CANARY_USER_IDS', '')`

---

## Deferred Enhancements (Explicitly Not In Scope)

1. Email order-of-service import
2. Song DB import and song resolver UI
3. Historical OpenLP bulk import

Future song import note:
- Use `OPENLP_SONGS_DB_PATH`
- Cadence: manual or weekly sync
- Ambiguous matches remain marked; manual resolver can come later

Future source priority note:
- When a second source (email/manual) is added, introduce a `ServiceItemSource` enum with `priority()` method to govern which source wins on conflict. Not needed until then.
