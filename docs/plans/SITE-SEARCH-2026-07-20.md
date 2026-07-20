# Site Search (2026-07-20) — Sermon keyword search + site-wide search page

> **Status (2026-07-20): approved, not started.** No dependencies on the July simplification
> backlog — both phases are additive and touch no code scheduled for deletion.
>
> **Relationship to [SEMANTIC-SERMON-SEARCH-AND-QA-2026-06-18.md](SEMANTIC-SERMON-SEARCH-AND-QA-2026-06-18.md):**
> this plan does **not** supersede it and must not drift into its territory. This plan ships
> *keyword* (LIKE) search over sermon **metadata** and site pages now; the semantic plan later
> upgrades the *ranking backend* behind the very same `?q=` URL param and search box on
> `BrowseSermons` (its Phase 3 was designed as exactly that seam). The handshake contract is
> defined in "Interplay with the semantic plan" below. **Do not** build transcript search,
> embeddings, or anything calling an AI API here.
>
> **What an agent must not do without maintainer input:** change header layout beyond adding
> the single search entry link (Phase B3); add dependencies (no Scout, no search engine, no
> MySQL FULLTEXT indexes — see "Approach rationale"); index the `/search` page or search-query
> states (both are `noindex`).

## Goal

1. **Phase A** — a public keyword search box on the sermon archive (`/christ/sermons`),
   searching title / preacher / reference / series / summary, composable with the existing
   book/chapter/preacher/series facets, state in `?q=`.
2. **Phase B** — a public site-wide search page at `/search` with grouped results (pages,
   sermons, preachers, series), plus a search entry point in the site header.

## Non-goals

- **No transcript/content search** — that is the semantic plan's job (embeddings, deep links).
- **No new dependencies and no MySQL FULLTEXT indexes.** At current scale (830 sermons,
  32 public pages, measured 2026-07-20) `LIKE '%term%'` on selected columns is milliseconds.
  FULLTEXT only pays off for long prose, which is exactly the content this plan does not search.
- **No AI-generated text anywhere** (standing maintainer decision, 2026-07-20).
- **No members-only song search** — `BrowseSongs` already has its own filtering for members.
- **No search analytics build-out** — note only: GA4's enhanced measurement auto-tracks site
  search when the query param is `q` (both phases use `q`); verifying that toggle is a GA-admin
  task that belongs with the GA plan's GA6 checklist, not code here.

## Approach rationale (settled by measurement, record only)

- The admin sermon list (`app/Livewire/Admin/Sermons/ListSermons.php:130-149`) already does
  escaped multi-column LIKE search over the same columns at the same scale — this is the
  proven in-repo pattern, reused rather than invented.
- The `WithFilterableListing` trait is deliberately **not** used on the public component: it is
  documented as centralising *admin* list behaviour, and `BrowseSermons` has bespoke chip/URL
  logic that the trait's blanket `updated()` hook would fight.

---

## Verified code map (read these before starting; line refs checked 2026-07-20)

