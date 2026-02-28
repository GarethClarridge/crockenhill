# Song Catalog and Usage Tracking Plan (Revised V1)

## Overview

This revised plan implements the deferred song import enhancement with a proper first-class `Song` domain, while keeping v1 lean for the current reality:

1. Single source database (`songs (1).sqlite`)
2. 1,166 source songs
3. Small duplicate-key set
4. Admin-only usage

Core outcomes:

1. Import song content (including lyrics/authors)
2. Link service song items to canonical songs
3. Provide admin UI for most-used songs and song details

---

## Goals

1. Add a canonical `Song` model with enough content for real display (lyrics, authors, metadata).
2. Keep matching deterministic and explainable.
3. Derive usage directly from `church_service_items` (no duplicate usage ledger).
4. Avoid speculative schema/workflow complexity in v1.

## Non-goals (v1)

1. Manual resolver UI for ambiguous matches.
2. Fuzzy/AI-based matching.
3. Public-facing song pages.
4. Song editing workflow in admin.
5. Multi-source import abstraction.

---

## Key Decisions from Review

1. `song_source_records` table is removed for v1.
2. `songs` schema is reduced to columns with direct UI/reporting value.
3. Canonical key generation is a static model method (`Song::canonicalizeKey()`), not a standalone service.
4. Lyrics parsing stays as a dedicated service (`OpenLpLyricsParser`).
5. Command responsibilities are split:
   - `service-tracking:sync-songs`
   - `service-tracking:link-songs`
6. Songbook data is imported in v1.

---

## Current State Summary

1. OpenLP service import already stores `openlp_search_title` on `church_service_items`.
2. Parser currently stores full OpenLP song key from `header.data.title`.
3. That key includes `@` segments (example: `who am i that the highest king@who you say i am`).
4. Source DB has `songs`, `authors`, `authors_songs`, `song_books`, `songs_songbooks`.

Critical implication:

1. Canonical linking must use the full key including the `@...` suffix.
2. Do not split on `@` in v1 matching.

---

## Data Model

## 1) `songs` table (new)

Canonical app songs.

Columns:

1. `id` (bigint PK)
2. `canonical_key` (string unique)
3. `title` (string)
4. `alternate_title` (string nullable)
5. `lyrics_xml` (longText)
6. `lyrics_plain` (longText nullable)
7. `verse_order` (string nullable)
8. `copyright` (string nullable)
9. `comments` (longText nullable)
10. `ccli_number` (string nullable)
11. `import_metadata` (json nullable)  
    - includes source IDs, duplicate merge info, parse warnings
12. timestamps
13. `deleted_at` (soft deletes)

Indexes:

1. unique(`canonical_key`)
2. index(`ccli_number`)
3. index(`deleted_at`)

Notes:

1. No `lyrics_structure` in v1.
2. No `search_lyrics` in v1.
3. No `search_title_raw` in v1.
4. No `is_active` in v1 (soft deletes are the lifecycle mechanism).
5. No `theme_name` in v1.

## 2) `song_authors` table (new)

Columns:

1. `id` (bigint PK)
2. `display_name` (string unique)
3. `first_name` (string nullable)
4. `last_name` (string nullable)
5. timestamps

## 3) `song_author_song` table (new pivot)

Columns:

1. `song_id` FK cascade
2. `song_author_id` FK cascade
3. `author_type` (string default `''`)

Constraints:

1. unique(`song_id`, `song_author_id`, `author_type`)

## 4) `song_books` table (new)

Columns:

1. `id` (bigint PK)
2. `source_book_id` (unsigned bigint unique)
3. `name` (string)
4. `publisher` (string nullable)
5. timestamps

## 5) `song_book_song` table (new pivot)

Columns:

1. `song_id` FK cascade
2. `song_book_id` FK cascade
3. `entry` (string)

Constraints:

1. unique(`song_id`, `song_book_id`, `entry`)

## 6) `church_service_items` update

Add:

1. `song_id` nullable FK to `songs.id` (`nullOnDelete`)

Indexes:

1. index(`song_id`)
2. index(`type`, `song_id`)

---

## Domain Models

