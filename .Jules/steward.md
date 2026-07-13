## 2026-06-04 - Harden ThumbnailGenerationServiceTest
**Pattern:** Reflection on public method
**Cause:** wrapText in ThumbnailCanvasComposer was previously private but had been refactored to public, yet the tests still used ReflectionClass to access it.
**Fix:** Replaced reflection-based calls with direct method calls, removing brittle and unnecessary boilerplate.

## 2026-06-06 - Reflection Hardening
**Pattern:** Reflection poking into private methods for unit testing.
**Cause:** Desire to test small, internal validation logic (validateAudioFileSize) without exercising the full service path.
**Fix:** Refactor tests to mock dependencies (StorageAdapterHelper, FFMpeg) and verify behavior through the public API (extractOptimizedAudio), asserting on the resulting status array and filesystem side effects. This hardened the test against future refactors of the private method signature or naming.

## 2026-06-13 - Harden accessibility assertions and relocate database-dependent unit tests
**Pattern:** Brittle HTML-string assertions and misclassified unit tests.
**Cause:** Exact markup matching with volatile CSS classes (Tailwind) in feature tests; presence of database-hitting tests in the Unit/ directory.
**Fix:** Replaced `assertSeeHtml` with `assertSeeInOrder` for more robust content verification. Relocated `MeetingPhotoMigrationServiceTest` and `MeetingShowPresenterTest` to the Integration/ directory to align with architectural standards and ensure proper environment setup.

## 2026-06-17 - Harden caching and log assertions
**Pattern:** Implementation-detail assertions (`Cache::has()`) and exact-string log assertions.
**Cause:** Caching tests previously relied on private key names and only checked existence, not effectiveness. Log assertions used exact string matching, making them brittle to minor copy changes.
**Fix:** Replaced `Cache::has()` with behavioral tests using `DB::enableQueryLog()` to verify zero queries on subsequent calls (after clearing internal memoization). Loosened log assertions to use `str_contains` via `withArgs` for resilience against rephrasing.

## 2026-06-18 - Harden Preacher cache tests
**Pattern:** Internal cache key assertion
**Cause:** Previous tests used `Cache::has('key')` which is brittle and doesn't guarantee the cache is actually used by the code path.
**Fix:** Replaced with behavioral verification using `DB::enableQueryLog()` and asserting zero subsequent queries to the 'preachers' table after cache warming.

## 2026-08-15 - Harden log mocking and job retry timing
**Pattern:** Strict log mocking and approximate timestamp assertions.
**Cause:** `Log::shouldReceive()` creates a strict mock that fails on any un-mocked log call (even at different levels), causing brittleness when unrelated code paths log information. Job `retryUntil` tests used `assertEqualsWithDelta` which is fragile and slow if execution hangs.
**Fix:** Introduced `Log::partialMock()` to isolate the specific logs under test while allowing the rest of the logging system to function naturally. Replaced approximate time assertions with deterministic checks using `Carbon::setTestNow()`.

## 2026-06-21 - Replace brittle log expectations with Spies
**Pattern:** Strict Log::shouldReceive() expectations.
**Cause:** Tests used pre-emptive Mockery expectations which are order-sensitive and fail on any unexpected log calls, causing risky test warnings in PHPUnit 13 when no other assertions were present.
**Fix:** Switched to Log::spy() in setUp() and verified behavior using Log::shouldHaveReceived() (Arrange-Act-Assert). This decouples test assertions from log order and eliminates risky test warnings.

## 2027-01-20 - Harden Sermon model integration tests
**Pattern:** Time-sensitive scopes, hardcoded URLs, and loose enum assertions.
**Cause:** Tests relied on `Carbon::now()` for relative date scopes, hardcoded `http://localhost` strings for URL expectations, and `assertContains` for single-value enums.
**Fix:** Introduced `Carbon::setTestNow()` for deterministic scope testing. Replaced hardcoded environment strings with the `url()` helper. Tightened enum assertions to use `assertSame` for precision. Removed implementation-leaking `Log::info` calls and stale commented-out test code.

## 2027-02-12 - Harden Sermon unit tests and remove DB overhead
**Pattern:** DatabaseTransactions and model factories in Unit tests.
**Cause:** `tests/Unit/Models/SermonTest.php` was hitting the database for attribute and validation testing, making it slow and brittle to DB state.
**Fix:** Removed `DatabaseTransactions` trait. Replaced `Sermon::factory()->make()` with direct instantiation (`new Sermon()`). Refactored validation tests to filter out database-dependent rules (`exists`, `Unique`) from the rule set, allowing format and bounds constraints to be verified without a database connection.
