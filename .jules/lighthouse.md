## 2026-03-26 - [Structured Data] Use Schema.org Article for Sermon Listings
**Learning:** Sermon listings (ItemList) benefit from using the more specific `Article` type for individual items instead of the generic `CreativeWork`. This aligns with the schema used on individual sermon pages and provides better specificity for search engine rich results.
**Action:** Always use `Article` with `headline`, `publisher`, and `mainEntityOfPage` properties when presenting sermon items in a list.