| What | Where |
|---|---|
| Public archive component | `app/Livewire/Sermons/BrowseSermons.php` — facet props with `#[Url]`, `updatedXFilter()` hooks all call `resetPage()` + `dispatchMetadataUpdate()`, `#[Computed] sermons()` calls `SermonRepository::publicBrowseQuery(...)->paginate(24)` |
| Archive query | `app/Services/Public/SermonRepository.php::publicBrowseQuery()` (facets → ordered `SermonBuilder`); `basePublicSermonQuery()` holds the public column allow-list incl. `summary`, `show_summary` |
| Admin LIKE pattern | `app/Livewire/Admin/Sermons/ListSermons.php:130-149` + `app/Traits/EscapesLikeWildcards.php` (`addcslashes($value, '\\%_')`) |
| Archive SEO | `app/Seo/SermonArchiveSeoPresenter.php` — `title()/description()/canonical()` all take the filter array shape `array{book,chapter,preacherId,series}`; canonical builds `route('sermons.index', $params)` |
| SSR SEO entry | `app/Http/Controllers/SermonController.php::index()` normalises query params and passes presenter output to `resources/views/sermons/index.blade.php`, which embeds `<livewire:sermons.browse-sermons />` in `<x-slot:fullWidth>` |
| Client-side head updates | top of `resources/views/livewire/sermons/browse-sermons.blade.php` — Alpine `x-init` listens for `sermon-filters-updated` and rewrites `document.title` / description / canonical |
| Robots meta | `resources/views/layouts/main.blade.php` — **hardcoded** `<meta name="robots" content="max-image-preview:large">`; head has `@push`/`@section` dual-path blocks for description, meta_tags, canonical (copy that pattern for robots) |
| Page shell | `resources/views/components/page/shell.blade.php` (`x-page.shell`) — pushes title/meta_description/meta_tags/canonical |
| Public pages | `app/Models/Page.php` — `scopePublic()` = `admin = 'no'`; `route` attribute = `/{area}/{slug}`; areas enum `christ|church|community|members|sermons`; searchable text lives in `heading`, `description`, `markdown` |
| Meetings | have **no public text of their own** (`app/Models/Meeting.php` fillable is logistics fields); their public face is the linked `Page` row, so page search covers them |
| Preachers | `app/Services/Public/PreacherListCache::forPublicList()`; public page `route('sermons.preacher', $preacher)` |
| Series | `SermonRepository::getSeriesForDisplay()` (cached names); public page `route('sermons.series.show', ['series' => Str::slug($name)])`, resolved by `resolveSeriesNameFromSlug()` |
| Route order | `routes/web.php` — catch-all `/{area}` + `/{area}/{slug}` are **last**; any explicit route added above them wins |
| Existing tests | `tests/Feature/Livewire/Sermons/BrowseSermonsTest.php` (component conventions: `RefreshDatabase`, explicit model cleanup in `setUp`, `SermonScriptureFilterIndexService` for facet rows); `tests/Integration/Presenters/SermonArchiveSeoPresenterTest.php` |
| Design system | `docs/design-style-guide.md` + `.claude/skills/frontend-design/SKILL.md` — **activate the `frontend-design` skill for all UI work in both phases.** Components available: `x-input`, `x-empty-state`, `x-badge`, `x-clickable-card` (props `link` + `heading`), `x-sermon-card`, `x-breadcrumbs` (props `area` + `heading`) |

---

## Phase A — Sermon archive keyword search

**Goal:** a search box on `/christ/sermons` that narrows the existing browse by keyword,
composable with facets, shareable via `?q=`, with correct SEO (`noindex` + canonical to the
un-searched state). One PR.

### A1. Repository: extend `publicBrowseQuery()`

Add `?string $q = null` to `SermonRepository::publicBrowseQuery()` and apply it after the
facet `when()`s. Use the `EscapesLikeWildcards` trait on the repository.

Semantics (mirror + extend the admin pattern):

- Trim; treat `''` as null. Cap raw input at 100 chars (`Str::limit` without ellipsis or plain
  `substr` — no exception, just truncate).
- Split on whitespace (`preg_split('/\s+/')`), keep at most the first 5 terms.
- **AND across terms, OR across columns within a term** — `"lloyd romans"` must match a sermon
  whose preacher matches "lloyd" AND whose reference/series/title matches "romans". Whole-string
  matching (the admin behaviour) fails that common query shape, which is why this deviates.
- Per-term OR-group columns:
  - `title`, `preacher` (legacy string column), `series`, `reference` — direct LIKE;
  - `preacherProfile.name` via `orWhereHas`;
  - `scripturePassage.display_reference` / `normalized_reference` via `orWhereHas`;
  - `summary` **only where `show_summary` is true** (`orWhere` group: `show_summary = 1 AND
    summary LIKE …`) — a hidden summary must not produce an inexplicable match. This is a
    deliberate default; if the maintainer prefers matching hidden summaries, it is a one-line
    change, but do not silently choose it.
- Ordering stays date-desc (unchanged). Do **not** attempt relevance ranking — that is the
  semantic plan's upgrade.

