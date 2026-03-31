## 2025-05-15 - [Eager Loading ScripturePassage]
**Learning:** The `scripturePassage` relationship was being accessed via `displayReference()` in multiple performance-critical paths (listings, sitemaps, RSS feeds) without eager loading, causing N+1 queries.
**Action:** Always eager load `scripturePassage:id,display_reference,normalized_reference` and ensure `scripture_passage_id` is included in `select()` calls for all sermon collection queries.

## 2026-03-31 - [Config Caching in Services and Presenters]
**Learning:** Calling `config()` or `asset()` inside collection loops (like `map()`) can cause measurable overhead when processing large datasets (e.g., sitemap generation or "All Sermons" list).
**Action:** Resolve and store configuration values in class constructors for long-lived services like `SermonStorageService`. For presenters handling collection mapping, resolve static values or configuration once outside the loop and pass them in via `use`.
