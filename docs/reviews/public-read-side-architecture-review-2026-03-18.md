# Public Read-Side Architecture Review

Date: 2026-03-18

## Scope

Reviewed the public read-side flow across:

- `routes/web.php`
- `PageController`
- `SermonController`
- `MeetingController`
- `PageRepository`
- `SermonRepository`
- `LayoutPageComposer` and related presenters/composers
- `Page`, `Sermon`, `Meeting`, and related read-side model accessors
- nearby feature/presenter tests

## Executive Summary

The repositories and a few small policy/services still earn their keep. The `layouts/page` composer/presenter layer does not. It has drifted into a second controller layer that reconstructs page state from URL segments, performs hidden database/storage work during render, and creates multiple sources of truth for visibility, canonical URLs, and page metadata.

If this area is going to be simplified, the highest-value move is:

1. Keep repositories for cached browse queries and explicit exposure policies.
2. Make controllers responsible for assembling the full public view model.
3. Turn `layouts/page` into a passive layout instead of a route-aware resolver.

## Findings

### 1. High: unknown one-segment URLs can return `200 OK` with a generic welcome page

The catch-all `/{area}` route accepts any top-level segment and hands it to `PageController::showPage()` ([`routes/web.php:216-218`](../../routes/web.php), [`app/Http/Controllers/PageController.php:23-45`](../../app/Http/Controllers/PageController.php)). When no matching landing page exists, the controller still returns `layouts/page` instead of aborting. The layout composer then falls through to `AreaLandingPresenter`, which supplies `"Welcome"` defaults rather than a `404` ([`app/View/Presenters/AreaLandingPresenter.php:15-50`](../../app/View/Presenters/AreaLandingPresenter.php)).

That means a typo like `/not-a-real-section` is treated as a successful page render instead of a broken link. It also creates duplicate landing-page URLs because `/{area}/{slug}` happily serves `slug === area`, while `Page::route` always emits the two-segment form ([`app/Models/Page.php:94-98`](../../app/Models/Page.php)).

Why it matters:

- Broken links and typos are masked as valid content.
- CDN/browser caches can store synthetic pages for invalid URLs.
- Search engines can discover extra low-value pages and duplicate landing-page URLs.

Suggested direction:

- Restrict the route to known areas, or abort when no landing page exists.
- Redirect `/{area}/{area}` to `/{area}`.
- Add tests for an unknown top-level segment returning `404`.

### 2. High: meeting pages bypass page-level visibility rules, and the meeting/page invariant is not enforced

`MeetingController::show()` eagerly loads the related page and then renders its content directly, but it never applies the same admin/member visibility checks that `PageController` applies ([`app/Http/Controllers/MeetingController.php:38-88`](../../app/Http/Controllers/MeetingController.php), [`app/Http/Controllers/PageController.php:23-67`](../../app/Http/Controllers/PageController.php)). The admin editor also allows any `Page` to be linked to a meeting, or no page at all, because `pageId` is only validated as `exists:pages,id` and the dropdown loads every page without filtering ([`app/Livewire/Admin/Meetings/MeetingForm.php:43-62`](../../app/Livewire/Admin/Meetings/MeetingForm.php), [`app/Livewire/Admin/Meetings/CreateMeeting.php:57-71`](../../app/Livewire/Admin/Meetings/CreateMeeting.php), [`app/Livewire/Admin/Meetings/EditMeeting.php:73-89`](../../app/Livewire/Admin/Meetings/EditMeeting.php)).

This produces two separate issues:

- A meeting can publicly expose content from an admin-only or members-only page.
- A page-less meeting still returns `meetings.show`, but the layout composer will ignore the controller-provided heading/content and re-resolve the request through `SectionPagePresenter`, which returns `null` metadata if no page exists ([`app/View/Composers/LayoutPageComposer.php:31-42`](../../app/View/Composers/LayoutPageComposer.php), [`app/View/Presenters/SectionPagePresenter.php:15-47`](../../app/View/Presenters/SectionPagePresenter.php)).

