# Admin & Livewire Surface Review — July 2026 Simplification Review, Phase 6

Date: 2026-07-03. Medium-depth session per the plan (`docs/plans/JULY-2026-SIMPLIFICATION-REVIEW-PLAN-2026-07-02.md`). No code changes made.

Prior art checked: the April Livewire-responsibility review (`docs/april-2026-review/livewire-view-responsibility-review-2026-04-16.md`), `docs/plans/SIMPLIFICATION-PLAN.md` (Phases 16–18 already consolidated the upload component once), and `docs/architecture/simplification-backlog.md`.

## 1. Scope reviewed

- `app/Livewire/` — all 52 files: 39 components (27 under `Admin/`, 5 `Auth/`, 4 root-level upload/logs components, 3 public browse/form components), 2 `Admin/ChurchServices/Concerns/` traits, 7 shared `Traits/`, 4 `Forms/` objects.
- Admin Blade views and partials: `resources/views/livewire/` (5,559 lines total), including the church-services cluster (9 views + 13 partials) and the media-upload views (`form`/`progress`/`status`).
- The Alpine controller `resources/js/livewire/media-upload-controller.js` (211 lines).
- Admin routes in `routes/web.php` (lines 158–260) including the retired-screen redirects.
- Livewire tests: 47 files, 12,168 lines, under `tests/Feature/Livewire/`, `tests/Integration/Livewire/`, `tests/Feature/Admin/`, `tests/Feature/Security/`, plus `tests/Browser/UploadRecordingTest.php`.

## 2. What this area is for

The admin area is where two or three church operators keep the public site truthful: publish and correct sermons, keep pages/meetings/calendar accurate, manage users, and — the biggest workflow — get each Sunday's recording from an uploaded file to a published sermon and reviewed service structure. The Livewire layer is the entire admin UI; there is no separate admin framework. The public-facing components (sermon/song browsing, Bible request form, auth) are small and were reviewed for shape only.

## 3. Complexity inventory

| Slice | Components | Component lines | Notes |
|---|---|---|---|
| Admin CRUD (Pages, Users, Preachers, Meetings, CalendarEvents, Sermons) | 16 | ~2,000 | Uniform list/create/edit pattern |
| ChurchServices admin | 9 (+2 concerns) | ~1,850 | Workbench, inbox, imports, songs |
| Upload flow (MediaUpload + Progress + Status + ProcessingLogsViewer + MediaUploadField) | 5 | ~1,240 | One operator flow |
| Public + auth | 9 | ~1,330 | Thin, delegating |
| Shared `Traits/` | 7 traits | 270 | Small, focused |
| `Forms/` | 4 objects | 815 | Pages, Meetings, Sermons, ChurchServices |

Supporting numbers:

- `MediaUpload.php` is 678 lines — the largest component by a factor of two. It has already been consolidated once (SIMPLIFICATION-PLAN Phases 16–17, June 2026: empty subclass dropped, 255-line `WithUploadLifecycle` trait inlined). The three-way split with `MediaUploadProgress` (36 lines) and `MediaUploadStatus` (111 lines) survived that pass.
- Church-services cluster: 9 routed components, 13 view partials — but the partials are almost all single-use decomposition of one page (the service workbench `show-church-service`), not duplication. Only `segment-confirmation` is included from two views.
- Tests outweigh components 12,168 : 7,402 lines. Several entities are covered twice by parallel suites (see finding 7).
- Retired screens really were retired: `ServiceReviewDashboard`, `ReviewInboundEmails`, `ListSectionPublications`, and the processing-review list are gone, replaced by `ReviewInbox` with redirects at `routes/web.php:200-203`. This is the "actually retire the old path" doctrine working.

### Status of April 2026 findings (prior art, re-checked)

| April finding | Status now |
|---|---|
| 1. `ListCalendarEvents` bypasses `CalendarService` | **Fixed** — `categorize()` now delegates to `CalendarService::manuallyCategorizeEvent()`/`manuallyUnCategorizeEvent()` (`ListCalendarEvents.php:48-67`) |
| 2. `ShowChurchService` too wide | **Largely fixed** — down from ~520 to 263 lines; read shaping in `ChurchServiceShowPresenter`, run matching in `ChurchServiceProcessingRunQuery`, writes in actions; the 343-line `unified-timeline` partial is gone |
| 3. Church-service Blade a parallel composition system | **Fixed** — every admin Livewire view now opens with `x-admin.page`/`list-shell`/`form-shell` (verified across all 21 admin views) |
| 4. `EditSermon` owns too many write concerns | **Mostly fixed** — save delegates to `SaveSermonDetails`; thumbnails split into `EditSermonThumbnails`; video visibility/quality actions remain on the page but are thin |
| 5. `x-toggle` Alpine/Livewire co-ownership | Not re-examined in depth (line-level; parked for Phase 9) |

