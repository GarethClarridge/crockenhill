# Workstream 3 — Public Read Path & Presentation Convergence (implementation plan)

> **Archived 2026-07-17 — complete.** All six PRs merged: #1221 (3.1), #1222 (3.2), #1223 (3.3),
> #1224 (3.4), #1225 (3.5), #1227 (3.6). A follow-up review of the merged work found thirteen
> issues (O39–O51 in `docs/issues/README.md`), fixed in separate follow-up PRs. One instruction
> below is superseded by what actually landed: PR 5 commit 5 also removed the `large`/`small`
> names from `PageImageCacheService`'s fallback chains (asking Spatie Media Library for an
> unregistered conversion name throws), so the live fallback is canonical conversion → original
> media URL. **Do not restore the old conversion names.**

Written 2026-07-16. This is the approved just-in-time implementation plan for **delivery-order
item 7** of `docs/plans/JULY-2026-SIMPLIFICATION-BACKLOG-2026-07-05.md`: backlog items
**3.1 → 3.2 → 3.3 → 3.4 → 3.5 → 3.6**, in that order. Decisions D13/D14 (2026-07-05) approved all
removals; the four open decision points were resolved with the maintainer on 2026-07-16 (§Decisions).

Design sources (read the cited sections before implementing each PR):

- `docs/reviews/july-2026-simplification/public-site-read-path-review-2026-07-02.md`
  §4.1–4.3, 4.6, 4.7, 6.2–6.8, 7
- `docs/reviews/july-2026-simplification/sermons-domain-review-2026-07-02.md` F5, F8, F10

Every review claim used here was **re-verified against the code on 2026-07-16**; the "Verified
current state" appendix at the bottom records the facts (file paths + line numbers) so you do not
need to re-derive them — but line numbers will drift as PRs land, so treat them as anchors, not
gospel. **Stop rule:** if the code does not match this document's description of it, stop and flag
the discrepancy instead of improvising.

## Context

The public read path currently answers "where does this view's data come from" in eight different
places (presenters, read models, view composers, component constructors, a one-file
`app/View/Presenters` namespace, inline Blade `@php` JSON-LD, `app/Seo/`, `app/Sitemap/`); runs
four caching layers with three invalidation strategies including a ~130-line hand-maintained
permutation-invalidation registry; carries a 7-file/1,238-line sermon presenter memoization
cluster; a 524-line sitemap decorating URLs search engines ignore; a parallel hand-entered meeting
recurrence subsystem duplicating Google Calendar; two parallel calendar admin surfaces; and two
byte-identical RSS templates. This workstream converges all of it onto one presentation
convention, TTL-based freshness, and one implementation per job.

## Decisions (maintainer, 2026-07-16)

1. **Meeting recurrence fields** (`meeting_date`, `is_recurring`, `frequency`): operator confirmed
   no reliance — **remove them**, columns dropped by migration. The recurring `Schedule` JSON-LD is
   **dropped, not re-derived** from CalendarEvent rows.
2. **Public `/calendar/uncategorized` route**: **delete** (route + `CalendarController::uncategorized`
   + view). The admin Livewire `ListCalendarEvents` (uncategorizedOnly filter) is the surviving worklist.
3. **`Header` nav fetch**: **keep** the constructor fetch, documented as the one sanctioned
   shell-component exception to the props convention; switch its `rememberForever` to
   `Cache::flexible` in PR 2.
4. **Podcast**: merge the templates **and add `<podcast:person>`** for the preacher.

## Delivery protocol

- **Six PRs, strictly serial** (PR 1 → 2 → 3 → 4 → 5 → 6), each branched off master after the
  previous merges. PRs 2, 3 and 6 all touch `SermonRepository`/presenters/`PodcastFeedService`,
  so do not parallelise.
- Every commit lands green. References are deleted before referents. **Tests are deleted in the
  same commit as their subjects — never separately, never kept as preservatives.**
- After opening each PR: wait for the Codex review, act on its comments. **Do not self-merge —
  ask the maintainer.**
- Quality gates, every PR: `vendor/bin/sail bin pint --dirty` · `vendor/bin/sail composer phpstan`
  (stays at 0 errors) · focused `vendor/bin/sail artisan test --compact <paths>` · full
  `vendor/bin/sail artisan test --compact --parallel` before merge · `vendor/bin/sail artisan dusk`
  for PRs 1–5 (they touch public routes; PR 6 is XML feeds, feature tests suffice).
- Playwright visual check after PRs 1 and 5 (`tests/Playwright/section-landings.spec.ts`): expect
  **zero snapshot drift** — these PRs change data wiring and invisible JSON-LD, not markup. Any
  drift means a mistake, not a re-baseline.
- British English in all user-facing strings and test assertions.
- After each PR merges, mark the corresponding 3.x item complete in the backlog doc (status-line
  style, as items 2.1–2.3 were marked).

---

## PR 1 — 3.1 One presentation convention

Rule being adopted: *every route's view data is one typed read-model object assembled in the
controller (or Livewire component); Blade components receive props; SEO/JSON-LD builders consume
the same read model.* `PublicPageReadModel`/`PublicMeetingReadModel` already are the target
pattern; this PR finishes the migration and deletes the losers.

