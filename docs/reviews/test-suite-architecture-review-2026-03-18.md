# Test Suite Architecture Review

Date: 2026-03-18

## Scope

Reviewed the architecture and maintenance characteristics of:

- `tests/Feature`
- `tests/Unit`
- `tests/Browser`
- `tests/Support`
- `tests/Traits`
- `database/factories`

## Snapshot

- Current test-file count: 295 total.
- Split by top-level area: 148 `Unit`, 135 `Feature`, 10 `Browser`, 2 `Performance`.
- `Unit` isolation is broad rather than narrow: 79 of 148 `Unit` files use `RefreshDatabase`, and 10 more use `DatabaseTransactions`.
- `Feature` isolation is similarly mixed: 93 of 135 `Feature` files use `RefreshDatabase`, and 34 use `DatabaseTransactions`.
- Biggest test classes are orchestration-heavy:
  - `tests/Unit/Services/OosAlignmentServiceTest.php` (1265 lines)
  - `tests/Unit/Jobs/ExtractSermonTest.php` (1007 lines)
  - `tests/Feature/Livewire/AdminChurchServiceTest.php` (978 lines)
  - `tests/Feature/SermonProcessingJobChainTest.php` (749 lines)
  - `tests/Unit/Services/UnifiedMediaProcessorTest.php` (653 lines)

## Findings

### 1. Test taxonomy no longer describes the real boundary or runtime cost

The folder split communicates "unit vs feature", but the suite behaves more like "service-layer integration vs HTTP/UI integration with some model tests mixed in."

- `phpunit.xml` only defines `Feature` and `Unit` suites, so `tests/Performance` is outside the default runner path entirely.
- `tests/Feature/PreacherModelTest.php` is a model/relationship/scope test, not a feature test.
- `tests/Feature/Repositories/SermonRepositoryTest.php` is a repository/cache test, not a feature test.
- `tests/Unit/Services/UnifiedMediaProcessorTest.php` and `tests/Unit/Services/LivestreamSegmentationServiceTest.php` both hit the database and other framework services, so "unit" frequently means "service integration".

Why this matters:

- It is hard to predict which folder is safe for fast feedback.
- It is hard to know where new tests should live.
- Folder names stop telling you which refactors are safe to make.

Evidence:

- `phpunit.xml:11-19`
- `tests/Feature/PreacherModelTest.php:12-139`
- `tests/Feature/Repositories/SermonRepositoryTest.php:15-220`
- `tests/Unit/Services/UnifiedMediaProcessorTest.php:26-103`
- `tests/Unit/Services/LivestreamSegmentationServiceTest.php:23-205`

Recommendation:

- Reorganize around boundary and cost, not habit. A clearer split would be something like `Domain`, `Integration`, `Http`, `Livewire`, `Browser`, and `Performance`, or keep the folders but add strict PHPUnit groups that map to those boundaries.

### 2. The heaviest orchestration tests build scenarios by hand instead of using stable characterization seams

The largest files are not just long; they repeatedly recreate large domain graphs inline. That makes them expensive to read and expensive to change.

- `tests/Unit/Services/OosAlignmentServiceTest.php` manually creates `ChurchService`, `ChurchServiceItem`, `MediaProcessingLog`, and `ServiceSection` records inside nearly every example.
- `tests/Feature/SermonProcessingJobChainTest.php` combines file setup, database writes, queue control, service binding, and end-state assertions in one class.
- `tests/Feature/Livewire/AdminChurchServiceTest.php` spans listing, upload, editing, job dispatch, publication, and authorization behavior in a single large suite.

Why this matters:

- Refactors that preserve behavior still cause wide churn because tests encode construction mechanics instead of user-facing or domain-facing contracts.
- The suite is missing a concise DSL for scenarios like "processing log with two low-confidence song sections" or "service with imported OpenLP items and one linked song".

Evidence:

- `tests/Unit/Services/OosAlignmentServiceTest.php:18-120`
- `tests/Feature/SermonProcessingJobChainTest.php:32-206`
- `tests/Feature/Livewire/AdminChurchServiceTest.php:53-230`

Recommendation:

- Add scenario builders under `tests/Support` for processing logs, service sections, church services, and media uploads.
- Pair those builders with custom assertions for stable outputs such as alignment results, pipeline plans, and review triggers.

### 3. Cross-cutting setup is duplicated even though the repo already has some of the right building blocks

The suite has helper infrastructure, but it is mostly low-level. Many tests still duplicate authentication, storage, and upload setup.

- `database/factories/UserFactory.php` already exposes `admin()` and `crockenhillAdmin()`, but many tests still inline `['is_admin' => true, 'email_verified_at' => now()]`.
- `tests/Support/OpenLpArchiveFactory.php` is a good domain helper, but Livewire tests still have to read the generated file back into memory and re-wrap it before they can use it.
- `tests/Traits/MediaProcessingTestHelpers.php` only provides a canned analysis payload and a success-only processor mock, so callers still duplicate storage fakes, queue fakes, config overrides, and processing-log setup.

Why this matters:

- Small contract changes force broad sweep edits.
- The helper layer does not absorb the expensive, repetitive parts of test setup.

Evidence:

- `database/factories/UserFactory.php:25-48`
- `tests/Support/OpenLpArchiveFactory.php:17-48`
- `tests/Traits/MediaProcessingTestHelpers.php:14-42`
- `tests/Feature/Livewire/AdminChurchServiceTest.php:116-183`

Recommendation:

- Promote scenario-level helpers such as `actingAsVerifiedAdmin()`, `fakeMediaDisks()`, `makeLivewireUpload()`, and `processingLogScenario()` instead of only raw payload helpers.

### 4. The default base test case globally disables throttling middleware

The suite currently opts out of real throttling by default, and a few tests explicitly re-enable it locally when they need it.

- `tests/TestCase.php` calls `withoutMiddleware(ThrottleRequests::class)` for every test process.
- That means rate-limit coverage is only real when an individual test remembers to override the base behavior.

Why this matters:

- New throttled routes can appear covered without ever exercising the middleware stack.
- Security coverage becomes opt-in instead of default-on.
- The test architecture bakes a parallel-execution workaround into the global base layer.

Evidence:

- `tests/TestCase.php:26-39`

Recommendation:

- Move the throttling bypass into a narrow base class or opt-in trait for the specific suites that need it, and let security/rate-limit tests inherit the real stack by default.

### 5. Several "unit" tests are white-box interaction tests that over-specify implementation details

There is a recurring pattern of mocking most collaborators while still using the database, storage, or bus. That creates brittle tests that fail during harmless refactors.

- `tests/Unit/Services/LivestreamSegmentationServiceTest.php` mocks four collaborators, persists logs, and asserts orchestration details like pipeline-builder calls.
- `tests/Unit/Services/UnifiedMediaProcessorTest.php` mixes `RefreshDatabase`, storage fakes, bus fakes, model queries, and several mocks in one suite.
- Request tests such as `tests/Unit/UpdateSermonRequestTest.php` and `tests/Unit/Http/Requests/RequestAuthorizationTest.php` partially mock the request object itself instead of exercising request authorization through a thin, public seam.
- `tests/Unit/Services/VideoStorageServiceTest.php` injects a private property via reflection, and the performance suite reflects into private methods as well.

Why this matters:

- Constructor changes, private method renames, or internal orchestration changes can break tests even when externally visible behavior is unchanged.
- White-box tests make large refactors feel riskier than they really are.

Evidence:

- `tests/Unit/Services/LivestreamSegmentationServiceTest.php:37-205`
- `tests/Unit/Services/UnifiedMediaProcessorTest.php:48-103`
- `tests/Unit/UpdateSermonRequestTest.php:21-99`
- `tests/Unit/Http/Requests/RequestAuthorizationTest.php:13-44`
- `tests/Unit/Services/VideoStorageServiceTest.php:117-225`
- `tests/Performance/ThumbnailGenerationPerformanceTest.php:75-155`

