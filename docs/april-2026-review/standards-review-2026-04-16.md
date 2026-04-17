# Standards Review

Date: 2026-04-16

## Findings

### P1. Routed admin Livewire authorization is still split between route middleware and ad-hoc component checks

The admin surface is correctly grouped behind `auth`, `verified`, and `admin` at the route level in [routes/web.php](/Users/garethclarridge/Projects/crockenhill/routes/web.php:115), but the app does not register any Livewire persistent middleware in [AppServiceProvider.php](/Users/garethclarridge/Projects/crockenhill/app/Providers/AppServiceProvider.php:33). Thirty Livewire classes now compensate with the custom [WithAdminAuthorization trait](/Users/garethclarridge/Projects/crockenhill/app/Livewire/Traits/WithAdminAuthorization.php:7), for example [ListSermons.php](/Users/garethclarridge/Projects/crockenhill/app/Livewire/Admin/Sermons/ListSermons.php:25), [ManageChurchService.php](/Users/garethclarridge/Projects/crockenhill/app/Livewire/Admin/ChurchServices/ManageChurchService.php:27), and [ServiceReviewDashboard.php](/Users/garethclarridge/Projects/crockenhill/app/Livewire/Admin/ChurchServices/ServiceReviewDashboard.php:29). That removes the original “everything is defined by the route contract” simplicity and still leaves `verified` outside the component-side recheck. The remaining drift is not just duplication now; it is an inconsistent authorization model across first page load vs. later Livewire updates.

### P2. `ManageChurchService` is still a large array-state transport layer rather than a structured Livewire form boundary

March’s extraction of persistence work into [SaveChurchServiceFromAdmin.php](/Users/garethclarridge/Projects/crockenhill/app/Actions/SaveChurchServiceFromAdmin.php:17) helped, but [ManageChurchService.php](/Users/garethclarridge/Projects/crockenhill/app/Livewire/Admin/ChurchServices/ManageChurchService.php:24) still owns the mutable `items` array, inline validation rules/messages, row reordering, song lookup queries, and the `buildSyncPayload()` contract that the action consumes. The result is that the UI component still defines both the browser state shape and the application-layer write payload, so the action boundary is only partial. This is the clearest remaining place where the March cleanup improved orchestration but left the component tightly coupled to domain input normalization.

### P2. `ServiceReviewDashboard` now has extracted query/action collaborators, but `render()` still mutates write-state

The split into [ServiceReviewDashboardQuery.php](/Users/garethclarridge/Projects/crockenhill/app/Queries/ServiceReviewDashboardQuery.php:19) and focused actions is a real improvement, but [ServiceReviewDashboard.php](/Users/garethclarridge/Projects/crockenhill/app/Livewire/Admin/ChurchServices/ServiceReviewDashboard.php:226) still seeds `$sectionEdits` and `$speakerEdits` during `render()` via [seedSectionEdits()](/Users/garethclarridge/Projects/crockenhill/app/Livewire/Admin/ChurchServices/ServiceReviewDashboard.php:278). That keeps the component’s read path coupled to its mutable write buffers: every refresh rebuilds the review groups and then conditionally back-fills edit state. The class is much better factored than it was in March, but this remaining render-time mutation still makes behavior harder to reason about and test.

### P2. Presentation logic has moved out of some models, but a lot of it now leaks through Blade service location

The sermon surface is clearly better than it was in March, but the coupling has not disappeared; it has moved. [Sermon.php](/Users/garethclarridge/Projects/crockenhill/app/Models/Sermon.php:777) still resolves presenters from the container for `meta_description` and sitemap output, while Blade templates now resolve presenters and policies directly in several places: [sermons/sermon.blade.php](/Users/garethclarridge/Projects/crockenhill/resources/views/sermons/sermon.blade.php:3), [components/sermon-card.blade.php](/Users/garethclarridge/Projects/crockenhill/resources/views/components/sermon-card.blade.php:5), [livewire/admin/sermons/list-sermons.blade.php](/Users/garethclarridge/Projects/crockenhill/resources/views/livewire/admin/sermons/list-sermons.blade.php:54), and [components/layout/header.blade.php](/Users/garethclarridge/Projects/crockenhill/resources/views/components/layout/header.blade.php:126). That is a fresh layer leak created by the cleanup wave: domain/presentation work is less model-heavy, but dependencies are now hidden in Blade instead of being passed in explicitly from controllers, Livewire components, or dedicated view models.

### P3. `MediaController` is the main remaining controller surface that still bypasses the app’s Form Request standard

Most of the HTTP layer has moved onto request classes, and [MediaController.php](/Users/garethclarridge/Projects/crockenhill/app/Http/Controllers/Api/MediaController.php:85) already uses `MediaStatusRequest` for the status endpoint. However, the two busiest write endpoints still validate inline in the controller at [upload()](/Users/garethclarridge/Projects/crockenhill/app/Http/Controllers/Api/MediaController.php:29) and [confirmSegment()](/Users/garethclarridge/Projects/crockenhill/app/Http/Controllers/Api/MediaController.php:167). That leaves validation, request-shape normalization, and part of the authorization story split across controller methods instead of following the same Laravel-native request-class pattern used elsewhere in the app.

## Open Questions

- Is the component-level admin re-authorization an intentional temporary substitute for missing Livewire persistent middleware registration, or should the route contract become the single source of truth again?
- Should the church-service admin screens stop at extracted actions, or is there appetite for a second pass that moves `items`, section edits, and speaker edits into `Livewire\Form` objects or child components?
- Is direct `app(...)` usage inside Blade an accepted local convention now, or should presenter/policy output be assembled before render?

## Clearly Improved Since March

- URL state modernization largely landed: the old `$queryString` pattern has mostly been replaced with `#[Url]` across the Livewire list/filter surface.
- The simpler admin forms did move to real Livewire form objects: pages and meetings now use [PageFormData.php](/Users/garethclarridge/Projects/crockenhill/app/Livewire/Forms/PageFormData.php:15) and [MeetingFormData.php](/Users/garethclarridge/Projects/crockenhill/app/Livewire/Forms/MeetingFormData.php:13).
- The processing-log viewer no longer calls a controller as an application service; [ProcessingLogsViewer.php](/Users/garethclarridge/Projects/crockenhill/app/Livewire/ProcessingLogsViewer.php:18) now depends on `GetMediaProcessingStatus`.
- The page-layout routing/composer tangle is much cleaner: [LayoutPageComposer.php](/Users/garethclarridge/Projects/crockenhill/app/View/Composers/LayoutPageComposer.php:9) is now purely decorative, and page assembly is centered in [PageController.php](/Users/garethclarridge/Projects/crockenhill/app/Http/Controllers/PageController.php:18).
- The sermon route surface has been normalized to Laravel-style naming and action names, so the older legacy route-name drift is no longer one of the main standards concerns.
