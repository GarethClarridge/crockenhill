# Phase 9 — Technical code-quality review (2026-07-19)

> Session brief: `docs/archived-plans/JULY-2026-SIMPLIFICATION-REVIEW-PLAN-2026-07-02.md` §Phase 9.
> **Precondition note:** the structural work has *substantially but not fully* landed. The maintainer
> waived the gate knowing R2–R15 of
> [../../plans/JULY-2026-SIMPLIFICATION-REMAINDER-2026-07-19.md](../../plans/JULY-2026-SIMPLIFICATION-REMAINDER-2026-07-19.md)
> remain open. Code on the R8–R12 deletion lists was therefore **counted but not polish-reviewed**;
> every finding below states its interaction with the remainder plan where one exists.
> No code was changed in this session.

## 1. Scope reviewed

- All of `app/` — 540 PHP files, ~88,100 lines (100% `declare(strict_types=1)`).
- Full test estate — 720 files, 5,767 tests / 17,912 assertions (clean parallel run this session, 4:45 wall).
- `config/` (29 files), `resources/views` (153 blades), `resources/js`, `routes/`, `composer.json`/`package.json`.
- Static analysis: Larastan level 8 (current), with trial runs at levels 9 and 10 (raw outputs preserved in the session scratchpad; regenerate with `vendor/bin/sail php vendor/bin/phpstan analyse --level=9 --error-format=raw`).
- Live request profiles via Debugbar CLI on `/`, `/christ/sermons`, a dated sermon page.

## 2. What this phase is for

Phases 1–7 asked "should this exist?"; this phase asks **"is the surviving code well made?"** The
outcome the church gets: a codebase a single maintainer (plus agents) can keep safe and fast —
fewer latent type holes, no known-vulnerable dependencies, a test suite that stays quick and
honest, and hot public pages that don't do wasted work.

## 3. Complexity inventory

| Surface | Count | Note |
|---|---|---|
| `app/` PHP files / lines | 540 / ~88,100 | 43 `readonly` classes; largest files are almost all on R8–R12 deletion lists |
| Tests / assertions | 5,767 / 17,912 | 4:45 parallel wall; 1,024 s summed worker-serial time |
| Config files | 29 | Down from ~38 (Workstream 6) |
| Blade views | 153 | Zero orphans (see F3.1) |
| `app/Data/` DTOs | 56 | Zero orphans; 8 use `spatie/laravel-data`, 48 plain readonly (see F2.4) |
| Console commands | 34 | Two newly-spent one-shots not yet on an R8 gate (see F3.2) |
| PHPStan level 8 | **0 errors, empty baseline** | The asset is intact |
| PHPStan level 9 (trial) | 867 errors | 67 sit in R8–R12 deletion-listed files → ~800 in surviving code |
| PHPStan level 10 (trial) | 1,248 errors | Not realistic this cycle |

## 4. Findings

### Axis 1 — Static-analysis ratchet

**F1.1 — Level 9 is a real but tractable project; do it after R9–R11, in clusters, without a
baseline.** The 867 errors are overwhelmingly `mixed`-strictness families (`argument.type` 210,
`cast.string` 199, `offsetAccess.nonOffsetAccessible` 142, `cast.double` 90, `cast.int` 70,
`return.type` 44). They are not random: the top ~20 files carry ~350 errors, and the sources are
four repeating shapes:

1. **Untyped array payloads** passed between jobs — e.g. `app/Jobs/SendCompletionNotification.php`
   (36 errors) reaching into `$data['sermon']['title']` with no array shape. These are the only
   cluster that is *bug-adjacent* rather than cosmetic: a malformed payload fails at interpolation
   time instead of at a typed boundary. Fix shape: `@phpstan-type` array shapes or small DTOs.
2. **`mixed` Livewire URL-bound properties** — `BrowseSongs` (14 errors; already parked by the
   songs review). Fix shape: type the properties.
3. **`config()`/`json_decode()` returns** used without casts in media services.
4. **DB scalar plucks** (`SermonRepository` return-type generics).

