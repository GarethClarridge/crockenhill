# Song Catalog and Usage Tracking Plan

## Overview

This plan implements the deferred song import enhancement as a proper first-class domain:

1. Import the local OpenLP songs SQLite database into application tables with full song content.
2. Link imported church service song items to canonical songs.
3. Build admin UI for "most used songs" and per-song details (lyrics, authors, usage history).

This is designed for Laravel 12 + Livewire 3 in the existing TALL project, and reuses current service tracking structures rather than introducing parallel usage pipelines.

---

## Goals

1. Introduce a canonical `Song` model with lyrics and metadata available for UI display.
2. Preserve and expose author relationships.
3. Reliably link `church_service_items` (`type='songs'`) to canonical songs.
4. Support usage reporting from real service records (not estimates).
5. Keep the implementation operationally simple and deterministic.

## Non-goals (for this phase)

1. Manual conflict resolver UI for ambiguous/non-deterministic matches.
2. Fuzzy/AI-based song matching.
3. Public-facing song catalog pages.
4. Editing songs in the admin UI (import remains source-of-truth).

---

## Current State Summary

1. Service import is implemented and stores song keys in `church_service_items.openlp_search_title`.
2. Service records and item lifecycle are already stable (`ChurchService`, `ChurchServiceItem`, soft-delete handling).
3. Real source DB (`songs (1).sqlite`) contains:
   - `songs` (1166 rows)
   - `authors` + `authors_songs`
   - `song_books` + `songs_songbooks`
4. Lyrics in source are OpenLP song XML content, not plain text.
5. Source contains duplicate `search_title` values; matching must be deterministic and collision-safe.

---

## Design Principles

1. Deterministic first: exact key-based linking before any heuristics.
2. Canonical model: one app-level song record per normalized search key.
3. Source traceability: preserve source identifiers and timestamps.
4. Operational simplicity: one sync command, one linker service, no extra queues required.
5. Laravel conventions: migrations, Eloquent relationships, typed services, focused tests.

---

## Data Model

## 1) `songs` table (new, canonical catalog)

One record per canonical song identity.

Proposed columns:

1. `id` (bigint PK)
2. `canonical_key` (string, unique)  
   - Normalized from source/OpenLP `search_title`
3. `title` (string)
4. `alternate_title` (string nullable)
5. `lyrics_xml` (longText)  
   - Raw OpenLP XML
6. `lyrics_plain` (longText nullable)  
   - Extracted plain text for display/search
7. `lyrics_structure` (json nullable)  
   - Parsed verses/chorus structure for future rendering
8. `verse_order` (string nullable)
9. `copyright` (string nullable)
10. `comments` (longText nullable)
11. `ccli_number` (string nullable)
12. `theme_name` (string nullable)
13. `search_title_raw` (string)  
14. `search_lyrics` (longText nullable)
15. `source_last_modified` (dateTime nullable)
16. `is_active` (boolean default true)
17. `import_metadata` (json nullable)  
   - e.g. duplicate source IDs merged into canonical record
18. timestamps

Indexes:

1. unique(`canonical_key`)
2. index(`is_active`)
3. index(`source_last_modified`)

## 2) `song_authors` table (new)

Proposed columns:

1. `id` (bigint PK)
2. `display_name` (string, unique)
3. `first_name` (string nullable)
4. `last_name` (string nullable)
5. timestamps

## 3) `song_author_song` table (new pivot)

Proposed columns:

1. `song_id` FK cascade
2. `song_author_id` FK cascade
3. `author_type` (string default `''`)  
4. composite PK/unique (`song_id`, `song_author_id`, `author_type`)

## 4) `song_source_records` table (new, traceability)

Tracks source rows mapped into canonical songs.

Proposed columns:

1. `id` (bigint PK)
2. `song_id` FK cascade
3. `source` (string, e.g. `openlp_sqlite`)
4. `source_song_id` (unsigned bigint)
5. `source_last_modified` (dateTime nullable)
6. `source_title` (string nullable)
7. `source_search_title` (string nullable)
8. timestamps

Indexes/constraints:

1. unique(`source`, `source_song_id`)
2. index(`song_id`)

## 5) `church_service_items` change

Add nullable FK:

1. `song_id` -> `songs.id` (`nullOnDelete`)

Indexes:

1. index(`song_id`)
2. index(`type`, `song_id`)

This keeps usage grounded in existing service items (no duplicate usage ledger table).

---

## Domain Models

Add:

1. `App\Models\Song`
2. `App\Models\SongAuthor`
3. `App\Models\SongSourceRecord`

Update:

1. `App\Models\ChurchServiceItem` -> `belongsTo(Song::class)`

Relationships:

1. `Song::authors()` many-to-many
2. `Song::sourceRecords()` hasMany
3. `Song::serviceItems()` hasMany
4. `Song::churchServices()` hasManyThrough (optional convenience)

---

## Import & Sync Architecture

## Configuration

Extend `config/service-tracking.php`:

