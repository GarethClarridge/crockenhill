## 2025-05-15 - [Eager Loading ScripturePassage]
**Learning:** The `scripturePassage` relationship was being accessed via `displayReference()` in multiple performance-critical paths (listings, sitemaps, RSS feeds) without eager loading, causing N+1 queries.
**Action:** Always eager load `scripturePassage:id,display_reference,normalized_reference` and ensure `scripture_passage_id` is included in `select()` calls for all sermon collection queries.
