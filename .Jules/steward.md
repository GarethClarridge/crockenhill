## 2026-06-04 - Harden ThumbnailGenerationServiceTest
**Pattern:** Reflection on public method
**Cause:** wrapText in ThumbnailCanvasComposer was previously private but had been refactored to public, yet the tests still used ReflectionClass to access it.
**Fix:** Replaced reflection-based calls with direct method calls, removing brittle and unnecessary boilerplate.

## 2026-06-06 - [Reflection Hardening]
**Pattern:** Reflection poking into private methods for unit testing.
**Cause:** Desire to test small, internal validation logic () without exercising the full service path.
**Fix:** Refactor tests to mock dependencies (, ) and verify behavior through the public API (), asserting on the resulting status array and filesystem side effects. This hardened the test against future refactors of the private method signature or naming.

## 2026-06-06 - [Reflection Hardening]
**Pattern:** Reflection poking into private methods for unit testing.
**Cause:** Desire to test small, internal validation logic (validateAudioFileSize) without exercising the full service path.
**Fix:** Refactor tests to mock dependencies (StorageAdapterHelper, FFMpeg) and verify behavior through the public API (extractOptimizedAudio), asserting on the resulting status array and filesystem side effects. This hardened the test against future refactors of the private method signature or naming.