**Create:**

- `app/Http/Controllers/LandingPageController.php` — three ~4-line methods injecting
  `PageCardService`: `home()` → `view('full-width-pages.home', ['pages' => $cards->forHome()])`;
  `church()` → same with `forChurch()` **plus** `'links' => $cards->churchLinks()`;
  `community()` → `forCommunity()`. Controller methods, not route closures (`route:cache`
  compatibility).
- `app/Seo/MeetingSeoPresenter.php` — one method used by both meeting views:
  `eventItemList(Meeting $meeting, Collection $events, ...): ?array` returning the Schema.org
  ItemList array currently built inline (null when `$events` empty). **Keep the `json_encode`
  flags and array key ordering byte-identical to the current inline output** so the JSON-LD
  assertions in `MeetingSeoTest`/`JsonLdConsistencyTest` don't churn.

**Modify:**

- `routes/web.php` — `/` (~L59), `/church` (~L68), `/community` (~L69) switch from `Route::view`
  to `LandingPageController` methods, **keeping the same route names**. `/christ` and `/christmas`
  stay `Route::view` — they are fully static blades with no composer and no data; "no data, no
  controller" *is* the convention.
- `bootstrap/providers.php` — remove `ViewServiceProvider`.
- `app/Presenters/RelatedPagePresenter.php` — absorb `PageLinksRepository` as private methods
  (it is the sole production consumer; its `orderedLinks()`/`randomLinks()` map 1:1 onto
  `ordered()`/`random()`). The presenter gains `PageLinksRepository`'s `PageListCache` constructor
  dependency. Replace the absorbed raw `$page->admin === 'yes'` check with `Page::isAdminOnly()`.
- `app/Http/Controllers/MeetingController.php` (`show`) and
  `app/Http/Controllers/CalendarController.php` (`eventsForMeeting`) — build the event ItemList
  via `MeetingSeoPresenter` (from the read model's `upcomingEvents` / the `$schemaEvents` concat
  respectively) and pass `'eventSchema'` to the view.
- `resources/views/meetings/show.blade.php` — delete the whole inline JSON-LD region (currently
  L61–207): the recurring `Event`+`Schedule` block (L61–135, gated on
  `$meeting->is_recurring && $meeting->frequency`) is **deleted outright, not ported** — the
  recurrence fields die in PR 5 and porting doomed markup is waste (removing a *consumer* early is
  safe; the review's "no partial removal" warning is about removing fields while consumers remain,
  which is the opposite direction). The upcoming-events ItemList block (L137–207) becomes a single
  `<script type="application/ld+json">{!! json_encode($eventSchema, ...) !!}</script>` guarded on
  `$eventSchema !== null`.
- `resources/views/meetings/events.blade.php` — same ItemList replacement (currently L28–134).
- `app/Services/Public/PageCardService.php` (~L87) and
  `app/Services/Public/PublicPageVisibilityGuard.php` (~L42) — replace raw `admin === 'yes'` /
  `!== 'yes'` comparisons with `Page::isAdminOnly()`.
- `app/View/Components/Layout/Header.php` — add a docblock naming the constructor `nav_pages`
  fetch the one sanctioned shell-component exception to the props convention (decision 3). Do not
  change its behaviour in this PR.

**Delete:**

- `app/View/Composers/` — all four: `PageShowComposer`, `HomePageComposer`, `ChurchPageComposer`,
  `CommunityPageComposer`.
- `app/Providers/ViewServiceProvider.php`.
- `app/View/Presenters/PageLinksRepository.php` and the now-empty `app/View/Presenters/` directory.

`PageShowComposer` deletion is verified safe: `pages.show` is rendered only by `PageController`
(both `show()` and `showPage()` pass full `toViewData()`, which always emits `headingpicture`,
`headingpictureMobile`, `headingpictureTablet`, `links`), and the blade already defaults every one
of those keys with `?? null` / `?? []` (`resources/views/pages/show.blade.php:7–14`).

**Tests:**

- `tests/Feature/ViewComposerTest.php` — rename the stale `it_populates_footer_with_latest_sermons`
  (the footer is static links; the test asserts link text). Keep
  `it_keeps_explicit_data_when_rendering_the_page_show_view` — it passes without the composer
  (blade `??` defaults). The HTTP-level card tests (`it_scopes_home_card_pages...`,
  `it_resolves_members_links_explicitly...`) exercise the routes, which emit identical data via
  the new controller — keep, update any composer-class references.
- `MeetingSeoTest` — delete the two recurring-Schedule test methods
  (`meeting_page_contains_recurring_event_json_ld`, `...does_not_contain...`); the ItemList
  assertions must pass unchanged (byte-identical output by construction).
- `tests/Feature/Security/JsonLdConsistencyTest.php` — drop `Schedule` sections.
- `tests/Integration/View/Presenters/PageLinksRepositoryTest.php` — fold its assertions into a
  `RelatedPagePresenter` test in the same commit that deletes the class.
- New: a small `MeetingSeoPresenter` unit/integration test (shape + null-when-empty).

---

## PR 2 — 3.2 Caching simplification + repository slim

