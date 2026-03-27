## 2026-03-17 - ItemList for Sermon Collections
**Learning:** Sermon listing pages (by preacher, series, or service) were missing structured data. Using `ItemList` with `CreativeWork` elements for each sermon improves how search engines understand and rank these collection pages.
**Action:** Always provide `ItemList` JSON-LD for listing pages using a dedicated presenter (like `SermonItemListPresenter`) to ensure consistency and correct Schema.org properties.

## 2026-03-17 - Centralized Breadcrumb JSON-LD
**Learning:** Hardcoded JSON-LD breadcrumbs in individual views are brittle and prone to inconsistency (e.g., showing 'Church' instead of 'Community'). The shared `x-breadcrumbs` component is a better place for this logic.
**Action:** Delegate breadcrumb generation (both UI and JSON-LD) to the shared component and ensure controllers pass the necessary `area` context.

## 2026-03-27 - Enhanced Preacher Metadata & Structured Data
**Learning:** Preacher detail pages were missing specific `Person` structured data and unique meta descriptions, which are valuable for church branding and search visibility.
**Action:** Implement `x-schema.person` component and update preacher views to utilize bio data for meta tags and social sharing.
