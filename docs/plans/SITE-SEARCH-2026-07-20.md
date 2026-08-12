# Site search — keyword discovery

> **Status (2026-08-12): approved, not started.** Deliver the sermon-archive search first; it is
> useful without any other plan. Deliver the site-wide page and its navigation entry together as a
> second complete feature. No Scout, external search engine, AI call, transcript search, or new
> dependency is required.
>
> **Sequencing dependency:**
> [architectural maintainability](ARCHITECTURAL-MAINTAINABILITY-DELIVERY-2026-08-12.md) **AM5 must
> land before Delivery 1.** AM5 establishes the single server/reactive document-head contract
> (title, description, canonical, robots). Delivery 1 consumes that shared event for its `q`/robots
> behaviour and **must not add a second metadata updater**; this plan owns search semantics, not
> head management.

## Ownership and boundaries

This plan owns deterministic public keyword search:

- the `?q=` contract and search UI on `/christ/sermons`;
- shared, bounded parsing of public search terms;
- metadata `LIKE` search over public sermons and pages;
- the `/search` page and its desktop/mobile navigation entry;
- search-state SEO (`noindex, follow` and canonical URLs without `q`).

The [semantic sermon plan](SEMANTIC-SERMON-SEARCH-AND-QA-2026-06-18.md) later adds a semantic
branch behind the **same sermon archive** `?q=` UI. It must preserve this keyword route as the
fallback. The general `/search` page remains deterministic metadata search; its “See all sermon
results” link may lead to the semantically ranked archive after that feature is enabled.

The design refresh owns shared header tokens/components, not the search feature. This plan owns
adding the Search destination. Coordinate the same header file with the newcomer backlog and land
this narrow feature before broad header visual work where practical.

## Non-goals

- transcript or answer generation;
- members-only song search;
- indexing private/member pages for guests;
- new search infrastructure or speculative caching;
- GA configuration—the analytics plan owns verifying Enhanced Measurement for `q` after release.

At the current content scale, escaped metadata `LIKE` queries are appropriate. Revisit that choice
only with measured latency and query-plan evidence.

## Delivery 1 — useful sermon archive search

Ship one complete PR on `/christ/sermons`.

### Query contract

Create one small `App\Support\SearchTerms` value/helper owned by this plan and use it in both
deliveries:

- trim input;
- treat an empty value as no search;
- cap raw input at 100 characters and retain at most five whitespace-separated terms;
- escape `\`, `%`, and `_` for `LIKE`;
- apply **AND across terms** and **OR across allowed fields for each term**.

Extend `SermonRepository::publicBrowseQuery()` with `?string $q = null`. Search:

- title, legacy preacher text, series, and reference;
- `preacherProfile.name`;
- scripture passage display and normalised references;
- summary only when `show_summary` is true.

Compose with existing book/chapter/preacher/series filters and retain date-descending order. Do not
invent relevance scoring here.

### Livewire and UI contract

- Add `#[Url(as: 'q', except: '')] public string $q = '';` to `BrowseSermons`.
- Use `wire:model.live.debounce.400ms`; this property, param, and debounce are the later semantic
  seam.
- Reset pagination and dispatch metadata changes when `q` changes.
- Include search in active-filter labels, individual removal, and Clear filters.
- Place the always-visible search input above the collapsible facet controls.
- Reuse the shared input and empty-state components, provide a scoped delayed loading indicator,
  and retain the existing results skip link and keyboard order.

Activate the project frontend, Livewire, and Tailwind skills when implementing the UI.

### SEO contract

Widen `SermonArchiveSeoPresenter`'s filter shape to include `q`:

- searched titles/descriptions describe the query;
- canonical URLs omit `q` but retain ordinary facets;
- searched states return `noindex, follow`;
- unsearched states retain the current robots output.

Add a reusable robots slot to `layouts/main.blade.php` using the same SSR/pushed-head approach as
canonical and description metadata. Livewire metadata events must update title, description,
canonical, and robots coherently after client-side filter changes.

### Delivery 1 tests