Direction (D13): **freshness-by-TTL over freshness-by-invalidation.** Everything listing-shaped
becomes `Cache::flexible($key, [300, 86400])` — fresh 5 minutes, stale-while-revalidate a day.
Targeted invalidation survives only for the operator edit-then-verify loops (page/meeting read
models) and file-metadata correctness. This intentionally changes freshness for card rails, nav,
and preacher lists from "instant" to "≤5 minutes" — state that in the PR description.

**Create:**

- `app/Support/FlexibleCache.php` — a single static/instance `forget(string $key)` helper that
  forgets both the key and Laravel's internal `illuminate:cache:flexible:created:{$key}`
  bookkeeping key. This framework-internal string is currently copy-pasted in three files; after
  this PR it exists exactly once.
- `app/Observers/PublicReadModelCacheObserver.php` — observes **Page** (forget page read model +
  page image cache + the linked meeting's read model) and **Meeting** (forget its read model).
  `ShouldHandleEventsAfterCommit`, same as the observer it replaces.

**Modify:**

- `app/Services/Public/SermonRepository.php` — delete the entire invalidation registry
  (`clearListingCaches`, `clearScriptureChapterCaches`, `forgetPreacherAndSeriesCaches`,
  `forgetBookAndChapterCaches`, `forgetFlexible`) and the request-memoization
  (`$memoizedPresents`/`$computed`, `rememberFlexible()`, `clearInternalCaches()`); listing
  getters call `Cache::flexible($key, [300, 86400], ...)` directly. Move the write-side helpers
  out: `findByDateAndServiceAndContentType()` and `generateUniqueSlug()` become private methods on
  `SermonCreationService` (its lines ~75/~423 are the only callers — note
  `SongCatalogSyncService` has its own separate private slug helper, leave it);
  `normalizeArchiveFilters()` becomes an instance method on `App\Support\BibleCanon` (drop its
  `BibleCanon` parameter; callers `app/Presenters/BreadcrumbPresenter.php:~156`,
  `app/Livewire/Sermons/BrowseSermons.php:~52`, `app/Http/Controllers/SermonController.php:~47`
  inject `BibleCanon` instead). Repository settles at ~300 lines of pure read model.
- `PageListCache`, `MeetingListCache`, `PreacherListCache` (`app/Services/Public/`) — TTLs →
  `[300, 86400]`; delete the hand-rolled memo copies; `PageListCache` also loses
  `forgetFlexible()`/`clearAreaCache()` and its card-key constants.
- `PublicPageReadModelCache`, `PublicMeetingReadModelCache`, `PageImageCacheService` —
  `Cache::rememberForever` → `Cache::flexible($key, [300, 86400])`. **This fixes the
  stale-upcoming-events bug** (review 4.2: `upcomingEvents` is a time-dependent query cached
  forever; a quiet fortnight leaves a passed event advertised as upcoming). Their `forget()`
  methods route through `FlexibleCache::forget()`.
- `app/View/Components/Layout/Header.php` — `nav_pages` `rememberForever` → `flexible([300, 86400])`.
- `app/Services/Public/PodcastFeedService.php` — delete `clearCache()` entirely (its only
  production caller is the observer being deleted; its presenter-flush half dies in PR 3 anyway).
  Keep the zero-length-enclosure self-heal (currently ~L59) but route its forget through
  `FlexibleCache`. `config/podcast.php` cache ttl → 300 / stale ttl → 86400.
- `app/Observers/SermonObserver.php` — take over calling
  `SermonStorageService::clearCachedMetadata($sermon)` when file-path columns change. This is
  file-metadata correctness (podcast enclosure length), not listing freshness — it must not be
  lost when the fan-out observer dies.
- `app/Providers/ModelObserverServiceProvider.php` — remove the four `SitemapCacheObserver`
  registrations (Sermon, Page, Meeting, Preacher); register `PublicReadModelCacheObserver` on
  Page and Meeting. `CalendarEventObserver` is untouched (already targeted; covers the admin
  categorise-then-check loop).

**Delete:**

- `app/Observers/SitemapCacheObserver.php`. Losing its `Cache::forget('sitemap')` is fine in the
  interim: `SitemapController`'s `Cache::flexible('sitemap')` timer keeps daily regeneration alive
  until PR 4 replaces the mechanism (worst case the sitemap is ~24 h stale between PR 2 and PR 4).

**Tests:**

- Delete the repository cache-invalidation suites (`SermonRepositoryCacheInvalidationTest` and
  the scripture-cache invalidation coverage) with the registry.
- Move the write-side helper tests to `SermonCreationServiceTest` / a `BibleCanon` test.
- Fold the duplicate `PublicMeetingReadModelCacheTest` pair (Feature ~174 lines + Integration
  ~212 lines) into **one** file updated for flexible semantics.
- Add a time-travel regression test proving a meeting's upcoming events refresh once the fresh
  window passes (pins the 4.2 bug fix).
- Trim the observer-dependent sections of `SitemapCacheTest` (the controller-regeneration parts
  survive until PR 4 deletes the file).
- Update podcast invalidation tests (`PodcastFeedServiceTest`, `PodcastFeedTest`).

**Watch items:**

- `Cache::flexible`'s stale-while-revalidate refresh runs as a deferred task. Verify on the
  production **file** cache driver that the deferred refresh actually fires post-response
  (`CACHE_DRIVER=file` is the production default). If it doesn't, the caches still serve stale
  values until natural expiry — degraded but not broken; note findings in the PR.
- Tests that implicitly relied on observer invalidation now need `Cache::flush()` or time travel.
- The array test-cache never serialises — nothing here touches `cache.serializable_classes`, but
  do not cache Eloquent collections with eager-loaded Spatie media (see project memory; the read
  models already normalise photos).

---

## PR 3 — 3.3 Sermon presenter collapse (7 files → 3, ~1,238 → ~600 lines)

**Design decision: keep the array output shapes; delete only the memo machinery.** The backlog
mentions a "typed `SermonView` Data object", but also mandates "output shapes unchanged" — and the
~15 consumers (blades like `sermon-card.blade.php`, Livewire components, `SermonApiController`,
the Seo/Sitemap presenters) all index arrays (`$view['title']`). The `array{...}` PHPStan
docblocks already give checked shapes. A DTO would churn every consumer for zero behavioural gain;
the line target is met by deletion alone.

**Modify:**

- `app/Presenters/SermonViewPresenter.php` (~250 lines) — same public method signatures
  (`present`, `presentForList`, `presentForApi`, `presentCollection`, and the individual
  field/URL accessors that `PodcastFeedService`/`SitemapService`/Seo presenters call), but eager
  computation with **zero internal state**. Absorbs: `SermonPresentationAssembler`'s three array
  builders (`forApi` 11 fields / `forList` 20 fields / `forFull` = forList + `transcript` +
  `plain_text_outline`), `SermonMetaPresenter`'s methods, and a memo-free (~50-line) version of
  `SermonIdentityResolver`'s preacher/reference resolution (the relation-first, string-column
  fallback logic is real and stays; the three memo stores go). `presentCollection()` keeps its
  keyed-by-id output via plain `keyBy`/`map`. **Delete** `clearInternalCaches()` and
  `preWarmForAdminList()`.
