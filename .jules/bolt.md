# Bolt's Journal - Church Website Performance

## 2025-05-22 - [Eager Loading for Sermons and Pages]
**Learning:** Found N+1 query patterns in SermonController and SitemapService where preacherProfile and media relationships were accessed for collections of models.
**Impact:**
- In `SermonController@getAll`, the number of queries is reduced from `1 + N` to `2`, where `N` is the number of sermons.
- In `SitemapService@generate`, the number of queries is reduced from `1 + N + M` to `3` (for sermons and pages), where `N` is sermons and `M` is pages.
- Measurably faster page loads for sermon listings and faster sitemap generation.
**Action:** Always check if relationships used in components (like sermon-card) or sitemap generation are eager-loaded in the controller or service.

## 2026-02-19 - [N+1 Optimizations in Podcast Feeds and Page Views]
**Learning:** Identified N+1 query patterns in `PodcastFeedService` (preacherProfile) and `ViewServiceProvider` (media relationship for Page models used in related links).
**Impact:**
- `PodcastFeedService@fetchSermons`: Reduced queries from 100+ to 2 per feed load.
- `ViewServiceProvider`: Reduced queries for every page with related links from 6 to 2.
- Added mandatory PHPDoc explaining these optimizations.
**Action:** Be cautious when removing view composers during optimization as they may be required by legacy templates not easily found by grep. Always add descriptive PHPDoc to explain why an eager load was added.

## 2026-02-20 - Admin Page List N+1 and Memory Optimization
**Learning:** The 'ListPages' admin component was fetching full 'body' and 'markdown' columns for every row and performing N+1 queries for 'media' and 'meeting' relationships.
**Action:** Use 'select()' to exclude large columns and 'with()' to eager load relationships in Livewire list components. This reduced query count from 22 to 4 for 10 items.

## 2026-02-26 - Sitemap Memory Optimization
**Learning:** `SitemapService` was fetching all columns for `Sermon`, `Page`, and `Meeting` models, including large text/JSON blobs like `summary`, `points`, `body`, and `markdown` which are not needed for sitemap tags.
**Action:** Use `select()` to limit columns for all models in the sitemap generation process. For `Sermon`, also use column selection on eager-loaded `preacherProfile` relationship. This reduces memory footprint and database pressure, especially as the content grows.