- each allowed sermon field/relationship and hidden-summary exclusion;
- multi-term semantics, literal wildcard handling, length/term caps, and facet composition;
- pagination reset, chips, clear/remove, loading, and empty states;
- SSR and Livewire title/description/canonical/robots behaviour;
- regression guard for ordinary pages using the shared robots layout;
- Dusk: type a query, combine a facet, remove the search chip;
- updated desktop/mobile sermon-index Playwright baselines.

This delivery is the prerequisite for semantic sermon search, but has no dependency on embeddings
or historic-import completion.

## Delivery 2 — complete site-wide search feature

Depends only on Delivery 1. Ship the service, page, and header entry in one release so the feature
is not left as an undiscoverable public route.

### Search service

Add a typed `SiteSearchResults` DTO and a `SiteSearchService` that accepts the query plus an explicit
`$includeMembersArea` boolean. The caller resolves authentication; the service remains auth-free.

Use the shared `SearchTerms` contract. A trimmed query shorter than two characters returns an idle
result. Return bounded groups in this order:

1. **Sermons** — at most five from the deterministic repository keyword query, plus total count.
2. **Pages** — at most ten public pages matching heading, description, or markdown. Guests exclude
   the members area; authenticated users may include it. Markdown is match-only: snippets use the
   safe description or heading, never rendered raw markdown.
3. **Preachers** — at most five case-insensitive matches from the existing public-list cache.
4. **Series** — at most five case-insensitive matches from the existing series list, with links
   proven to round-trip through the existing slug resolver.

Select only required database columns, eager-load result relationships used by presenters, and
inspect the bounded page/sermon queries. Do not add a cache until profiling demonstrates a need.

### Page and route

- Register explicit `GET /search` above the page catch-all and name it `search`.
- Use a thin controller for SSR metadata and a class-plus-Blade Livewire component for results.
- Persist `q` in the URL with the same 400 ms debounce.
- Always emit `noindex, follow`; canonicalise to `/search` without `q`; do not add it to the sitemap.
- Provide deterministic idle, loading, empty, error, and results states with an `aria-live` results
  summary and proper group headings.
- “See all N sermon results” links to `sermons.index?q=...`.

### Navigation entry

- Add an accessible Search entry to desktop and mobile navigation.
- Preserve the existing Alpine expanded-state and grid behaviour; do not combine this feature with
  a header restructure.
- Use `wire:navigate`, visible focus styles, appropriate touch targets, and `aria-current`.
- Rebase over any already-landed newcomer/header changes rather than recreating them.

### Delivery 2 tests

- group caps, sermon total, min-length gate, shared term semantics, and literal wildcards;
- guest/authenticated/admin-page visibility matrix;
- case-insensitive preacher/series matching and valid links;
- route precedence against the public page catch-all;
- HTTP canonical/robots and SSR initial query;
- Livewire idle/loading/empty/results states and See all link;
- Dusk desktop and mobile navigation through Search to a result;
- full public Playwright baseline review because the shared header changes.

## Semantic handshake

The later semantic implementation must honour all five points:

1. keep `?q=` and the existing sermon search box—no second param or UI;
2. select semantic ranking only behind its own feature flag and rate limiter;
3. fall back to this deterministic keyword query on disablement, timeout, or API failure;
4. retain this plan's SEO and active-filter behaviour;
5. leave `/search` deterministic; only the destination archive may semantically rerank its query.

## Recommended order

1. Delivery 1: sermon archive search.
2. Delivery 2: site-wide search plus header entry.
3. Semantic sermon MVP.

Each numbered delivery is independently deployable and user-visible. Do not split Delivery 2 into a
long-lived unlinked page and a later navigation PR; internal review commits are fine, but promote the
complete feature together.

## Quality gates

For each delivery: focused tests first, PHPStan, Pint, the full parallel suite, Dusk for changed
interaction, and approved Playwright diffs for changed public appearance. Keep all user-facing copy
in British English and sentence case.

**Who benefits:** visitors who know a topic, preacher, passage, or page but not its navigation path.

**What observably improves:** sermon search ships first at the high-value archive, followed by one
discoverable site-wide search feature with predictable privacy, fallback, and SEO behaviour.
