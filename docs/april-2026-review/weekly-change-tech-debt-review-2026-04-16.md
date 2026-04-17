# Weekly Change Tech-Debt Review

Date: 2026-04-16

Scope: committed changes from 2026-04-09 through 2026-04-16. This review covers committed Git history only and excludes the current uncommitted working tree.

## Findings

### 1. High: the new public song catalogue can silently hide legitimate songs when a service has processing logs that should not yet replace the order of service

File refs: `app/Services/PublicSongCatalogService.php:150-186`, `tests/Feature/PublicSongCatalogServiceTest.php:252-267`

`PublicSongCatalogService::qualifyingUsageSubquery()` now treats a song as countable when either there is a completed livestream processing log with a confirmed song section, or there are no processing logs at all for the service. That leaves a gap in the middle: if a service has a failed, pending, in-progress, or non-livestream processing log, the order-of-service song can disappear from usage counts and public catalogue results even though no authoritative livestream song match exists yet.

This is not just a performance concern. It is a public read-side correctness bug on a new surface, and it is easy to miss because the happy-path tests only cover the completed-livestream-confirmed case.

Smallest safe improvement: treat completed livestream processing as the only case that overrides order-of-service song usage. Add focused regression coverage for failed, pending, non-livestream, and completed-without-confirmed-section cases.

### 2. Medium: the sermon archive now has two owners for filter state, which can let metadata and rendered results drift apart

File refs: `app/Http/Controllers/SermonController.php:38-57`, `app/Http/Controllers/SermonController.php:124-156`, `app/Livewire/Sermons/BrowseSermons.php:36-53`, `app/Livewire/Sermons/BrowseSermons.php:88-118`

`SermonController::index()` reads raw query parameters to build the archive query, canonical URL, and JSON-LD payload. The `BrowseSermons` Livewire component then reinterprets the same state in `mount()`, clearing invalid books and chapters before rendering the real page body. That means invalid or stale query params can produce a mismatch where the HTML archive shows the unfiltered or corrected result set while the controller-level metadata was built from the unsanitized request.

This also keeps the route on a double-query path during initial render, because both the controller and the component paginate the browse query separately.

Smallest safe improvement: move archive filter normalization into one shared boundary that both the controller metadata layer and the Livewire component consume, then make one layer the single owner of the initial archive query and JSON-LD payload.

### 3. Medium: `SermonViewPresenter` memoization became unsafe once the presenter was registered as a singleton

File refs: `app/Providers/MediaProcessingServiceProvider.php:18-22`, `app/Presenters/SermonViewPresenter.php:16-59`, `app/Presenters/SermonViewPresenter.php:173-213`, `app/Presenters/SermonViewPresenter.php:312-354`

The new presenter memoization keys are based on sermon id and `updated_at`, but several presenter outputs still depend on whether relations such as `preacherProfile` and `scripturePassage` were loaded on the specific model instance used for the first call. Because the presenter itself is now a singleton, an early call with a partially-hydrated sermon can cache a fallback value that later relation-loaded instances for the same sermon id will reuse within the same request lifecycle.

That creates hidden call-order coupling around `displayPreacherName()`, `displayReference()`, and `preacherUrl()`. The presenter still works in many paths, but the caching strategy is no longer obviously safe by construction.

Smallest safe improvement: stop treating the presenter as a singleton while it carries per-sermon mutable cache, or refactor the memoization so cached outputs no longer depend on relation-loaded state. Add regression tests that exercise the same sermon id through both partially-loaded and relation-loaded instances.

### 4. Low: the scripture-filter rebuild command scales linearly in memory because it loads the full sermon table before doing any work

File refs: `app/Console/Commands/SyncSermonScriptureFilters.php:28-46`

`sermons:sync-scripture-filters` collects every matching sermon with `get()` before looping over them. That is fine for today's dataset, but it is classic operational debt for a rebuild-style command: memory usage grows with the full table size, and the command will become harder to run safely as the archive grows.

This is not an urgent production bug, but it is a good candidate for preventative cleanup while the feature is still new.

Smallest safe improvement: switch the command to `chunkById()` or `lazyById()`, keep the `--sermon` fast path, and leave the user-facing output behavior unchanged.

## Tech-Debt Summary

The last week did introduce some tech debt, but it is concentrated rather than broad. The main new risks are on public browse surfaces and in stateful memoization that was added for performance. The positive news is that the mitigation work is fairly well-bounded: one public song read-rule fix, one sermon-archive state unification pass, one presenter lifetime decision, and one command-scaling cleanup should remove most of the risk without undoing the broader improvements from the week.
