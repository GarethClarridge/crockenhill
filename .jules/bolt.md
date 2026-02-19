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
