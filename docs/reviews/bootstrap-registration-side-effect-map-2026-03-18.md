# Bootstrap / Registration Side-Effect Map

Date: 2026-03-18

Scope reviewed:

- `bootstrap/app.php`
- `bootstrap/providers.php`
- `app/Providers/*`
- `app/Observers/*`
- `app/Events/*`
- `app/Listeners/*`
- route registration in `routes/web.php`, `routes/api.php`, `routes/console.php`
- model boot / route-binding hooks in `app/Models/*`

Method:

- Source-level trace only.
- I attempted to confirm runtime registration with `vendor/bin/sail artisan event:list` and `vendor/bin/sail artisan schedule:list`, but Docker was not running, so Sail could not boot in this environment.

## Executive Summary

The registration surface is fairly small, but several important write paths are hidden behind observer and event wiring rather than obvious controller or job entrypoints.

Highest-signal findings:

1. `ChurchService` changes fan out through two separate registration chains:
   - model observer on `created` / selected `updated` events
   - domain event listener on canonical list changes
   Both converge on `ChurchServiceReconciliationDispatcher`, which performs a hidden database write to `media_processing_logs.processing_metadata` and enqueues reconciliation jobs.
2. Media-processing bootstrapping is split across `AppServiceProvider` and `MediaProcessingServiceProvider`, so domain-specific container bindings are not localized to one provider.
3. `SitemapCacheObserver` invalidates several caches on every create/update/delete for `Sermon`, `Page`, `Meeting`, and `Preacher`, but it does not wait until after commit. That makes cache invalidation broader and earlier than the `ChurchService` observer strategy.
4. `bootstrap/app.php` schedules write-heavy commands directly, with no environment gating. If the scheduler runs in a non-production environment, the app will still sync Google Calendar, delete temp files, delete unpublished assets, and refresh scripture passages.
5. Several registrations are redundant or low-value noise:
   - `AppServiceProvider` rebinds `path.public` to Laravel's default path.
   - `MediaProcessingServiceProvider` publishes `config/media-processing.php` to itself.
   - `TestServiceProvider` is globally registered but intentionally empty.
   - `phpinfo` is registered in all environments and aborts outside local, instead of only being registered locally.
6. There are no custom model boot hooks, no `dispatchesEvents` arrays, and no custom `Route::bind` / `Route::model` / `resolveRouteBinding` overrides. That absence is useful: most hidden behavior is provider/observer/event driven, not model-boot driven.

## Registration Map

### 1. `bootstrap/app.php`

| Location | Registers | Runtime effect | Hidden write behavior | Env/config dependence |
| --- | --- | --- | --- | --- |
| `bootstrap/app.php:9-14` | Web/API/console routes and health endpoint | Loads `routes/web.php`, `routes/api.php`, `routes/console.php`, exposes `/up` | None by itself | None |
| `bootstrap/app.php:15-24` | Scheduler entries | Registers 4 scheduled commands | Yes, all 4 commands mutate state downstream | Cron always active where scheduler runs |
| `bootstrap/app.php:25-47` | Global middleware, aliases, auth redirects, trusted proxies | Adds security headers middleware; aliases `admin`, Sanctum ability middleware, media/mailgun/service guards; redirects guests/users | None directly | `config('app.trusted_proxies')` shape controls proxy trust |
| `bootstrap/app.php:48-59` | Exception JSON rules and safe-message rendering | Forces JSON rendering for API-like requests and safe 422s for `ProvidesSafeMessage` exceptions | None directly | Request shape driven |

Scheduled command side effects:

- `calendar:sync` from `bootstrap/app.php:16`
  - Calls `App\Services\GoogleCalendarSyncService::syncFromGoogleCalendar()` via `app/Console/Commands/SyncGoogleCalendarCommand.php:16-39`.
  - Writes:
    - `CalendarEvent::updateOrCreate(...)` at `app/Services/GoogleCalendarSyncService.php:95-114`
    - `CalendarEvent::whereIn(...)->delete()` at `app/Services/GoogleCalendarSyncService.php:57-58`
  - External dependency:
    - Google Calendar API read at `app/Services/GoogleCalendarSyncService.php:26-38`