The area has genuinely improved. The findings below are about what's left.

## 4. Findings

### F1. The upload trio is one feature fragmented — the children exist only to route clicks back to their parent

`MediaUploadProgress` (36 lines) and `MediaUploadStatus` (111 lines) hold no state of their own: every property is `#[Reactive]`, passed down from `MediaUpload` on each render. Their only behaviour is dispatching page-global browser events (`media-upload:cancel-upload`, `media-upload:cancel-processing`, `media-upload:retry-upload`) that the parent catches with `#[On]` handlers and re-filters by component id (`MediaUpload.php:397-425`). Both the PHP (`MediaUpload.php:30-43`) and the JS controller (`media-upload-controller.js:1-6`) carry "singleton-only by contract" warnings solely because these event names are page-global.

If the two children were Blade partials of the parent's view, the buttons would call `wire:click="cancelUpload"` on `$wire` directly and the entire event relay — dispatchers, three `#[On]` handlers, id filtering, the singleton contract, and both warning comments — would be deletable. The only non-markup logic in the children, `MediaUploadStatus::matchedServiceUrl()` (a three-path run→service lookup), belongs beside the existing `ChurchServiceProcessingRunQuery`/presenter layer anyway.

**Direction:** collapse to one component + two partials. Deletes 2 classes, 2 test files (`MediaUploadStatusTest`, part of `MediaUploadTest`), the event round-trip, and the singleton constraint. This is the completion of SIMPLIFICATION-PLAN Phase 16, which flattened the directory but kept the split.

### F2. `MediaUpload` itself is a hand-rolled state machine with four parallel message channels

The component tracks `status` as a loose string (`idle`/`uploading`/`processing`/`failed`/`cancelled`/`completed`), plus `isUploading`, `uploadCancelled`, `showUploadForm`, `showProcessingStatus` booleans, plus four mutually exclusive message fields (`errorMessage`, `successMessage`, `cancelledMessage`, `manualReviewMessage`). Because no single field owns the state, every transition must null out the other channels by hand — the same 3–5 line reset block appears at `MediaUpload.php:165-169, 216-219, 336-344, 439-443, 479-505, 525-528, 537-541, 558-567`. `checkProcessingStatus()` (lines 456–521) is a 65-line if/elseif ladder mapping log status onto these fields.

Progress reporting additionally flows through three mechanisms at once: throttled JS `updateUploadProgress` calls during upload, an SSE `EventSource` in an inline Alpine block in the view (`form.blade.php:182-216`) that triggers `$wire.checkProcessingStatus()`, and the status re-derivation inside that method.

**Direction:** one backed enum (`UploadState`) plus a single `statusMessage`/`statusUrl` pair derived from the processing log in one place. Most of the 678 lines are transition bookkeeping and would fall away; the view branches on one enum. This is doctrine item 1 (collapse a heuristic cluster behind one typed contract) applied to UI state. Also prune the debugging-era logging (`mount()` logs component mount success at info level, `getDynamicRules()` logs config-derived rules on every validation — `MediaUpload.php:119-133, 583-586`).

### F3. The upload flow sits outside the admin components' own safety contract

`tests/Integration/Livewire/Traits/AdminLivewireComponentsUseTraitTest.php` pins one rule only: every class under `App\Livewire\Admin` *uses* the `WithAdminAuthorization` trait (a `ReflectionClass::usesTraitRecursive` check — [test:25-58](../../../tests/Integration/Livewire/Traits/AdminLivewireComponentsUseTraitTest.php)). It does **not** inspect mutating methods, so it does not enforce that each action calls `authorizeAdmin()` — that per-action call is a convention this test cannot see. The upload flow is admin-routed (`/admin/services/upload-recording`, `routes/web.php:196`) but lives at the `App\Livewire` root, so even the trait-usage half never sees it. `MediaUpload` checks a Gate once in `mount()` only; its mutating actions (`uploadComplete`, `startProcessing`, `cancelProcessing`, `retryUpload`) have no per-action check, unlike the convention in `Admin/`. `ProcessingLogsViewer`, `MediaUploadProgress`, and `MediaUploadStatus` are in the same blind spot.