### A2. Component: `BrowseSermons`

- Add `#[Url(as: 'q', except: '')] public string $q = '';` — **the param name `q` and the
  400 ms debounce are contractual** (semantic plan Phase 3 takes them over verbatim).
- `updatedQ(): void` → `resetPage()` + `dispatchMetadataUpdate()` (same shape as the existing
  `updatedBookFilter()` etc.).
- `sermons()` passes `q: trim($this->q) ?: null`.
- `hasActiveFilters()` gains `|| trim($this->q) !== ''`.
- `clearFilters()` resets `q` too (add to the `reset([...])` list).
- `removeFilter('search')` clears `q` (new branch), and `activeFilterLabels()` adds
  `'search' => 'Search: "'.trim($this->q).'"'` so the existing chip row renders/removes it
  with zero new chip markup.
- `activeFilters()` array gains `'q' => trim($this->q) ?: null` — this widens the presenter
  filter-array shape; see A3.

### A3. SEO presenter + robots mechanism

`SermonArchiveSeoPresenter` (and its phpdoc array shapes) gains `q: string|null`:

- `title()`: when `q` set, prepend a `Search: "{q}"` part to the existing parts list
  (e.g. `Search: "hope" | Romans | Sermons`).
- `description()`: when `q` set, prefix with `Sermons matching "{q}"` phrasing (British
  English, sentence case).
- `canonical()`: **never includes `q`.** A searched state canonicalises to the same URL
  *without* the search — this implements the semantic plan's "canonical to base archive"
  decision and needs no new robots plumbing to be correct, but we add `noindex` as belt and
  braces because Livewire pagination links will carry `q`:
- New method `robots(array $filters): ?string` → `'noindex, follow'` when `q` is set,
  else `null`.

Layout change (`resources/views/layouts/main.blade.php`): replace the hardcoded robots line
with the same dual-path pattern already used for canonical directly below it:

```blade
@php $pushedRobots = trim($__env->yieldPushContent('robots')); @endphp
<meta name="robots" content="{{ $pushedRobots !== '' ? $pushedRobots.', max-image-preview:large' : 'max-image-preview:large' }}">
```

Wire-up:
- `SermonController::index()` reads `$request->query('q')` (trimmed, no normalisation beyond
  that), includes it in the presenter filter array, and passes a `robots` value to the view.
- `sermons/index.blade.php` pushes `robots` when non-null.
- The Alpine `sermon-filters-updated` handler in `browse-sermons.blade.php` additionally
  updates `meta[name="robots"]` from a new `robots` key in the dispatched payload
  (`dispatchMetadataUpdate()` adds it), so client-side transitions keep head state coherent.
- **Callers check:** the filter-array shape is consumed by more than the controller — grep for
  `activeFilters` / `SermonArchiveSeoPresenter` usages (there is at least a breadcrumb
  presenter sharing `preacherName()`); Larastan's array-shape checks will flag any missed
  call site — run `composer phpstan` early, not last.

### A4. UI (activate `frontend-design` skill first)

- Search input sits **above** the "Filter sermons" toggle row, inside the same
  `max-w-2xl lg:max-w-5xl xl:max-w-7xl` container — always visible, not inside the collapsible
  facet panel (search is the primary affordance; facets are the refinement).
- `<x-input type="search" wire:model.live.debounce.400ms="q" …>` with a visible label or
  `sr-only` label "Search sermons", placeholder `Search by title, preacher, series or passage…`,
  and a clear (×) affordance when non-empty (Alpine, or rely on the native `type=search` clear).
