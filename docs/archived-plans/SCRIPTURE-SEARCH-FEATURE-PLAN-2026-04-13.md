# Plan: Scripture Search and Sermon Browse

## Objective
Add book and chapter browsing to `/christ/sermons/all` without creating a second competing scripture identity path, while preserving the current grouped-by-date browse experience when no filters are active.

## Confirmed Product Decisions
These choices are now treated as fixed for this implementation plan.

1. Multi-passage and cross-chapter references should appear under every covered book and chapter.

2. `/christ/sermons/all` should keep its current grouped-by-date chronology when no filters are active.

3. Book and chapter enabled and disabled states should reflect the current preacher and series filters.

4. The chapter control should stay visible but disabled until a book is chosen.

5. Filtered URLs are shareable only. `/christ/sermons/all` remains the canonical URL.

6. Sermons with empty or unparseable references should remain in unfiltered results but be excluded from scripture filters.

7. Book labels should use canonical parser-aligned names.

8. Filter changes should use Livewire's default replace-state URL behavior rather than filling browser history.

## Implementation Defaults
- Book and chapter matching is comprehensive across all parsed covered chapters, not "first passage only."
- Book options are shown in canonical Bible order.
- Filtered states are shareable but not separately canonicalized or indexed.
- The unfiltered page remains the canonical SEO representation of `/christ/sermons/all`.
- Filtered queries are not cached. The index table with appropriate indexes makes them fast enough, and the combinatorial key space (66 books x chapters x preachers x series) makes caching impractical and creates stale-data risk.

## Current-State Notes
- Sermon scripture identity already has a canonical-vs-cache boundary: `scripture_passage_id` is canonical and `reference` is a synchronized cache.
- Pre-save sermon identity normalization already runs in `SermonIdentityObserver` via `SermonIdentitySyncService`.
- Post-save sermon side effects already run in `SermonObserver`, which is registered in `ModelObserverServiceProvider`.
- `/christ/sermons/all` is currently a controller-driven cached grouped listing from `SermonRepository::getAllSermons()`.
- `ScriptureReferenceResolver::normalize()` currently discards multi-passage data — it only returns the first parsed passage. The indexing service must NOT depend on post-normalized output for multi-passage support.
- Current data snapshot on 2026-04-13:
  - `710` total sermons
  - `656` sermons with a non-empty `reference`
  - `39` references that look multi-segment or cross-chapter
  - `29` explicit cross-chapter ranges
  - `5` sermons currently linked to `scripture_passage_id`

## Scope Boundary: Existing Sermon Pages
This plan only modifies `/christ/sermons/all`. The existing preacher, series, service, and index pages remain unchanged.

Keep:
- `/christ/sermons` — index page with latest sermons and "Find older sermons" CTA
- `/christ/sermons/preachers` — preacher directory with sermon counts
- `/christ/sermons/preachers/{slug}` — single-preacher sermon list (has `x-schema.person` structured data and profile image OG tags)
- `/christ/sermons/series` — series directory
- `/christ/sermons/series/{name}` — single-series sermon list
- `/christ/sermons/morning|evening|other` — service-specific pages (has podcast RSS `<link>` tags)

Future opportunity: once the browse component is proven in production, evaluate whether single-preacher and single-series detail pages should 301-redirect to `/christ/sermons/all` with pre-applied filter query parameters. This is a separate, smaller follow-up, not part of this plan.

## Recommendation
Use a derived scripture filter index table instead of adding ambiguous `bible_book` and `bible_chapter` columns directly to `sermons`.

Recommended table: `sermon_scripture_filters`

Why this is the better default:
- It avoids false negatives for cross-chapter and multi-segment references.
- It fits the current architecture, where scripture identity is already normalized before save and side effects run after save.
- It avoids introducing generic column names on `sermons` that would actually mean "primary opening passage only."
- The data volume is tiny enough that an indexed lookup table is operationally cheap.

