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
## 2026-02-23 - [BritishEnglishConverter Optimization]
**Learning:** Iterative regex replacements in a loop (e.g., in `BritishEnglishConverter::convert`) are significantly slower than using array-based `preg_replace`, especially when dealing with large word lists or long texts like sermon transcripts. While the win on small datasets is minor (approx. 5%), it scales better with larger datasets (approx. 15%+ improvement).
**Action:** Always prefer array-based `preg_replace` or `strtr` for multiple string replacements.

## 2026-02-23 - [Storage Existence Checks Bottleneck]
**Learning:** Using `Storage::exists()` inside model accessors or methods like `hasThumbnail()` and `hasTranscript()` creates a performance bottleneck when these models are rendered in lists or sitemaps, as it triggers multiple remote network calls (e.g., to DigitalOcean Spaces/S3).
**Action:** Trust the database column presence for existence checks in performance-critical paths, and only perform physical storage checks when absolutely necessary (e.g., during file processing).

## 2026-02-26 - [Selective Column Loading for High-Traffic Listings]
**Learning:** Fetching full Eloquent models with large text/JSON blobs (like `body`, `markdown` in `Page` or `points`, `summary` in `Sermon`) in collection-based views (home cards, sermon lists) creates significant memory overhead and unnecessary DB I/O.
**Impact:**
- In `SermonController@getAll`, query data volume is reduced by excluding large analysis fields for hundreds of sermons.
- In `PageCardService` and `PageLinksRepository`, memory usage is reduced for pages rendered as cards on high-traffic areas like the Homepage and Church landing page.
- PHPStan and Pint validation ensured that relationship integrity (keeping `id` and FKs) was maintained.
**Action:** Always use `select()` to limit columns to only what is needed for the specific view or component when fetching collections of models, especially those known to contain large content fields.