Timing matters: R9/R10/R8 deletions remove 67 errors outright, R7 rewrites most of
`OosEmailParserService`, R11 (1.7c) reshapes `MatchSongsFromTranscript` (11), and the two
`ImportOosArchive*` retirement candidates below carry 63. Ratcheting **before** those lands means
typing code that dies weeks later. Recommendation: **stay at level 8 until R9–R11 merge, then fix
to level 9 in 2–4 focused sessions, cluster by cluster, keeping the baseline empty.** A per-entry
baseline for ~700 errors would be noise, not a ratchet.

**F1.2 — Level 10 is not realistic this cycle** (1,248 errors; adds `method.nonObject` 92 and
stricter `return.type` 129 on top of everything level 9 wants). Reassess only once level 9 has
held at zero for a while.

**F1.3 — Ignore-comment hygiene.** `app/Services/Calendar/GoogleCalendarSyncService.php` carries
**12 bare `/** @phpstan-ignore-next-line */` comments with no error identifier** (lines 57–191) —
the exact cluster the public-site review parked for this phase — and the same file contributes 14
level-9 errors. Every other ignore in `app/` is identifier-scoped and justified. Direction: type
the Google API payloads properly once (probably alongside the level-9 work) and delete the
cluster; at minimum add identifiers so the ignores stop suppressing unknown future errors. The
three `function.alreadyNarrowedType` ignores in `ManagesSectionPublication` disappear with
remainder R3 item 2 — do not touch them separately.

### Axis 2 — Standards, idioms, and the modernization backlog

**F2.1 — The archived Laravel-12 modernization backlog is fully superseded; nothing needs
resurrecting.** Verified against live code: `declare(strict_types=1)` is 540/540 (BL-013 ✔);
`Gate::before` is gone (BL-004 ✔); `app/app` is gone (BL-015 ✔); `env()` outside config exists
only as the documented `TRUSTED_PROXIES` bootstrap exception (BL-012 ✔); `AppServiceProvider` is
organized and commented by concern (BL-011 ✔); the `Sermon` model has been decomposed —
transcript I/O, storage URLs, sitemap, and processing state are all delegated
(`SermonSitemapPresenter`, `SermonProcessingState`) (BL-009 substantially ✔); and
`SermonJobPipelineService` (an R15 named target) **no longer exists** — its only remaining
references are in two planning docs. The archival header already says "do not work from this
file"; this review confirms that is correct.