**Direction:** whatever survives F1/F2 should move under `App\Livewire\Admin\` (e.g. `Admin\Sermons\UploadRecording`) so the trait-usage test covers it, use the same trait, **and add an explicit `authorizeAdmin()` at the top of each mutating action** (`uploadComplete`/`startProcessing`/`cancelProcessing`/`retryUpload`). Relocating + adding the trait alone is *not* sufficient — the structural test would pass while those actions stayed unguarded. If per-action coverage matters, the durable fix is to extend `AdminLivewireComponentsUseTraitTest` to assert each public mutating method calls `authorizeAdmin()` (or route the actions through a guarded base method), turning today's convention into an enforced contract.

### F4. The CRUD screens genuinely share structure — the traits are not a fig leaf — but the sharing is by copy, with two stragglers

Answering the critical-friend question directly: the six CRUD domains follow one honest pattern. Every list component composes the same traits, calls `sanitizeSorting()`/`computeHasFilters()` at the top of `render()`, escapes LIKE wildcards, delegates deletes to `adminDelete()` and writes to `adminSave()` with sanitised audit fields, and renders through the shared `x-admin` shells. The traits carry real behaviour (audit logging, filter/sort state, redirects), not ceremony. New-screen cost is already low.

What remains is copy-level repetition and two deviations:

- Each list re-declares the same ~60 lines of scaffolding (sort constants, `#[Url]` filter props, `filterProperties()`, a `delete()` wrapper, header arrays, `->layout(...)`). Tolerable at 7 lists; a small abstract `AdminListComponent` (or just a documented recipe in AGENTS.md) would make the next screens near-free — see O2.
- **`ListChurchServices` is the one snowflake**: it uses `WithSortableListing` but not `WithFilterableListing`, hand-rolling `updatedSearch`/`updatedServiceFilter`/`updatedNeedsReviewFilter`/`resetFilters`/`hasFilters` (`ListChurchServices.php:70-95`) — exactly the drift the trait exists to prevent.
- **`ListUsers` folds `sortBy`/`sortDirection` into `filterProperties()`** and puts `#[Url]` on them (`ListUsers.php:44-62`), so "reset filters" also resets sorting — behaviour no sibling has. One convention should win.
- Form objects are half-adopted: Pages, Meetings, Sermons, and ChurchServices have `Forms/` data objects; Users, Preachers, and CalendarEvents keep properties on the component. The component-local style is fine at their size — this is only worth aligning if a screen grows.

### F5. ChurchServices cluster: healthy core, with single-use indirection and duplicated small utilities at the edges

The cluster is the best evidence in the codebase that the April recommendations landed: components delegate to `Actions/`, queries live in `Queries/`, read shaping in presenters, and the 13 partials are decomposition of one big workbench page rather than a parallel system. Remaining accretion:

- **`ReviewsServiceSections` (295 lines) is a trait with one consumer.** Its doc block says it is "shared by the service workbench and (until it retires) the review dashboard" — the dashboard retired, so the trait is now indirection for `ShowChurchService` alone. Inline it (or accept it as a size-management device and fix the comment).
- **`markServiceReviewed()` is implemented twice** — once in that trait (`ReviewsServiceSections.php:107-121`) and once, near-identically, in `ReviewInbox` (`ReviewInbox.php:148-169`), because `ReviewInbox` uses only the other concern. One shared home (e.g. `ManagesSectionPublication`, which both components already use) removes the duplicate.
- **`ManagesSectionPublication` guards `authorizeAdmin()` behind `method_exists()`** with a phpstan-ignore (three times). A trait-requires-trait declaration (`use WithAdminAuthorization;` inside the concern, as `WithAdminDelete` already does) deletes the defensive branching.
- **`abortIfDisabled()` is copy-pasted into 8 components** (all ChurchServices screens) — a four-line private method each. The `service-tracking.enabled` feature gate is route-level policy; a middleware on the services route group (or one tiny trait) replaces all eight copies and their `mount()` calls.
- The items eager-load closure (`'items' => fn ($q) => $q->with('song:id,title')->orderBy('position')->orderBy('id')`) is repeated three times inside `ShowChurchService` (`:42-47, 204-209, 241-246`) — a named scope or relation default would remove it.

### F6. Segment confirmation has two full UIs; the standalone page is the weaker one

