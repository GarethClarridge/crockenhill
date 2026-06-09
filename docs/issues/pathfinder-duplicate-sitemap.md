# 🔗 Pathfinder: Duplicate sitemap entry for sermons index

- **Summary:** The URL `/christ/sermons` is listed twice in `sitemap.xml`.
- **Affected items:** `http://localhost/christ/sermons` (2 instances).
- **Verification:** Ran `SitemapService::generate()` and parsed `<loc>` tags using a diagnostic script. The duplicate entries were confirmed in the generated XML.
- **Likely cause:** `SitemapService::addStaticUrls` adds `/christ/sermons` manually (from `route('sermons.index')`). However, `addPages` also includes the `Page` record with area `christ` and slug `sermons`. The current exclusion logic in `addPages` only targets the `sermons` area (PageArea::Sermons) for index duplicates like `preachers`, `series`, and `all`, but `/christ/sermons` is the canonical index which exists as a Page record in the `christ` area.
- **Suggested action:** Update the exclusion logic in `SitemapService::addPages` to also exclude the `sermons` slug when the area is `christ`, as it is already handled by `addStaticUrls`.
- **Risk note:** This item is in the sitemap. Duplicate entries can slightly impact crawl efficiency or be flagged by SEO tools, though search engines usually handle them gracefully by selecting one canonical.