Recommendation:

- Extract pure decision-making into small collaborators or value objects, then test those directly.
- Keep a thinner set of integration tests around the Laravel wiring.
- Avoid reflection and partial mocks when a public seam can be introduced instead.

### 6. Browser coverage is fragile and the page-object layer is effectively unused

The Dusk suite relies on raw selectors, inline-style checks, and JavaScript-driven clicks. There is a page-object layer in the tree, but the current tests do not appear to use it.

- `tests/Browser/NavigationTest.php` checks menu state via `style.display` and custom `waitUntil(...)` expressions.
- `tests/Browser/PageCardsTest.php` recreates page fixture data inline and clicks links with `script(...)` instead of using stable, higher-level helpers.
- `tests/Browser/Pages/HomePage.php` is still a stub and is not being used as a meaningful abstraction layer.

Why this matters:

- Minor markup or CSS changes can break browser tests even when user behavior is unchanged.
- Dusk intent is harder to read than the corresponding feature coverage.

Evidence:

- `tests/Browser/NavigationTest.php:59-103`
- `tests/Browser/PageCardsTest.php:14-145`
- `tests/Browser/Pages/HomePage.php:7-35`

Recommendation:

- Keep Dusk for a very small number of smoke journeys.
- Add stable `data-testid` hooks where DOM structure is otherwise volatile.
- Either invest in the page-object layer or remove the dead abstraction.

### 7. Performance and dedicated coverage is hard to discover and easy to drift

There is a split between default tests, dedicated tests, browser tests, and performance tests, but the boundaries are only partly encoded in the tooling.

- `phpunit.xml` does not include `tests/Performance`.
- CI runs browser tests separately, but the performance tests are invisible to the normal `php artisan test` path.
- `tests/Performance/LivestreamProcessingPerformanceTest.php` uses wall-clock thresholds and `echo` output.
- `tests/Performance/ThumbnailGenerationPerformanceTest.php` mixes timing assertions with reflection on private methods.
- `tests/README_THUMBNAIL_TESTS.md` still documents `tests/Integration/*` files and thumbnail test paths that no longer exist.

Why this matters:

- These checks behave more like ad hoc benchmarks than reliable regression tests.
- The documented map of the suite is stale, which makes future cleanup harder.

Evidence:

- `phpunit.xml:11-19`
- `.github/workflows/deploy.yml:111-115`
- `.github/workflows/deploy.yml:209-213`
- `tests/Performance/LivestreamProcessingPerformanceTest.php:38-203`
- `tests/Performance/ThumbnailGenerationPerformanceTest.php:41-155`
- `tests/README_THUMBNAIL_TESTS.md:46-68`
- `tests/README_THUMBNAIL_TESTS.md:156-169`

Recommendation:

- Treat performance checks as explicit benchmarks or a separate opt-in CI job with stable baselines.
- Update or remove stale test-suite documentation so the suite map matches reality.

## Strong Foundations Already Present

- `tests/Support/OpenLpArchiveFactory.php` is a solid domain-specific fixture helper.
- Factory states already exist for some common roles and processing records.
- CI already separates Dusk from the core suite, which makes it realistic to slim browser coverage down to smoke paths.

## Suggested Follow-On Sequence

1. Define a test taxonomy based on boundary and execution cost.
2. Introduce scenario builders in `tests/Support` for church services, service sections, processing logs, uploads, and authenticated users.
3. Break the largest orchestration suites into narrower characterization suites around stable public seams.
4. Remove the global throttle bypass from the default base test case.
5. Replace reflection and partial-mock tests with public-seam tests or extracted pure collaborators.
6. Reduce Dusk to a few critical journeys and move the rest of the coverage down into faster layers.
7. Reframe performance work as benchmarks with an explicit execution path, then clean up stale docs.
