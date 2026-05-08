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

## 2026-04-10 - [Testing API Resources with Relationships]
**Learning:** When testing API responses that use `JsonResource` wrappers (e.g., `SermonResource`), assertions must account for data transformation logic in the resource. For example, if a resource uses methods like `displayPreacherName()` which prioritize related model data over local table columns, the test expectations must match the related model's values when that relationship is loaded.
**Action:** Verify the resource's `toArray` implementation and related model accessor/display methods when setting up assertions for API integration tests.

## 2026-04-09 - [S3 Disk Mocking Gotcha]
**Learning:** `AwsS3V3Adapter` constructor enforces that the bucket name is a string. Setting it to `null` in `config()` during tests (even if mocking local disks) can cause a `TypeError` if the S3 disk is instantiated by Laravel's filesystem manager during a request cycle.
**Action:** Always provide a dummy string (e.g., 'fake-bucket') for the bucket configuration when testing components that might trigger disk resolution, even if the test is intended to exercise a different disk.

## 2026-05-15 - [Testing Admin Actions and Password Complexity]
**Learning:** Admin user management tests must account for strict `Password::defaults()` (12+ chars, letters, numbers, symbols, uncompromised). Using `Log::shouldReceive` is an effective way to verify that critical administrative actions (deletions, permission changes) are being audited as required.
**Action:** Use complex strings (e.g., `C0mplex_Passw0rd!`) for test passwords to avoid validation failures. Mock `Log` to assert that `Log::warning` is called with correct metadata for audit trails.

## 2026-04-26 - [Testing Redirects vs Binary Responses for Assets]
**Learning:** Sermon asset serving logic often involves an internal decision between redirecting to a public delivery URL (for public files) and serving the file directly as a `BinaryFileResponse` (for private files). Testing this requires `assertRedirect()` for public assets and `assertStatus(200)` + `assertHeader('Content-Type', ...)` for private ones.
**Action:** Always test both public (redirect) and private (binary) serving paths to ensure asset delivery is optimized for the storage disk while maintaining security.

## 2026-04-12 - [Sermon Model Testing Invariants]
**Learning:** The `sermons` table has several `NOT NULL` columns with database-level defaults (`video_quality_status`, `video_visibility_override`). When writing tests, avoid passing `null` for these columns as it triggers integrity violations; use explicit enum values. Additionally, `ThumbnailMetadata` object verification requires specific keys (`id`, `timestamp`, `score`, `plain_path`) in each candidate to satisfy the `ThumbnailMetadata::candidateList()` parser.
**Action:** Ensure sermon test factories or explicit `create()` calls provide valid enum values for status columns and properly structured arrays for metadata fields.

## 2026-05-01 - [Testing Dynamic Routes with Static Overrides]
**Learning:** Catch-all dynamic routes (e.g., `/{area}`) are superseded by static route definitions (e.g., `Route::view('/christ', ...)`) regardless of their order in the route file if they are more specific. When testing fallback logic in a catch-all controller, ensure the test cases use parameters that do not match any static overrides.
**Action:** Use `php artisan route:list --path=parameter` to verify if a path is handled by a static view or a controller before writing assertions for catch-all behavior.

## 2026-05-18 - [Parallel Safety in Filesystem Tests]
**Learning:** When testing services that interact with the local filesystem (like `LegacySermonImporter`), using a static directory name (e.g., `storage_path('app/temp_import')`) causes race conditions in parallel test runs.
**Action:** Always use unique temporary directory names (e.g., `Str::uuid()`) and ensure they are deleted in `tearDown()` using `File::deleteDirectory()`.