**F2.2 — `Sermon` model residue (the "Sermon model slim" named target is mostly done).** What
remains at `app/Models/Sermon.php` (564 lines): (a) a 56-line static `validationRules()` block —
a judgment call, since it is the single shared source for admin form + API validation; moving it
to a dedicated rules class would finish the "model = relationships/casts/accessors" story but is
not urgent; (b) stale historical comments in `$fillable` ("Renamed from 'filename' for
consistency") that describe migrations long past — mechanical deletion.

**F2.3 — `AiServiceProvider` binding inconsistency (parked by platform review F7, confirmed).**
`SermonAnalysisInterface` (`app/Providers/AiServiceProvider.php:30-38`) is the only binding using
if/else instead of `match`, and one of two that silently fall through to the real (paid) service
on an unrecognised config value instead of throwing like the `ServiceTranscriptionInterface` /
`ServiceStructureInterface` bindings. Mechanical: convert both it and the
`TranscriptionServiceInterface` binding to the match-with-exception idiom.

**F2.4 — `spatie/laravel-data` is a droppable dependency (parking-lot item resolved).** Exactly 8
of 56 `app/Data/` classes extend `Spatie\LaravelData\Data`; total package-feature usage across
them is **zero `::from()` calls, zero validation invocations, four validation attributes** —
they are plain constructor-promoted DTOs that happen to inherit `toArray()`. The other 48 DTOs
already follow the plain-`readonly`-class convention. Direction: convert the 8 (hand-rolled
`toArray()` where call sites need it), delete `config/data.php`, drop the composer dependency.
Small PR; removes a whole vendor config file and a dependency surface. (The unread `data.*` keys
flagged by the config scan are this package's — they die with it.)

**F2.5 — Production code branching on test context (recurring smell, three files + one
provider).** `app()->environment('testing')` branches survive in
`StorageAdapterHelper.php:234`, `SitemapService.php:229`, and
`VideoSegmentationService.php:60` (the last dies or shrinks with R10 — leave it), and
`AppServiceProvider` carries three test-serving hooks (visual-regression clock freeze,
deterministic Faker, `ParallelTestingProcessLimiter` argv rewriting). The Playwright/parallel
hooks are deliberate infrastructure with explanatory comments — acceptable — but the
`StorageAdapterHelper` and `SitemapService` branches are the same pattern Phase 18 removed
elsewhere and should be inverted (inject the behaviour, or gate on config the test sets).

**F2.6 — Broad exception handling is concentrated and mostly deliberate, with one named fix.**
103 `catch (\Exception)` sites, clustered in media-pipeline services
(`MetadataExtractionService` 11, `VideoSegmentationService` 6, `StorageAdapterHelper` 5 …) where
"log and continue the pipeline" is the design. The one parked, user-facing case is still present:
`SermonRepository::getExistingSeries()` (`app/Services/Public/SermonRepository.php:237`) converts
*any* DB failure into a silently empty series filter with only a `Log::warning`. Narrow it (or
drop the try/catch and let the page error honestly). Do not blanket-"fix" the pipeline catches.

**F2.7 — Small idiom residue (all mechanical):** `AudioTranscriptionService`
`$processingId = 'unknown'` parameter defaults (lines 71, 189 — April P2 residue; callers always
pass a real id now, so drop the defaults); `@php` blocks in ~10 admin blade views (small
presentation logic like `$maxCount` math in `show-song.blade.php` — fold into computed
properties when those views are next touched; not worth a dedicated pass);
`BootstrapSpeakerProfilesCommand` rename-consideration (platform review) — recommend **keep the
name**, it genuinely bootstraps profiles; a rename buys nothing.

**F2.8 — Closed parked items (verified fixed by the consolidation work, no action):** the
`MediaUpload` component trio is now one 508-line enum-driven component (`UploadState`), its
`getProcessor()` pass-through and string-status comparisons are gone; `x-toggle`'s
`$wire.entangle` co-ownership is gone; `StructureEvaluateCommand` now defaults to the bound
detector ("a bare run never costs money"); the dangling `media-processing.storage.legacy_disk`
read is gone; `SermonExposurePolicy`'s `environment('testing')` branches are gone; the enums
formerly co-located in `SermonCreationService.php` are gone; **Alpine.js duplication
(parking-lot item): none found** — inline `x-data` blocks are small and all unique, JS lives in
four purposeful files.

### Axis 3 — Dead code

Confidence stated per item. The R8–R12 lists were treated as already-dispositioned and are not
re-flagged here.

**F3.1 — The reference-level hygiene is excellent (high confidence, verified this session):**
zero orphaned `app/Data/` classes (re-census after the service-UI consolidation), zero orphaned
`app/Contracts/`, zero orphaned blade views (the one scan hit, `pages/christ/free-bible.blade.php`,
is resolved dynamically by `PageController::resolveView()`'s documented `pages.{area}.{slug}`
override and seeded by `PageSeeder`), and `config/redirects.php` is consumed whole-array in
`routes/web.php:245`.

**F3.2 — Two newly-spent one-shots are not on any R8 gate list (medium-high confidence; needs
the same operator-gate treatment as R8):**

| Candidate | Evidence | Suggested gate |
|---|---|---|
| `ImportOosArchiveCommand` (536 lines) + `OosArchiveEvaluator` (~400) + their two test files | Referenced only by themselves and their tests; the OoS archive import + pipeline eval completed 2026-07-11 (plan archived). Together they carry **63 of the level-9 errors** | Maintainer confirms the OoS paper archive is fully imported and no further eval runs are planned |
| `BackfillSongPraiseNumbersCommand` | The PR #1171 song-number backfill companion; one-shot by design | Operator confirms the prod backfill + `service-tracking:link-songs` ran after #1171 merged |

Fold both into the R8 deletion batch (delete tool + tests in one commit, pre-deletion tag in the
PR description, per the AGENTS.md one-shot convention).

**F3.3 — Dead config keys (high confidence — no reads anywhere in `app/`, `resources/`,
`routes/`, `bootstrap/`, `tests/`, `database/`):**

- `config/calendar.php` → the whole `performance` block and the whole `google` block (the live
  `GOOGLE_CALENDAR_ID` read is `services.google.calendar_id` in `config/services.php`; the
  calendar.php copy is a stale duplicate). Workstream 6 removed other calendar keys but these
  two blocks survived.
- `config/podcast.php` → `enabled` (the feed controller and service never gate on it — either
  delete the key, or decide it *should* gate the route; delete is recommended, the route works).
- `config/thumbnail-generation.php` → the `ffmpeg` block (frame extraction reads
  `media-processing.video.ffmpeg_path`; nothing reads this copy of `FFMPEG_PATH`).

The other keys the scan flagged (`health.*`, `data.*`, `blade-icons.*`, `laravel-ffmpeg.*`,
`openai.organization/project/request_timeout`) are vendor-package-read — **not dead** (though
`data.*` dies with F2.4).

### Axis 4 — Test quality

**F4.1 — The suite is healthy at the macro level.** 5,767 tests / 17,912 assertions, zero
failures, zero risky tests, 4:45 parallel wall. Pint passes repo-wide. No `assertTrue(true)`;
Mockery appears in only 62/720 files; 2 `sleep()` calls in tests. (A first run this session
produced 4,331 errors — that was Docker DNS breakage between containers after a containerd
glitch, cured by `sail down && sail up`; noted here so nobody mistakes the transcript for a
suite problem.)

**F4.2 — 124 PHPUnit 13 "mock without expectations" notices (mechanical).** PHPUnit 13 now flags
mocks used as stubs ("No expectations were configured for the mock object… Consider a test
stub"). Example: `AudioCompressionServiceTest::it_can_be_instantiated_in_test_environment`
(which is also a weak assert-it-constructs test — delete or strengthen it while there). Sweep:
convert to `createStub()` where no expectations exist, or add
`#[AllowMockObjectsWithoutExpectations]` where the mock is shared fixture. Run
`vendor/bin/sail php vendor/bin/phpunit <dir> --display-all-issues --no-progress` per directory
to enumerate.

**F4.3 — The slow cluster is thumbnail GD rendering, including mislabeled "Unit" tests.** The 11
slowest tests (5–12 s each) are all `ThumbnailGenerationService*`/`ThumbnailCanvasComposerTest`
full-resolution canvas renders; `Tests\Unit\Services\ThumbnailCanvasComposerTest` runs up to
10.8 s — an integration render in the Unit suite. Direction: a test-profile render size (the
canvas geometry assertions scale) or moving the pixel-assertion cases to a smaller fixture
resolution; and re-home the composer test out of `Unit`. Worth roughly 60–80 s of worker-serial
time. Everything else: next-slowest is 4.3 s (`AdminLivewireAuthorizationTest` routed-render
smoke — earning its keep) and the long tail is fine.

**F4.4 — Already-tracked duplicates not re-derived here:** the five legacy flat Livewire test
files are R14's table; the five-directory integrity-test convention is the R14/7.2 conventions
item. Nothing new to add to either.

### Axis 5 — Dependency hygiene

**F5.1 — SECURITY (act this week, independent of all other work):**
`spatie/laravel-medialibrary` is pinned at 11.22.1; `composer audit` reports
**CVE-2026-48557 (HIGH — file upload restriction bypass)** and **CVE-2026-48555 (medium — SSRF)**,
both fixed in 11.23.0; 11.23.2 is available and semver-minor. This is the media library backing
public-facing meeting/page images. `vendor/bin/sail composer update spatie/laravel-medialibrary`,
run the medialibrary-touching tests, deploy. (GitHub's open Dependabot alert list currently shows
only the js-yaml item below — do not treat Dependabot as the source of truth; `composer audit`
caught these.)

> **Correction (2026-07-20): this finding's version claim was wrong.** `composer.lock` has
> carried 11.23.1 (both CVEs patched in 11.23.0) since commit `ac2ced40f` (2026-07-03), so
> production — which installs from the lock — was not exposed when this review ran. The 11.22.1
> reading came from a stale local vendor tree: `composer audit` audits *installed* packages, not
> the lock. Resolved by `composer install`; audit clean as of 2026-07-20. The remediation plan's
> WP1 was downgraded accordingly.

**F5.2 — js-yaml moderate DoS (dev-only, low urgency):** transitive via `@lhci/cli` (Lighthouse
CI), js-yaml 3.14.2 < 3.15.0. `npm audit fix` will not cross the major; needs an `overrides`
entry or an `@lhci/cli` bump when one appears. CI-tooling-only exposure.

**F5.3 — Everything else is minor/patch drift:** 20 composer packages one minor/patch behind
(notably laravel/framework 13.15→13.20, livewire 4.3.0→4.3.3, larastan 3.9.6→3.10.0); npm has 4
patch-level bumps. The only held-back majors are `symfony/http-client`/`symfony/mailgun-mailer`
7.4→8.1, which arrive with the framework — leave them. No unused direct dependencies were
identified. After the framework/larastan bumps, re-run `boost:install` per CLAUDE.md.

### Axis 6 — Performance

**F6.1 — `#[Computed]` methods called as methods, defeating memoization — the hottest public
page does its listing work three times.** Measured live: `/christ/sermons` executes 19 queries of
which **12 are duplicates in 4 groups** — the count/rows/preachers/scripture-passages block runs
3× because `BrowseSermons` invokes its computed `sermons()` with call syntax
(`app/Livewire/Sermons/BrowseSermons.php:139,222,238`); Livewire only memoizes **property**
access (`$this->sermons`). The same pattern exists on `preacherOptions()`/`seriesOptions()` in
`BrowseSermons` (lines 146–148) and `sectionTypeOptions()`/`preacherOptions()` in
`ShowChurchService` (lines 67–68), where backing scoped caches happen to absorb most of the
damage. Fix: property access at ~10 call sites, plus one regression guard (assert query count on
the browse page, or a shared structural test that no `#[Computed]` method is invoked with
parentheses). Mechanical-with-test; the largest single win in this review for public-page work
avoided.

**F6.2 — No Eloquent strict mode anywhere.** `Model::shouldBeStrict()` /
`preventLazyLoading()` is absent, so the framework's cheapest N+1/missing-attribute tripwire is
off even in local/testing. Direction: enable `Model::shouldBeStrict(! app()->isProduction())` in
`AppServiceProvider::boot()`, run the full suite, and fix whatever surfaces (the suite passing
today with the flag on is *not* guaranteed — treat as a small project, not a one-liner; that is
why it is not in the mechanical list).

**F6.3 — Otherwise the read path is demonstrably healthy.** Warm profiles: `/` = 4 queries,
56 ms; dated sermon page = 8 queries, no duplicates; the singleton/scoped cache layering in
`AppServiceProvider` is doing its job. No further hot-path work recommended.

## 5. Opportunities unlocked

- **A permanent query-count regression net.** Fixing F6.1 pairs naturally with Debugbar-CLI or
  `expectsDatabaseQueryCount()` assertions on the three hot public pages — cheap now that the
  profiles exist, and it makes every future listing change honest.
- **Level 9 as a payload-typing forcing function.** The job-payload clusters (F1.1 shape 1) are
  exactly the seams the doctrine wants typed; ratcheting converts "phpstan chores" into typed
  boundaries for `SendCompletionNotification` and friends.
- **A leaner dependency story.** F2.4 (drop laravel-data) + F5 updates + the R8 deletions leave
  a dependency list where everything present is demonstrably used — which is what makes future
  `composer audit` findings actionable at a glance.
- **PHPUnit 13's mock-notice sweep (F4.2) doubles as an over-mocking audit** — each of the 124
  sites is a place to ask "should this be a stub, a fake, or the real object?"

## 6. Removal candidates (needs decision)

| Candidate | Cost of keeping | Cost/risk of removing |
|---|---|---|
| `ImportOosArchiveCommand` + `OosArchiveEvaluator` + 2 test files (~1,100 lines total) | Carries 63 level-9 errors; one-shot import completed 2026-07-11 | None once the maintainer confirms the archive import is final; restorable from git |
| `BackfillSongPraiseNumbersCommand` + test | One-shot riding indefinitely | None once prod backfill confirmed (operator gate, counts only) |
| `spatie/laravel-data` dependency + `config/data.php` + 8 class conversions | A vendor surface + published config for features nobody calls | Small conversion PR; verify `toArray()` call sites on the 8 classes first |
| Dead config keys (F3.3): `calendar.performance`, `calendar.google`, `podcast.enabled`, `thumbnail-generation.ffmpeg` | Misleads readers about what is configurable | None found; `podcast.enabled` needs the one-line decision "gate or delete" |

## 7. Quick wins

Each under an hour: the medialibrary security update (F5.1); the `AiServiceProvider` match
conversion (F2.3); the `SermonRepository::getExistingSeries()` catch narrowing (F2.6); dropping
the `processingId = 'unknown'` defaults (F2.7); deleting the stale `$fillable` comments (F2.2b);
the four dead config keys (F3.3); npm patch bumps (F5.3).

## 8. Open questions for the user

1. **OoS archive (gates F3.2a):** is the order-of-service paper archive fully imported, with no
   further evaluator runs planned?
2. **Praise! backfill (gates F3.2b):** did `songs:backfill-praise-numbers` (+
   `service-tracking:link-songs`) run in production after #1171?
3. **`podcast.enabled` (F3.3):** should the feed route actually be gateable, or delete the key?
4. **Ratchet appetite (F1.1):** agree the sequencing "level 9 after R9–R11, no baseline"? If you
   want it sooner, the deletion-scheduled files would need temporary excludes instead — workable
   but messier.

## 9. Out of scope, noted for the remainder plan

- Everything in R2–R15 was left alone, including: `OosEmailParserService` internals (R7),
  `ChurchServiceReviewStateService` (R6), the timeline family (R5), heuristic cluster +
  visual stack (R9/R10), `ProcessingPhaseRegistry` shape (R11 1.7e), and the five duplicate
  test suites (R14).
- `VideoSegmentationService.php:60`'s testing branch — reassess whatever half survives R10.
- The `app/Data/` domain-subfolder reorganization (platform F6) — worth doing *after* F2.4's
  conversions so files move once. Not urgent; it's navigation polish.
- Coordination note for R9: the deletion commits will remove files carrying 67 level-9 errors —
  if the ratchet lands first for some reason, exclude rather than fix those files.

---

## Mechanical fixes (execute wholesale in one implementation session, no per-item sign-off)

Safe, rote, behavior-preserving; run the standard quality gates once at the end
(`composer phpstan` at level 8 = 0, `pint --dirty`, full parallel suite, Dusk only if the
browse-sermons view changes).

1. `composer update spatie/laravel-medialibrary` to ≥ 11.23.2 (F5.1) — do first, ship alone.
2. `#[Computed]` call-syntax → property-access at the ~10 sites in `BrowseSermons` and
   `ShowChurchService`, plus a query-count assertion on the sermons browse page (F6.1).
3. `AiServiceProvider`: convert `SermonAnalysisInterface` and `TranscriptionServiceInterface`
   bindings to `match` with an `InvalidArgumentException` default (F2.3).
4. Delete dead config keys: `calendar.performance`, `calendar.google`,
   `thumbnail-generation.ffmpeg`, and (pending Q3) `podcast.enabled` (F3.3).
5. Narrow `SermonRepository::getExistingSeries()`'s `catch (\Exception)` to `QueryException` —
   or remove the try/catch (F2.6).
6. Remove `$processingId = 'unknown'` parameter defaults in `AudioTranscriptionService` (F2.7).
7. Delete the historical rename comments inside `Sermon::$fillable` (F2.2).
8. PHPUnit mock-notice sweep: `createStub()`/`#[AllowMockObjectsWithoutExpectations]` across the
   124 notice sites; delete or strengthen `it_can_be_instantiated_in_test_environment`-style
   constructor-only tests found while there (F4.2).
9. Add error identifiers to the 12 bare `@phpstan-ignore-next-line` comments in
   `GoogleCalendarSyncService` (full typing of the Google payloads belongs to the level-9
   session, not this sweep) (F1.3).
10. npm patch bumps (`tailwindcss`, `@tailwindcss/vite`, `vite`, `laravel-vite-plugin`) and
    routine composer minor updates; re-run `boost:install` after the framework/larastan bumps
    (F5.3).

**Explicitly *not* mechanical** (judgment or gated): `Model::shouldBeStrict()` enablement
(F6.2 — needs a survey run), the laravel-data removal (F2.4 — dependency change, needs approval
per AGENTS.md), the two one-shot deletions (F3.2 — operator gates), `validationRules()`
relocation (F2.2 — judgment), the `environment('testing')` inversions (F2.5 — design), the
thumbnail test-render profile (F4.3 — touches assertion values), the js-yaml override (F5.2 —
override hygiene call), and the entire level-9 ratchet (F1.1 — sequenced behind R9–R11).