- Loading state: `wire:loading.delay` on a small spinner/indicator scoped with
  `wire:target="q"`. **Do not** compose it as `wire:loading.flex.delay.500ms` — that exact
  modifier chain is invalid and produced the always-on spinner bug (#911).
- Empty state: reuse `x-empty-state` — `No sermons match your search.` with a "Clear search"
  action wired to `removeFilter('search')`.
- The existing "Showing X of Y sermons" count and result cards are unchanged.
- Keyboard/focus: input participates in normal tab order before the skip-link target; the
  existing `#sermon-results` skip link stays first.

### A5. Tests (Phase A)

Feature (`tests/Feature/Livewire/Sermons/BrowseSermonsTest.php` or a sibling
`BrowseSermonsSearchTest.php` — prefer a sibling; the existing file is already long):

- matches by title, by `preacherProfile.name`, by legacy `preacher` string, by `series`, by
  `reference`, by `scripturePassage.display_reference`;
- summary matches only when `show_summary` is true;
- multi-term AND semantics (`"smith john"` style: two terms hitting different columns match;
  a term matching nothing excludes);
- LIKE wildcards are escaped (`%`, `_` literals do not act as wildcards);
- input > 100 chars and > 5 terms are truncated without error;
- `q` composes with a facet (e.g. book filter + search);
- `updatedQ` resets pagination; chip renders; `removeFilter('search')` clears; `clearFilters`
  clears; empty state renders.

HTTP/SEO (`SermonController::index` path):

- `GET /christ/sermons?q=hope` → 200, `<meta name="robots" content="noindex, follow, max-image-preview:large">`,
  canonical **without** `q`, title contains `Search: "hope"`;
- `GET /christ/sermons` (no `q`) → robots meta unchanged from today (regression guard for the
  layout edit — this guards **every** page, since the layout is shared).

Integration: extend `tests/Integration/Presenters/SermonArchiveSeoPresenterTest.php` for the
new `q` behaviour of `title()/description()/canonical()/robots()`.

Dusk: type a query → results narrow; add a facet → both apply; remove the search chip →
results restore. (Remember the Dusk redis-vs-array cache note in memory if caching is touched —
it should not be; `publicBrowseQuery` is uncached.)

Playwright visual baselines: the sermons index changes (new input) — regenerate
`sermons-index-desktop` / `sermons-index-mobile` per the procedure in
`docs/design-style-guide.md`.

### A6. Risks (Phase A)

- **Layout robots edit touches every page** → the no-`q` regression test above, plus eyeball
  `view-source` on home in review.
- **Presenter array-shape ripple** → phpstan early; grep all callers.
- **Livewire pagination links carry `q`** → intended (state is shareable); `noindex` +
  canonical-without-`q` contain the SEO surface.

---

## Phase B — Site-wide search page

**Goal:** `/search` — one box, grouped results across the public site, `noindex`, linked from
the header. Two PRs (B1+B2 page, B3 header entry) so the page can soak before it is promoted.

### B1. Service + DTO

`app/Services/Public/SiteSearchService.php` (constructor-inject `SermonRepository` and
`PreacherListCache`), using `EscapesLikeWildcards`, returning a typed DTO
`app/Data/SiteSearchResults.php` (readonly, promoted-property style matching `App\Data\*`
siblings) with four typed collections/arrays:

- **pages** (max 10): `Page::query()->public()` — heading / description / markdown LIKE
  (same term-split semantics as Phase A; extract the term-splitting into a small shared helper,
  e.g. `App\Support\SearchTerms::split()`, and have Phase A's repository use it too rather than
  duplicating). Visibility: guests exclude `area = members`; authenticated users include it
  (mirrors the `Meeting::scopePubliclyAccessible` reasoning — pass an
  `$includeMembersArea: bool` flag in, resolved by the caller from `auth()->check()`; keep the
  service auth-free). Result fields: `heading`, snippet (description, fall back to heading —
  do **not** snippet raw `markdown`; it matches but is not display-safe), `route` (the model's
  route attribute), area label for the group chip.
- **sermons** (max 5): delegate to `SermonRepository::publicBrowseQuery(q: $q)->limit(5)->get()`
  — one search implementation, two surfaces. Also return the total match count
  (`->count()` on an unlimited clone) so the UI can render "See all N sermon results".
- **preachers** (max 5): filter `PreacherListCache::forPublicList()` by name in PHP
  (case-insensitive `str_contains`) — it is a small cached collection; no query needed.
- **series** (max 5): filter `SermonRepository::getSeriesForDisplay()` names in PHP; each
  result links via `route('sermons.series.show', ['series' => Str::slug($name)])`.
- Minimum query length 2 (after trim); below that return an "idle" DTO the UI renders as the
  pre-search state. Same 100-char/5-term caps as Phase A via the shared helper.

No caching layer: every underlying source is either already cached (preacher list, series
list) or a cheap LIKE. Do not add one speculatively.

### B2. Route, controller, Livewire page

- Route: `Route::get('/search', [SearchController::class, 'index'])->name('search');` placed
  with the other explicit public routes in `routes/web.php` — anywhere **above** the
  catch-all `/{area}` block (which must stay last). `search` is not a `PageArea` value, so no
  page-slug collision exists; add a route test anyway.
- `app/Http/Controllers/SearchController.php::index()` mirrors `SermonController::index()`:
  reads `q` for SSR head state, returns `search/index.blade.php` with heading `Search`,
  a description for the meta tag, `robots => 'noindex, follow'` (mechanism from A3 — Phase B
  depends on Phase A's layout change), canonical `route('search')` **without** `q`.
- `resources/views/search/index.blade.php`: `@extends('layouts.main')` + `x-page.shell`
  (heading "Search", no headline picture needed) embedding
  `<livewire:search.site-search />` — copy the `sermons/index.blade.php` shape including the
  `@push('meta_tags')` block.
- `app/Livewire/Search/SiteSearch.php` + `resources/views/livewire/search/site-search.blade.php`:
  - `#[Url(as: 'q', except: '')] public string $q = '';`, `wire:model.live.debounce.400ms`,
    autofocus on mount (`x-init` focus — not the HTML `autofocus` attribute, which misbehaves
    with `wire:navigate`).
  - Renders the DTO as grouped sections in order **Sermons, Pages, Preachers, Series**
    (sermons first — it is the deepest content and the most likely intent), each with a
    sentence-case British-English group heading, `x-clickable-card` (props `link`/`heading`)
    or compact list items; sermons group reuses the existing public card/list partials where
    they fit and ends with "See all N sermon results" → `route('sermons.index', ['q' => $q])`
    when N > 5 — **this link is the seam that makes Phase A and B compound.**
  - States (all four designed, per the design workflow): idle (short explanatory copy +
    example queries), loading (`wire:loading.delay`, correct modifier per the #911 note),
    empty (`x-empty-state`: `No results for "{q}".` with suggestions to browse sermons or the
    calendar), results.
  - Accessibility: the results region is `aria-live="polite"`; result-count sentence updates
    with it; group headings are real `h2`s under the page `h1`.
  - Do **not** add the page to `SitemapController` output (it is `noindex`).

### B3. Header entry point (separate PR; activate `frontend-design` skill)

- Desktop: a magnifying-glass icon link (`x-heroicon-o-magnifying-glass`, with `sr-only`
  "Search" text) appended to the desktop `nav` `ul` in
  `resources/views/components/layout/header.blade.php`, styled like the existing items
  (border-b active-state pattern, `aria-current="page"` when `request()->is('search')`).
- Mobile: a "Search" entry in the expanded mobile menu (top of the grid, full-width row,
  matching existing link classes).
- **Scope discipline:** the header's grid (`grid-cols-7` / `lg:grid-cols-12`) and its
  Alpine `expanded` choreography are fiddly and have a known bug history (broken desktop
  hamburger overlay is a separate NEWCOMER-UX item — do not fix or refactor it here). If the
  icon cannot be added without restructuring the grid, stop and flag it rather than
  restructuring.
- The header renders on every page → **regenerate all Playwright visual baselines** per
  `docs/design-style-guide.md` (pinned-image + seeded-DB procedure).

### B4. Tests (Phase B)

Service (feature tests, factories):
- grouping + per-group caps; sermon total count; min-length gate; term semantics + escaping
  (via the shared helper's own unit tests — write those once, in one place);
- page visibility matrix: admin pages never returned; members-area pages returned only with
  `$includeMembersArea = true`;
- preacher/series name filtering incl. case-insensitivity; series links resolve
  (`resolveSeriesNameFromSlug(Str::slug($name))` round-trip).

HTTP:
- `GET /search` → 200, robots `noindex, follow`, canonical without `q`, renders idle state;
- `GET /search?q=xy` → SSR renders results (Livewire mounts with `q` from URL);
- route does not shadow and is not shadowed by the `/{area}` catch-all (assert both `/search`
  and an ordinary page like `/church/about-us`-style slug still resolve).

Livewire: query updates results; group headings appear/disappear; "See all N sermon results"
href carries `q`; empty + idle states.

Dusk (with B3): click header search icon → `/search` → type → click a sermon result → sermon
page; and the mobile-menu path.

### B5. Risks (Phase B)

- **Catch-all route shadowing** → explicit route-resolution test (above).
- **Members-content leak** → the visibility matrix test is the gate; the service takes an
  explicit boolean so the rule is unit-testable without auth plumbing.
- **Header regression on every page** → B3 is its own PR; Playwright full-baseline regen;
  Dusk nav tests.
- **Markdown in snippets** → never render `markdown` content in results (match-only field).

---

## Interplay with the semantic plan (the contract)

Recorded here and as an amendment note in the semantic plan itself:

1. **URL + UI slot:** `?q=` on `sermons.index` with a 400 ms-debounced Livewire property is
   owned by `BrowseSermons` from Phase A onward. Semantic Phase 3 **replaces the ranking
   backend** (LIKE → `ArchiveSearchService`) behind `archive_search.enabled`; it does not add
   a second box or param.
2. **Fallback:** when `archive_search.enabled` is off (or the embedding call fails), the
   keyword LIKE path from this plan **remains and serves** — semantic Phase 3 must keep it as
   the fallback branch, not delete it.
3. **SEO:** `noindex` + canonical-without-`q` from A3 already satisfy semantic Phase 3's SEO
   requirement; Phase 3 inherits it with no further work.
4. **Site-wide page:** `/search`'s sermon group calls whatever
   `SermonRepository::publicBrowseQuery(q:)` does — when the semantic backend lands behind
   that same seam (or `SiteSearchService` is later pointed at `ArchiveSearchService`), the
   site-wide page upgrades for free. Keep the sermon-search entry point in `SiteSearchService`
   to that single delegation call so the swap stays one line.
5. The semantic plan's `archive-search` **rate limiter is not needed now** (no per-query API
   cost exists in this plan) and must not be built early; it arrives with the embedding spend
   it protects.

## Delivery order & PR stack

```text
PR 1  Phase A  — repository q + shared SearchTerms helper + BrowseSermons UI
                 + SEO presenter/robots mechanism + tests + Dusk
                 + sermons-index Playwright baselines
PR 2  Phase B1+B2 — SiteSearchService + DTO + /search route/controller/page
                 + Livewire component + tests (page is live but unlinked)
PR 3  Phase B3 — header entry (desktop + mobile) + Dusk nav tests
                 + full Playwright baseline regen
```

PR 2 depends on PR 1 (robots mechanism, repository `q`, shared helper). PR 3 depends on PR 2.
No feature flag: each PR is individually shippable and the `/search` page is dark-by-obscurity
until PR 3 links it (acceptable for a public read-only page; the maintainer can visit and sign
off between PR 2 and PR 3).

## Quality gates (every PR)

1. Focused tests for the changed behaviour first (bug-report changes start with a failing test).
2. `vendor/bin/sail composer phpstan` — 0 errors (run early in Phase A; the presenter
   array-shape change is the likely tripwire).
3. `vendor/bin/sail bin pint --dirty`.
4. `vendor/bin/sail artisan test --compact --parallel` (capture with `tee` on the first run).
5. `vendor/bin/sail artisan dusk` (PR 1 and PR 3 change public UI; PR 2 adds a public page).
6. British English, sentence case, in every user-facing string; activate `frontend-design`
   before writing any Blade.