- `app/Presenters/SermonUrlBuilder.php` (~110) — every method drops its
  `SermonViewPresenter $presenter` first argument (the passback existed only for the
  plain→primary thumbnail fallback, which becomes an internal call).
- `app/Presenters/SermonDateFormatter.php` (~70) — stateless; drop `clearCache()`/memo. Still
  delegates to `App\Support\SermonContentFormatter`.
- `app/Livewire/Admin/Sermons/ListSermons.php` (~L174) — remove the `preWarmForAdminList()` call
  and its "Performance Optimization" comment. Safe: the component's own query eagerly loads
  `preacherProfile:id,name,slug,image_path` + `scripturePassage`, and
  `Preacher::profileImageUrl` is a cached Attribute — no query regression.
- `app/Seo/SermonItemListPresenter.php` — delete its own `clearInternalCaches()` method and
  hand-rolled author memo; resolve authors eagerly without internal state.
- `app/Providers/AppServiceProvider.php` (~L68) — drop the now-stateless presenter's `scoped()`
  binding; remove its row from `tests/Feature/SingletonRegistrationTest.php`.
- `app/Services/Sermon/SermonStorageService.php` — remove its `clearInternalCaches` interaction
  with the presenter if present.

**Delete:** `app/Presenters/SermonPresenterCache.php`, `SermonPresentationAssembler.php`,
`SermonIdentityResolver.php`, `SermonMetaPresenter.php` (+ their dedicated tests in the same
commit).

**Tests:**

- Merge `tests/Unit/Presenters/SermonViewPresenterTest.php` (278 lines) and
  `tests/Integration/Presenters/SermonViewPresenterTest.php` (739 lines) into one Integration
  file: delete every memo/reset/passback behaviour test; **keep every output-shape assertion
  verbatim** by moving the exact key-set assertions from the deleted
  `SermonPresentationAssemblerTest` into it (shapes are unchanged — these are the regression net).
- Add an N+1 guard asserting query count on a 24-sermon `presentCollection` — this is the
  backlog's mandated sanity check on the archive page. Also verify manually with Debugbar on
  `/christ/sermons` before merge.

---

## PR 4 — 3.4 Sitemap simplification (524 → ~150 lines)

**Modify:**

- `app/Services/Public/SitemapService.php` — plain sitemap: static landing-route list, detail
  URLs (sermons, pages, meetings, preachers) with lastmod, and book/series archive URLs as bare
  `<loc>` entries built from the already-cached filter lists. Delete
  `getRepresentativeSermonsForStaticUrls()` and the two inline `ROW_NUMBER()` window subqueries in
  `addBooks`/`addSeries`, and **all** `priority`/`changefreq` (Google documents both as ignored).
  The `whereVisibleInSitemap` scope (`app/Models/Builders/SermonBuilder.php:68–80`) is
  **untouched** — it is the exposure logic that actually matters, and `RouteCanaryRegistry` also
  uses it.
- `app/Sitemap/SermonSitemapPresenter.php` — **survives** (image/video tags on sermon *detail*
  URLs have genuine discovery value; the review condemns only archive enrichment); drop its
  priority/changefreq lines, keep lastmod + image/video tags.
