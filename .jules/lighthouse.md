## 2026-03-26 - [Structured Data] Use Schema.org Article for Sermon Listings
**Learning:** Sermon listings (ItemList) benefit from using the more specific `Article` type for individual items instead of the generic `CreativeWork`. This aligns with the schema used on individual sermon pages and provides better specificity for search engine rich results.
**Action:** Always use `Article` with `headline`, `publisher`, and `mainEntityOfPage` properties when presenting sermon items in a list.

## 2026-03-29 - [Structured Data] Rich Snippets for Preacher Profiles
**Learning:** Preacher profile pages represent individuals. Providing Schema.org `Person` JSON-LD on these pages helps search engines associate sermons with specific authors and church affiliations.
**Action:** Implement a reusable `x-schema.person` component and use the preacher's bio for both JSON-LD description and meta description tags.
