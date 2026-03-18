# Laravel / Livewire Idioms Review (2026-03-18)

## Scope

- Reviewed the current codebase against the installed framework versions: Laravel `12.51.0` and Livewire `3.6.4`.
- Focused on patterns that add framework-level complexity without adding business value: legacy Livewire v2 conventions, controller/service boundary leaks, model overreach, and homegrown routing/presentation plumbing.
- Static review only. I did not run the browser flows, tests, or performance tooling for this report.

## Executive Summary

- The codebase already uses some modern Laravel 12 foundations well: `bootstrap/app.php` is current, enums are widely used, and many write paths already delegate to services or actions.
- The biggest cleanup opportunity is not in the domain rules themselves; it is in the remaining framework plumbing around them.
- The highest-value modernization sequence is:
  1. Replace legacy Livewire query-string arrays with `#[Url]`.
  2. Replace trait-based admin form state with `Livewire\Form` objects.
  3. Remove duplicated admin authorization plumbing in routed Livewire components and lean on route middleware plus policies.
  4. Extract shared read/write services from fat components and stop treating controllers as service objects.
  5. Slim the `Sermon` model so it stops doing storage I/O and presentation work.

## Findings

### P1. Routed admin Livewire components still carry a custom authorization layer that Livewire 3 can already provide

`routes/web.php:121-180` already places the routed admin Livewire surface behind `auth`, `verified`, and `admin` middleware. On top of that, `app/Livewire/Traits/WithAdminAuthorization.php:7-12` adds a second authorization path that only checks `is_admin`, and many components call it in `mount()` and in every mutating action, for example `app/Livewire/Admin/Sermons/ListSermons.php:61-79`, `app/Livewire/Admin/ChurchServices/ManageChurchService.php:53-56`, and `app/Livewire/Admin/ChurchServices/ServiceReviewDashboard.php:47-55`.

This is legacy Livewire-style defensive plumbing. In Livewire 3, route middleware on full-page components is persisted across requests, so duplicating it in every component adds boilerplate and weakens the effective contract by dropping the `verified` check. The same drift shows up in the old gate layer in `app/Providers/AuthServiceProvider.php:31-41` and the members dashboard view checks in `resources/views/members/home.blade.php:28-125`.

Safe replacement:

- Keep `auth`, `verified`, and `admin` on the routed admin group.
- Remove `WithAdminAuthorization` from full-page routed admin components.
- Keep model-specific authorization in policies where the rule is richer than “admin only”.
- Collapse the trivial `manage-*` gates once the affected views are switched to the canonical middleware/policy path.

### P1. The admin Livewire surface still uses the old `$queryString` API instead of Livewire 3 `#[Url]`

I did not encounter any `#[Url]` usage during the scan. Instead, routed components are still wiring URL state through legacy `$queryString` arrays, for example `app/Livewire/Admin/Sermons/ListSermons.php:58-60` and `app/Livewire/Admin/ChurchServices/ManageChurchService.php:50-52`. The same pattern appears across the admin list/filter surface (`ListPages`, `ListMeetings`, `ListUsers`, `ListChurchServices`, `ListSongs`, `ReviewInboundEmails`, `ListSectionPublications`, `ListCalendarEvents`, `ListPreachers`).

That is not broken, but it is outdated for Livewire 3 and makes filter state harder to reason about because URL behavior lives in a hidden array instead of on the property itself.

Safe replacement:

- Convert filter/search properties to typed properties with `#[Url]`.
- Use `except`, aliases, and boolean defaults on the attribute instead of maintaining a parallel `$queryString` array.
- Start with read-only list components first because they are low-risk and highly repetitive.

### P1. Reusable admin forms are still trait-based instead of using `Livewire\Form`

`app/Livewire/Admin/Pages/PageForm.php:11-70` and `app/Livewire/Admin/Meetings/MeetingForm.php:10-91` are both homegrown form objects implemented as traits. They carry state, validation, transformation rules, and helper methods, while the host components still have to map model state in and out manually, for example `app/Livewire/Admin/Pages/CreatePage.php:13-44`, `app/Livewire/Admin/Pages/EditPage.php:13-52`, `app/Livewire/Admin/Meetings/CreateMeeting.php:14-72`, and `app/Livewire/Admin/Meetings/EditMeeting.php:14-90`.

