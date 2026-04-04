## 2025-05-15 - [Eager Loading ScripturePassage]
**Learning:** The `scripturePassage` relationship was being accessed via `displayReference()` in multiple performance-critical paths (listings, sitemaps, RSS feeds) without eager loading, causing N+1 queries.
**Action:** Always eager load `scripturePassage:id,display_reference,normalized_reference` and ensure `scripture_passage_id` is included in `select()` calls for all sermon collection queries.

## 2026-03-31 - [Config Caching in Services and Presenters]
**Learning:** Calling `config()` or `asset()` inside collection loops (like `map()`) can cause measurable overhead when processing large datasets (e.g., sitemap generation or "All Sermons" list).
**Action:** Resolve and store configuration values in class constructors for long-lived services like `SermonStorageService`. For presenters handling collection mapping, resolve static values or configuration once outside the loop and pass them in via `use`.

## 2026-04-04 - [Attribute Caching and Bulk Sitemap Generation]
**Learning:** Computed Eloquent attributes (using the `Attribute` class) with complex logic or string manipulation (like `metaDescription` or `formattedDateTime`) can be called multiple times during a single request-response cycle, especially when passed to presenters or JSON resources. Additionally, calling `now()` inside a large sitemap loop generates thousands of unnecessary Carbon objects and system clock calls.
**Action:** Use `shouldCache()` on all computationally expensive computed model attributes. When generating large collections (like sitemaps), capture `now()` once before the loop and pass it as a parameter to any calculation methods (e.g., `toSitemapTag`) to reduce CPU overhead and memory churn.