## New models

1. `App\Models\Song`
2. `App\Models\SongAuthor`
3. `App\Models\SongBook`

## Model updates

1. `App\Models\ChurchServiceItem` adds `belongsTo(Song::class)`

## Song model helpers

Add static helper:

```php
public static function canonicalizeKey(string $value): string
```

Behavior:

1. `trim`
2. `lowercase`
3. collapse internal whitespace

No punctuation/symbol stripping in v1 (including `@`).

---

## Import Architecture

## Config

Extend `config/service-tracking.php`:

```php
'songs' => [
    'sqlite_path' => env('OPENLP_SONGS_DB_PATH'),
],
```

`.env.example`:

```ini
OPENLP_SONGS_DB_PATH=
```

## Command 1: Sync Catalog

`service-tracking:sync-songs {--path=} {--dry-run}`

Responsibilities:

1. Load source songs/authors/songbooks from SQLite.
2. Build canonical song map by `Song::canonicalizeKey(source search_title)`.
3. Merge duplicate source rows sharing the same canonical key.
4. Upsert `songs`, `song_authors`, `song_author_song`, `song_books`, `song_book_song`.
5. Emit metrics summary.

Duplicate merge strategy:

1. Group by canonical key.
2. Choose representative row by newest source `last_modified`.
3. Store all grouped source song IDs in `songs.import_metadata.source_song_ids`.
4. Store duplicate count in metadata.

Missing-source handling in v1:

1. Do not auto-delete/soft-delete songs during sync.
2. Keep lifecycle simple and non-destructive.
3. Add explicit pruning behavior only if/when needed later.

## Command 2: Link Service Items

`service-tracking:link-songs {--dry-run}`

Responsibilities:

1. Process active song items (`type='songs'`, `deleted_at IS NULL`).
2. Canonicalize `openlp_search_title`.
3. Match exactly against `songs.canonical_key`.
4. Set `church_service_items.song_id` when deterministic match exists.
5. Leave unmatched rows with `song_id = null`.
6. Emit metrics summary.

No fallback to `source_title` in v1 to avoid false positives.

---

## Lyrics Handling

Create `App\Services\OpenLpLyricsParser`.

Input:

1. OpenLP XML in source `songs.lyrics`

Output:

1. `lyrics_plain` for display/search

Behavior:

1. Parse XML safely.
2. Extract verse text from lyric XML.
3. Preserve verse order in flattened plain text.
4. Fail gracefully; keep `lyrics_xml` even if parsing fails.
5. Record parse warnings in `songs.import_metadata`.

This service remains separate because it is non-trivial and test-worthy.

---

## Service Import Integration

After each OpenLP service import:

1. Continue parser + service item sync flow as-is.
2. Run linker service for that service's song items only.

Touch points:

1. `ChurchServiceController@store`
2. `UploadChurchService::save`

This ensures newly imported services are link-at-ingest, while `service-tracking:link-songs` handles historical backfill.

---

## Admin UI (Livewire)

## 1) Most Used Songs

Route:

1. `GET /admin/services/songs` -> `App\Livewire\Admin\ChurchServices\ListSongs`

Columns:

1. title
2. authors
3. usage count (song service items)
4. distinct services count
5. last used date

Filters:

1. search (title, alternate title, author, CCLI)
2. service slot (`morning`/`evening`/`other`)
3. date range (optional if easy in v1)

## 2) Song Detail

Route:

1. `GET /admin/services/songs/{song}` -> `App\Livewire\Admin\ChurchServices\ShowSong`

Content:

1. song metadata
2. authors
3. songbook entries
4. full lyrics (`lyrics_plain`, with fallback note if parse warning)
5. recent usage history linked to service detail pages

## 3) Navigation

Add links from existing service admin pages to song pages.

---

## Usage Query Strategy

Usage remains derived from existing linked service items.

Metrics:

1. `usage_count`: count of active `church_service_items` per `song_id`
2. `services_count`: count distinct `church_service_id`
3. `last_used_date`: max related `church_services.date`

No dedicated `song_usages` table in v1.

---

## Implementation Phases

## Phase 1: Schema + Models