If product intent explicitly prefers "primary preaching text only" semantics to minimize scope, the fallback design is to add `primary_bible_book` and `primary_bible_chapter` columns on `sermons`.
Do not call those columns `bible_book` and `bible_chapter`, because those names imply complete coverage.

## Proposed Architecture

### 1. Extend `x-select` for Disabled Options
Update the shared select component so options may include a `disabled` flag.

Expected option shape:
- `id`
- `name`
- `disabled` (optional boolean)

This remains backwards-compatible with current component usage.

Known UX debt: Native `<select>` with many disabled options (66 Bible books, most disabled) is hard to navigate with keyboard. A filtered/searchable combobox using Alpine.js would be better long-term. This does not block v1 but should be revisited if user feedback indicates friction.

### 2. Add a Bible Canon Wrapper Over Parser Data
Create an app-owned support class such as `App\Support\BibleCanon`.

Responsibilities:
- Provide the ordered list of all 66 books.
- Provide maximum chapter count per book.
- Generate canonical-order book options for the filter UI.
- Generate chapter options for a selected book.

Implementation approach:
- Read canon data from the parser's own structure file (`vendor/techwilk/bible-verse-parser/data/bibleStructure.php`), which is the same data the `BiblePassageParser` constructor loads. This is a package-internal file (not a documented public API), so this coupling must be explicitly guarded.
- Wrap it in an app-owned API rather than hardcoding a second copy of Bible data.
- This keeps book names, ordering, and chapter counts in sync with parser output by construction, eliminating drift risk from maintaining a separate copy.
- The wrapper provides the option-building, ordering, and chapter-expansion APIs that the filter UI and indexing service need.

Guarding the vendor coupling:
- The constructor should validate that the loaded structure has the expected shape: an array of 66 entries, each with `name` (string), `abbreviations` (array), and `chapterStructure` (array). Throw a clear `RuntimeException` if the shape has changed, so a package upgrade surfaces immediately rather than causing silent corruption.
- Pin `techwilk/bible-verse-parser` to a tight version constraint in `composer.json` (e.g., `^1.x` or the current minor). This prevents accidental major upgrades.
- Add a dedicated unit test that constructs both `BibleCanon` and `BiblePassageParser`, parses a reference for every book (`Genesis 1:1` through `Revelation 1:1`), and asserts the parser's returned book name matches `BibleCanon`'s name for that book. This test breaks on upgrade if names diverge.

Key methods needed:
- `allBooks(): array` — ordered list of `['number' => int, 'name' => string, 'chapters' => int]`
- `chaptersInBook(string $bookName): int`
- `bookOptions(Collection $enabledBooks): array` — for the filter UI, with disabled flags
- `chapterOptions(string $bookName, Collection $enabledChapters): array` — for the filter UI, with disabled flags
- `expandPassageToChapters(BiblePassage $passage): array` — returns all `['book' => string, 'chapter' => int]` pairs covered by the passage, including cross-book spans

