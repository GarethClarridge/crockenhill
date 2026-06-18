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