```php
'songs' => [
    'sqlite_path' => env('OPENLP_SONGS_DB_PATH'),
    'source_name' => 'openlp_sqlite',
],
```

Add to `.env.example`:

```ini
OPENLP_SONGS_DB_PATH=
```

## Import Command

Create Artisan command:

`service-tracking:sync-songs {--path=} {--dry-run} {--link-services} {--deactivate-missing}`

Behavior:

1. Resolve SQLite path (`--path` overrides config).
2. Validate file exists/readable and has required tables.
3. Read source songs and related authors.
4. Canonicalize by normalized search key.
5. Upsert canonical `songs`, `song_authors`, pivot links, and `song_source_records`.
6. Optionally deactivate canonical songs not present in latest source scan.
7. Optionally run service-item linker (`--link-services`).
8. Print metrics table (created/updated/linked/unmatched/duplicates merged).

Implementation note:

Use a dedicated temporary runtime SQLite connection via `DB::connection(...)` (Laravel multiple connection pattern), without changing default MySQL app connection.

## Canonicalization Rules

Canonical key (`canonical_key`) should be generated identically for importer and linker:

1. lowercase
2. trim
3. collapse internal whitespace

No punctuation stripping in v1 (to avoid accidental collisions).  
If a future mismatch pattern appears, extend rules with explicit migration and backfill.

## Duplicate Source Handling

Source can contain duplicate `search_title` rows. Strategy:

1. Merge into one canonical `songs` row by `canonical_key`.
2. Choose representative content from most recently modified source row.
3. Preserve all contributing source rows in `song_source_records`.
4. Record duplicate count and source IDs in `songs.import_metadata`.

This yields stable UI/reporting while preserving auditability.

---

## Lyrics Handling

Create `App\Services\OpenLpLyricsParser`:

Input:

1. OpenLP lyrics XML string from source `songs.lyrics`

Output:

1. `lyrics_plain` (display-friendly text)
2. `lyrics_structure` JSON (verses with type/label/text)

Parser behavior:

1. Parse XML safely.
2. Extract `<verse type="..." label="..."><![CDATA[...]]></verse>`.
3. Normalize line endings and trim surrounding whitespace.
4. Preserve order for rendering and verse-order reconciliation.
5. Fail gracefully: if XML parse fails, keep raw XML and set `lyrics_plain` to null; set warning in metadata.

---

## Service Item Linking Strategy

Create `App\Services\ChurchServiceSongLinker`.

Linking source:

1. Primary key: normalized `church_service_items.openlp_search_title`

Rules:

1. Only process active song items (`type='songs'` and `deleted_at IS NULL`).
2. Deterministic exact key match only in v1.
3. If no key or no catalog match, keep `song_id = null`.
4. Return structured metrics: linked, already linked, missing key, no match.

Integration points:

1. After OpenLP service import in API controller.
2. After OpenLP service import in Livewire upload flow.
3. In sync command with `--link-services`.

Backfill command option:

1. `--link-services` performs full relink across existing service items.

---

## Admin UI (Livewire)

## 1) Most Used Songs page

Route:

1. `GET /admin/services/songs` -> `App\Livewire\Admin\ChurchServices\ListSongs`

Features:

1. Search by title, alternate title, author, CCLI.
2. Filter by active/inactive.
3. Sort by usage count (default desc), last used date, title.
4. Usage stats columns:
   - `usage_count` (service item count)
   - `services_count` (distinct church services)
   - `last_used_date` (max church service date)
5. Row action to song detail page.

Data source:

Derived query over `songs` + `church_service_items` + `church_services`.

## 2) Song detail page

Route:

1. `GET /admin/services/songs/{song}` -> `App\Livewire\Admin\ChurchServices\ShowSong`

Features:

1. Song metadata (title, alt title, CCLI, theme, authors).
2. Lyrics display from parsed plain text (fallback to XML snippet if plain unavailable).
3. Recent usage table with date/service and link to service detail.
4. Source traceability summary (source rows count, last sync timestamps).

## 3) Navigation updates

Add links from existing service admin pages:

1. "Songs" link on service list page.
2. Optional button on member admin home under Sermons section.

---

## Query Strategy for Usage Metrics

Use aggregate subqueries or grouped joins from `songs`:

1. `usage_count`: count of active `church_service_items` for each `song_id`
2. `services_count`: count distinct `church_service_id`
3. `last_used_date`: max related `church_services.date`

This avoids maintaining a separate denormalized usage table.

If performance becomes an issue later, add a cached read model as a separate optimization phase.

---

## Implementation Phases

## Phase 1: Schema and Models

Deliver:

1. Migrations for `songs`, `song_authors`, `song_author_song`, `song_source_records`
2. Migration adding `church_service_items.song_id`
3. New models + relationships

Tests:

1. Model relation tests for songs/authors/source records
2. Schema tests for new indexes/FKs

## Phase 2: Import Core

Deliver:

1. `OpenLpLyricsParser`
2. `SongCanonicalKeyGenerator` (shared canonicalization utility)
3. `SongCatalogSyncService`
4. `service-tracking:sync-songs` command