Cross-book expansion algorithm for `expandPassageToChapters`:
1. Get the from-book number and from-chapter.
2. Get the to-book number and to-chapter.
3. Iterate through every book number from from-book to to-book (using the parser's ordered structure data).
4. For the first book, include chapters from from-chapter to the book's max chapter.
5. For intermediate books, include all chapters.
6. For the last book, include chapters from 1 to to-chapter.
7. For same-book passages, include chapters from from-chapter to to-chapter.

Tests should verify:
- All 66 books are present and in canonical order.
- Chapter counts match parser expectations.
- Single-chapter passages expand to one pair.
- Cross-chapter passages expand to each covered chapter.
- Cross-book passages expand through all intervening books and chapters.
- Book names returned by the wrapper match book names returned by parser output (parser alignment test: parse a reference for each book and compare names).

### 3. Add a Derived Filter Index Table and Model
Create a migration for `sermon_scripture_filters`.

Suggested columns:
- `id`
- `sermon_id` foreign key, cascade on delete
- `bible_book` string(50)
- `bible_chapter` unsigned small integer
- timestamps

Suggested indexes:
- unique index on `(sermon_id, bible_book, bible_chapter)`
- index on `(bible_book, bible_chapter, sermon_id)`
- optional index on `(bible_book, sermon_id)` if book-only queries need help

Create an Eloquent model `App\Models\SermonScriptureFilter` with:
- `fillable`: `sermon_id`, `bible_book`, `bible_chapter`
- `belongsTo` relationship to `Sermon`
- Corresponding `hasMany` relationship on `Sermon` model

Create a factory `SermonScriptureFilterFactory` for test usage.

Notes:
- No new book and chapter columns are added to `sermons` in the recommended design.
- This table stores derived browse facts, not canonical scripture identity.

### 4. Add a Scripture Filter Indexing Service
Create `App\Services\SermonScriptureFilterIndexService`.

Responsibilities:
- Parse a sermon's raw `reference` field using `BiblePassageParser`.
- Expand each parsed passage into all covered `(book, chapter)` pairs using `BibleCanon::expandPassageToChapters()`.
- Rebuild the filter rows for one sermon at a time.
- Delete rows when the sermon is not a public sermon, has no reference, or the reference is unparseable.

Suggested public methods:
- `entriesForReference(?string $reference): array`
- `syncForSermon(Sermon $sermon): void`

Important behavior:
- Parse the sermon's `reference` field directly using `BiblePassageParser::parse()`. Do NOT use `ScriptureReferenceResolver::normalize()`, which discards all passages after the first. The indexing service needs all passages to fulfill product decision #1 (multi-passage coverage).
- For a passage such as `John 15:9-16:3`, index both `John 15` and `John 16`.
- For multi-segment references such as `Mark 8:31-33, 9:30-37, 10:32-34`, index all three chapters.
- For cross-book ranges, use `BibleCanon::expandPassageToChapters()` to enumerate all covered books and chapters.
- Deduplicate `(book, chapter)` pairs before writing rows — a multi-segment reference may cover the same chapter from different segments.

### 5. Integrate with the Existing Observer Flow
Keep the current observer architecture intact.

Do:
- Leave `SermonIdentityObserver` responsible for pre-save normalization.
- Extend the existing `SermonObserver::saved()` flow to rebuild scripture filter rows after commit.
- Keep observer registration in `ModelObserverServiceProvider`.

Do not:
- Register new sermon observers in `AppServiceProvider`.
- Put derived filter-row writes in a `saving` hook.
- Create a second independent scripture normalization path beside `SermonIdentitySyncService`.

Guard the rebuild with a dirty-check to avoid unnecessary churn on unrelated saves (e.g., title edits, download count increments):

```php
public function saved(Sermon $sermon): void
{
    // Existing children's talk logic...

    if ($sermon->wasRecentlyCreated || $sermon->wasChanged(['reference', 'content_type'])) {
        app(SermonScriptureFilterIndexService::class)->syncForSermon($sermon);
    }
}
```

Expected save flow:
1. `SermonIdentityObserver` normalizes `reference` and `scripture_passage_id`.
2. Sermon saves successfully.
3. `SermonObserver` runs after commit.
4. If `reference` or `content_type` changed (or the sermon was just created), `SermonScriptureFilterIndexService` deletes and rebuilds that sermon's derived filter rows.

### 6. Add an Idempotent Backfill and Repair Command
Create `sermons:sync-scripture-filters`.

Responsibilities:
- Rebuild derived scripture filter rows from current sermon references.
- Repair stale rows by deleting and recreating them.
- Report counts for indexed, cleared, unparseable, and skipped sermons.

Suggested options:
- `--only-missing`
- `--sermon=ID`
- `--dry-run`

Recommendation:
- Make the default behavior repair-oriented and idempotent.
- Run this before or during rollout so the feature does not ship with mostly empty filter data.

### 7. Add the Public Browse Livewire Component
Create `App\Livewire\Sermons\BrowseSermons` and a matching Blade view.

Suggested URL-bound properties:
- `public ?string $bookFilter = null`
- `public ?int $chapterFilter = null`
- `public ?int $preacherFilter = null`
- `public ?string $seriesFilter = null`

Behavior:
- `updatedBookFilter()` resets `chapterFilter` and page.
- Other filter updates reset page.
- `clearFilters()` resets all filters and page.
- Use `WithPagination`.
- Run in two modes:
  - unfiltered mode: render the existing grouped-by-date browse view
  - filtered mode: render flat paginated results

Recommended query-string behavior:
- Readable parameter names such as `book`, `chapter`, `preacher`, and `series`.
- Use default replace-state browser history.

### 8. Build Query Logic on Top of the Existing Repository Shape
Do not bypass the repository entirely.

Recommended approach:
- Unfiltered mode:
  - reuse `SermonRepository::getAllSermons()`
  - preserve current grouped-by-date rendering and cache behavior
- Filtered mode:
  - reuse `SermonRepository::publicSermonQuery()` for the base query
  - add scripture filtering via `whereExists` or an equivalent constrained subquery against `sermon_scripture_filters`
  - keep existing eager loading for `preacherProfile` and `scripturePassage`
  - do NOT cache filtered results — the index makes them fast, and the combinatorial key space makes caching impractical

Filtered results query shape:
- Start from public sermons only.
- Filter by preacher and series directly on `sermons`.
- Filter by book and chapter through the derived index table.
- Order by `date desc`.
- Paginate with `24` per page.

Why this matters:
- It preserves the app's existing public sermon query conventions.
- It preserves the current `/all` page experience when no filters are active.
- It avoids duplicating list-select logic in another public read path.

### 9. Compute Filter Options from Truthful Data
Book and chapter option availability should be derived from the filter index table, not from raw sermon references.

Recommended behavior:
- Book options:
  - all 66 books in canonical order
  - enabled if at least one currently eligible sermon matches that book
  - eligibility should reflect active preacher and series filters
- Chapter options:
  - all chapters for the selected book
  - enabled if at least one currently eligible sermon matches that chapter
  - eligibility should reflect selected book plus active preacher and series filters

Preacher options:
- Continue using `Preacher::getForPublicList()`
- If product wants option states to reflect active scripture filters, that can be a later enhancement

Series options:
- Continue using `SermonRepository::getSeriesForDisplay()`
- If product wants option states to reflect active scripture filters, that can be a later enhancement

### 10. Update the Public Page Shell and Handle JSON-LD
Update `SermonController::all()` so it renders the page shell only.

Keep:
- title
- description
- links
- WebPage-level metadata

JSON-LD handling:
- The current `all()` controller builds a canonical `ItemList` from `$sermons` and passes it to the view. When the sermon list moves into a Livewire component, the controller no longer has the sermon data at render time.
- Decision: the controller continues to call `SermonRepository::getAllSermons()` and builds the full canonical `ItemList` JSON-LD, same as today. This call is already cached (24-48h flexible), so there is no additional cost. The full 710-sermon ItemList is what currently ships and is already indexed by Google — changing it would be a regression.
- The JSON-LD represents the canonical unfiltered page state. It does NOT change when Livewire filters are applied — this matches the decision that filtered states are browse-only, not separately indexed.

Remove or change:
- do not treat filtered Livewire states as separate SEO pages

Recommendation:
- Keep unfiltered page SEO static and canonical
- Treat filtered states as browse-only, not as separately indexed pages

### 11. Update `resources/views/sermons/all.blade.php`
Replace the current grouped listing with the new Livewire component.

Recommended page behavior:
- show filter bar above results
- when no filters are active, render the grouped-by-date sermon browse view
- when any filter is active, render flat paginated sermon cards
- use existing `x-sermon-card` for cards
- show a clear empty state when no sermons match
- show pagination only in filtered mode

### 12. UI Behavior Recommendations
Book filter:
- canonical Bible order
- all books shown
- disabled books remain visible for discoverability

Chapter filter:
- visible but disabled until a book is chosen
- once a book is selected, all chapters are shown
- chapters with no matching sermons are disabled

Clear behavior:
- clear button only appears when at least one filter is active
- clearing filters resets pagination to page 1 and returns the page to grouped unfiltered mode

Loading and empty states:
- disable controls during dependent updates where useful
- show a compact loading state for filtered results
- show a clear no-results state with a reset action

### 13. Testing Plan
Add or update tests in four groups.

Feature or Livewire tests for public browsing:
- no filters shows grouped-by-date results
- no filters preserves current grouped browse markup
- book filter returns matching sermons
- book and chapter filter returns matching sermons
- preacher filter returns matching sermons
- series filter returns matching sermons
- combined filters return intersections
- changing book resets chapter
- clear filters resets everything
- clear filters returns to grouped unfiltered mode
- URL state round-trips correctly
- disabled book options render
- disabled chapter options render
- empty state renders correctly

Unit tests for `BibleCanon`:
- all 66 books are present in order
- chapter counts are correct
- book names match parser output
- option-building marks enabled and disabled states correctly
- single-chapter passage expansion returns one pair
- cross-chapter passage expansion returns all covered chapters
- cross-book passage expansion returns all intervening books and chapters

Unit tests for `SermonScriptureFilterIndexService`:
- single-chapter references index one chapter
- cross-chapter references index each covered chapter
- multi-segment references index each segment
- cross-book references index each intervening book and chapter
- duplicate chapters from overlapping segments are deduplicated
- empty and unparseable references clear rows
- childrens talks or non-public content do not keep browse rows

Unit tests for `SermonScriptureFilter` model:
- factory creates valid records
- relationship to Sermon works

Feature tests for the command:
- backfills missing rows
- repairs stale rows
- reports counts accurately
- `--dry-run` does not write
- `--sermon=ID` scopes to one sermon

Existing tests that should be updated:
- `SermonControllerTest`
- `SermonPagesTest`
- `SermonBrowseSeoTest`
- `SermonListingCacheTest`

Important expectations to preserve:
- `all_sermons` cache remains meaningful for the unfiltered page
- canonical `/christ/sermons/all` SEO remains tied to the unfiltered experience

Estimated test files: 3-4 new test files, 4 updated test files.

### 14. Rollout Plan
1. Ship migration, model, indexing service, and observer integration.
2. Run `sermons:sync-scripture-filters` in production before exposing the UI.
3. Ship the Livewire browse UI.
4. Monitor unparseable-reference counts from the command output.
5. If needed, add an admin cleanup flow for malformed historical references later.

Note on deployment: steps 1 and 3 ship in the same deployment. The browse component gracefully handles partial index data — if the backfill hasn't completed yet, scripture filters simply return fewer results (never incorrect results). The observer starts indexing new saves immediately. Running the backfill command promptly after deployment fills in historical data.

## Scope Summary
Expected files and change areas in the recommended plan:

| Area | Work |
|---|---|
| `x-select` | minor edit |
| `BibleCanon` support class | 1 new file (wrapper over parser data) |
| scripture filter index migration | 1 new file |
| `SermonScriptureFilter` model | 1 new file |
| `SermonScriptureFilterFactory` | 1 new file |
| `SermonScriptureFilterIndexService` | 1 new file |
| `SermonObserver` integration | minor edit |
| `Sermon` model (hasMany relationship) | minor edit |
| backfill command | 1 new file |
| `BrowseSermons` Livewire component and view | 2 new files |
| `SermonController::all()` | minor edit |
| `all.blade.php` | minor edit |
| tests | 3-4 new files, 4 updated files |

## Out of Scope
- Verse-level searching
- Full-text scripture searching
- Filter-specific landing pages and canonical rules
- Automatic repair of malformed historical references beyond index rebuild output
- Reworking preacher and series pages (see "Scope Boundary" section for future consolidation opportunity)
- Consolidating preacher, series, or service detail pages into the browse component
- Searchable/filterable combobox for book selection (noted as UX debt, revisit based on user feedback)

## Quality Gates
Before implementation is considered complete:
- run focused tests for new browse behavior
- run `vendor/bin/sail composer phpstan`
- run `vendor/bin/sail bin pint --dirty`
