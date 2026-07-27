## 2026-03-26 - [Structured Data] Use Schema.org Article for Sermon Listings
**Learning:** Sermon listings (ItemList) benefit from using the more specific `Article` type for individual items instead of the generic `CreativeWork`. This aligns with the schema used on individual sermon pages and provides better specificity for search engine rich results.
**Action:** Always use `Article` with `headline`, `publisher`, and `mainEntityOfPage` properties when presenting sermon items in a list.

## 2026-03-31 - [Structured Data] BreadcrumbList for Listing Pages
**Learning:** High-level listing pages (Sermon Index, All Sermons, Preachers, Series, Services) and special landing pages (Christmas, Easter) were missing `BreadcrumbList` JSON-LD. Adding this improves search engine understanding of the site hierarchy and enhances search result appearance.
**Action:** Use the `<x-breadcrumbs />` component with the `jsonOnly` prop to add `BreadcrumbList` schema to all primary landing and listing pages.

## 2026-06-10 - [Video SEO] VideoObject for Gospel Landing Pages
**Learning:** Landing pages featuring a central video (like the gospel explanation on `/christ`) should implement `VideoObject` JSON-LD. This signals to search engines that the page contains significant video content, potentially qualifying it for video rich results and enhancing its appearance in search.
**Action:** Include `VideoObject` schema on key landing pages with prominent video content, ensuring `duration`, `uploadDate`, and a high-quality `thumbnailUrl` are provided.

## 2026-06-25 - [Schema.org] Church is a Place, not an Organization
**Learning:** Schema.org `Church` sits under `Place > CivicStructure > PlaceOfWorship`, **not** under `Organization`. Properties whose expected range is an organization — `publisher`, `worksFor`, `organizer` — require `Organization` (or `Person`); supplying a `Church` (a Place) there makes Google's rich-result parsers ignore or flag the value. `Church` is only appropriate for the standalone place/organization entity node itself (e.g. a `LocalBusiness`/`Place` block with address and geo).
**Action:** Keep `@type: Organization` for `publisher`/`worksFor`/`organizer` relationship values. Reserve `Church` for the dedicated place entity, not for organization references.

## 2026-07-27 - [Schema.org] Representing Church and Organization via @graph
**Learning:** To resolve the conflict where a Church (Place) is needed for physical/location parameters (address, geo, opening hours) but an Organization is required for corporate relationships (taxID, worksFor, publisher), we can bundle both into a Schema.org `@graph` container. This allows defining distinct nodes `#organization` and `#church` that cleanly link to one another (e.g. via `parentOrganization`).
**Action:** Implement a dual node `@graph` format in `organization.blade.php` to define the legal `Organization` and the physical `Church` of worship in perfect harmony.