Confirming a paused livestream's sermon segment can be done on the dedicated `ProcessingReview` page (`/services/processing/{log}/review`, 105-line component + 114-line view + `segment-confirmation` partial) **and** inline on the service workbench (`ShowChurchService::confirmRunSegment()`, same partial, same `ConfirmLivestreamSermonSegment` action). The standalone page is a deep-link target from three places (upload page `manualReviewUrl`, the `ManualReviewRequired` email, and inbox segment rows), but everything it shows exists on the workbench, which also has the surrounding context (other runs, service items, sections). The inbox already conditionally links to the workbench instead when the run has a matched service (`review-inbox.blade.php:249`).

**Direction:** point all three deep links at the workbench (the orphan-run case — no service yet — is the one thing to solve, and the inbox already has a "create this service" affordance for exactly that). Then delete `ProcessingReview`, its view, and its 251-line test. Needs a decision — see R2.

### F7. Test estate: real coverage, but the old flat suites were never retired when per-component suites arrived

12,168 lines of Livewire tests for 7,402 lines of component code. The concern is not volume but duplication — the same promoted-but-not-retired residue the doctrine hunts in production code:

- `tests/Feature/Livewire/Admin/EditSermonTest.php` (562 lines) **and** `tests/Feature/Livewire/Admin/Sermons/EditSermonTest.php` (218 lines) — two classes, same component, overlapping methods (add/remove points, validation, slug handling, access control in both).
- `AdminUserTest.php` (439) overlaps `Admin/Users/{ListUsersTest,CreateUserTest,EditUserTest}` (537 combined) — e.g. `it_can_delete_user`/`it_cannot_delete_self`/`it_can_toggle_admin_status` appear verbatim-equivalent in both generations.
- `AdminMeetingTest.php` (241) vs `Admin/Meetings/*` (504 combined); `AdminChurchServiceTest.php` (1,402 — the largest test file in the suite, spanning list + upload + manage + show) vs `Admin/ChurchServices/ShowChurchServiceTest.php` (1,077) and `UploadChurchServiceTest.php` (172).
- Cross-cutting suites (`AdminUrlStateTest` 401, `ClearFiltersTest` 117, `SearchSecurityAndGroupingTest` 230, `AdminAuditTraitsTest`, `AdminLivewireSecurityTest`, `AdminLivewireAuthorizationTest`) then re-exercise trait behaviour per component a third time. Sort-sanitisation alone is asserted in at least four places.

**Direction:** adopt "one suite per component, cross-cutting behaviour tested once at the trait level", then fold each flat mega-suite into the per-component suites and delete it. Mechanical, large win for suite time and discoverability. (Per ground rules, actual deletion needs Phase 8 sign-off; the structural `AdminLivewireComponentsUseTraitTest` and the trait-level tests are the keepers.)

### F8. Two small parallel implementations flagged by the doctrine

- **Sermon delete exists twice:** `ListSermons::delete()` (audit-logged via `adminDelete`) and `SermonAdminController::destroy()` (its own log + redirect), the latter serving plain forms on the public sermon page's admin overlay (`sermon-card-admin-overlay.blade.php`, `sermons/sermon.blade.php:483`). Not dangerous — both authorize — but one entity, two delete paths, two log formats.
- **`EditPreacher` carries speaker-identification management** (`recomputeProfile()` rebuilding embeddings from approved `SpeakerSample`s, `removeProfile()` — `EditPreacher.php:152-215`). Whether this UI earns its place is decided by Phase 1's verdict on the speaker-identification subsystem; if that retires, ~100 lines of component plus the profile section of `preacher-form.blade.php` go with it. Parked as a dependency, not re-litigated here.

## 5. Opportunities unlocked

