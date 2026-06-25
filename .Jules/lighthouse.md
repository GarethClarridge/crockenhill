## 2026-03-26 - [Structured Data] Use Schema.org Article for Sermon Listings
**Learning:** Sermon listings (ItemList) benefit from using the more specific `Article` type for individual items instead of the generic `CreativeWork`. This aligns with the schema used on individual sermon pages and provides better specificity for search engine rich results.
**Action:** Always use `Article` with `headline`, `publisher`, and `mainEntityOfPage` properties when presenting sermon items in a list.

## 2026-03-31 - [Structured Data] BreadcrumbList for Listing Pages
**Learning:** High-level listing pages (Sermon Index, All Sermons, Preachers, Series, Services) and special landing pages (Christmas, Easter) were missing `BreadcrumbList` JSON-LD. Adding this improves search engine understanding of the site hierarchy and enhances search result appearance.
**Action:** Use the `<x-breadcrumbs />` component with the `jsonOnly` prop to add `BreadcrumbList` schema to all primary landing and listing pages.

## 2026-06-10 - [Video SEO] VideoObject for Gospel Landing Pages
**Learning:** Landing pages featuring a central video (like the gospel explanation on `/christ`) should implement `VideoObject` JSON-LD. This signals to search engines that the page contains significant video content, potentially qualifying it for video rich results and enhancing its appearance in search.
**Action:** Include `VideoObject` schema on key landing pages with prominent video content, ensuring `duration`, `uploadDate`, and a high-quality `thumbnailUrl` are provided.

## 2026-06-25 - [Schema.org] Canonical Entity Type for Churches
**Learning:** The canonical @type for a church in Schema.org structured data is 'Church' (a subtype of Organization and LocalBusiness). Using this more specific type improves semantic accuracy for search engines compared to the generic 'Organization'.
**Action:** Always use @type: Church instead of Organization in all structured data contexts (publisher, worksFor, organizer, etc.) related to the church entity.
