# Public Site & Read Path — Simplification Review (Phase 5)

Date: 2026-07-02. Phase 5 of the July 2026 simplification review; doctrine, ground rules, and template per [`docs/plans/JULY-2026-SIMPLIFICATION-REVIEW-PLAN-2026-07-02.md`](../../plans/JULY-2026-SIMPLIFICATION-REVIEW-PLAN-2026-07-02.md). No code changes were made in this session.

Prior art: the April public-read-side review ([`docs/april-2026-review/public-read-side-and-read-path-review-2026-04-16.md`](../../april-2026-review/public-read-side-and-read-path-review-2026-04-16.md)) and its backlog items 11/12/12A ([`docs/archived-plans/APRIL-2026-REVIEW-BACKLOG-2026-04-16.md`](../../archived-plans/APRIL-2026-REVIEW-BACKLOG-2026-04-16.md)). Section 4.0 below reconciles what was and wasn't actioned.

## 1. Scope reviewed

- **Services:** `app/Services/Public/` (13 files, ~2,345 lines: `SermonRepository`, `SitemapService`, `PodcastFeedService`, `PageCardService`, `PageListCache`, `MeetingListCache`, `PreacherListCache`, `PageImageCacheService`, `PublicPageReadModelCache`, `PublicMeetingReadModelCache`, `PublicPageVisibilityGuard`, `PublicSongCatalogService`, `PublicSongUsageService`), `app/Services/Calendar/` (2 files, 424 lines).
- **Presentation layers (the central item):** `app/Presenters/` (14 files, ~1,994 lines), `app/Seo/` (6 files, ~805 lines), `app/Sitemap/` (4 files, 223 lines), `app/View/Presenters/` (1 file, 104 lines), `app/View/Composers/` (4 files, 88 lines), `app/View/Components/` (6 class components, ~167 lines), and the read-model Data objects `PublicPageReadModel`, `PublicMeetingReadModel`, `ChurchServiceShowReadModel`, `PodcastFeedItemReadModel` (~226 lines).
- **Pages CMS:** model `Page`, `PageController`, the `/{area}` and `/{area}/{slug}` catch-all routes, `pages.show` + `full-width-pages/*` + per-slug override views, `PageSeeder`.
- **Meetings & calendar:** models `Meeting` (519 lines), `CalendarEvent` (168 lines), `CalendarController`, `MeetingController`, `CalendarAdminController`, `SyncGoogleCalendarCommand`, `config/calendar.php`.
- **Members area:** `MemberController`, `members/home.blade.php`, members-only song routes (`PublicSongListController`).
- **Sitemap/SEO:** `SitemapService` (524 lines), `SitemapController`, `SitemapCacheObserver`, the `app/Seo/` and `app/Sitemap/` presenters, schema Blade components, inline JSON-LD in views.
- **Tests:** the ~30 test files touching these classes (~6,200 lines in the direct set; more via sermon-page feature tests).

Routes covered: everything in `routes/web.php` outside `admin.*` except sermon-asset serving internals (Phase 3) — home, christ/church/community/christmas, calendar, meetings, sermons archive shells, childrens-corner, members, songs, sitemap, and the page catch-alls.

## 2. What this area is for

This is the church's shop window and its only always-on public output. It serves: (a) ~33 CMS pages of editable content across five areas (christ/church/community/members/sermons) that staff edit through the admin without deployments; (b) meeting pages ("Coffee Cup", "Bible study") whose dates come from the church's real Google Calendar; (c) the sermon archive, detail pages, and podcast feeds (Phase 3 owns sermon production; this phase owns their public read path); (d) a small members dashboard; and (e) the SEO surface — sitemap, meta tags, JSON-LD — that makes a small village church findable.

Operators get: edit-a-page-without-a-developer, automatic calendar publication (with a manual categorisation fallback), and search visibility. Visitors get fast, mostly-static pages.

## 3. Complexity inventory

### 3.1 The presentation surface, by location