There is also a content-format drift here: `MeetingController` passes `$page?->body` explicitly, which bypasses the markdown rendering path used elsewhere ([`app/Http/Controllers/MeetingController.php:68-87`](../../app/Http/Controllers/MeetingController.php), [`app/View/Composers/LayoutPageComposer.php:48-55`](../../app/View/Composers/LayoutPageComposer.php)).

Suggested direction:

- Treat a public meeting page as either:
  - a first-class meeting read model that owns its own content fields, or
  - a thin wrapper around a required public `Page`.
- If the second option is kept, enforce `page_id` as required and restricted to public community pages.
- Reuse the same visibility/markdown rules as `PageController`.

### 3. Medium: visibility filtering is opt-in, so admin pages can leak into public navigation and cards

The low-level page cache is not actually scoped to “public” pages. `PageRepository::getAllLinksForArea()` caches every page in the area, including admin-only pages ([`app/Repositories/PageRepository.php:19-29`](../../app/Repositories/PageRepository.php)). Some callers remember to exclude admin pages later, but others do not.

Two public entry points are especially exposed:

- `HeaderComposer` caches all navigation pages with no admin filter ([`app/View/Composers/HeaderComposer.php:14-21`](../../app/View/Composers/HeaderComposer.php)).
- `PageCardService::forHome()`, `forCommunity()`, and `forChurch()` filter by slug only, not visibility ([`app/Services/PageCardService.php:21-55`](../../app/Services/PageCardService.php), [`app/Services/PageCardService.php:79-88`](../../app/Services/PageCardService.php)).

So the visibility rule is currently “safe if every caller remembers to filter.” That is fragile, especially in an editor-driven site where `navigation` and page relationships can change without code changes.

Suggested direction:

- Add a single repository/query scope for “public page links”.
- Make admin exclusion the default, and require an explicit opt-in for private/admin reads.
- Add a feature test that a `navigation=true` admin page never appears in the public header.

### 4. Medium: sermon detail rendering still does route-driven presenter work after the controller already resolved the sermon

`SermonController::showWithDate()` validates the bound sermon and then delegates to `show()` ([`app/Http/Controllers/SermonController.php:184-201`](../../app/Http/Controllers/SermonController.php)). But the layout layer then decides that five-segment sermon URLs need `SermonDetailPresenter`, which re-queries `Sermon` from the database using route segments ([`app/View/Composers/LayoutPageComposer.php:77-95`](../../app/View/Composers/LayoutPageComposer.php), [`app/View/Presenters/SermonDetailPresenter.php:16-52`](../../app/View/Presenters/SermonDetailPresenter.php)).

The slug-only sermon route has the opposite problem: it falls into `DeepPagePresenter`, which does not understand sermon routes as first-class objects and resets `slug` to segment 2 (`sermons`) instead of the actual sermon slug ([`app/View/Presenters/DeepPagePresenter.php:14-39`](../../app/View/Presenters/DeepPagePresenter.php), [`app/Http/Controllers/SermonController.php:80-88`](../../app/Http/Controllers/SermonController.php)).

The existing presenter test suite is mostly locking this behavior in rather than challenging it. `SermonDetailPresenterTest` explicitly exercises the extra query-based presenter path instead of asserting controller state reuse ([`tests/Feature/View/Presenters/SermonDetailPresenterTest.php:33-77`](../../tests/Feature/View/Presenters/SermonDetailPresenterTest.php)).

Suggested direction:

- Stop resolving sermon detail metadata from URL segments in the layout.
- Let sermon controllers pass the complete layout state, including related links if they are still wanted.
- Replace presenter tests with HTTP-level assertions against the rendered routes.

### 5. Medium: canonical URL rules are internally inconsistent across HTML, sitemap, and RSS

For sermons, `SermonExposurePolicy::publicUrl()` points at the slug route, while `canonicalUrl()` points at the date route ([`app/Services/SermonExposurePolicy.php:43-73`](../../app/Services/SermonExposurePolicy.php)). The public sermon page uses `public_url` as the canonical tag ([`resources/views/sermons/sermon.blade.php:10-25`](../../resources/views/sermons/sermon.blade.php)), but the sitemap presenter uses `canonicalUrl()` ([`app/Presenters/SermonSitemapPresenter.php:20-40`](../../app/Presenters/SermonSitemapPresenter.php)).

