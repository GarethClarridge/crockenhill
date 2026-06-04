## 2026-06-04 - Harden ThumbnailGenerationServiceTest
**Pattern:** Reflection on public method
**Cause:** wrapText in ThumbnailCanvasComposer was previously private but had been refactored to public, yet the tests still used ReflectionClass to access it.
**Fix:** Replaced reflection-based calls with direct method calls, removing brittle and unnecessary boilerplate.