Tests:

1. Unit tests for canonical key generation
2. Unit tests for lyrics parsing
3. Feature tests for command (happy path, dry-run, missing file, duplicate merges)

## Phase 3: Linking

Deliver:

1. `ChurchServiceSongLinker` service
2. Integration into API and Livewire service upload flows
3. Backfill path via command `--link-services`

Tests:

1. Unit tests for linking outcomes
2. Feature tests validating `song_id` assignment after service import
3. Regression tests for unmatched behavior (no false positives)

## Phase 4: Admin UI

Deliver:

1. `ListSongs` Livewire component + Blade view
2. `ShowSong` Livewire component + Blade view
3. Routes and navigation links

Tests:

1. Livewire feature tests for list/filter/sort/pagination
2. Livewire feature tests for detail page and usage history

## Phase 5: Hardening and Operations

Deliver:

1. Command metrics logging (import + linking)
2. Optional scheduler entry (weekly sync) behind config flag
3. Runbook additions (how to run sync, check unmatched items)

Tests:

1. Command output/metrics assertions
2. Smoke test for scheduled invocation

---

## Detailed File Plan

## New files (expected)

1. `app/Models/Song.php`
2. `app/Models/SongAuthor.php`
3. `app/Models/SongSourceRecord.php`
4. `app/Services/OpenLpLyricsParser.php`
5. `app/Services/SongCanonicalKeyGenerator.php`
6. `app/Services/SongCatalogSyncService.php`
7. `app/Services/ChurchServiceSongLinker.php`
8. `app/Console/Commands/SyncSongsCatalogCommand.php`
9. `app/Livewire/Admin/ChurchServices/ListSongs.php`
10. `app/Livewire/Admin/ChurchServices/ShowSong.php`
11. `resources/views/livewire/admin/church-services/list-songs.blade.php`
12. `resources/views/livewire/admin/church-services/show-song.blade.php`
13. migrations for all schema additions above
14. focused tests under `tests/Unit/...` and `tests/Feature/...`

## Existing files to update

1. `app/Models/ChurchServiceItem.php` (song relation)
2. `app/Http/Controllers/Api/ChurchServiceController.php` (invoke linker)
3. `app/Livewire/Admin/ChurchServices/UploadChurchService.php` (invoke linker)
4. `routes/web.php` (song admin routes)
5. `config/service-tracking.php` (songs config)
6. `.env.example` (path variable)
7. `bootstrap/app.php` (optional scheduling entry)
8. relevant admin blade pages for navigation links

---

## Testing Plan (Required)

Run with Sail:

1. `vendor/bin/sail artisan test --compact --filter=SongCanonicalKeyGenerator`
2. `vendor/bin/sail artisan test --compact --filter=OpenLpLyricsParser`
3. `vendor/bin/sail artisan test --compact --filter=SyncSongsCatalog`
4. `vendor/bin/sail artisan test --compact --filter=ChurchServiceSongLinker`
5. `vendor/bin/sail artisan test --compact --filter=AdminSong`
6. `vendor/bin/sail composer phpstan`
7. `vendor/bin/sail bin pint --dirty`

Before merge:

1. `vendor/bin/sail artisan test --parallel --compact`

---

## Rollout Plan

1. Deploy schema + code with feature hidden behind route availability (admin-only).
2. Set `OPENLP_SONGS_DB_PATH` in environment.
3. Run first dry-run sync.
4. Run full sync with `--link-services`.
5. Validate:
   - song count imported
   - percentage of service song items linked
   - top songs page shows expected results
6. Enable optional weekly scheduler only after first successful cycles.

Rollback:

1. Disable scheduler/command usage.
2. Keep imported tables (read-only impact, low risk).
3. Unlink service items by setting `song_id` null if required.

---

## Risks and Mitigations

1. Source duplicate keys map unexpected content  
   - Mitigation: deterministic merge + source traceability + duplicate metrics.
2. Lyrics XML parse edge cases  
   - Mitigation: preserve raw XML and non-fatal parser behavior.
3. Unlinked historical song items  
   - Mitigation: backfill linker + unmatched metrics report.
4. Query performance on usage leaderboard  
   - Mitigation: correct indexing first; add cached read model only if needed.

---

## Simplicity Check

What we intentionally avoid:

1. No separate `song_usages` event table.
2. No fuzzy matching in first release.
3. No edit workflows for song content.
4. No additional queue graph for import.

Why this is still "proper":

1. First-class `Song` domain exists.
2. Full content (lyrics/authors/metadata) is imported and queryable.
3. Usage stats come from real linked service records.
4. Source provenance is preserved.

---

## Acceptance Criteria

1. Admin can view a song list ranked by usage from service data.
2. Admin can open a song detail page and read lyrics + see authors.
3. New OpenLP service uploads automatically link song items to catalog songs when keys match.
4. Sync command can import/update/deactivate songs and print reliable metrics.
5. All tests and quality gates pass under Sail.

