## 2026-02-27 - [Sitemap Media Testing]
**Learning:** Testing `Spatie\Sitemap` integration requires understanding the internal property names of `Video` and `Image` tags. For example, `Video` uses `thumbnailLoc` and `contentLoc` instead of `thumbnailUrl` and `contentUrl`.
**Action:** Use `var_dump` or reflection on third-party tag objects to verify correct property assertions.

## 2026-02-27 - [Testing without Database]
**Learning:** In environments where the primary database (MySQL) is unavailable and migrations fail on alternatives (SQLite), unit tests for model methods can still be performed using `Model::factory()->make()`.
**Action:** Prefer `make()` over `create()` for unit tests that only verify data transformation logic and do not require persistence. Also, remove the `DatabaseTransactions` trait from such tests as it still attempts to establish a DB connection.

## 2026-02-21 - SQLite Compatibility and View Composers
**Learning:** Raw MySQL syntax like `DB::raw('RAND()')` or `SHOW INDEX FROM...` in migrations prevents tests from running in SQLite environments. Using `inRandomOrder()` is the standard Laravel way to ensure cross-database compatibility.
**Action:** Always prefer `inRandomOrder()` and wrap driver-specific migration logic in driver checks (`DB::getDriverName() === 'mysql'`).

## 2026-02-21 - Parallel Testing Artifacts
**Learning:** Running parallel tests with SQLite in Laravel creates local database files (e.g., `crockenhill_test_1`) in the root directory.
**Action:** Ensure these artifacts and any temporary `.env` files are removed before committing to avoid polluting the repository with binary data.

## 2026-02-28 - [Testing BinaryFileResponse and Naming Conventions]
**Learning:** `BinaryFileResponse` (returned by `response()->file()`) can be verified in feature tests using `assertStatus` and `assertHeader`. When using PHPUnit `#[Test]` attributes, method names should not have the `test_` prefix to avoid redundancy and follow strict project conventions.
**Action:** Use `#[Test]` with descriptive, non-prefixed method names like `can_serve_audio_locally`.

## 2026-03-14 - [Config Mocking in Unit Tests]
**Learning:** To test services that depend on configuration values (`config('...')`), use `Config::set()` within the test method to dynamically change the environment state. This allows verifying different logic branches (e.g., public vs. private features) within the same test file.
**Action:** Always wrap such tests with `Config::set()` and consider resetting to defaults if necessary, though Laravel's test state usually handles this per-test.

## 2026-03-28 - [Integration Tests for Legacy Migration Services]
**Learning:** Testing services that perform legacy data migration (e.g., `LegacyPlayDateSongUsageImporter`) requires careful setup of temporary filesystem artifacts (SQL dumps) and database state (songs). Using `DatabaseTransactions` is mandatory for these tests to ensure that every execution starts with a clean database while avoiding the overhead of `RefreshDatabase` in parallel runs.
**Action:** Place integration-style service tests in `tests/Feature/Services/` and use `tempnam()` for generating temporary input files, ensuring they are deleted in `tearDown()`.

## 2026-04-01 - [Sermon Asset Authorization Testing]
**Learning:** Testing authorization logic for `BinaryFileResponse` endpoints requires simulating guest and authenticated user states while toggling configuration values (`Config::set()`). Guests should be redirected to login (`assertRedirect(route('login'))`) for private content, while authenticated users or public content should resolve to the asset's public URL or serve the file directly.
**Action:** Use `Config::set()` to toggle content visibility and `actingAs()` to simulate different user levels in feature tests targeting asset controllers.