| Location | Files | Lines | Role today |
|---|---|---|---|
| `app/Presenters/` | 14 | ~1,994 | Model → view-array presenters, incl. the 7-file sermon cluster |
| `app/Seo/` | 6 | ~805 | JSON-LD ItemList + title/description/canonical builders |
| `app/Sitemap/` | 4 | 223 | Model → `Spatie\Sitemap\Url` builders |
| `app/View/Presenters/` | 1 | 104 | `PageLinksRepository` — filtering over `PageListCache` |
| `app/View/Composers/` | 4 | 88 | Card rails for home/church/community; defaults for `pages.show` |
| `app/View/Components/` | 6 | ~167 | Class components that fetch data in constructors (`Header`, `Breadcrumbs`) |
| `app/Data/` (read models) | 4 | ~226 | Cached view payloads (`PublicPageReadModel` etc.) |
| Inline Blade `@php` JSON-LD | — | ~200 | Meetings/events views build Schema.org arrays inline |

Eight places answer the question "where does this view's data come from", and a ninth (`app/Queries/`) serves the admin side. `docs/plans/SIMPLIFICATION-PLAN.md` Phase 22 (June 2026) deliberately created the `Presenters`/`Seo`/`Sitemap` three-way split — it *organised* the sprawl but did not reduce it, and it left `View/Presenters`, `View/Composers`, component-constructor fetching, and inline Blade JSON-LD outside the scheme.

### 3.2 The sermon presentation cluster

`SermonViewPresenter` (309) + `SermonIdentityResolver` (352) + `SermonPresentationAssembler` (152) + `SermonPresenterCache` (131) + `SermonUrlBuilder` (123) + `SermonDateFormatter` (94) + `SermonMetaPresenter` (77) = **1,238 lines across 7 files** to turn a `Sermon` row into display arrays. Three distinct memoization stores (sermon-id-keyed, identity-keyed, collection-keyed), a "pass the presenter back into the collaborator" convention so all reads flow through one memo layer, and explicit `clearInternalCaches()` choreography that leaks into consumers (`PodcastFeedService::clearCache()` must know the presenter is a scoped singleton whose memo would go stale). This is the end state of the Phase 14 decomposition (995-line god-class → 7 small files): the doctrine's "small single-responsibility files" was applied, but the *total machinery* grew around a caching strategy whose value is unproven (see finding 4.3).

### 3.3 A single CMS page render

`PageController::show()` → `PublicPageVisibilityGuard` → `PublicPageReadModelCache` → (`PageLayoutPresenter` + `PageImageCacheService`) → `PublicPageReadModel::toViewData()` + `RelatedPagePresenter` → `PageLinksRepository` → `PageListCache` → `PageCardPresenter` → `PageImagePresenter`, rendered through `pages.show` with `PageShowComposer` re-supplying defaults `toViewData()` already provides. **Ten classes** cooperate to render one cached, mostly-static page.

### 3.4 Caching layers on the read path

1. `Cache::flexible` listing caches with hand-rolled invalidation that must also forget Laravel's internal `illuminate:cache:flexible:created:{key}` bookkeeping key — this framework-internal string is copy-pasted in three files (`SermonRepository`, `PageListCache`, `PodcastFeedService`).
2. Per-instance request memoization — the same `memoizedPresents`/`computed` dance is re-implemented in at least six classes (`SermonRepository`, `PageListCache`, `MeetingListCache`, `PreacherListCache`, `SermonItemListPresenter`, `SermonArchiveSeoPresenter`), plus the three bespoke stores inside the sermon cluster.
3. `Cache::rememberForever` read models (`PublicPageReadModel`, `PublicMeetingReadModel`, page images, `nav_pages`) invalidated by observer fan-out — `SitemapCacheObserver` clears five global keys plus model-specific ones on *any* create/update/delete of Page/Meeting/Sermon/Preacher.
4. ~150 lines of permutation-based invalidation in `SermonRepository` (`forgetBookAndChapterCaches` triple-nested loops over books × preachers × series).