- `app/Http/Controllers/SitemapController.php` — becomes a plain file server of
  `public_path('sitemap.xml')` with generate-if-missing (keeps fresh deploys working); drop the
  `Cache::flexible('sitemap')` timer.
- `app/Console/Commands/GenerateSitemap.php` — already exists (`sitemap:generate`); drop its
  `Cache::forget('sitemap')`.
- `bootstrap/app.php` `->withSchedule()` — add `sitemap:generate` daily (~04:00),
  `withoutOverlapping()` + `onOneServer()` + production environments, grace time per the file's
  existing schedule-monitor convention (`calendar:sync` at ~L32 is the pattern to copy). Check
  whether the deploy pipeline runs `schedule-monitor:sync`; note in the PR if the new task needs it.
- `AppServiceProvider` — drop the bindings for the three deleted sitemap presenters.

**Delete:**

- `app/Sitemap/PageSitemapPresenter.php`, `MeetingSitemapPresenter.php`,
  `PreacherSitemapPresenter.php` — inlined into the service as plain
  `Url::create(...)->setLastModificationDate(...)` calls.
- `tests/Feature/SitemapCacheTest.php` (368 lines — pins the deleted timer/invalidation).
- `tests/Integration/Services/SitemapServiceEagerLoadTest.php` (97 lines — guards the deleted
  window queries).

**Tests:** shrink `tests/Feature/SitemapTest.php` (555 lines): **keep every exposure/visibility
assertion** (private/childrens-talk exclusion etc.), drop priority/changefreq/archive-image
assertions. Shrink `tests/Unit/Services/SitemapServiceTest.php` and
`SermonSitemapPresenterTest` (keep image/video assertions). Keep
`tests/Integration/Models/SitemapableTest.php` and `SermonSitemapMediaTest.php`.

**Verification:** run `sail artisan sitemap:generate`, diff the old vs new URL sets — **no URL may
disappear**; only enrichment attributes differ. spatie/laravel-sitemap ^8.0 is already installed.

---

## PR 5 — 3.5 Meetings & calendar decisions (one PR, five green commits)

**Commit 1 — calendar admin convergence + public route deletion.**
Delete `app/Http/Controllers/Admin/CalendarAdminController.php` + its four admin routes
(`routes/web.php` ~169–172) + views `admin/calendar/uncategorized.blade.php` and
`admin/calendar/patterns.blade.php`; delete the public `/calendar/uncategorized` route
(~`web.php:76`) + `CalendarController::uncategorized()` + `resources/views/calendar/uncategorized.blade.php`.
Port a **"Sync now"** action onto `app/Livewire/Admin/CalendarEvents/ListCalendarEvents.php`
(method injecting `GoogleCalendarSyncService`, try/catch + flash, logic lifted from
`CalendarAdminController::syncCalendar`; the nightly `calendar:sync` schedule is unchanged). The
**patterns page is dropped, not ported** — it is a read-only render of
`config('calendar.meeting_patterns')` whose audience is the developer, who has the config file.
Then grep `getUncategorizedEvents` — expected orphaned; delete it from `CalendarService` + its
`CalendarServiceTest` coverage. Tests: delete `CalendarAdminControllerTest`; extend
`ListCalendarEventsTest` with the sync action (success + failure flash).

**Commit 2 — sync preserves manual categorisation.**
In `app/Services/Calendar/GoogleCalendarSyncService.php` `syncSingleEvent()` (~113–150): when the
existing local row has `is_categorized_automatically === false`, omit `meeting_slug` and
`is_categorized_automatically` from the upsert payload (the extended-properties *read* in
`determineMeetingSlug()` can stay or go with the write-back — with no writer it will never find a
manual slug for *new* events, so simplify it to pattern-matching only). Delete
`syncCategorizationToGoogle()` (~157–185), `removeCategorizationFromGoogle()` (~192–219), and
`app/Data/CalendarCategorizationResult.php`. `CalendarService::manuallyCategorizeEvent()` /
`manuallyUnCategorizeEvent()` (~L86/L109) return the `CalendarEvent` instead of the result DTO;
update the three call sites' flash messages (`ListCalendarEvents::categorize` ~58–62,
`EditCalendarEvent::save` ~96–104; the controller caller dies in commit 1). PR description must
state the two accepted costs (D14/review 6.5): manual categorisations are no longer visible inside
the Google Calendar UI, and would not survive a local DB wipe; the Google service account can now
be demoted to read-only **on the Google side** (the spatie/laravel-google-calendar package
hardcodes the write scope in its vendor config — no code change available). Tests: rewrite the
write-back tests in `GoogleCalendarSyncServiceTest`/`CalendarServiceTest` as preserve-manual tests
(a manually-categorised row survives a sync unchanged; an automatic row still recategorises);
delete `CalendarCategorizationResultTest`; update `CalendarAdminControllerTest` references (file
already deleted in commit 1).

**Commit 3 — recurrence-field code removal (all consumers in ONE commit; the review warns
against partial removal).**

- `app/Models/Meeting.php`: remove `meeting_date`/`is_recurring`/`frequency` from `$fillable`
  (~83–85), casts (~99–104), `validationRules()` (~188–190), property docblocks.