Deliver:

1. Migrations:
   - `songs`
   - `song_authors`
   - `song_author_song`
   - `song_books`
   - `song_book_song`
   - add `church_service_items.song_id`
2. Models and relationships.
3. `Song::canonicalizeKey()`.

Tests:

1. model relationship tests
2. canonicalization tests on `Song` model
3. schema/index/FK tests

## Phase 2: Import + Parsing

Deliver:

1. `OpenLpLyricsParser`
2. `SongCatalogSyncService`
3. `service-tracking:sync-songs`

Tests:

1. lyrics parser unit tests
2. sync command feature tests:
   - happy path
   - dry-run
   - invalid path
   - duplicate key merge behavior
   - author/songbook import

## Phase 3: Linking

Deliver:

1. `ChurchServiceSongLinker`
2. `service-tracking:link-songs`
3. integration after service upload (API + Livewire)

Tests:

1. linker unit tests
2. feature tests for service-upload linking
3. feature tests for full backfill linking command
4. explicit `@` key parity tests between imported songs and service items

## Phase 4: Admin UI

Deliver:

1. `ListSongs` Livewire + Blade
2. `ShowSong` Livewire + Blade
3. route/navigation updates

Tests:

1. Livewire list filter/sort/pagination
2. Livewire detail content/usage assertions

## Phase 5: Hardening

Deliver:

1. command metrics and logging polish
2. operational notes/runbook additions

Tests:

1. command metric assertions
2. regression checks around linking idempotency

---

## Detailed File Plan

## New files (expected)

1. `app/Models/Song.php`
2. `app/Models/SongAuthor.php`
3. `app/Models/SongBook.php`
4. `app/Services/OpenLpLyricsParser.php`
5. `app/Services/SongCatalogSyncService.php`
6. `app/Services/ChurchServiceSongLinker.php`
7. `app/Console/Commands/SyncSongsCommand.php`
8. `app/Console/Commands/LinkSongsCommand.php`
9. `app/Livewire/Admin/ChurchServices/ListSongs.php`
10. `app/Livewire/Admin/ChurchServices/ShowSong.php`
11. `resources/views/livewire/admin/church-services/list-songs.blade.php`
12. `resources/views/livewire/admin/church-services/show-song.blade.php`
13. new migrations listed above
14. focused tests under `tests/Unit` and `tests/Feature`

## Existing files to update

1. `app/Models/ChurchServiceItem.php`
2. `app/Http/Controllers/Api/ChurchServiceController.php`
3. `app/Livewire/Admin/ChurchServices/UploadChurchService.php`
4. `routes/web.php`
5. `config/service-tracking.php`
6. `.env.example`
7. relevant admin navigation views

---

## Testing and Quality Gates

Run with Sail:

1. `vendor/bin/sail artisan test --compact --filter=Song`
2. `vendor/bin/sail artisan test --compact --filter=SyncSongs`
3. `vendor/bin/sail artisan test --compact --filter=LinkSongs`
4. `vendor/bin/sail artisan test --compact --filter=AdminSong`
5. `vendor/bin/sail composer phpstan`
6. `vendor/bin/sail bin pint --dirty`

Before merge:

1. `vendor/bin/sail artisan test --parallel --compact`

---

## Rollout

1. Deploy migrations + code.
2. Set `OPENLP_SONGS_DB_PATH`.
3. Run dry-run sync:
   - `vendor/bin/sail artisan service-tracking:sync-songs --dry-run`
4. Run actual sync:
   - `vendor/bin/sail artisan service-tracking:sync-songs`
5. Backfill links:
   - `vendor/bin/sail artisan service-tracking:link-songs`
6. Verify:
   - imported songs count
   - linked service song item count
   - top songs UI correctness

---

## Acceptance Criteria

1. Songs are imported with lyrics and authors from source SQLite.
2. Songbooks and entries are imported and visible in song detail context.
3. Service song items can be linked deterministically to songs via canonical keys.
4. Admin can view most-used songs and per-song detail with lyrics and usage history.
5. Plan remains lean: no `song_source_records`, no usage ledger table, no fuzzy matching.