### 3.5 Meetings, calendar, members

- `Meeting` model: 519 lines, of which ~150 are dead (see 4.5).
- Google sync: `GoogleCalendarSyncService` 263 lines + `CalendarService` 161 + command + config. One-directional pull, *except* a write-back of manual categorisation into Google extended properties.
- Calendar admin exists twice: `CalendarAdminController` (uncategorised/patterns/categorise/sync, classic Blade) *and* Livewire `ListCalendarEvents`/`EditCalendarEvent` (which also categorise).
- Members area: one dashboard view + guarded song pages — proportionate, no findings.

### 3.6 Tests

Direct domain tests ≈ 6,200 lines across ~30 files. Sitemap alone: `SitemapTest` (555) + `SitemapCacheTest` (368) + `Unit/SitemapServiceTest` (156) + `SitemapServiceEagerLoadTest` (97) ≈ **1,176 lines**. `PublicMeetingReadModelCacheTest` exists twice (Feature 174 + Integration 212). Several tests pin production-dead methods (see 4.4).

## 4. Findings

### 4.0 April follow-up: the tactical items landed; the structural question didn't move

Everything the April review flagged was actioned and verified (backlog items 11/12, verified 2026-05-12/13): the sermon archive no longer double-queries (controller renders shell, `BrowseSermons` owns the query — `app/Http/Controllers/SermonController.php:45`, `app/Livewire/Sermons/BrowseSermons.php:135`); transcripts are lazy-fetched (`resources/views/sermons/sermon.blade.php:223`); meeting event archives query bounded slices (`CalendarService::getRecentPastEventsForMeeting`); card rails use surface-specific cache keys (`PageCardService`); Blade no longer service-locates (class components `Header`/`Breadcrumbs` with constructor DI).

What *didn't* happen is the retirement half of the doctrine (#6): the fixes added new paths (read-model caches, surface caches, class components) while the superseded machinery stayed — dead repository methods, a dead presenter method pair, dead model accessors (4.4, 4.5). The June Phase 22 reorganisation likewise moved presenters into three namespaces without asking whether the count should shrink. The result is the sprawl this phase is centred on.

### 4.1 High: eight presentation locations where one convention would do

Evidence: table 3.1. The same job — "turn models into what a Blade template needs" — is done by presenters (`app/Presenters/PageLayoutPresenter.php`), read models (`app/Data/PublicPageReadModel.php`), composers (`app/View/Composers/HomePageComposer.php`), component constructors (`app/View/Components/Layout/Header.php` fetches `nav_pages` with `Cache::rememberForever` inside a component constructor), a one-file namespace (`app/View/Presenters/PageLinksRepository.php`), and inline Blade (`resources/views/meetings/show.blade.php:43-102` builds ~100 lines of Schema.org JSON-LD in `@php`).

The inconsistency is most visible in SEO/metadata, where each page family has its own mechanism: sermons use `app/Seo/` classes; meetings build JSON-LD inline in Blade; static landing pages hand-write `<x-meta-tags>`/`<x-schema.faq>` props (`resources/views/full-width-pages/church.blade.php:13-42`); CMS pages get `metaDescription` from the read model.