- Delete `app/Enums/MeetingFrequency.php` (verified: only `Meeting` + `MeetingFormData` use it).
- `app/Livewire/Forms/MeetingFormData.php`: remove props (~36–40), `setMeeting` hydration
  (~59–62), rules (~98–103), `updatedIsRecurring()` (~108–113), `frequencyOptions()` (~146–151),
  payload entries (~172–174), `normalizeForSave()` frequency-nulling (~181–183). The
  `meetingDate` input has no other use — remove it too.
- `resources/views/livewire/admin/meetings/meeting-form.blade.php`: remove the recurring toggle
  (~L44), frequency select (~46–52), meeting-date input (~54–55).
- `CreateMeeting.php` (~L50) / `EditMeeting.php` (~L63): remove the `'frequencies'` view data.
- `app/Livewire/Admin/Meetings/ListMeetings.php`: remove `is_recurring`/`recurring` from
  `ALLOWED_SORT_COLUMNS` (~34–35), the `recurringFilter` URL prop (~48), the select columns
  (~100), the filter where (~107), the sort branch (~113–114).
- `resources/views/livewire/admin/meetings/list-meetings.blade.php`: remove the recurring filter
  select (~22–27) and the Recurring badge column (~77–86).
- `database/factories/MeetingFactory.php`: remove the default recurrence attributes (~48–51), the
  `recurring()` state (~76–82), the one-off state fields (~90–91), and `meeting_date` in other
  states (~68/108/128). `MeetingSeeder` has no recurrence references (verified).
- Tests deleted/trimmed **in this same commit**: `tests/Feature/DataIntegrity/`
  `MeetingFrequencyIntegrityTest`, `MeetingRecurringIntegrityTest`, `MeetingDateIndexTest`;
  recurrence sections of `ListMeetingsTest`, `CreateMeetingTest` (~123/167/179–196),
  `AdminMeetingTest` (~83–92), `AdminUrlStateTest` (~149–169 `recurringFilter`),
  `Admin/MeetingValidationTest` (~19–25, 68–69), `Integration/Models/MeetingTest` +
  `MeetingValidationTest`, `MeetingFortificationTest` (constraint assertions), and any surviving
  `MeetingSeoTest`/`JsonLdConsistencyTest`/`SitemapTest` recurrence references (most already
  handled in PR 1).

**Commit 4 — the drop migration.** New migration file (the schema is squashed into
`database/schema/mysql-schema.sql`, so this is a standalone file): drop the CHECK constraint
`meetings_recurring_frequency_check` **first**, then indexes `meetings_meeting_date_index` and
`meetings_is_recurring_index`, then columns `meeting_date`, `is_recurring`, `frequency`.
MySQL: `DB::statement('ALTER TABLE meetings DROP CHECK meetings_recurring_frequency_check')`.
**Destructive in production — flag at deploy time; recoverable only from backups.** Any
schema-assertion test for this not-yet-dumped migration must use `RefreshDatabase` (project
memory: parallel workers otherwise read stale worker DBs).

**Commit 5 — duplicate Page media conversions.** In `app/Models/Page.php`
`registerMediaConversions()` (~269–323): delete the `large` and `small` conversion registrations
(~308–322 — exact duplicates of `desktop`/`thumbnail`, kept "for backwards compatibility").
*(Superseded as landed: the `large`/`small` names were also removed from `PageImageCacheService`'s
fallback chains, because requesting an unregistered conversion name throws; the fallback is now
the original media URL — see the archival header.)*

---

## PR 6 — 3.6 Podcast merge + `<podcast:person>` + exposure-policy fix

**Create:** `resources/views/rss/feed.blade.php` — the current template content (the two are
byte-identical, verified by diff) plus a `<podcast:person>` element emitted when the item has a
preacher name. Confirm the RSS root declares the `podcast:` XML namespace
(`xmlns:podcast="https://podcastindex.org/namespace/1.0"`) — the templates already emit
`<podcast:transcript>`, so it should be present.

**Delete:** `resources/views/rss/morningFeed.blade.php`, `eveningFeed.blade.php`.

**Modify:**

- `app/Http/Controllers/PodcastFeedController.php` — drop the `match` selecting between the two
  identical templates (~30–33); render `rss.feed`.
- `app/Data/PodcastFeedItemReadModel.php` — add `public ?string $preacherName`.
- `app/Services/Public/PodcastFeedService.php` `enrichSermonForFeed()` — populate it; the value
  is already computed (`$this->sermonViewPresenter->displayPreacherName($sermon)` at ~L128 for
  the summary line).
- `app/Services/Sermon/SermonExposurePolicy.php` — **delete the three constructor-memoized config
  properties and the constructor entirely; getters read `config()` directly on every call.**
  `config()` is an array lookup; the memoization was over-optimisation, and this removes all three
  `app()->environment('testing')` branches (~49–56, ~237–239, ~245–247) with zero test
  choreography: `tests/Integration/Services/SermonExposurePolicyTest` mutates config after
  construction and passes unchanged. The scoped binding (`AppServiceProvider:64`) and
  `SingletonRegistrationTest` rows stay untouched.

