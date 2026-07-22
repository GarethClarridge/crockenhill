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

## 2026-06-19 - [Pure Logic Service Testing]
**Learning:** Unit testing a pure-logic service like `MediaInterludeCueDetector` that performs simple keyword matching is straightforward but essential for ensuring the robust detection of media interludes during structural section alignment.
**Action:** Use descriptive test names that reflect the business rules (e.g., case-insensitivity, embedded phrases) and prefer `new Class` instantiation for services with no dependencies.

## 2026-07-10 - [Testing Service Memoization with Query Logs]
**Learning:** To verify that a service correctly memoizes database results within the same request (preventing redundant queries), use `DB::enableQueryLog()`, `DB::flushQueryLog()`, and `DB::getQueryLog()` to assert that subsequent calls do not trigger additional database queries.
**Action:** Apply this pattern when testing repository-style services that implement internal request-level caching.

## 2026-07-15 - Unit Testing Model Validation Rules
**Learning:** To unit test model `validationRules()` without a database connection, use `Validator::make()` but filter out database-dependent rules (e.g., `exists`, `unique`) from the rule array. Filtering must account for both string-based rules (e.g., `'exists:table,column'`) and object-based rules (e.g., `Rule::unique()`). To verify rule configuration, cast the rule to a string (e.g., `unique:users,email,"123",id`) before filtering.
**Action:** Use a `filterDatabaseRules` helper in unit tests to strip DB-dependent rules before passing them to the Validator. Assert the configuration of `unique` rules by casting the rule object to a string.

## 2026-07-20 - Asserting Unescaped HTML Text
**Learning:** When asserting text containing single quotes or apostrophes (such as "I've got children") in HTML responses within feature tests, Laravel's `assertSee()` defaults to escaping the searched string (converting `'` to `&#039;`). If the page renders raw characters or the assertion string is already escaped differently, this causes brittle test failures. Passing `false` as the second argument (`$escape`) bypasses this default behavior.
**Action:** Use `$response->assertSee('text', false)` to safely perform unescaped assertions in feature tests.