- `media:cleanup-temp-files --hours=24` from `bootstrap/app.php:17`
  - Deletes local temp files through `Storage::disk('local')->delete(...)` at `app/Console/Commands/CleanupOrphanedTempFiles.php:159`
- `media:cleanup-unpublished-section-assets --hours=48` from `bootstrap/app.php:18-20`
  - Deletes extracted media on known disks at `app/Console/Commands/CleanupUnpublishedSectionAssetsCommand.php:71-72,109-113`
  - Updates `service_sections` rows at `app/Console/Commands/CleanupUnpublishedSectionAssetsCommand.php:74-80`
- `scripture:refresh-passages` from `bootstrap/app.php:21-23`
  - Calls api.bible if enabled at `app/Console/Commands/RefreshScripturePassages.php:22-25,52-54`
  - Updates `scripture_passages` rows at `app/Console/Commands/RefreshScripturePassages.php:72-77`

Notes:

- Only two scheduled tasks use `withoutOverlapping(...)`: unpublished asset cleanup and scripture refresh.
- `calendar:sync` is write-heavy and external-API-backed, but has no overlap guard or environment gate.

### 2. `bootstrap/providers.php`

Registered providers in order (`bootstrap/providers.php:3-12`):

1. `App\Providers\AppServiceProvider`
2. `App\Providers\UrlServiceProvider`
3. `App\Providers\AuthServiceProvider`
4. `App\Providers\ViewServiceProvider`
5. `App\Providers\ModelObserverServiceProvider`
6. `App\Providers\RateLimitServiceProvider`
7. `App\Providers\MediaProcessingServiceProvider`
8. `App\Providers\TestServiceProvider`

Observations:

- The provider list is global, not environment-specific.
- `TestServiceProvider` loads in every environment even though its body is intentionally empty.

### 3. Service Providers

#### `app/Providers/AppServiceProvider.php`

Registration:

- `register()` at `app/Providers/AppServiceProvider.php:29-57`
  - Binds `path.public` to `base_path().'/public'` at `:31-33`
  - Binds `SermonAnalysisInterface` via config-driven implementation switch at `:36-44`
  - Binds `TranscriptionServiceInterface` via config-driven implementation switch at `:47-54`
  - Binds `OosEmailItemExtractor` directly to `OpenAiOosEmailItemExtractor` at `:56`
- `boot()` at `app/Providers/AppServiceProvider.php:59-72`
  - Manually registers event listener:
    - `ChurchServiceCanonicalListChanged` -> `DispatchChurchServiceReconciliation` at `:61-64`
  - Registers password defaults at `:66-72`

Side effects:

- No direct writes in the provider itself.
- Hidden write origin is introduced by the event listener registration, because the listener dispatches reconciliation work and persists trigger metadata.

Environment/config behavior:

- `media-processing.analysis.service` chooses mock vs real analysis service at `:37-44`
- `media-processing.transcription.service` chooses mock vs real transcription service at `:48-54`
- `OosEmailItemExtractor` is not config-switched; it is hard-wired to the OpenAI implementation

Review note:

- This provider mixes generic app bootstrap, AI/media bindings, and event registration. It is now a cross-domain provider rather than an app-shell provider.

#### `app/Providers/UrlServiceProvider.php`

Registration:

- Forces root URL from `config('app.url')` at `app/Providers/UrlServiceProvider.php:19-21`
- Forces HTTPS when `APP_URL` starts with `https://` at `:23-25`

Side effects:

- No writes.
- Global URL generation is overridden for every runtime context that resolves URLs.

Environment/config behavior:

- Entire behavior is driven by `config('app.url')`

Review note:

- This is a strong environment-specific override embedded in a provider. It affects more than HTTP requests; console, queued jobs, notifications, signed URLs, and package URL generation can all observe it.

