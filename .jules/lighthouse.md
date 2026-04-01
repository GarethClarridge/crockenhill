## 2026-03-26 - [Structured Data] Use Schema.org Article for Sermon Listings
**Learning:** Sermon listings (ItemList) benefit from using the more specific `Article` type for individual items instead of the generic `CreativeWork`. This aligns with the schema used on individual sermon pages and provides better specificity for search engine rich results.
**Action:** Always use `Article` with `headline`, `publisher`, and `mainEntityOfPage` properties when presenting sermon items in a list.

## 2026-03-31 - [Structured Data] BreadcrumbList for Listing Pages
**Learning:** High-level listing pages (Sermon Index, All Sermons, Preachers, Series, Services) and special landing pages (Christmas, Easter) were missing `BreadcrumbList` JSON-LD. Adding this improves search engine understanding of the site hierarchy and enhances search result appearance.
**Action:** Use the `<x-breadcrumbs />` component with the `jsonOnly` prop to add `BreadcrumbList` schema to all primary landing and listing pages.