That means the HTML page says one URL is canonical while the sitemap advertises another. The app currently treats both as valid public read paths, but different channels disagree about which one should be indexed.

There is a second duplication issue in community URLs. Meetings were migrated to reuse or create community pages with the same slug ([`database/migrations/2026_01_21_170857_create_pages_for_meetings.php:14-47`](../../database/migrations/2026_01_21_170857_create_pages_for_meetings.php)). `SitemapService` then adds all public pages and all meetings ([`app/Services/SitemapService.php:65-93`](../../app/Services/SitemapService.php)). Spatie deduplicates by URL only at render time, so whichever tag is added first wins ([`vendor/spatie/laravel-sitemap/src/Sitemap.php:69-75`](../../vendor/spatie/laravel-sitemap/src/Sitemap.php)). In practice that means page metadata shadows meeting metadata for shared `/community/{slug}` URLs.

Suggested direction:

- Pick one canonical sermon URL and use it everywhere.
- In the sitemap, emit either community pages or meetings for shared URLs, not both.
- Add a regression test that canonical tags, sitemap entries, and feed links agree on the same sermon URL shape.

### 6. Low: the “cached” read side still performs hidden filesystem/storage I/O during render

Several hot public paths look cheap at controller level but still do late I/O:

- `PageRepository` caches pages plus media relations, but page image accessors still call `Storage::exists()`/`url()` when no generated media conversion is present ([`app/Repositories/PageRepository.php:23-29`](../../app/Repositories/PageRepository.php), [`app/Models/Page.php:237-311`](../../app/Models/Page.php)).
- `<x-page-header>` calls `file_exists()` during rendering ([`resources/views/components/page-header.blade.php:8-13`](../../resources/views/components/page-header.blade.php)).
- Sermon detail templates pull audio/video/thumbnail/transcript through model accessors that resolve services and, for transcripts, read storage at render time ([`app/Models/Sermon.php:163-176`](../../app/Models/Sermon.php), [`app/Models/Sermon.php:421-443`](../../app/Models/Sermon.php), [`app/Models/Sermon.php:621-623`](../../app/Models/Sermon.php)).

This does not mean the accessors are wrong, but it does mean the current architecture hides meaningful I/O behind presentation objects. That makes it harder to reason about cacheability and query budgets from controller/repository code alone.

There is also a cache invalidation blind spot: page-link caches include `media`, but cache busting only runs on `Page` model events, not on media-library model changes ([`app/Repositories/PageRepository.php:23-29`](../../app/Repositories/PageRepository.php), [`app/Observers/SitemapCacheObserver.php:46-61`](../../app/Observers/SitemapCacheObserver.php)).

Suggested direction:

- Move expensive read decisions into explicit view models or services.
- Cache resolved image URLs if storage-backed fallbacks remain necessary.
- Add cache invalidation for page media changes, or stop caching serialized page+media models if those relations are expected to drift independently.

## Does The Controller / Composer / Presenter Split Still Earn Its Complexity?

Short answer: partially.

What still earns its keep:

- `SermonRepository` and `PageRepository` centralize list queries and cache keys.
- `SermonExposurePolicy` is a good boundary for children’s-talk visibility rules.
- `SermonPageContextService` keeps secondary sermon-detail lookups out of controllers.

What no longer earns its keep:

- `LayoutPageComposer` is effectively a second router/controller for `layouts/page`.
- Presenter resolution is based on URL shape, not on explicit controller intent.
- The same layout data can come from a controller, a presenter query, or a model accessor depending on the route.
- That indirection is no longer just “presentation”; it changes query behavior, visibility behavior, and even whether a route returns meaningful content.

My read is that this split was probably useful when many pages were thin and route-driven. It has now crossed the line where it obscures behavior instead of simplifying it.

## Tests To Add Before Refactoring

- A feature test asserting `/definitely-not-a-real-section` returns `404`.
- A feature test asserting a meeting linked to an admin-only or members-only page is not publicly readable.
- A feature test asserting a page-less meeting still renders the intended heading/content, or is rejected earlier.
- A regression test asserting sermon canonical URLs match between HTML, sitemap, and feed output.
- Query-budget tests around slug and date sermon detail routes if the presenter layer is kept.