**Simplification direction (doctrine #3, #4, #7):** adopt one rule — *"every route's view data is one typed read-model object assembled in the controller (or Livewire component); Blade components receive props; SEO/JSON-LD builders consume the same read model."* Concretely that deletes:

- `PageShowComposer` (21 lines) — it re-supplies defaults `PublicPageReadModel::toViewData()` already emits.
- The three landing composers + `ViewServiceProvider` registrations — `Route::view('/church', ...)` becomes a two-line controller method passing `PageCardService` output explicitly. Same data, one fewer indirection layer and no hidden coupling between view name and provider.
- `app/View/Presenters/` as a namespace — `PageLinksRepository`'s only consumers are `RelatedPagePresenter` (both methods) and nothing else; fold it in.
- Inline Blade JSON-LD in `meetings/show.blade.php` and `meetings/events.blade.php` — becomes a `MeetingSeoPresenter` in `app/Seo/`, matching the sermon convention, and the recurrence `match` expressions leave the template.
- Constructor-fetching in `Header` — nav pages become an explicit prop from the layout (or stay, but documented as the one sanctioned shell exception; the point is choosing).

This is not a rewrite: `PublicPageReadModel`/`PublicMeetingReadModel` already are the target pattern. The work is finishing the migration and deleting the losers — the step that historically doesn't happen.

### 4.2 High: the caching machinery, not the content, is the read path's main complexity

Evidence: 3.4. The site's content changes at most a few times a day, yet the read path operates four caching layers with three invalidation strategies, including:

- A fragile coupling to Laravel's private flexible-cache key format in three files (`app/Services/Public/SermonRepository.php:615-619`, `PageListCache.php:120-124`, `PodcastFeedService.php` `clearCache()`).
- ~150 lines of permutation invalidation (`SermonRepository::forgetBookAndChapterCaches`, triple-nested loops) to keep 24-hour listing caches precisely fresh.
- Observer fan-out (`SitemapCacheObserver::clearCaches`) that nukes global keys on any model event, making every write O(all caches).
- A correctness gap: `PublicMeetingReadModelCache::get()` stores `upcomingEvents` (a time-dependent query, `PublicMeetingReadModelCache.php:104-115`) under `Cache::rememberForever`. Invalidation only fires when a `CalendarEvent` row actually changes; the nightly sync's `updateOrCreate` saves nothing when Google is unchanged, so in a quiet fortnight a meeting page keeps advertising a passed event as upcoming.

**Simplification direction (doctrine #1 applied to infrastructure):** choose freshness-by-TTL over freshness-by-invalidation for listings. A `Cache::flexible` with `[300, 86400]` (fresh 5 min, stale-while-revalidate a day) makes *all* of the manual invalidation unnecessary at this traffic level: the permutation loops, the flexible-key hack, and most of `SitemapCacheObserver` go away, and the stale-upcoming-events bug fixes itself. Reserve `rememberForever` + explicit `forget` for the few payloads where a 5-minute lag genuinely matters (arguably none on this site). If request-level memoization survives profiling, extract the one `memoizedPresents`/`computed` pattern into a single small helper instead of six copies (or use Laravel's `Cache::memo` driver, available since 12.x).

### 4.3 High: the sermon presenter cluster is caching-architecture in search of a workload

Evidence: 3.2. Seven files / 1,238 lines whose organising principle is lazy per-field memoization — yet every hot consumer already sits behind a 24-hour listing cache (`SermonRepository::getSermonsBy*`), and the underlying expensive facts (storage URLs, thumbnail metadata) are cached at their own layer. The memoization mostly saves re-running cheap string formatting within one request, at the cost of: three memo stores, cache keys combining id + `updated_at`, the circular "collaborator receives the presenter" signature (`SermonUrlBuilder::audioUrl(SermonViewPresenter $presenter, Sermon $sermon)`), and consumer-visible reset semantics (`PodcastFeedService::clearCache()` flushing presenter internals).

**Simplification direction (doctrine #3):** present each sermon **once, eagerly, into a typed `SermonView` Data object** (the shape `SermonPresentationAssembler::forFull()` already defines), built by one presenter that calls the formatter/URL/identity helpers as plain pure functions. Collections are presented in one pass (the code already does this via `presentCollection`). That deletes `SermonPresenterCache` entirely, the identity-store half of `SermonIdentityResolver`, the presenter-passback convention, and `clearInternalCaches()` from every consumer — plausibly 7 files → 3 (presenter, formatter, url-builder) and ~1,240 → ~600 lines, with the output shape unchanged (doctrine #4: emit what downstream already consumes).

### 4.4 Medium: dead read-path code, pinned alive by its tests

All verified by repo-wide grep (production callers = zero; only tests reference them):

| Dead item | Evidence |
|---|---|
| `SermonRepository::getLatestSermons()`, `getAllSermons()`, `getRecentSermonsForJsonLd()` | Only `tests/Feature/Repositories/SermonRepositoryTest.php`, `...CacheInvalidationTest.php`, `tests/Unit/Services/SitemapServiceTest.php` call them. The JSON-LD method's cache key is still ritually cleared in `clearListingCaches()` (`SermonRepository.php:566`) |
| `CalendarService::getEventsForMeeting()`, `getAllUpcomingEvents()` | Only `tests/Integration/Services/CalendarServiceTest.php` |
| `MeetingShowPresenter::layoutData()` (57 of its 74 lines) | No callers; `PublicMeetingReadModelCache` uses only `photos()` |
| `PageLayoutPresenter::present()` | Sole caller is the dead `layoutData()`; live consumers use only `renderContent()` |
| `Page::hasMeeting()` | No callers |

These are exactly doctrine #6's "promoted but not retired" residue: each was superseded by the read-model caches and bounded calendar queries, but the old entry points and their tests remain, so the suite actively defends code the application never runs.

**Direction:** delete the methods and their test coverage together (the tests assert nothing the surviving paths need).

### 4.5 Medium: the `Meeting` model carries a parallel scheduling subsystem nothing reads

Evidence: `app/Models/Meeting.php:311-397` — `getNextOccurrence()` plus four private occurrence calculators (~90 lines of clamped date maths); `:410-455` — `upcomingEvents`/`pastEvents`/`nextEvent`/`lastEvent` accessors; plus `formattedDateTime`, `hasPhotos()`, `hasContent()`, the `photos` accessor, and scopes `upcoming`/`onDate`/`isRecurring`. Grep finds **no production consumers of any of these** — the public meeting page gets real dates from `CalendarEvent` rows (synced from Google) via `PublicMeetingReadModelCache`, and photos via `MeetingShowPresenter::photos()`.

The admin form dutifully captures `meeting_date`/`is_recurring`/`frequency` (`app/Livewire/Forms/MeetingFormData.php`), but the only reader of those fields is the inline Schema.org `Schedule` markup in `meetings/show.blade.php:43-102`. So the church maintains two scheduling models — hand-entered recurrence rules and the actual Google Calendar — where the recurrence rules drive nothing a visitor sees except JSON-LD.

**Direction:** delete the occurrence calculators, the four event accessors, and the unused scopes/helpers now (~150 lines, zero risk). Separately decide (needs-decision item 6.4) whether the recurrence *fields* stay for schema markup or whether `Schedule` JSON-LD should be derived from `CalendarEvent` data, letting the fields and their form plumbing retire too.

### 4.6 Medium: `SitemapService` spends ~60% of its lines decorating URLs search engines mostly ignore

Evidence: `app/Services/Public/SitemapService.php`. Of 524 lines, roughly 300 exist to attach a *representative sermon image and lastmod* to archive/series/book landing URLs: three window-function subqueries (`getRepresentativeSermonsForStaticUrls`, and the ranked subqueries in `addBooks`/`addSeries`), each hand-selecting 12–17 columns, plus per-URL `priority`/`changefreq` settings. Google ignores `priority` and `changefreq` outright, and image-sitemap entries for *archive* pages (as opposed to the sermon detail pages, which already get their own entries) have negligible discovery value. Meanwhile generation is triggered per-request through a `Cache::flexible('sitemap', ...)` whose cached value is just `true` — the cache is being used as a timer (`SitemapController`).

**Direction:** emit a plain sitemap — detail URLs (sermons, pages, meetings, preachers) with lastmod, plus a static list of landing routes — and generate it on the existing scheduler next to `calendar:sync`, keeping the controller as a dumb file server. Plausibly 524 → ~150 lines, deletes the three window queries, and the four `app/Sitemap/` presenters shrink or fold into the service. The `whereVisibleInSitemap` exposure logic (the part that actually matters) is untouched. The corresponding ~1,176 lines of sitemap tests shrink with it.

### 4.7 Low: Google Calendar sync — the "bidirectional" part exists only to survive the sync's own overwrite

Evidence: `GoogleCalendarSyncService::syncSingleEvent()` unconditionally rewrites `meeting_slug` from Google on every sync (`GoogleCalendarSyncService.php:128-147`), so a manual categorisation made in the admin would be lost on the next nightly run — *unless* it is first written back into the Google event's private extended properties (`syncCategorizationToGoogle`/`removeCategorizationFromGoogle`, ~90 lines, plus the `CalendarCategorizationResult` DTO and write-scope credentials).

If sync instead preserved locally-categorised rows (skip the `meeting_slug` field when `is_categorized_automatically === false`), the write-back, its failure-handling, and the need for write credentials on the service account all disappear, and Google stays a read-only upstream. Cost: the categorisation is no longer visible inside the Google Calendar UI and would not survive a full local wipe — needs-decision item 6.5. Otherwise the sync is proportionate (263 lines, sensible seen/processed/deleted bookkeeping); this is *not* a case of runaway integration complexity.

Related small items: calendar admin exists as both classic controller pages and Livewire components (3.5) — converging on Livewire deletes `CalendarAdminController` + two Blade views; and `/calendar/uncategorized` is a **public** route (`routes/web.php:75`) exposing an operator worklist to visitors (question 8.2).

### 4.8 Low: the Pages CMS itself is proportionate — its cost is the read path, not the flexibility

The critical-friend question was whether the areas are effectively static and better served by plain Blade. Finding: **the split is already right.** The four area landing pages *are* plain Blade (`full-width-pages/*.blade.php`, hard-coded FAQ/schema/content), while the ~33 leaf pages are CMS rows that staff genuinely can edit (markdown + heading images through the admin). Reverting leaf pages to Blade would trade "editable without a developer" for nothing.

The disproportion is in the machinery per render (3.3: ten classes) and a few legacy warts on the model: the `admin` column is a `'yes'`/`'no'` string with `isAdminOnly()` defined but scattered raw comparisons (`$page->admin === 'yes'` in `PageLinksRepository`, `PageCardService`, `PublicPageVisibilityGuard`); `registerMediaConversions` generates six conversions per heading image of which two (`large`, `small`) are exact duplicates of `desktop`/`thumbnail` kept "for backwards compatibility" (`app/Models/Page.php:315-331`); and the per-slug view override (`PageController::resolveView`, with its own slug-coupling warning comment) exists for exactly one page (`pages/christ/free-bible.blade.php`). Each is a small deletion once decided.

### 4.9 Tests: solid coverage, three proportionality issues

The domain is well tested where it matters (visibility rules in `PublicReadSideInvariantsTest`, controller feature tests, N+1 guards). Issues:

1. **Dead-method pinning** (4.4): ~5 test files partially exist to exercise methods production never calls.
2. **Duplicate layers:** `PublicMeetingReadModelCacheTest` exists in both `tests/Feature/` (174 lines) and `tests/Integration/Services/` (212 lines) with overlapping intent; sitemap behaviour is spread across four files (~1,176 lines) for a 524-line service.
3. **Brittle UI-string assertions:** `ViewComposerTest` asserts exact Tailwind/Alpine class strings (`tests/Feature/ViewComposerTest.php:133-138`) and carries stale names (`it_populates_footer_with_latest_sermons` — the footer has been static links for some time).

Direction: prune alongside the code they pin; fold the meeting read-model tests into one file; keep the invariants suite as the domain's backbone.

## 5. Opportunities unlocked

1. **Site-wide SEO/metadata becomes one implementation instead of four.** With the single read-model convention (4.1), every route emits the same typed "page head" payload (title, description, canonical, OG image, JSON-LD). Today adding Open Graph images or fixing a metadata bug means touching sermons' `app/Seo`, meetings' inline Blade, static pages' hand-written component props, and CMS read models separately. After convergence it is one builder + one layout include — and things like per-page OG images or an `Event` rich-results pass become an afternoon, not a project.
2. **Page-speed work becomes cheap.** With TTL-based freshness (4.2) the read path stops depending on write-time invalidation correctness, which is precisely what makes full-page caching, CDN caching, or `Cache-Control` headers safe to add. The current observer web makes any additional cache another invalidation liability; the simplified scheme makes response caching a config change.
3. **A design-system pass gets a stable seam.** One read-model shape per page family means Blade templates consume documented props instead of a mixed bag of composer injections and inline lookups — templates can be restyled (or the `frontend-design` skill applied) without archaeology into where each variable comes from.
4. **Calendar-driven features get one source of truth.** Deleting the parallel recurrence subsystem (4.5) and deriving everything (display, JSON-LD `Schedule`, "next occurrence" badges) from `CalendarEvent` rows makes features like "what's on this week" strips or iCal feeds straightforward — the data is already synced and correct.
5. **Faster suite, less drag per change.** Retiring dead methods, duplicate test layers, and sitemap enrichment removes ~1,500+ test lines whose only job is defending code with no users, shrinking the cost of every future refactor in this domain.

## 6. Removal candidates (needs decision)

| # | Candidate | Cost of keeping | Cost/risk of removing |
|---|---|---|---|
| 6.1 | Dead read-path methods + accessors (4.4, 4.5): 3 `SermonRepository` methods, 2 `CalendarService` methods, `MeetingShowPresenter::layoutData` + `PageLayoutPresenter::present`, `Page::hasMeeting`, ~150 lines of `Meeting` accessors/calculators/scopes, and their tests | ~400 production lines + ~500 test lines maintained, misleading future readers into thinking these paths are live | Near zero — grep-verified no production callers; removal is mechanical |
| 6.2 | `PageShowComposer`, the three landing composers, `app/View/Presenters/` namespace (4.1) | Three extra indirection layers; hidden view↔provider coupling; a one-file namespace | Low — landing routes need a small controller method to pass card data explicitly; behaviour identical |
| 6.3 | Sitemap archive enrichment: representative-sermon window queries, `priority`/`changefreq`, archive images; request-triggered generation (4.6) | ~300 service lines + heavy tests, for metadata search engines document as ignored | Low — plain URLs + lastmod preserved; theoretical loss of image-sitemap hints on archive pages. Requires adding one scheduled command |
| 6.4 | `Meeting` recurrence fields (`meeting_date`, `is_recurring`, `frequency`) + form plumbing, once 4.5's dead calculators are gone | Operators hand-maintain schedule data duplicating Google Calendar; only consumer is inline JSON-LD | Medium — `Schedule` markup would need re-deriving from `CalendarEvent` (or dropping); admin form shrinks; migration to drop columns |
| 6.5 | Google categorisation write-back (`syncCategorizationToGoogle`/`removeCategorizationFromGoogle`, `CalendarCategorizationResult`) in favour of sync-preserves-manual-rows (4.7) | ~90 lines + write credentials + failure messaging for a mirror of local state | Low-medium — categorisation no longer visible in Google Calendar UI; wouldn't survive a local DB wipe. Needs the user's answer to Q8.1 |
| 6.6 | Sermon presenter memo machinery: `SermonPresenterCache`, identity stores, presenter-passback (4.3) | 7-file cluster, consumer-visible reset semantics, hardest-to-explain code on the read path | Medium effort, low risk — output shapes unchanged; needs a profiling sanity check that eager one-pass presentation doesn't regress the 24-item archive page |
| 6.7 | Duplicate `Page` media conversions (`large`/`small`) | Every heading upload renders 6 conversions; storage + processing waste | Low — regenerate or keep serving old files via fallback chain already in `PageImageCacheService` |
| 6.8 | `CalendarAdminController` + its two Blade views (converge on the Livewire calendar admin) | Two parallel admin surfaces for the same worklist | Low — Livewire side already does categorisation; patterns page is a read-only config dump easily ported |

## 7. Quick wins (<1 hour each)

1. Delete `SermonRepository::getRecentSermonsForJsonLd/getAllSermons/getLatestSermons` + the `sermons_jsonld_recent_100` line in `clearListingCaches()` + their test blocks.
2. Delete `CalendarService::getEventsForMeeting/getAllUpcomingEvents` + test blocks.
3. Delete `MeetingShowPresenter::layoutData()` and `PageLayoutPresenter::present()` (keep `photos()`/`renderContent()`), drop `MeetingShowPresenter`'s now-unused constructor dependency.
4. Delete `Page::hasMeeting()` and `Meeting`'s unused scopes/accessors (`scopeUpcoming`, `scopeOnDate`, `scopeIsRecurring`, `upcomingEvents`, `pastEvents`, `nextEvent`, `lastEvent`, `formattedDateTime`, `hasPhotos`, `hasContent`, `photos`).
5. Move `PageLinksRepository` into `app/Presenters/` (or inline into `RelatedPagePresenter`) and delete the `app/View/Presenters/` directory.
6. Delete `PageShowComposer` + its `ViewServiceProvider` line (verify `pages.show` defaults via the existing `ViewComposerTest::it_keeps_explicit_data_when_rendering_the_page_show_view`).
7. Rename/retire the stale `it_populates_footer_with_latest_sermons` test.
8. Replace raw `$page->admin === 'yes'` comparisons with the existing `Page::isAdminOnly()`.

## 8. Open questions for the user

1. **Does anyone look at meeting categorisation inside the Google Calendar UI** (the private extended properties), or is it only consumed by this site? Decides 6.5.
2. **Is `/calendar/uncategorized` meant to be publicly accessible?** It renders an operator worklist ("events needing assignment") with no auth (`routes/web.php:75`); the admin has its own copy.
3. **Are the three members-area CMS pages actually used** by members, and does the members area have a future beyond the dashboard? (Affects how much of the `PageArea::Members` guard duplication is worth carrying.)
4. **Do operators rely on the hand-entered meeting recurrence fields** (`meeting_date`/`is_recurring`/`frequency`), or is Google Calendar the real source of truth? Decides 6.4.
5. **Is the `/christmas` static page still wanted year-round** (it's routed, sitemapped at priority 0.8, and hard-coded to 2023 imagery), or should seasonal pages be CMS pages that can be unpublished?

## 9. Out of scope, noted for later phases

- **Phase 4 (Songs):** `PublicSongCatalogService` and `PublicSongUsageService` build near-identical "qualifying usage" subqueries (completed-livestream/confirmed-match rules duplicated in both); candidate for one shared query object.
- **Phase 6 (Admin/Livewire):** `ChurchServiceShowPresenter` + `ChurchServiceShowReadModel` + the `app/Queries/` objects are admin-side read path; assess together with the ChurchServices Livewire fleet. The calendar admin duplication (6.8) overlaps with that phase's consistency question.
- **Phase 7 (Platform):** `MeetingPhotoMigrationService` sits in `app/Services/` root and looks like a spent one-off; the `SanitizesLogData` trait usage and `config/calendar.php`'s unused keys (`uncategorized_slug`, `performance.cache_duration`) belong to the config-sprawl sweep; `Cache::flexible` internal-key coupling is also a candidate for a shared helper there.
- **Phase 9 (Code quality):** the repeated `memoizedPresents`/`computed` boilerplate (if any survives Phase 8 decisions), the `@phpstan-ignore-next-line` cluster in `GoogleCalendarSyncService`, and `ViewComposerTest`'s CSS-string assertions.
