## 2025-06-05 - [MediaProcessingLog Testing]
**Learning:** Testing model logic that depends on complex JSON metadata (cast via Spatie Laravel Data) requires careful factory state setup or manual attribute overrides to hit different code branches (e.g., `requiresManualSermonReview` depending on `manualReview->status`).
**Action:** Always check the `casts()` method and the corresponding Data objects to understand the expected structure of metadata before writing assertions against it.

## 2025-06-05 - [Legacy Fallback Testing]
**Learning:** Some models maintain legacy fallback logic (e.g., parsing string-based `error_message` when `processing_metadata` is null). Testing these ensures backward compatibility during migrations.
**Action:** Use `grep` to find these legacy patterns and explicitly test them alongside modern implementations.

## 2026-06-15 - [Enum Inference Testing]
**Learning:** For Enums that provide domain categorization via inference (e.g. `ServiceSectionType::inferFromTitle`), unit tests should cover both direct keyword matches and case-insensitivity to ensure robust classification.
**Action:** Always include a dedicated unit test for Enums that contain logic beyond simple case definitions.

## 2026-06-15 - [Model Precedence Testing]
**Learning:** Testing methods like `semanticSectionType` which combine explicit property checks, type mapping, and fuzzy inference requires multiple test cases to verify the correct precedence order (Explicit property > Type match > Fuzzy inference).
**Action:** Write separate test methods for each level of precedence in the model to ensure behavior is exactly as intended and protected against refactoring.

## 2026-06-16 - [Presenter Memoization Testing]
**Learning:** Testing memoization in presenters that use model identity (ID + `updated_at`) for cache keys requires either using `factory()->create()` or manually setting the `updated_at` timestamp. Without a persisted state or explicit timestamp, consecutive calls might yield different cache keys if the object hash or internal state changes.
**Action:** Use `factory()->create()` for memoization tests that rely on `cacheKey()` logic, or explicitly mock the storage service to verify that it is only called once.

## 2026-06-18 - [Logic-Dense Service Unit Testing]
**Learning:** Testing services like `SectionItemAlignmentScorer` that perform complex scoring and string manipulation (tokenization) is most effective when done via isolated unit tests using unpersisted models (`new Model()`). This avoids database overhead and ensures the tests focus purely on the algorithmic logic.
**Action:** Prefer `new Model()` over `factory()->create()` for unit tests of services that do not require database persistence or complex relationship resolution.