**Tests:** feed tests updated for the single view name plus one `<podcast:person>` assertion
(`PodcastFeedTest`, `PodcastFeedControllerTest`, `PodcastFeedTranscriptLinkTest`);
`SermonExposurePolicyTest` — remove anything pinning constructor-time memoization. Verify feed
byte-compatibility otherwise (GUIDs `sermon-{id}`, enclosures, pubDate unchanged — podcast apps
must not re-download); run the output through a podcast feed validator once.

---

## End-to-end verification per PR

- **PRs 1–5:** `sail artisan dusk`; manually load `/`, `/church`, `/community`, a CMS page, a
  meeting page, `/sermons`, and a sermon detail page — identical rendering, JSON-LD present in
  source where expected.
- **PR 2:** with Debugbar, edit a Page in admin → public page updates immediately (targeted
  observer); edit a preacher → listing updates within 5 min (TTL); the time-travel test pins the
  upcoming-events fix.
- **PR 3:** Debugbar on `/sermons` (24-item archive) — no per-sermon query regression; byte-diff
  the podcast feed and sitemap before/after.
- **PR 4:** `sail artisan sitemap:generate`; diff old vs new URL sets (no URL lost);
  `curl /sitemap.xml`.
- **PR 5:** exercise admin meetings list + create/edit meeting in the browser; calendar events
  list categorise + Sync now; confirm a manually-categorised event survives `calendar:sync`.
- **PR 6:** `curl` both feed routes, diff against pre-change output (only the person tag added);
  feed validator.

## Verified current state (2026-07-16 appendix — anchors for implementation)

Facts confirmed against the working tree; supersede the reviews where they differ.

- **Composers** (`app/View/Composers/`, registered `ViewServiceProvider:29–32`):
  `PageShowComposer` → `pages.show` (re-supplies headingpicture×3 + links defaults);
  `HomePageComposer` → `full-width-pages.home` (`pages => PageCardService->forHome()`);
  `ChurchPageComposer` → `.church` (`pages => forChurch()`, `links => churchLinks()`);
  `CommunityPageComposer` → `.community` (`pages => forCommunity()`). `PageCardService`'s only
  callers are these three landing composers.
- **Landing routes** are `Route::view` (`web.php` L59 `/`, L62 `/christmas`, L65 `/christ`,
  L68 `/church`, L69 `/community`); christ/christmas have no composer and are fully static.
- **`PageLinksRepository`** (`app/View/Presenters/`): sole production consumer
  `app/Presenters/RelatedPagePresenter.php` (ctor inject; `ordered()`/`random()`).
- **Meetings JSON-LD**: `meetings/show.blade.php` L61–135 recurring Event+Schedule (gated on
  `is_recurring && frequency`), L137–207 ItemList of upcoming CalendarEvents;
  `meetings/events.blade.php` L28–134 ItemList from `$schemaEvents`
  (`CalendarController::eventsForMeeting` L55 concat). No `MeetingSeoPresenter` exists in
  `app/Seo/` (contents: Preacher/Series/Sermon/Song ItemListPresenters + Sermon/Song archive SEO
  presenters).
- **`pages.show` blade** defaults all composer-supplied keys (`show.blade.php:7–14`, `?? null`/`?? []`).
- **Raw `admin === 'yes'`** comparisons: `PageLinksRepository:93`, `PageCardService:87`,
  `PublicPageVisibilityGuard:42`; `Page::isAdminOnly()` exists (`Page.php:212`).
- **SermonRepository** (558 lines): registry at `clearListingCaches` 479–522,
  `clearScriptureChapterCaches` 390–427, `forgetPreacherAndSeriesCaches` 435–443,
  `forgetBookAndChapterCaches` 452–464, `forgetFlexible` 527–531 (created-key hack L530);
  memoization 25–28 + `rememberFlexible` 543–557 + `clearInternalCaches` 470–474.
  `clearListingCaches` is called **only** from `SitemapCacheObserver:91`. Write-side helpers:
  `findByDateAndServiceAndContentType` L228 (caller `SermonCreationService:75`),
  `generateUniqueSlug` L245 (caller `SermonCreationService:423`), `normalizeArchiveFilters` L196
  (callers `BreadcrumbPresenter:156`, `BrowseSermons:52`, `SermonController:47`).
  `basePublicSermonQuery()` eager-loads `preacherProfile:id,name,slug,image_path` +
  `scripturePassage:id,display_reference,normalized_reference`.
- **SitemapCacheObserver** (103 lines) observes Sermon/Page/Meeting/Preacher
  (`ModelObserverServiceProvider:39–43`); forgets `sitemap`, `nav_pages`, `admin_preacher_list`,
  `public_preacher_list`, `admin_meeting_list` (62–66) + page/meeting read models +
  `SermonStorageService::clearCachedMetadata` (Sermon) + `SermonRepository::clearListingCaches` +
  `PodcastFeedService::clearCache` (Sermon|Preacher).
- **PublicMeetingReadModelCache**: `rememberForever` L25 embeds time-dependent
  `upcomingEvents` (L48, query 104–113) — the stale bug; `getPastEventsForMeeting` (86–99) is
  computed fresh. `PublicPageReadModelCache`: `rememberForever public_page_view_{id}`.
