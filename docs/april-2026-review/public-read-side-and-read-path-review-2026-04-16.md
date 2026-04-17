# Public Read Side And Read-Path Review

Date: 2026-04-16

## Findings

### 1. High: the sermon archive still pays for the browse query twice on first render, and then rebuilds filter option sets on every Livewire round-trip

File refs: `app/Http/Controllers/SermonController.php:38-57`, `resources/views/sermons/index.blade.php:36-37`, `app/Livewire/Sermons/BrowseSermons.php:88-118`, `app/Livewire/Sermons/BrowseSermons.php:132-166`

`SermonController::index()` paginates the archive solely to build `json_ld_data`, but the actual page body is rendered by `<livewire:sermons.browse-sermons />`, whose `render()` method immediately runs the same `publicBrowseQuery(...)->paginate(24)` again. That means the main public archive route does the expensive read twice on first paint. After that, each Livewire update also rebuilds preacher options, series options, and the enabled book/chapter lists, including two distinct `sermon_scripture_filters` queries.

This is the clearest remaining hotspot on a primary public route because it compounds both server work and perceived latency on the page that users are most likely to browse interactively.

Smallest safe improvement: let one layer own the initial archive payload. Either move the JSON-LD generation into the Livewire component from the already-loaded paginator, or pass the controller paginator into a non-querying initial component state. Separately, cache or precompute the public filter manifest so book/chapter options are not recomputed on every round-trip.

### 2. High: sermon detail pages eagerly read and ship the full transcript even when the panel stays collapsed

File refs: `app/Presenters/SermonViewPresenter.php:257-272`, `app/Services/SermonTranscriptReader.php:28-68`, `resources/views/sermons/sermon.blade.php:154-202`

The full sermon presenter always calls `SermonTranscriptReader::read($sermon)`, and the Blade view embeds the entire transcript inside the response HTML even though the UI starts collapsed behind Alpine state. The transcript cache avoids repeated storage hits, but it does not avoid the main public cost: large transcript strings still have to be fetched from cache, kept in memory, transformed through `Str::markdown(...)`, and sent over the wire on every sermon detail request.

This makes sermon detail pages heavier than they look from the controller, and it pushes response-size cost onto every reader rather than only the users who actually expand the transcript.

Smallest safe improvement: lazy-load the transcript body behind the expand action, either through a small Livewire lazy component or a lightweight transcript endpoint/fragment. Keep the transcript presence flag in the initial payload, but do not render the full transcript HTML until the user asks for it.

### 3. Medium: meeting event archive pages still load full history, re-sort in PHP, and only then trim what gets rendered

File refs: `app/Http/Controllers/CalendarController.php:44-60`, `app/Services/CalendarService.php:21-41`, `resources/views/meetings/events.blade.php:71-109`

`eventsForMeeting()` loads every confirmed event for a meeting, sorts the collection again in PHP even though the query is already ordered, and then the view splits that full collection into upcoming and past groups. The view only renders the first 20 past events, but the controller has already paid to fetch the entire history.

This is not likely to hurt small meetings, but it scales poorly for long-running ministries whose calendar history keeps growing. It is also one of the remaining places where the view layer decides how much of an unbounded dataset the user actually needs.

Smallest safe improvement: query upcoming events and recent past events separately at the database level, with explicit limits or pagination for the past side. That keeps the route fast without changing the current UI shape.

### 4. Low: public landing pages still hydrate whole-area page caches to render small curated card sets

File refs: `app/Repositories/PageRepository.php:19-30`, `app/Services/PageCardService.php:23-58`, `app/Services/PageCardService.php:63-95`

The public page-link cache is now correctly scoped to public pages, which is a real improvement. But the landing-card services for home, church, and community still read the entire cached collection for an area, hydrate those `Page` models plus `media`, and then filter in PHP to a small hard-coded subset of slugs.

That is perfectly acceptable at the current content size, but it keeps the hottest landing pages tied to broad area-cache deserialization when they only need a handful of cards.

Smallest safe improvement: add small surface-specific caches for the already-presented arrays used by home, church, and community card rails, or query only the needed slugs when the set is intentionally curated.

### 5. Low: the public shell is cleaner than in March, but important view composition is still partly implicit and view-time

File refs: `app/View/Composers/HeaderComposer.php:14-22`, `resources/views/layouts/main.blade.php:91-93`, `resources/views/components/layout/header.blade.php:3-7`, `resources/views/components/layout/header.blade.php:114-189`, `resources/views/components/breadcrumbs.blade.php:7-16`, `resources/views/sermons/serieses.blade.php:27-37`

The big route-driven layout indirection is gone, but the shell still relies on a mix of composer-injected data, `request()` checks inside Blade, container lookups from the view, and even a synthetic `Sermon` model in `serieses.blade.php` just to generate a series URL. None of this is a large runtime cost by itself, but it keeps the public composition boundary blurrier than it needs to be and makes render-time behavior harder to reason about.

Smallest safe improvement: pass explicit shell-ready data into these views and components. In practice that means `pagesByArea`, `showChildrensCorner`, prebuilt breadcrumb items/JSON-LD, and direct series URLs instead of constructing them through view-time service resolution.

## Open Questions

1. Is there any product or SEO reason the full sermon transcript must be present in the initial HTML response, or would a lazy-loaded transcript be acceptable?

2. Should `/meetings/{meeting}/events` behave as a browsable archive, with pagination for older events, or is the real intent just “all upcoming plus a recent past slice”?

3. Are the home/church/community card rails meant to stay as fixed editorial curation, or should they eventually become editor-managed? That answer changes whether separate surface caches are worth introducing.

## What Improved Since March

The public read side is materially clearer than it was in March. Unknown top-level areas now fail closed with `404`, page and meeting visibility rules are centralized through `PublicPageVisibilityGuard`, and the old route-shape-heavy `LayoutPageComposer` has been reduced to a passive data pass-through. The page and meeting read-model caches are real, and media/page/calendar invalidation now reaches the relevant read-side caches instead of leaving obvious drift gaps.

The sermon side is also more coherent. Canonical sermon URLs now agree across routes and invariants, the slug-only route redirects to the date-based canonical path, and the reading-reference lookup has been narrowed to a direct `service_sections` query instead of hydrating the whole section graph. Schema-side support is also much healthier now, with the relevant public read paths backed by the indexes added around pages, sermon scripture filters, service sections, and calendar events.