#### `app/Providers/AuthServiceProvider.php`

Registration:

- Policies for `Meeting` and `Sermon` at `app/Providers/AuthServiceProvider.php:19-22`
- Gates `manage-sermons`, `manage-meetings`, `manage-pages` at `:31-41`

Side effects:

- No writes.
- Pure authorization registration.

#### `app/Providers/ViewServiceProvider.php`

Registration:

- View composers for header/footer/photo selector/layout/home/community/church views at `app/Providers/ViewServiceProvider.php:32-38`

Side effects:

- No writes in registration itself.
- Potential hidden read/query behavior lives in the composer classes when those views render.

#### `app/Providers/ModelObserverServiceProvider.php`

Registration:

- `ChurchService::observe(ChurchServiceObserver::class)` at `app/Providers/ModelObserverServiceProvider.php:25`
- `Sermon`, `Page`, `Meeting`, and `Preacher` all observe `SitemapCacheObserver` at `:26-29`

Side effects:

- This provider is the main hidden write/cache invalidation registration point for Eloquent lifecycle behavior.

#### `app/Providers/RateLimitServiceProvider.php`

Registration:

- `api` limiter at `app/Providers/RateLimitServiceProvider.php:21-23`
- `media-upload` limiter at `:25-44`
- `media-retry` limiter at `:46-53`
- `mailgun-inbound` limiter at `:55-62`

Side effects:

- No writes.
- Request-shape-dependent throttling only.

#### `app/Providers/MediaProcessingServiceProvider.php`

Registration:

- Binds `LivestreamSegmentationService` manually at `app/Providers/MediaProcessingServiceProvider.php:15-22`
- Binds `ProcessingLogService` at `:23`
- Binds `UnifiedMediaProcessor` at `:26`
- Binds `SpeakerIdentificationInterface` through provider switch at `:29-36`
- Calls `$this->publishes(...)` for `config/media-processing.php` at `:42-45`

Side effects:

- No direct writes in provider registration.
- Downstream jobs/services created from these bindings perform heavy storage/database/queue work.

Environment/config behavior:

- `media-processing.speaker_identification.provider` chooses null vs `Resemblyzer` implementation at `:30-35`

Review note:

- The `publishes(...)` entry appears redundant because it maps the app's own `config/media-processing.php` file back to the same target path.

#### `app/Providers/TestServiceProvider.php`

Registration:

- Globally registered at `bootstrap/providers.php:11`
- Intentionally empty at `app/Providers/TestServiceProvider.php:20-25`

Side effects:

- None today.

Review note:

- The name implies environment-specific behavior, but the registration is unconditional and the implementation is a no-op.

### 4. Observers

#### `app/Observers/ChurchServiceObserver.php`

Registration source:

- Bound in `app/Providers/ModelObserverServiceProvider.php:25`

Behavior:

- Implements `ShouldHandleEventsAfterCommit` at `app/Observers/ChurchServiceObserver.php:11`
- On `created`, dispatches matching reconciliations at `:17-20`
- On `updated`, only reacts when `date` or `service` changed at `:22-29`
- Delegates to `ChurchServiceReconciliationDispatcher::dispatchForChurchService(...)` at `:31-34`

Hidden writes from this registration chain:

1. Save/update `ChurchService`
2. Observer runs after commit
3. Dispatcher queries matching `MediaProcessingLog` rows at `app/Services/ChurchServiceReconciliationDispatcher.php:25-31`
4. Dispatcher may write `processing_metadata.reconciliation_triggers` via `saveQuietly()` at `app/Services/ChurchServiceReconciliationDispatcher.php:46-68`
5. Dispatcher enqueues `ReconcileServiceSections` jobs at `app/Services/ChurchServiceReconciliationDispatcher.php:33-38`
6. Job may update `media_processing_logs.church_service_id` at `app/Jobs/ReconcileServiceSections.php:51-56`
7. Job calls `OosAlignmentService`, which updates `service_sections` and `church_services` state:
   - per-section `save()` at `app/Services/OosAlignmentService.php:117-120`
   - service review state `saveQuietly()` at `app/Services/OosAlignmentService.php:999-1021`