- **`Cache::flexible` call sites** (all `[86400, 172800]`): `SitemapController:16`,
  `MeetingListCache:36`, `PodcastFeedService:38`, `SermonRepository:551`,
  `PageListCache:44/75/116`, `PreacherListCache:40/62`. Created-key hack copies:
  `SermonRepository:530`, `PageListCache:164`, `PodcastFeedService:190`. `Cache::memo()` unused.
  Production cache driver: file; tests: array.
- **Presenter cluster** (`app/Presenters/`): `SermonViewPresenter` 309 (facade; collaborators
  `new`'d in ctor; scoped binding `AppServiceProvider:68`), `SermonIdentityResolver` 352,
  `SermonPresentationAssembler` 152 (forApi/forList/forFull; takes presenter first-arg),
  `SermonPresenterCache` 131 (keys `{type}_{id}_{updated_at}`), `SermonUrlBuilder` 123 (presenter
  passback for thumbnail fallback L105), `SermonDateFormatter` 94, `SermonMetaPresenter` 77.
  Consumers: `present()` — SermonController:126, ChildrensCornerController:65; `presentForList()` —
  SermonCard:26, ChildrensTalkCard:26, SermonItemListPresenter:314, sermon-card.blade.php:8;
  `presentForApi()` — SermonApiController:140; `presentCollection()` — BrowseSermons:238,
  ChildrensCornerController:32, SermonController:182/235/263; `preWarmForAdminList()` —
  ListSermons:174 (only caller). `SermonViewPresenter::clearInternalCaches()` has no production
  callers; the same-named methods on `SermonStorageService` and `SermonItemListPresenter` clear
  those classes' own independent memo stores. Output is arrays with `array{...}` docblocks — no
  DTOs.
- **Sitemap**: `SitemapService` 524 lines; window queries at 175–252 (`getRepresentativeSermonsForStaticUrls`),
  `addBooks` 291–312, `addSeries` 454–476; `whereVisibleInSitemap` in `SermonBuilder:68–80`
  (also used by `RouteCanaryRegistry:101`). `SitemapController` uses `Cache::flexible('sitemap')`
  as a timer (return discarded) then serves `public_path('sitemap.xml')`. `sitemap:generate`
  command exists (`app/Console/Commands/GenerateSitemap.php`) but is **not scheduled**; scheduler
  lives in `bootstrap/app.php` `->withSchedule()` (`calendar:sync` at ~L32 is the pattern).
  spatie/laravel-sitemap ^8.0 installed. Sitemap presenter bindings in `AppServiceProvider:70–71`.
- **Meeting** (288 lines): recurrence fields in fillable 83–85, casts 99–104, rules 188–190;
  occurrence calculators already deleted (2026-07-12). Schema (`mysql-schema.sql` 357–389):
  `meeting_date datetime NULL`, `is_recurring tinyint(1) NOT NULL DEFAULT 0`,
  `frequency enum(...) NULL`; indexes `meetings_meeting_date_index`, `meetings_is_recurring_index`;
  CHECK `meetings_recurring_frequency_check`. `MeetingFrequency` used only by Meeting +
  MeetingFormData.
- **Google sync**: `syncSingleEvent` 113–150 (`determineMeetingSlug` 221–250 prefers Google
  extended property `private.meeting_slug`, else `config('calendar.meeting_patterns')`;
  `is_categorized_automatically = !$hasManualSlug` L145); write-back `syncCategorizationToGoogle`
  157–185 / `removeCategorizationFromGoogle` 192–219; DTO `app/Data/CalendarCategorizationResult.php`.
  Indirection: `CalendarService::manuallyCategorizeEvent:86` / `manuallyUnCategorizeEvent:109`;
  their callers: `CalendarAdminController::categorizeEvent:50`, `ListCalendarEvents::categorize:58–62`,
  `EditCalendarEvent::save:96–104`. `syncFromGoogleCalendar` called only from
  `CalendarAdminController::syncCalendar:89` (plus the scheduled `calendar:sync`).
- **Calendar admin routes**: controller routes `web.php:169–172` (admin group); Livewire routes
  229–230; public uncategorized `web.php:76`.
- **Page conversions** (`Page.php:269–323`): desktop 1920×960 q85, tablet, mobile, thumbnail
  300×200 q80, `large` ≡ desktop (308–314), `small` ≡ thumbnail (316–322).
  `PageImageCacheService` fallback chains at 26–29 reference `large`/`small` as secondary
  fallbacks (keep).
- **Podcast**: `morningFeed.blade.php` ≡ `eveningFeed.blade.php` (diff empty, 61 lines each);
  controller `match` at `PodcastFeedController:30–33`; `enrichSermonForFeed` already computes
  `displayPreacherName` (`PodcastFeedService:128`).
- **SermonExposurePolicy** (256 lines): ctor memoizes 3 config values (38–40); testing-env
  branches 49–56 / 237–239 / 245–247; scoped `AppServiceProvider:64`;
  `Integration/Services/SermonExposurePolicyTest` mutates config post-construction at 36–40,
  51–62, 71–77, 97–103, 224–228 and must keep passing after the fix.