This is exactly the problem Livewire 3 `Form` objects were added to solve. The current trait approach works, but it hides required host properties (`$page`, `$meeting`), duplicates model-to-form mapping, and makes the components carry ceremony that is not business logic.

Safe replacement:

- Create `PageFormData` and `MeetingFormData` classes extending `Livewire\Form`.
- Move rules, normalization, and derived helper methods into the form classes.
- Let `Create*` and `Edit*` components keep only `mount()`, `save()`, and page-level render concerns.
- Use the same move on `ManageChurchService` later once the simpler form traits are converted successfully.

### P1. `ProcessingLogsViewer` calls an HTTP controller as if it were an application service

`app/Livewire/ProcessingLogsViewer.php:81-100` fetches data by resolving a controller in `findControllerForProcessingId()`, and `app/Livewire/ProcessingLogsViewer.php:287-295` directly pulls `App\Http\Controllers\Api\MediaController` from the container. That controller is itself implementing `ProcessingStatusContract` in `app/Http/Controllers/Api/MediaController.php:19-148`.

This is a clear boundary leak. Controllers should adapt HTTP to an application service, not become shared service objects for other framework layers. The current shape makes the Livewire component depend on HTTP-oriented code for no business reason.

Safe replacement:

- Extract a dedicated status/query service such as `GetMediaProcessingStatus`.
- Let `MediaController` call that service for JSON responses.
- Let `ProcessingLogsViewer` inject the same service directly or inject `UnifiedMediaProcessor` if that is already the true application boundary.
- Drop `ProcessingStatusContract` from the controller layer once the shared service exists.

### P2. `ServiceReviewDashboard` is doing read-model building, validation, orchestration, and render-side state mutation in one class

`app/Livewire/Admin/ChurchServices/ServiceReviewDashboard.php` is `851` lines long. The main problems are structural rather than domain-specific:

- `saveSection()` manually assembles payloads and runs `Validator::make(...)` in-component: `app/Livewire/Admin/ChurchServices/ServiceReviewDashboard.php:72-113`.
- The component performs write orchestration directly, including publication-state transitions and manual-review metadata writes: `app/Livewire/Admin/ChurchServices/ServiceReviewDashboard.php:116-170` and `app/Livewire/Admin/ChurchServices/ServiceReviewDashboard.php:199-274`.
- `render()` mutates component state by calling `seedSectionEdits(...)`: `app/Livewire/Admin/ChurchServices/ServiceReviewDashboard.php:276-290` and `app/Livewire/Admin/ChurchServices/ServiceReviewDashboard.php:453-479`.
- The read side is a hand-built dashboard assembler with grouping, sorting, counters, and service identity matching: `app/Livewire/Admin/ChurchServices/ServiceReviewDashboard.php:344-567`.

The core issue is that this is no longer a UI component with a few callbacks; it is a full application service plus read model plus cache of editable row state. Livewire 3 gives better shapes for this: dedicated actions, `Form` objects, smaller child components, and computed/read-only properties.

Safe replacement:

- Extract a dashboard query/read model for `reviewGroups()`, `summary()`, and service identity resolution.
- Extract focused write actions for “save reviewed section”, “mark service reviewed”, and “approve pending publications”.
- Move editable row state into child components or form objects so `render()` becomes side-effect free.

### P2. `ManageChurchService` is still a hand-built array state machine instead of a structured Livewire 3 form flow

`app/Livewire/Admin/ChurchServices/ManageChurchService.php` still follows a Livewire v2-era pattern:

- URL state uses legacy `$queryString`: `app/Livewire/Admin/ChurchServices/ManageChurchService.php:50-52`.
- Nested service items are managed as raw arrays with manual move/reset/sync logic: `app/Livewire/Admin/ChurchServices/ManageChurchService.php:45-48`, `app/Livewire/Admin/ChurchServices/ManageChurchService.php:121-191`.
- Validation is manual and centered on `items.*` arrays: `app/Livewire/Admin/ChurchServices/ManageChurchService.php:85-119`.
- The save path opens a transaction and coordinates canonical snapshots, item syncing, song linking, and inbound-email review completion: `app/Livewire/Admin/ChurchServices/ManageChurchService.php:193-255`.
- Additional parsing and prefill logic is still embedded in the component via service-locator calls: `app/Livewire/Admin/ChurchServices/ManageChurchService.php:401-455`.

This works, but the component is paying a lot of framework tax to represent a form that Livewire 3 can model more explicitly.

Safe replacement:

- Use `#[Url]` for `inboundEmailId`.
- Move top-level fields and item normalization into a `Livewire\Form`.
- Consider a child component or nested form object per service item instead of a raw `array<int, array{...}>`.
- Leave the transaction and canonical update workflow in an application service or action, not in the component.

### P2. `Sermon` still mixes ORM behavior with storage I/O, exposure policy, and presentation concerns

`app/Models/Sermon.php` is still one of the clearest model-boundary problems in the codebase:

- URL accessors resolve storage services directly: `app/Models/Sermon.php:163-176` and `app/Models/Sermon.php:621-624`.
- Public/canonical URL and sitemap behavior resolve services/presenters from the model: `app/Models/Sermon.php:196-199`, `app/Models/Sermon.php:279-287`, `app/Models/Sermon.php:715-725`.
- `getTranscriptAttribute()` performs storage reads and logging side effects inside the model accessor: `app/Models/Sermon.php:413-444`.

None of this is domain state. It makes innocent-looking property access (`$sermon->transcript`, `$sermon->thumbnail_url`, `$sermon->canonical_url`) potentially expensive and surprising, and it keeps container lookups inside the entity itself.

Safe replacement:

- Keep relationships, casts, and pure scopes on the model.
- Move transcript loading and read-disk fallback fully into `TranscriptStorageService` or a dedicated transcript presenter/value object.
- Move URL-generation concerns into presenters/resources/view models.
- Keep sitemap formatting in `SermonSitemapPresenter` only and stop resolving it from the model.

### P3. Public page rendering still depends on a homegrown request-segment router in a view composer

`app/Providers/ViewServiceProvider.php:30-38` registers `LayoutPageComposer` on `layouts/page`. That composer then chooses a presenter by inspecting raw request segments in `app/View/Composers/LayoutPageComposer.php:31-42` and `app/View/Composers/LayoutPageComposer.php:77-95`. Meanwhile `app/Http/Controllers/PageController.php:23-85` still does its own page lookup, auth gating, markdown conversion, and view-data assembly for the same layout.

This is a legacy Blade-era abstraction that now hides route behavior in two places at once:

- Controllers decide some page behavior.
- The layout composer decides other page behavior by guessing from URL shape.

That makes the presentation layer more magical than it needs to be.

Safe replacement:

- Let controllers or route handlers choose the presenter explicitly.
- If the generic page system stays, use route names or a page-type enum, not `request()->segment()` heuristics.
- Keep view composers for truly shared decoration, not route-level dispatch.

### P3. The sermon route/controller surface still uses legacy naming and compatibility endpoints instead of Laravel conventions

The sermon route group in `routes/web.php:58-103` is the biggest remaining legacy naming pocket in the app. It still uses route names like `sermonIndex`, `allSermons`, `getPreachers`, `getSerieses`, `showSermonWithDate`, and controller methods like `getAll()`, `getPreachers()`, `getSerieses()`, `getService()`, and `showWithDate()` in `app/Http/Controllers/SermonController.php:47-213`.

This is not harmful by itself, but it keeps routing, views, tests, and redirects tied to a custom naming scheme instead of Laravel’s predictable controller/resource conventions. The extra compatibility redirects in the same route group show the surface is already carrying historical weight.

Safe replacement:

- Normalize to dotted route names and conventional action names.
- Keep redirect aliases only as a transition layer.
- Treat the sermon surface as a focused routing cleanup rather than a behavior change.

## Suggested Order

1. Convert the list/filter components from `$queryString` to `#[Url]`.
2. Replace `PageForm` and `MeetingForm` with real `Livewire\Form` objects.
3. Remove `WithAdminAuthorization` from routed admin components and collapse the trivial `manage-*` gates.
4. Extract a shared media-processing status query service and stop resolving `MediaController` from Livewire.
5. Split `ServiceReviewDashboard` and `ManageChurchService` into read-model plus action/service boundaries.
6. Slim `Sermon` model accessors so they stop doing storage I/O and presentation work.
7. Clean up the page-composer router and sermon route naming once the higher-value Livewire work is finished.

## Not Flagged As Priorities

- `bootstrap/app.php` is already using the Laravel 12 bootstrap model correctly.
- Most small CRUD Livewire components are not urgent from a modernization perspective once the form and authorization layers are cleaned up.
- Focused service classes are generally a strength in this codebase; the main issue is where UI/framework layers are still doing service work themselves.