Risk note:

- This is the single most important hidden write path in the review because a seemingly simple `ChurchService` save can fan out to queue work and write to unrelated tables.

#### `app/Observers/SitemapCacheObserver.php`

Registration source:

- Bound to `Sermon`, `Page`, `Meeting`, and `Preacher` in `app/Providers/ModelObserverServiceProvider.php:26-29`

Behavior:

- On `created`, `updated`, and `deleted`, calls `clearCaches(...)` at `app/Observers/SitemapCacheObserver.php:22-41`
- Clears:
   - `sitemap`
   - `nav_pages`
   - `admin_preacher_list`
   - `public_preacher_list`
   at `app/Observers/SitemapCacheObserver.php:48-51`
- Clears page-area cache via `PageRepository::clearAreaCache(...)` when model is `Page` at `:53-55`
- Clears sermon listing caches via `SermonRepository::clearListingCaches(...)` at `:57-61`

Hidden writes from this registration chain:

- Cache invalidation only. No DB writes.

Risk notes:

- This observer does not implement after-commit semantics, unlike `ChurchServiceObserver`.
- It invalidates caches on any update, not only public-facing fields, so admin-only metadata changes still clear public caches.

### 5. Events and Listeners

#### Event: `app/Events/ChurchServiceCanonicalListChanged.php`

Behavior:

- Implements `ShouldDispatchAfterCommit` at `app/Events/ChurchServiceCanonicalListChanged.php:11`
- Carries `churchServiceId`, `source`, and `changes` payload at `:18-22`

Origin:

- Dispatched from `app/Services/ChurchServiceCanonicalUpdateService.php:84-90`

Important note:

- The event is not emitted by a model hook. It is emitted by a domain service after canonical diffing.

#### Listener: `app/Listeners/DispatchChurchServiceReconciliation.php`

Registration source:

- Registered manually in `app/Providers/AppServiceProvider.php:61-64`

Behavior:

- Loads the `ChurchService` by ID at `app/Listeners/DispatchChurchServiceReconciliation.php:17-23`
- Calls `ChurchServiceReconciliationDispatcher::dispatchForChurchService(...)` with trigger context at `:25-29`

Hidden writes from this registration chain:

1. A caller invokes `ChurchServiceCanonicalUpdateService::finalize(...)`
2. The service may `saveQuietly()` review state onto the `ChurchService` at `app/Services/ChurchServiceCanonicalUpdateService.php:74-82`
3. The service dispatches `ChurchServiceCanonicalListChanged` at `:84-90`
4. The listener reloads the service and invokes the same reconciliation dispatcher as the observer path
5. Dispatcher writes trigger history into `media_processing_logs.processing_metadata` and enqueues jobs

Known callers of `ChurchServiceCanonicalUpdateService::finalize(...)`:

- `app/Services/ImportChurchServiceFromOpenLp.php:68`
- `app/Services/InboundEmailImportService.php:184`
- `app/Livewire/Admin/ChurchServices/ManageChurchService.php:234`

Risk note:

- Reconciliation-on-canonical-change is not obvious from those entrypoints unless you know to follow the event registration in `AppServiceProvider`.

## Route Registration and Route/Model Hooks

### Route registration

Key route-level registrations:

- Public web routes in `routes/web.php`
- API routes in `routes/api.php`
- Closure console command in `routes/console.php:16-18`

Environment/config-specific route behavior:

- `phpinfo` route is always registered, but aborts outside local: `routes/web.php:191`
- Local-only debug routes are conditionally registered:
   - `/500` at `routes/web.php:208-211`
   - `/dev/components` at `routes/web.php:213`
- Permanent redirects are generated from config:
   - `foreach (config('redirects') as $from => $to)` at `routes/web.php:197-199`
   - Source data in `config/redirects.php`

Review notes:

- There are no route-level write closures in the route files reviewed.
- The closure routes are read-only or redirect-only.
- The `phpinfo` route is the main case where environment behavior is embedded inside the route closure instead of the registration condition.

### Route-model binding

Custom route key behavior:

- `Page::getRouteKeyName()` returns `slug` at `app/Models/Page.php:86-89`
- `Meeting::getRouteKeyName()` returns `slug` at `app/Models/Meeting.php:109-112`
- `Preacher::getRouteKeyName()` returns `slug` at `app/Models/Preacher.php:55-58`
- `Sermon` commonly uses explicit `{sermon:slug}` syntax in routes, for example `routes/web.php:38,75,91,98,150`

Not found:

- No `Route::bind(...)`
- No `Route::model(...)`
- No `resolveRouteBinding(...)`
- No `resolveChildRouteBinding(...)`
- No `scopeBindings()` / `missing()` / route-binding closures with side effects

## Model Boot Hooks

Not found in the application code reviewed:

- no `booted()`
- no `protected static function boot()`
- no `static::creating(...)`, `static::updated(...)`, etc.
- no `dispatchesEvents` property
- no `#[ObservedBy(...)]` attributes

Interpretation:

- Save-time side effects are centralized in providers/observers, not embedded directly in model classes.
- That keeps models simpler, but it also hides behavior farther away from the models being mutated.

## Hidden Write Origins

### Chain A: `ChurchService` observer path

`ChurchService` create/update -> `ChurchServiceObserver` -> `ChurchServiceReconciliationDispatcher` -> hidden write to `MediaProcessingLog.processing_metadata` -> queue `ReconcileServiceSections` -> `OosAlignmentService` updates `service_sections`, `church_services`, and sometimes `media_processing_logs.church_service_id`

Primary code points:

- Observer registration: `app/Providers/ModelObserverServiceProvider.php:25`
- Observer execution: `app/Observers/ChurchServiceObserver.php:17-34`
- Hidden metadata write: `app/Services/ChurchServiceReconciliationDispatcher.php:46-68`
- Queued write path: `app/Jobs/ReconcileServiceSections.php:51-68`
- Alignment writes: `app/Services/OosAlignmentService.php:71-122,955-1021`

### Chain B: canonical list changed event path

`ChurchServiceCanonicalUpdateService::finalize()` -> hidden `ChurchService` review-state write -> event dispatch after commit -> listener registration in `AppServiceProvider` -> same reconciliation dispatcher path as Chain A

Primary code points:

- Finalize service write/event: `app/Services/ChurchServiceCanonicalUpdateService.php:74-90`
- Listener registration: `app/Providers/AppServiceProvider.php:61-64`
- Listener execution: `app/Listeners/DispatchChurchServiceReconciliation.php:17-29`

### Chain C: sitemap/public cache invalidation

`Sermon` / `Page` / `Meeting` / `Preacher` create/update/delete -> `SitemapCacheObserver` -> cache key invalidation + repository cache clears

Primary code points:

- Observer registration: `app/Providers/ModelObserverServiceProvider.php:26-29`
- Cache invalidation: `app/Observers/SitemapCacheObserver.php:22-65`
- Repository cache clears:
   - `app/Repositories/PageRepository.php:35-38`
   - `app/Repositories/SermonRepository.php:129-155`

### Chain D: scheduler-triggered writes

Scheduler tick -> command registration in `bootstrap/app.php` -> command handle method -> database/storage/external-API work

Primary code points:

- Schedule registration: `bootstrap/app.php:15-24`
- Calendar sync writes: `app/Services/GoogleCalendarSyncService.php:40-58,95-114`
- Temp file deletions: `app/Console/Commands/CleanupOrphanedTempFiles.php:128-179`
- Unpublished asset cleanup writes: `app/Console/Commands/CleanupUnpublishedSectionAssetsCommand.php:61-80,103-116`
- Scripture refresh writes: `app/Console/Commands/RefreshScripturePassages.php:22-31,72-77`