- **O1 — Better operator feedback from one upload component.** With F1+F2 done, the upload page becomes one component with one state enum. That makes the improvements operators would actually notice cheap for the first time: retry-from-failure without re-selecting the file (state carries `tempFilePath`), a persistent "your last upload" card after navigating away, and honest stall messaging (today's JS stall timer literally re-arms itself forever and does nothing — `media-upload-controller.js:64-77`). Multi-instance mounting (e.g. upload from the workbench) stops being forbidden by contract.
- **O2 — New admin screens near-free.** The CRUD pattern is already consistent enough that a base `AdminListComponent` + the existing form-object pattern would reduce a new list screen to: a query, a `filterProperties()` map, headers, and a Blade table body. Codifying the recipe (base class or an AGENTS.md checklist) also gives the structural test a natural anchor and pulls `ListChurchServices` back into line.
- **O3 — Feature gating as routing, not component boilerplate.** A `service-tracking` middleware replaces eight `abortIfDisabled()` copies and makes the on/off behaviour visible in one place (`routes/web.php`), where the next feature flag can reuse it.
- **O4 — A faster, navigable test suite.** De-duplicating the two test generations (F7) removes on the order of 2,000+ lines of redundant Feature tests — directly cutting the suite's wall-clock time and making "where is this behaviour tested?" a one-look answer.

## 6. Removal candidates (needs decision)

- **R1 — `ProcessingLogsViewer` (304-line component + 297-line view + 294-line test).** A developer debug console — log-level/step filters, memory peak, execution times, configurable refresh — mounted in exactly one place (the upload status panel). Operators get run status from the status panel itself and the workbench; the log detail duplicates what `MediaProcessingLog`/Pail/Debugbar give a developer. *Keep:* zero effort, occasionally handy when an upload fails oddly. *Remove:* ~900 lines of UI surface nobody operates, one fewer polling component, and the upload page stops shipping a developer tool to operators. Middle path: replace with a plain "view technical log" link for admins.
- **R2 — `ProcessingReview` standalone page (component + view + 251-line test),** per F6, once its three deep links point at the workbench. *Keep:* a focused, minimal page for the one decision a paused run needs. *Remove:* one of two UIs for the same action; the workbench version has more context and the same action object. Risk is low — both paths already share `ConfirmLivestreamSermonSegment`.
- **R3 — Legacy flat test suites** (`AdminUserTest`, `AdminMeetingTest`, `Admin/EditSermonTest`, and the overlapping portions of `AdminChurchServiceTest`), per F7. *Keep:* they still pass and occasionally cover an assertion the new suites missed. *Remove:* duplicate coverage is a tax on every CI run and every refactor. The fold-in must diff assertions before deleting, so it is Phase 8 work, not a quick win.
- **R4 — Speaker-profile management UI in `EditPreacher`** — contingent on Phase 1's speaker-identification decision; listed here so the dependency is recorded on both sides.

## 7. Quick wins (under an hour each)

1. Route-group middleware (or shared trait) for `service-tracking.enabled`; delete the eight `abortIfDisabled()` copies (F5).
2. `use WithAdminAuthorization;` inside `ManagesSectionPublication`; delete the three `method_exists()` guards and their phpstan-ignores (F5).
3. Move `markServiceReviewed()` to the shared concern; delete the duplicate in `ReviewInbox` (F5).
4. Fix the stale "review dashboard" doc block on `ReviewsServiceSections` (or inline the trait if appetite allows — the inline is bigger than an hour) (F5).
5. Named relation default/scope for the church-service items eager-load; delete the three inline copies in `ShowChurchService` (F5).
6. Align `ListUsers::filterProperties()` with its siblings (drop sort keys) and pick one convention for `#[Url]` on sort state (F4).
7. Strip the debugging-era `Log::info` calls from `MediaUpload::mount()`/`getDynamicRules()` (F2).
8. Delete the self-re-arming no-op stall timer and the duplicated cancel path in `media-upload-controller.js` (F1/O1).

## 8. Open questions for the user

1. **Do you ever open the "Processing Logs" panel on the upload page** (log-level filters, memory metrics) — or when something fails do you go to the service workbench / ask the developer? Decides R1.
2. **When an upload pauses for sermon-segment review, do you follow the email/upload-page link, or do you do it from the service page/inbox?** Decides R2.
3. **On the songs screen (`/admin/services/songs`), do you ever use the date-from/date-to filters,** or only search and the usage sort? (Candidate for filter pruning; not written up as a finding because the cost is small.)
4. Is `EditUser`'s "reset filters also resets sort ordering" behaviour intentional anywhere, or just drift? (Affects quick win 6's direction.)

## 9. Out of scope, noted for later phases

- **Phase 7:** the admin `phpinfo` route (`routes/web.php:261`) — operational hygiene, not Livewire; `AdminAttentionCounts` caching contract (C1) belongs with the platform caching review.
- **Phase 9 (code quality):** the `x-toggle` `$wire.entangle` ownership question from April finding 5; `MediaUpload::getProcessor()` pass-through method; inline `@php` usage in `review-inbox.blade.php`; string-vs-enum status comparisons throughout `MediaUpload`; `ProcessingLogsViewer`'s `app()->environment('testing')` branch in `mount()` (production code branching on test context — same smell Phase 18 removed elsewhere) if the component survives R1.
- **Phase 1 dependency:** R4 (speaker-profile UI) follows the media-pipeline verdict on speaker identification.
- **Phase 2 dependency:** if the LLM-first structure path retires the heuristic classifiers, the workbench's reclassify affordance (`ShowChurchService::reclassify()`) and the `timeline-alignment-*` partials should be re-checked for dead branches.
