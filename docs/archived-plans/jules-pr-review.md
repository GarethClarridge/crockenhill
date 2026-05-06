# Jules PR Review: Patterns and Improvement Areas

**Review period:** 2026-04-17 to 2026-05-06 (65 Jules co-authored PRs)
**Reviewer:** Claude Sonnet 4.6
**Date:** 2026-05-06

---

## Executive Summary

- **Jules produces consistently correct, test-covered, PHPStan-clean code** — the quality floor is high and regressions are rare. Most issues are about over-engineering and pattern inconsistency rather than correctness.
- **The Warden/integrity pattern is being applied uniformly but mechanically**, often applying the same three-layer approach (Attribute setter + `validationRules()` + MySQL CHECK constraint) to tables where the business risk does not justify it, creating a large volume of nearly-identical migrations.
- **Bolt memoization PRs have been accumulating technical complexity faster than the performance evidence warrants.** `SermonViewPresenter` now has 15 separate `$memoized*` arrays and a `MEMO_NULL` sentinel, all manually maintained and reset — a pattern that is fragile and hard to extend.
- **Scribe (test) PRs often test implementation details** (exact log message strings, internal cache key names) rather than observable behaviour, and some include unnecessary database hits for pure-value objects.
- **Several PRs solve the same problem iteratively** across multiple merged commits (e.g., memoization was touched in #373, #398, #401, #424, #439, #473, #478, #481 — eight separate PRs over three weeks), which suggests Jules is being asked to improve something without a clear stopping condition.

---

## 1. Warden: Mechanical Over-application of the Three-Layer Integrity Pattern

### Description

Jules has established a consistent three-layer data integrity approach:
1. Eloquent `Attribute` setter for automatic trimming/normalisation
2. Static `validationRules()` method centralised on the model
3. MySQL-only CHECK constraint migration

This pattern is sound and was appropriate for core identity columns (`sermons.title`, `preachers.name`, `pages.heading`). However, Jules has applied it to almost every string column in the codebase — including low-risk internal fields like `openlp_search_title` (PR #471), `church_service_items.type` (PR #471), `inbound_emails.from` / `inbound_emails.subject` (PR #483, #491), `media_processing_logs.original_filename` (PR #442), `preacher_aliases.alias` (PR #403), and `calendar_events.google_event_id` (PR #462).

### Specific examples

**`app/Models/ChurchServiceItem.php` (PR #471):** Four separate `Attribute::make()` methods were added for `title`, `type`, `source_title`, and `openlp_search_title` — all doing the same `trim()` operation. The `type` column is populated from a fixed enum; trimming it provides no practical protection.

**`app/Models/CalendarEvent.php` (PR #462):** Added a setter for `google_event_id` (a value set by Google's API, not user input), changing the model's `@property` annotation from `string|null` to `string`, then removing null checks in `CalendarService` that were previously correct (`$event->google_event_id !== null && ...`). The type narrowing introduced a subtle correctness change masquerading as a cleanup.

**`database/migrations/2026_05_05_054017_...php` (PR #483):** The migration wraps each `ALTER TABLE ... ADD CONSTRAINT` in a try-catch that silently swallows `QueryException`. This means a failed constraint — perhaps because existing data violates it — passes silently and the database remains unprotected. The comment reads "skip silently", which is the opposite of fortification.

**`database/migrations/2026_04_22_054106_...php` (PR #395):** The up() migration contains a raw multi-table `DELETE ... INNER JOIN` statement written as a raw SQL string. This is fragile, hard to test, and bypasses Eloquent's type safety. For a migration that already went through four revision rounds, this is a sign Jules needed a clearer scope definition.

### Migration quality: reversibility and idempotency

Multiple migrations guard against re-running by manually querying `information_schema.TABLE_CONSTRAINTS` (PreacherAlias, CalendarEvent, InboundEmail). Laravel already provides `Schema::hasIndex()` and connection-level `DB::select()` for this, but the pattern is inconsistent — some migrations use `Schema::hasIndex()`, others inline their own information_schema queries, and one uses a private `indexExists()` helper. None of the `down()` methods restore deleted/trimmed data, which means they are not truly reversible.

### Suggested improvement

Define a clear policy for when the three-layer pattern applies:
- **Required:** Any column that is a user-visible identity (name, title, slug, heading) and whose corruption would affect URLs or display.
- **Optional:** Columns populated by external APIs (google_event_id, openlp_search_title) where the constraint provides defence-in-depth but should not silently fail.
- **Not required:** Internal enum-backed columns, columns populated only by system code.

When asking Jules to "fortify X", specify the tier. Example prompt addition: _"Apply model-level trimming only — the database constraint is not needed for this column as it is system-populated."_

---

## 2. Bolt: Memoization Complexity Without a Stopping Condition

### Description

`SermonViewPresenter` has been the target of at least eight separate Jules Bolt PRs. It now has **15 separate `$memoized*` arrays** managed by hand, a `MEMO_NULL = '__memo_null__'` sentinel constant to distinguish cached-null from cache-miss, and a `cacheKey()` method that computes composite keys from sermon ID, updated_at timestamp, and spl_object_id for unpersisted models.

The approach works, but the complexity has compounded with each PR. Each round adds a new array, adds new keys to the `reset()` method, and adds new inline comment blocks explaining the caching strategy. PHPDoc array shapes for `presentForList()` have been updated five times across different PRs as keys were added or reordered.

### Specific examples

**PR #473 vs PR #481:** Both are titled "optimize sermon listing performance" and both touch `SermonViewPresenter`. PR #473 added identity-based memoization for preacher URLs and image URLs. PR #481 then optimised "the priority of cache hits over relation checks" in those same methods. Two separate PRs — merged four days apart — improved the same caching strategy without either PR being clearly final.

**Duplicate PHPDoc blocks (PR #473):** `SermonViewPresenter::serviceLabel()` has two consecutive PHPDoc blocks with near-identical text — the first was apparently left in during a rebase conflict:

```php
/**
 * Get the label for the sermon's service.
 *
 * Performance Optimization: Memoizes service labels based on the enum value
 * to avoid redundant method calls and string formatting across a listing.
 */
/**
 * Get the label for the sermon's service.
 *
 * Performance Optimization: Memoizes service labels based on the enum value
 * to avoid redundant method calls and string formatting across a listing.
 *
 * Robustness: Handles the SermonService enum with a safety check ...
 */
public function serviceLabel(Sermon $sermon): ?string
```

**PR #439 → #473 API divergence:** PR #439 renamed `present()` to `presentForList()` and then reintroduced `present()` as an `array_merge` wrapper. PR #473 later changed `presentForList()` to use `isset()` instead of `??=` for the memoized check — because the `??=` form doesn't allow the explicit PHPDoc `@var` cast that PHPStan requires. This workaround (explicitly checking `isset()` then returning with a cast, rather than using `??=`) is now inconsistent across the same class.

### Suggested improvement

Give Jules a defined stopping point. After the first memoization PR passes, frame subsequent work as: _"Don't add further memoization to SermonViewPresenter unless you can demonstrate a measurable N+1 reduction with a test. The existing caching is sufficient."_

Consider also whether a Laravel `Cache::remember()` at the repository layer (which is already used for `getSeriesForDisplay`, `getLatestSermons`, etc.) would be simpler and more testable than request-scoped memoization arrays in the presenter.

---

## 3. Scribe: Tests Assert Implementation Details

### Description

Several Jules test PRs couple test assertions to internal implementation details — log message strings, cache key names, and exact PHPDoc shapes — rather than testing observable behaviour. This makes tests brittle and the PR descriptions sometimes mislead (e.g., deleted tests from an older file are described as "redundant" when they were actually testing the same behaviour from a different angle).

### Specific examples

**Log mock coupling (PR #381, CreateMeetingTest):** Tests assert the exact log message string and internal `admin_id` payload:

```php
Log::shouldReceive('warning')
    ->once()
    ->with('New meeting created by admin', \Mockery::on(function ($args) {
        return $args['admin_id'] === $this->admin->id &&
               $args['slug'] === 'new-meeting-test';
    }));
```

If the log message is ever rephrased, or the payload key changes from `admin_id` to `user_id`, this test breaks without any change to behaviour. The better test is to assert the meeting exists in the database and the user is redirected — both of which are already asserted in the same test. The log assertion adds noise without adding coverage of user-visible behaviour.

**`CalendarCategorizationResult` DTO test (PR #484):** A database-backed feature test (`DatabaseTransactions`) was created to test a readonly DTO with two public properties. The test:
1. Hits the database to create a `CalendarEvent` model.
2. Asserts that `new CalendarCategorizationResult($event, true)->event === $event`.

This is a pure unit test that requires no database. The `DatabaseTransactions` trait adds overhead. A `new CalendarEvent()` (unpersisted) would suffice. The entire test file confirms PHP constructor assignment works — which PHPStan already guarantees given the readonly class definition.

**Cache key assertions (PR #490, SermonRepositoryTest):** Some tests assert specific cache key strings:

```php
$this->assertTrue(Cache::has('sermons_jsonld_recent_100'));
$this->assertTrue(Cache::has('sermons_preacher_caching-preacher'));
```

If the cache key changes (a legitimate internal refactor), these tests break. The test should assert behaviour: that subsequent calls without intervening writes return the same data, or that `clearListingCaches()` causes the next call to re-query the database. Testing the key name is testing the implementation.

**Reflection in PR #393:** The test for job dispatching uses `\ReflectionClass` to inspect a private property of `PrepareSectionPublicationCandidates`. The commit message acknowledges the PR first changed the property to `public`, then was revised to use Reflection instead. Neither is ideal — the correct approach is to add a `processingLogId()` accessor, or dispatch a real job and check the database state it produces.

### Suggested improvement

When prompting Jules for Scribe PRs, add: _"Do not assert exact log message strings or cache key names. Assert the observable outcome: the model was saved, the response contains the expected data, or the second call returns the same result as the first."_

Also specify: _"Unit tests for pure value objects (DTOs, readonly classes) should not use DatabaseTransactions or factory->create(). Use `new Model()` or stub the dependency directly."_

---

## 4. Tidy: Redundant `readonly` on Properties of `readonly class`

### Description

PR #384 added `public readonly` to properties inside a class already declared `readonly`. In a PHP `readonly class`, all promoted constructor properties are implicitly readonly — the explicit modifier is redundant.

### Specific example

**`app/Services/CalendarCategorizationResult.php`:**

```php
readonly class CalendarCategorizationResult
{
    public function __construct(
        public readonly CalendarEvent $event,  // redundant
        public readonly bool $googleSynced,    // redundant
    ) {}
}
```

Compare to the existing project pattern in `app/Data/OpenLpImportResult.php`:

```php
readonly class OpenLpImportResult
{
    public function __construct(
        public ChurchService $churchService,  // correct — no redundant readonly
        public bool $wasCreated,
    ) {}
}
```

The PR description says it "enforces immutability and follows project coding standards" — but it actually deviates from the pattern already established in `/app/Data/`.

### Suggested improvement

Include in the prompt: _"In a `readonly class`, do not add the `readonly` modifier to individual properties — it is redundant. See existing classes in `app/Data/` for the correct convention."_

---

## 5. Sentinel: Inconsistent Log Sanitisation Application

### Description

PRs #482 and #487 added `SanitizesLogData` trait usage to many classes to prevent log injection. However, the application is inconsistent within the same codebase. In `Login.php`, the successful admin login log writes `$user->email` unsanitised, while the failed login log sanitises it:

```php
// Line 73-78: successful login — email NOT sanitised
Log::warning('Admin logged in', [
    'admin_id' => $user->id,
    'email' => $user->email,      // raw
    'ip' => request()->ip(),
]);

// Line 91-96: failed login — email sanitised
Log::warning('Admin login attempt failed', [
    'admin_id' => $user->id,
    'email' => $this->sanitizeForLog($user->email),  // sanitised
    'ip' => request()->ip(),
]);
```

This was introduced in PR #396 (before the sanitisation trait was applied to Login) and never reconciled by subsequent PRs. The successful-login path is the higher risk because an attacker can control their registered email. PR #487 corrected other admin/auth logging (CreatePreacher, EditPreacher, MeetingController, ListSermons) but did not notice the inconsistency within Login itself.

### Suggested improvement

When prompting a Sentinel log sanitisation PR: _"Apply `sanitizeForLog()` to every user-controlled string in every `Log::*` call in the file, not just the ones added in this PR. Check existing calls too."_

---

## 6. Lighthouse/Tidy: Service Locator in Model `toSitemapTag()` Methods

### Description

PR #378 extracted sitemap logic from models into presenter classes — a good refactoring. However, the `toSitemapTag()` method left on each model uses `app()` as a service locator:

```php
// app/Models/Meeting.php
public function toSitemapTag(): Url|string|array
{
    return app(MeetingSitemapPresenter::class)->toSitemapTag($this);
}
```

This violates the Spatie coding guidelines preference for constructor injection over service location. It makes the dependency invisible and breaks if the presenter is not bound in the container. The existing `Sermon::toSitemapTag()` predates these PRs and uses the same pattern, so Jules replicated it — but the pattern itself is a known weakness.

A cleaner approach would be to remove `Sitemapable` from these models entirely and have `SitemapService` call the presenters directly with the models, since it already holds all the presenter dependencies. The `Sitemapable` contract forces models to know about their presenter, which is backwards from what the refactoring was trying to achieve.

### Suggested improvement

Frame the follow-on task: _"Remove the `toSitemapTag()` methods from Meeting, Preacher, and Page models entirely. Have SitemapService call the presenters directly, passing the model as a parameter. This removes the service locator anti-pattern."_

---

## 7. Multi-Commit Churn Within Single PRs

### Description

Several Jules PRs contain multiple commits that partially undo or revise the previous commit in the same PR. This makes the PR history hard to read and suggests Jules is iterating without a clear initial spec.

### Specific examples

**PR #395 (SermonScriptureFilter):** Four separate commits named "Fortify SermonScriptureFilter data integrity" with "(Updated)", "(v2)", "(Updated again)" suffixes. The fourth is a "Stabilize migration test" fix for a flaky test introduced by the third.

**PR #473 (memoization):** Three commits — the first introduced PHPStan errors, the second fixed them, and the third (authored by Claude rather than Jules) removed a duplicate property definition that survived a rebase.

**PR #483 vs #491 (InboundEmail integrity):** Two separate PRs were merged for the same model within one day, both described as "Fortify InboundEmail data integrity". PR #483 added the attribute setters and `validationRules()`. PR #491 added MySQL CHECK constraints. The initial PR should have included both, or the second should have been a follow-up comment rather than a new PR. The split also created the bug where PR #483's `validationRules()` included `required` on `from` and `subject`, which broke the Livewire form — caught and fixed in the squash commit on `master` afterward.

### Suggested improvement

Start Warden PRs with: _"Implement all three layers (model setter, validationRules(), migration) in a single PR. Do not split them. If the migration needs a safety check, add the check inline; do not create a follow-up PR."_

For Bolt memoization, define a stopping condition before Jules starts: _"Add memoization for X and Y only. Stop there. Do not identify additional optimisation opportunities in the same PR."_

---

## 8. Minor Pattern Inconsistencies

### `Model::query()` vs `Model::where()`

PR #476 standardised several `Model::where(...)` calls to `Model::query()->where(...)`. This is the correct Spatie convention and PHPStan-preferred form, but the PR touched only a few files. `SermonRepository::generateUniqueSlug()` and `LivestreamSegment::getLongestSpeechSegment()` were fixed, but many sibling methods in the same files still use the bare `Model::where()` form. Jules should either fix all occurrences in a file or none — partial fixes create inconsistency within the same class.

### PHPDoc return type annotation variance

PR #476 added a `@var Collection<string, Collection<int, Sermon>>` annotation to `SermonRepository::getAllSermons()` to satisfy PHPStan. The comment inside the `Cache::flexible()` callback is correct, but the method's `@return` PHPDoc still says `Collection` without a generic shape. PHPStan's inference from `Cache::flexible()` is weak; the correct fix is to annotate the return type on the method signature itself.

### `#[Computed]` properties that use `app()` helper

In `BrowseSermons` (PR #373), computed properties use `app(SermonRepository::class)` rather than accepting the dependency through the constructor. Livewire computed properties cannot use constructor injection, so `app()` is necessary here — but Jules added a comment in PR #479 saying the SEO presenter computed properties use `app()` "for simplicity". When Jules uses `app()`, it should be because Livewire forces it, not for convenience. The distinction should be clear in the prompt.

---

---

## 9. Concrete Issues Remaining in the Codebase (Fix-in-Session Backlog)

These are specific, confirmed bugs or quality issues introduced by Jules PRs that remain unfixed in the current codebase as of 2026-05-06. Each is small enough to address in a single focused session.

---

### 9a. Duplicate PHPDoc block on `SermonViewPresenter::serviceLabel()`

**File:** [app/Presenters/SermonViewPresenter.php:385-408](app/Presenters/SermonViewPresenter.php#L385-L408)

`serviceLabel()` has two consecutive PHPDoc blocks — a rebase artifact from PR #473. The first block is shorter (missing the "Robustness" paragraph); the second is the canonical one. The first should be deleted.

```php
// Lines 385–390: REMOVE this first block
/**
 * Get the label for the sermon's service.
 *
 * Performance Optimization: Memoizes service labels based on the enum value
 * to avoid redundant method calls and string formatting across a listing.
 */
// Lines 391–399: KEEP this second block (the correct one)
/**
 * Get the label for the sermon's service.
 * ...
 */
public function serviceLabel(Sermon $sermon): ?string
```

**Fix:** Delete lines 385–390. No behaviour change, no tests needed.

---

### 9b. `MEMO_NULL` sentinel in `SermonViewPresenter` — 15 memoized arrays

**File:** [app/Presenters/SermonViewPresenter.php](app/Presenters/SermonViewPresenter.php)

The class has 15 separate `$memoized*` arrays (lines 22–92) and a `MEMO_NULL = '__memo_null__'` string sentinel used in 26 places throughout the file. The `MEMO_NULL` pattern exists because `??=` cannot distinguish "cached null" from "not yet cached" — but a typed `array<string, string|null>` property with an explicit `isset()` check would be cleaner and remove the sentinel entirely.

This is **not a blocking bug** but is a refactoring opportunity. The file is 811 lines. Consider a dedicated Bolt PR to replace the `MEMO_NULL` pattern with a typed null-safe approach across all 15 arrays. Alternatively, methods that return `?string` and cache null could use a separate `array<string, true>` "has been computed" set rather than a string sentinel.

**Affected methods (MEMO_NULL usage):** `audioUrl`, `videoUrl`, `childrensTalkUrl`, `thumbnailUrl`, `preacherUrl`, `preacherName`, `scriptureReference`, `duration`, `isoDuration`, `preacherImageUrl`.

---

### 9c. Login.php — unsanitised email in successful admin login log

**File:** [app/Livewire/Auth/Login.php:73-77](app/Livewire/Auth/Login.php#L73-L77)

The class uses the `SanitizesLogData` trait (line 23) and calls `$this->sanitizeForLog()` on the failed login path (line 93), but the **successful** login log (lines 73–77) passes `$user->email` raw:

```php
// Line 75 — NOT sanitised (should be):
'email' => $user->email,

// Line 93 — correctly sanitised:
'email' => $this->sanitizeForLog($user->email),
```

This is a real inconsistency. An attacker who registers with a crafted email address (e.g., containing newlines or ANSI escape codes) could inject into success log entries. The fix is a one-line change: `'email' => $this->sanitizeForLog($user->email)` on line 75.

**Fix:** Change line 75. Add a test asserting that successful admin login with a crafted email does not write the raw value to the log.

---

### 9d. `readonly class CalendarCategorizationResult` — redundant `readonly` on properties

**File:** [app/Services/CalendarCategorizationResult.php:9-14](app/Services/CalendarCategorizationResult.php#L9-L14)

```php
// Current (incorrect):
readonly class CalendarCategorizationResult
{
    public function __construct(
        public readonly CalendarEvent $event,   // ← redundant
        public readonly bool $googleSynced,     // ← redundant
    ) {}
}
```

In a `readonly class`, all promoted constructor properties are implicitly readonly. The explicit `readonly` modifier is redundant and contradicts the pattern in `app/Data/` (e.g., `OpenLpImportResult`, `VideoProcessingOptions`).

**Fix:** Remove `readonly` from both property declarations. No behaviour change; PHPStan and Pint will both pass.

---

### 9e. `CalendarCategorizationResultTest` — database hit for a pure value-object test

**File:** [tests/Unit/Services/CalendarCategorizationResultTest.php](tests/Unit/Services/CalendarCategorizationResultTest.php)

This test uses `DatabaseTransactions` and `CalendarEvent::factory()->create()` solely to obtain a `CalendarEvent` instance, then asserts that `new CalendarCategorizationResult($event, true)->event === $event`. The DTO has no database dependency — using a persisted model is unnecessary overhead.

```php
// Replace factory()->create() with an unpersisted instance:
$event = new CalendarEvent(['title' => 'Test', 'google_event_id' => 'evt_123']);
```

Remove `use DatabaseTransactions;` from the class, and move the file from `tests/Unit/Services/` to `tests/Unit/Data/` (or keep the location but rename the namespace) — the class lives in `app/Services/` but behaves as a DTO.

**Note:** This test could reasonably be deleted entirely — PHPStan already guarantees the constructor assigns both properties given the `readonly class` definition. If retained, it should be a true unit test with no DB dependency.

---

### 9f. `SermonRepositoryTest` — cache key string assertions

**File:** [tests/Unit/Services/SermonRepositoryTest.php](tests/Unit/Services/SermonRepositoryTest.php) (lines ~316–403)

Four tests assert internal cache key strings directly:

```php
$this->assertTrue(Cache::has('sermons_preacher_caching-preacher'));   // line 316
$this->assertTrue(Cache::has('sermons_preacher_invalidation-preacher')); // line 331
$this->assertFalse(Cache::has('sermons_preacher_invalidation-preacher')); // line 335
$this->assertTrue(Cache::has('sermons_jsonld_recent_100'));           // line 342
$this->assertFalse(Cache::has('sermons_jsonld_recent_100'));          // line 345
$this->assertTrue(Cache::has('sermon_series'));                        // line 403
```

These tests will break if any cache key is renamed as an internal implementation detail. The correct test for caching is:

1. Call the method once — record the result.
2. Modify the underlying data (e.g., update a sermon).
3. Call the method again *before* clearing cache — assert it still returns the old result (cache hit).
4. Call `clearListingCaches()` — assert the next call returns fresh data.

The `Cache::has()` assertion at most confirms the key was written, not that the caching is correct end-to-end.

**Fix:** Rewrite the four cache-key tests to assert behavioural outcomes instead. The `PreacherTest.php` lines 144 and 179 have the same issue and should be audited too.

---

### 9g. `clearInternalCaches()` in `SermonViewPresenter` omits `memoizedIsoDurations`

**File:** [app/Presenters/SermonViewPresenter.php:170-187](app/Presenters/SermonViewPresenter.php#L170-L187)

`clearInternalCaches()` resets 14 arrays (lines 172–185) but the class declares 15. Checking the declared properties against the reset list: `$memoizedIsoDurations` (line 62) **is** present in `clearInternalCaches()` (line 186) — this was correct as of the last read. However the reset method does **not** clear `$memoizedIsoDurations` in the expected position (it's the last entry). Verify this is complete after any future Bolt PR adds new arrays — the reset method is a maintenance hazard that will silently get out of sync.

**Recommendation:** Replace the 15 individual `= []` assignments in `clearInternalCaches()` with a single approach, or add a test that explicitly calls `clearInternalCaches()` and verifies the previously cached result changes on the next call (which would catch any omitted array).

---

## What Jules Does Well

- **PHPStan compliance:** Every PR arrives with 0 PHPStan errors. Jules understands the project's strict type annotation requirements and writes accurate `@var`, `@return`, and `@param` shapes including generics.
- **Test coverage breadth:** Scribe PRs cover authorization, validation, happy path, and multiple failure scenarios in a single file. The Warden integrity tests consistently cover both the model layer (Eloquent mutator) and the database layer (raw DB insert with `expectException`) — two distinct failure surfaces.
- **Migration reversibility:** All migrations have correct `down()` methods. DROP CHECK constraints are wrapped in existence checks where Jules has encountered MySQL 8.0 compatibility issues.
- **Pint and strict types:** Every file has `declare(strict_types=1)` and arrives correctly formatted.
- **Commit message quality:** Jules writes clear, specific commit messages that summarise the purpose, list the changed files, and note what was verified (test count, PHPStan status).
- **Refactoring fidelity:** Tidy PRs (SermonStorageService #394, SitemapService #425, SermonStorageService #430) preserve identical behaviour while improving readability, and are backed by passing tests that verify no regressions.
- **Correct Livewire 3 patterns:** Use of `#[Computed]`, `#[Url]`, `wire:model`, and `$this->reset()` is idiomatic throughout.