## Environment-Specific Behavior Embedded In Registration

### Explicitly embedded

- `bootstrap/app.php:29-33`
  - Trust proxies only if `config('app.trusted_proxies')` is a non-empty string
- `app/Providers/UrlServiceProvider.php:19-25`
  - URL root and scheme forced from `APP_URL`
- `app/Providers/AppServiceProvider.php:36-54`
  - analysis/transcription bindings switch implementation by config
- `app/Providers/MediaProcessingServiceProvider.php:29-35`
  - speaker identification implementation switches by config
- `routes/web.php:191`
  - `phpinfo` behavior branches on `app()->isLocal()`
- `routes/web.php:208-214`
  - local-only diagnostic routes
- `routes/web.php:197-199`
  - route table shape depends on `config('redirects')`
- `bootstrap/app.php:15-24`
  - scheduler behavior is global unless the deployment avoids running the scheduler

### Implicitly embedded through downstream command/service behavior

- `calendar:sync`
  - sync window is driven by `config('calendar.sync_window.*')`
- `scripture:refresh-passages`
  - enabled/disabled and refresh cadence depend on `config('services.api_bible.*')`
- reconciliation jobs
  - queue name comes from `config('media-processing.queues.livestream')`

## What Should Move or Be Simplified

### High priority

1. Move the `ChurchServiceCanonicalListChanged` listener registration out of `AppServiceProvider`.
   - Best fit: a dedicated event provider or event discovery.
   - Why: the current registration hides domain workflow inside the app-shell provider.
2. Consolidate media-processing bindings.
   - Move `SermonAnalysisInterface`, `TranscriptionServiceInterface`, and likely `OosEmailItemExtractor` out of `AppServiceProvider` and into `MediaProcessingServiceProvider` or a new domain-specific provider.
   - Why: all media/AI binding decisions should be in one place.
3. Make `SitemapCacheObserver` after-commit and narrower.
   - Consider implementing after-commit semantics and invalidating only when public-facing fields change.
   - Why: current behavior is broader and earlier than needed.

### Medium priority

4. Decide whether observer registration should live closer to the models.
   - Laravel 12 supports `#[ObservedBy(...)]` attributes.
   - Moving observer wiring onto `ChurchService`, `Sermon`, `Page`, `Meeting`, and `Preacher` would improve locality.
   - If centralization is preferred, keep the provider but rename/comment it as a side-effect registry rather than a generic observer provider.
5. Gate or qualify scheduled write tasks.
   - Consider `->environments(...)`, config flags, or explicit comments for tasks that hit external APIs or delete files.
   - `calendar:sync` is the strongest candidate because it is external-API-backed and lacks `withoutOverlapping(...)`.
6. Register `phpinfo` only in local.
   - Prefer the same pattern already used for `/500` and `/dev/components`.

### Low priority / cleanup

7. Remove the redundant `path.public` binding from `AppServiceProvider` unless a non-standard public path is actually needed.
8. Remove the self-publishing config mapping from `MediaProcessingServiceProvider`.
9. Remove `TestServiceProvider` until it contains real test-only bootstrap behavior, or register it conditionally in tests only.
10. Remove empty `register()` methods from providers where they add no value.

## Stable Areas

These parts looked straightforward and low-risk:

- `AuthServiceProvider` policy and gate registration
- `RateLimitServiceProvider` throttle registration
- route files do not contain write-heavy closure logic
- no custom model boot hooks or custom route-binding resolvers

## Follow-Up Questions For Later

1. Should reconciliation trigger from both:
   - `ChurchService` date/service changes
   - canonical item list changes
   or should one of those paths become the single domain trigger?
2. Is `SitemapCacheObserver` intentionally immediate, or should it align with the after-commit semantics used for `ChurchServiceObserver`?
3. Is global `URL::forceRootUrl()` still required, or is `APP_URL` already sufficient for the contexts that matter here?
4. Should scheduled mutators be opt-in by environment/config rather than always registered?
