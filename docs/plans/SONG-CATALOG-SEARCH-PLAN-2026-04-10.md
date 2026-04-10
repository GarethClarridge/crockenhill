# Song Catalogue Search Plan (2026-04-10)

This plan covers a v1 search experience for the logged-in songs catalogue page.

The goal is to add a modern, reactive catalogue search without introducing new search infrastructure yet. We will use Laravel 12, Livewire 3, and MySQL-native search capabilities first, while keeping a clear upgrade path to Scout + Meilisearch later if typo tolerance becomes important.

## Scope

- Show the full song catalogue by default.
- Keep `All time` and `This year` as filters on the catalogue page.
- Search across title, alternate title, authors, CCLI, and lyrics.
- Update results live while typing.
- Persist search and filter state in the URL.
- Rank search results by relevance first, then by usage.
- Show highlighted lyric snippets only when the song matched in lyrics.
- Keep the page logged-in only for now.

## Confirmed Product Decisions

- Default page state:
  - Show the full catalogue.
  - Sort by all-time usage descending, then last sung date descending, then title.
- `This year` filter:
  - Acts as a true filter.
  - Shows only songs with qualifying usage in the current calendar year.
- Search behavior:
  - Require all query words in v1.
  - Order by relevance first once a search is active.
  - Do not claim typo tolerance in v1.
- Result presentation:
  - Show lyric snippets only for lyric matches.
  - Show up to 5 snippets per result.
  - Deduplicate repeated identical lines.
  - Preserve lyric line breaks.
  - Highlight matched words case-insensitively.
- Usage copy:
  - Songs with no qualifying usage should show `Not yet sung`.
- Empty states:
  - Differentiate between:
    - no catalogue matches for the current search
    - no songs sung this year

## Recommended Architecture

Use a new public Livewire component for the listing UI, while keeping the existing route and controller shell in place.

Reasoning:

- The page now needs live search, live filters, URL state, and pagination reset behavior.
- The app already uses Livewire 3 for reactive listings.
- The current controller-driven Blade page is a good shell, but it is no longer the right place to own reactive listing state.

Recommended shape:

- Keep `PublicSongListController@index` as a thin entry point that still handles the feature-flag guard and page metadata.
- Replace the current listing logic in `resources/views/church/songs/index.blade.php` with a public Livewire component such as `App\Livewire\Church\Songs\BrowseSongs`.
- Keep `PublicSongListController@show` as-is apart from any copy tweaks needed later.

## Search Strategy

V1 should stay database-backed and dependency-free.

### Why not Scout / Meilisearch yet

- The catalogue is ~1600 songs — well within range for a MySQL query.
- Defer external search infrastructure for now.
- Snippet generation can be handled server-side for the 24 visible results on each page.

### Database approach

Use a hybrid search strategy:

- MySQL full-text search on `songs.lyrics_plain` only (for lyric matching)
- `LIKE` matching for:
  - title and alternate title
  - author names via relationship query
  - CCLI number

Add a migration for a full-text index on `lyrics_plain` only.

### Query semantics

For a search like `amazing grace`, all tokens must be present somewhere across the searchable surface.

Behavior:

1. Normalize the search string into distinct tokens.
2. For each token, require at least one match in a grouped clause:
   - title (`LIKE`)
   - alternate title (`LIKE`)
   - authors (`LIKE` via relationship)
   - CCLI (`LIKE`)
   - lyrics (full-text)
3. Order results using the two-bucket approach described below.

This gives us:

- strict multi-word matching
- no false impression of fuzzy search
- predictable ordering that puts title matches first

## Ranking Plan

When no search is active:

- `usage_count DESC`
- `last_sung_date DESC`
- `songs.title ASC`

When search is active, use a two-bucket approach:

- **Bucket 1**: Songs where the query matched in title or alternate title (sorted by usage, then last sung, then title)
- **Bucket 2**: Songs where the query matched only in lyrics, authors, or CCLI (sorted by usage, then last sung, then title)

Implementation: a single `CASE WHEN` boolean in the `ORDER BY` — `0` for title/alternate-title matches, `1` for everything else — followed by the standard usage ordering.

This keeps title matches visually prominent without introducing a complex scoring system.

## Usage / Filter Semantics

The current page only shows songs with qualifying usage. That needs to change.

V1 target behavior:

- `range=all`, empty search:
  - show all non-deleted songs
  - include songs with `usage_count = 0`
- `range=year`, empty search:
  - show only songs with qualifying usage in the current year
- search + `range=all`:
  - search the full catalogue
- search + `range=year`:
  - search only songs sung this year

This means the current `whereExists(...)` behavior in `PublicSongUsageService::query()` is no longer sufficient for the catalogue page.

## Service Layer Plan

Do not keep growing `PublicSongUsageService` into a mixed “usage + catalogue search + snippet” class.

Preferred split:

- Keep `PublicSongUsageService` responsible for usage aggregation and per-song stats/history.
- Introduce a new public read-model service, for example:
  - `App\Services\PublicSongCatalogService`

Suggested responsibilities for the new service:

- build the base catalogue query
- attach usage aggregates for the selected range
- apply the `all` / `year` filter rules
- apply tokenized search filters
- compute relevance ordering

Add a second focused collaborator for snippet generation, for example:

- `App\Services\SongLyricSnippetBuilder`

Suggested responsibilities:

- extract matching lyric lines from `lyrics_plain`
- deduplicate repeated lines
- limit to 5 unique lines
- produce escaped, highlighted snippet fragments for the Blade view

## Livewire Plan

Create a public listing component rather than retrofitting the controller view with Alpine-only behavior.

Suggested component state:

- `public string $search = ''`
- `public string $range = 'all'`

Suggested URL behavior:

- map `search` to `q`
- keep `range`
- omit `q` when empty
- omit `range` when it is `all`

Suggested component behaviors:

- `wire:model.live.debounce.500ms` on the search input
- `updatedSearch()` resets pagination
- `updatedRange()` resets pagination
- pagination remains URL-aware

Suggested implementation note:

- Do not force the admin `WithFilterableListing` trait into this public component unless it remains cleanly generic.
- A small public-specific component may be easier to reason about than stretching an admin trait into a new context.

## UI / UX Plan

Use the current public songs page as the visual base and keep it aligned with `docs/design-style-guide.md`.

### Filters

- Keep the current hero/filter treatment.
- Add a shared `x-input` search field in the filter area.
- Keep the `All time` and `This year` controls visually prominent.
- Ensure mobile wrapping works cleanly.

### Result cards

Keep the existing card structure, then extend it carefully:

- retain title, usage, authors, last sung, and CTA
- add a lyric snippet block only when a lyric match exists
- show no extra hint for title/author/CCLI-only matches
- update zero-usage wording to `Not yet sung`

### Loading and empty states

- Add a subtle loading state during Livewire updates
- Empty states should vary by context:
  - empty search in `year` range: no songs sung this year
  - active search: no songs match the current search

### Accessibility

- Keep internal links on `wire:navigate`
- maintain visible focus styles
- ensure search is keyboard-usable
- ensure snippet highlighting does not rely on color alone

## Likely Target Files

Existing files likely to change:

- `app/Http/Controllers/PublicSongListController.php`
- `app/Services/PublicSongUsageService.php`
- `resources/views/church/songs/index.blade.php`
- `tests/Feature/PublicSongListTest.php`
- `tests/Feature/PublicSongListControllerTest.php`

Likely new files:

- `app/Livewire/Church/Songs/BrowseSongs.php`
- `resources/views/livewire/church/songs/browse-songs.blade.php`
- `app/Services/PublicSongCatalogService.php`
- `app/Services/SongLyricSnippetBuilder.php`
- migration adding the songs full-text index
- focused unit / Livewire tests for new search and snippet behavior

## Implementation Phases

### Phase 1: Query and data model preparation

- Add a full-text index migration on `songs.lyrics_plain`.
- Introduce the new public catalogue read-model service.
- Move catalogue query logic out of the controller.
- Preserve existing qualifying-usage logic as the source of truth for usage counts.

Exit criteria:

- The catalogue query can return:
  - full catalogue for `all` (including zero-usage songs)
  - only qualifying songs for `year`
  - usage aggregates for both

### Phase 2: Reactive public listing

- Add the Livewire component and Blade view.
- Move search/range/pagination state into Livewire.
- Keep URL query string behavior stable and shareable.
- Replace the old controller-passed paginator in the page view.

Exit criteria:

- Search and range update live
- page resets on filter changes
- URL reflects `q`, `range`, and page

### Phase 3: Two-bucket ordering and lyric snippets

- Add the two-bucket search ordering (title matches first, then others by usage).
- Add lyric snippet extraction and highlight rendering.
- Render snippets only for lyric matches.
- Deduplicate repeated lines and cap at 5.

Exit criteria:

- Title matches appear above lyric-only matches
- Lyric matches show readable, highlighted excerpts
- Title-only matches remain visually clean

### Phase 4: Empty states, polish, and verification

- Differentiate empty states by search/range context.
- Update zero-usage copy to `Not yet sung`.
- Verify mobile layout, focus states, and loading behavior.
- Run tests, PHPStan, and Pint.

Exit criteria:

- UX details are complete
- quality gates pass

## Test Plan

Every code change for this feature should be covered programmatically.

### Feature / Livewire coverage

- full catalogue is shown by default, including songs with zero usage
- default ordering uses all-time usage first
- `This year` excludes songs not sung this year
- changing search resets pagination
- changing range resets pagination
- URL keeps `q` and `range`
- search requires all words
- title matches appear above lyric-only matches (two-bucket ordering)
- author search works
- CCLI search works
- lyric search works
- title-only matches render no snippet block
- lyric matches render highlighted snippets
- snippets are deduplicated and limited to 5
- zero-usage songs render `Not yet sung`
- empty state differs between no yearly songs and no search matches

### Unit coverage

- token normalization for multi-word search
- two-bucket ordering logic
- lyric snippet extraction
- duplicate lyric-line suppression
- case-insensitive highlighting

## Quality Gates

- `vendor/bin/sail artisan test --compact <focused test paths>`
- `vendor/bin/sail composer phpstan`
- `vendor/bin/sail bin pint --dirty`

## Deferred / Future Improvements

These are explicitly out of scope for v1:

- typo tolerance
- external search infrastructure
- highlighting on the song detail page
- public rollout changes

If v1 search proves valuable and typo tolerance becomes important, revisit:

1. Laravel Scout adoption
2. Scout database engine as a low-friction intermediate step
3. Meilisearch for typo tolerance and richer ranking controls

## Definition of Done

- Logged-in users can browse the full catalogue by default.
- `This year` behaves as a true usage-based filter.
- Search is live, URL-backed, and paginated.
- Results search title, alternate title, authors, CCLI, and lyrics.
- Search requires all words in v1.
- Active search puts title matches above lyric-only matches, then orders by usage.
- Lyric matches show highlighted snippets with up to 5 unique lines.
- Title-only matches do not show snippet UI.
- Zero-usage songs show `Not yet sung`.
- Contextual empty states are in place.
- Focused tests, PHPStan, and Pint all pass.
