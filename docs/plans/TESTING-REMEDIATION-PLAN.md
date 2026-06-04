# Testing Remediation Plan

Created 2026-06-04 from the findings in [docs/reviews/2026-06-04-testing-review.md](../reviews/2026-06-04-testing-review.md).

This plan turns each recommendation (R1–R9) in that report into an ordered, verifiable phase. Phases are numbered `T1…T9` and map 1:1 to the report's recommendation IDs. Each phase is independently shippable.

## Goal

Cut suite runtime and flakiness **without reducing real coverage**. The two levers are: (a) stop doing work that tests nothing (stray network calls), and (b) move variant coverage to the cheapest level that still proves the behaviour. The MySQL coupling stays — see Guardrails.

## Guardrails (read before starting any phase)

- **Do not switch the default test DB to SQLite `:memory:`.** 51 `markTestSkipped` guards across 18 files depend on MySQL CHECK/ENUM enforcement; SQLite would skip them silently. The coupling is deliberate.
- **Do not branch production code on `app()->runningUnitTests()`.** Phase 18 of the simplification plan purged that pattern. Test-environment changes belong in `tests/TestCase.php`, `phpunit.xml`, or test-only container bindings.
- **Re-level, don't delete.** When moving an assertion to a lower level (T3), keep one HTTP smoke test per page type so the wiring stays guarded.
- **One phase per PR.** Each phase below is sized to be reviewed and reverted independently.

## Quality gates (run for every phase that touches the relevant area)

1. `vendor/bin/sail bin pint --dirty --format agent`
2. `vendor/bin/sail composer phpstan` — must stay at 0 errors.
3. `vendor/bin/sail artisan test --compact --parallel <focused test paths>` for the phase, then a full `--parallel` run before merge.
4. `vendor/bin/sail artisan dusk` only for phases touching public routes or the upload form (none of T1–T9 do, but T3 touches SEO views — run Dusk there).

To re-measure timing after a phase: `vendor/bin/sail artisan test --parallel --log-junit storage/test-timing.xml` and re-parse (see the report's Appendix).

---

## T1 — Eliminate the Pwned Passwords network call  (report R1)

**Priority: High · Risk: Very low · Est. impact: removes the 10 s `PasswordDefaultsTest` + `AuditLoggingTest::it_logs_user_creation` outliers and de-flakes 11 files.**

### Root cause
[app/Providers/AppServiceProvider.php:92-98](../../app/Providers/AppServiceProvider.php#L92-L98) configures `Password::defaults()` with `->uncompromised()` in every environment. The `uncompromised` rule resolves an `Illuminate\Contracts\Validation\UncompromisedVerifier` from the container and calls **api.pwnedpasswords.com** — even when the password already failed the format rules — so every password validation and user creation in the suite pays a real round-trip.

### Approach
Do **not** edit `AppServiceProvider` (production keeps the breach check). Instead rebind the verifier to a no-network fake in the test base class. This leaves production password policy byte-for-byte unchanged and avoids the `runningUnitTests()` anti-pattern.

### Target files
- [tests/TestCase.php](../../tests/TestCase.php) — add the binding in `setUp()`.

### Tasks
- [ ] In `tests/TestCase.php::setUp()` (after `parent::setUp()`), bind a fake verifier:
  ```php
  use Illuminate\Contracts\Validation\UncompromisedVerifier;
  // ...
  $this->app->instance(UncompromisedVerifier::class, new class implements UncompromisedVerifier {
      public function verify($data): bool
      {
          return true; // treat every password as not-breached; no network in tests
      }
  });
  ```
- [ ] If any test genuinely needs to assert breach rejection (none found today), it can override this binding locally with a verifier returning `false`.
- [ ] Decide the fate of [tests/Feature/Security/PasswordDefaultsTest.php](../../tests/Feature/Security/PasswordDefaultsTest.php): it still validly asserts min-length/letters/numbers/symbols against `Password::defaults()`; keep it, just confirm it no longer hits the network (it won't, post-binding).

### Verification
- [ ] `vendor/bin/sail artisan test --compact --parallel tests/Feature/Security/PasswordDefaultsTest.php tests/Feature/Security/AuditLoggingTest.php tests/Feature/Livewire/AdminUserTest.php tests/Feature/Auth/PasswordStrengthTest.php tests/Feature/Auth/AuthRateLimitingTest.php tests/Feature/Livewire/Admin/Users`
- [ ] Confirm `PasswordDefaultsTest` drops from ~10 s to <1 s.

### Exit criteria
- No test reaches api.pwnedpasswords.com; the 11 user/auth files no longer depend on an external service.

---

## T2 — Make the S3 / DigitalOcean fallback fail fast  (report R2)

**Priority: High · Risk: Very low · Est. impact: removes the two ~12.6 s outliers (`SermonPagesTest`, `RobustPathProtectionTest`).**

### Root cause
[phpunit.xml:64](../../phpunit.xml#L64) sets `DO_SPACES_ENDPOINT=https://nyc3.digitaloceanspaces.com` — a *reachable* host. Tests that deliberately leave `do_spaces` unfaked to catch wrong-fallback regressions (e.g. [tests/Feature/SermonPagesTest.php:134-145](../../tests/Feature/SermonPagesTest.php#L134-L145)) make the S3 client connect to the real bucket and block on a TCP timeout (~12 s) before failing. The regression guard is legitimate; the 12 s wait is not.

### Approach
Point the test endpoint at an address that **refuses immediately** instead of timing out, mirroring the existing OpenAI trick (`OPENAI_BASE_URL=http://127.0.0.1:1/v1` in the same file). A wrong S3 fallback still errors out — it just errors in milliseconds. Also pin the AWS SDK retry count to 0 in tests so a refused connection isn't retried with backoff.

### Target files
- [phpunit.xml](../../phpunit.xml) — change the endpoint env; optionally add an SDK-retry override.
- [config/filesystems.php:73-84](../../config/filesystems.php#L73-L84) — `do_spaces` disk; confirm it reads `DO_SPACES_ENDPOINT` (it does, line 79) and add an optional `'retries' => env('DO_SPACES_RETRIES', 3)` so tests can set 0.

### Tasks
- [ ] In `phpunit.xml`, change `DO_SPACES_ENDPOINT` to `http://127.0.0.1:1` (connection-refused, instant).
- [ ] Add `<env name="DO_SPACES_RETRIES" value="0"/>` and wire `'retries'` into the `do_spaces` disk config's S3 client options (`'options' => ['retries' => ...]` or the client's `retries` key) so the SDK does not retry the refused connection.
- [ ] Re-read the two affected tests' intent comments and confirm they still assert "we did NOT fall back to S3" (status 200, no S3 content). With a fast-fail endpoint, a wrong fallback now surfaces as a fast 500 rather than a 200, so the guard tightens rather than weakens.

### Verification
- [ ] `vendor/bin/sail artisan test --compact --parallel tests/Feature/SermonPagesTest.php tests/Feature/Security/RobustPathProtectionTest.php`
- [ ] Confirm both target tests drop from ~12.6 s to sub-second.
- [ ] Spot-check a couple of asset-serving tests that *do* fake `do_spaces` to ensure the endpoint change doesn't affect them (it shouldn't — faked disks bypass the network).

### Exit criteria
- No test blocks on a real DigitalOcean Spaces timeout; the S3-fallback regression guards still fail (fast) on wrong behaviour.

---

## T3 — Re-level the SEO / metadata cluster  (report R3)

**Priority: High · Risk: Low–Medium (mitigated by keeping smoke tests) · Est. impact: removes dozens of full-render HTTP tests; biggest structural win.**

### Root cause
~10 core SEO files drive full HTTP renders to string-match `<meta>`/JSON-LD that is assembled by `SermonViewPresenter` and the SEO presenters — which already have dedicated tests. Each HTTP variant pays routing + middleware + DB seed + Blade render to re-assert presenter output.

### Files in scope (verify line counts before/after)
| HTTP file | Methods | Action |
|---|---:|---|
| [tests/Feature/SermonOpenGraphTest.php](../../tests/Feature/SermonOpenGraphTest.php) | 4 | Move OG/Twitter variant assertions to presenter; keep 1 smoke test. Delete unused `User` in `setUp` ([line 23](../../tests/Feature/SermonOpenGraphTest.php#L23)). |
| [tests/Feature/SermonJsonLdTest.php](../../tests/Feature/SermonJsonLdTest.php) | 4 | Move JSON-LD shape variants to presenter; keep 1 smoke test asserting a `ld+json` block renders. |
| [tests/Feature/SermonSeoTest.php](../../tests/Feature/SermonSeoTest.php) | 7 | Consolidate with the above; keep 1 smoke test. |
| [tests/Feature/SermonSocialMetadataTest.php](../../tests/Feature/SermonSocialMetadataTest.php) | 4 | Fold into the OG presenter tests. |
| [tests/Feature/SermonBrowseSeoTest.php](../../tests/Feature/SermonBrowseSeoTest.php) | 7 | Move archive-page SEO variants to `SermonArchiveSeoPresenterTest`; keep 1 smoke test. |
| [tests/Feature/StructuredDataTest.php](../../tests/Feature/StructuredDataTest.php) | 5 | Move shape assertions to presenter. |
| [tests/Feature/SeoMetaTagsTest.php](../../tests/Feature/SeoMetaTagsTest.php) | 17 | Largest; split: per-field variants → presenter, 1–2 page smoke tests stay. |
| [tests/Feature/SeoMetadataTest.php](../../tests/Feature/SeoMetadataTest.php) / [SeoMetadataImprovementTest](../../tests/Feature/SeoMetadataImprovementTest.php) / [SeoRegressionTest](../../tests/Feature/SeoRegressionTest.php) | 5/4/4 | Audit for duplication with the above; collapse overlapping cases. |

### Landing zone (where assertions move to)
- [tests/Integration/Presenters/SermonViewPresenterTest.php](../../tests/Integration/Presenters/SermonViewPresenterTest.php) (already 694 lines — meta description, OG, JSON-LD inputs).
- [tests/Integration/Presenters/SermonArchiveSeoPresenterTest.php](../../tests/Integration/Presenters/SermonArchiveSeoPresenterTest.php), `SongArchiveSeoPresenterTest`.
- [tests/Unit/Support/SermonContentFormatterTest.php](../../tests/Unit/Support/SermonContentFormatterTest.php) (DB-free string assembly — preferred home for description-truncation variants).

### Tasks (per file — repeat the loop)
- [ ] For each HTTP test method, classify it: **(a) logic variant** (different input → different meta/JSON-LD string) or **(b) wiring** (the page actually emits the block).
- [ ] Move every (a) down to the matching presenter/formatter test, asserting on the presenter's return value (no HTTP, no DB seed where the presenter accepts a model built in-memory).
- [ ] Keep exactly **one** (b) smoke test per page type at the HTTP level: e.g. "sermon show page contains an `og:title` and a `ld+json` block", "archive page contains an `ItemList` JSON-LD block".
- [ ] Delete now-redundant HTTP methods and the unused `SermonOpenGraphTest::setUp` `User`.
- [ ] Track net method count: aim to convert ~50 HTTP methods into presenter assertions + ~8 retained smoke tests.

### Verification
- [ ] Run the full SEO landing zone + retained smoke tests: `vendor/bin/sail artisan test --compact --parallel tests/Integration/Presenters tests/Unit/Support/SermonContentFormatterTest.php tests/Feature/Sermon*SeoTest.php tests/Feature/SeoMetaTagsTest.php tests/Feature/SermonJsonLdTest.php tests/Feature/SermonOpenGraphTest.php`
- [ ] `vendor/bin/sail artisan dusk` (SEO views are public routes).
- [ ] Confirm no presenter behaviour lost coverage: every assertion deleted at HTTP level has an equivalent at presenter level (review the diff method-by-method).

### Exit criteria
- Each public page type has ≤2 SEO HTTP smoke tests; all metadata *variant* coverage lives in presenter/formatter tests; no duplicated `og:title`-format assertions across files.

---

## T4 — Catch future stray HTTP with `preventStrayRequests`  (report R4)

**Priority: Medium · Risk: Low (but may surface existing offenders — triage required) · Depends on T1, T2.**

### Approach
After T1/T2 remove the known stray calls, add `Http::preventStrayRequests()` in `TestCase::setUp()` so any *new* unmocked Laravel-`Http` call fails loudly instead of hanging. Note this governs only the `Http` facade — raw Guzzle / the OpenAI SDK are unaffected (those already point at the refused `127.0.0.1:1` endpoint).

### Tasks
- [ ] Add `Http::preventStrayRequests();` to `tests/TestCase.php::setUp()`.
- [ ] Run the **full** suite once and triage every failure it surfaces (each is a real unmocked external call). Existing `Http::fake` users (`ApiBibleClientTest` and the 2 others) are unaffected because they set their own fakes.
- [ ] For each surfaced offender, add a scoped `Http::fake([...])` in that test.
- [ ] If triage is large, land `preventStrayRequests` behind the fakes incrementally rather than blocking the phase.

### Verification
- [ ] Full `vendor/bin/sail artisan test --parallel` passes with the guard on.

### Exit criteria
- A new unmocked `Http::` call in any test fails fast with a clear "stray request" error.

---

## T5 — Trim thumbnail-render cost  (report R5)

**Priority: Medium · Risk: Medium (fidelity-sensitive) · Est. impact: up to ~54 s of summed work; investigate, don't degrade.**

### Notes from the code
[tests/Unit/Services/ThumbnailCanvasComposerTest.php](../../tests/Unit/Services/ThumbnailCanvasComposerTest.php) already caches rendered canvases in a static `$canvasCache` (good — reused across the class). The remaining cost is (a) the real Intervention/GD composition and (b) per-test pixel-bounds scanning loops. The geometry assertions are tuned to specific canvas dimensions (720px tall), so blind shrinking will break the hard-coded coordinate expectations.

### Tasks (investigate first; only proceed if fidelity holds)
- [ ] Audit the 13 `ThumbnailCanvasComposerTest` + 15 `Integration/ThumbnailGenerationServiceTest` methods for **redundant variants** — cases that exercise the same composition branch with cosmetically different inputs. Collapse duplicates.
- [ ] Evaluate whether the pixel-bounds scan can be bounded to the region of interest rather than the full canvas (it partly is — confirm no full-canvas scans remain).
- [ ] Do **not** reduce canvas dimensions unless the coordinate assertions are re-derived; the constants (`CENTERED_TITLE_MAX_Y = 468`, etc.) are dimension-specific.
- [ ] Confirm `Integration/ThumbnailGenerationServiceTest` mocks `VideoSegmentationService` (no real ffmpeg) — keep it that way.

### Verification
- [ ] `vendor/bin/sail artisan test --compact tests/Unit/Services/ThumbnailCanvasComposerTest.php tests/Integration/ThumbnailGenerationServiceTest.php` — all still pass, time reduced.

### Exit criteria
- Redundant render variants removed; no loss of composition-branch coverage; canvas fidelity assertions intact.

---

## T6 — Consolidate schema / column tests  (report R6)

**Priority: Medium · Risk: Low · Depends on: Phase 13 CI drift gate (already shipped).**

### Root cause
`tests/Feature/Database/` (12), `tests/Feature/Schema/`, and parts of `tests/Feature/DataIntegrity/` (26) assert column/table existence per column — re-asserting what migrations + `mysql-schema.sql` + the Phase 13 CI drift gate already guarantee. Each still boots the framework and hits MySQL.

### Tasks
- [ ] Inventory every `Schema::hasColumn` / `Schema::hasTable` / column-type assertion (12 files identified in the report).
- [ ] For each table, collapse per-column existence assertions into **one** guardrail test per table (or delete where the Phase 13 drift gate fully covers it).
- [ ] **Keep** every MySQL CHECK/ENUM constraint test (the `markTestSkipped('...requires MySQL')` ones) — these verify runtime behaviour, not schema shape.
- [ ] Keep `DataIntegrity` tests that assert *behavioural* invariants (cascade deletes, uniqueness enforcement); only trim pure shape checks.

### Verification
- [ ] `vendor/bin/sail artisan test --compact --parallel tests/Feature/Database tests/Feature/Schema tests/Feature/DataIntegrity`
- [ ] Deliberately break a column in a scratch migration and confirm the Phase 13 drift gate still catches it (so the consolidated coverage holds).

### Exit criteria
- One schema-guardrail test per table; no per-column existence duplication; constraint/behaviour tests untouched.

---

## T7 — Replace placeholder assertions  (report R7)

**Priority: Low (signal quality) · Risk: Low.**

### Root cause
35 `assertTrue(true)` placeholder assertions across ~10 files pass unconditionally (e.g. `SermonOpenGraphTest` ends a real test with `assertTrue(true, 'Open Graph meta tags are successfully implemented')`).

### Target files (from the report grep)
`tests/Unit/ExampleTest`, `tests/Unit/StorageAdapterHelperTest`, `tests/Unit/Services/AudioExtractionServiceTest`, `MediaValidationServiceTest`, `FrameExtractionServiceTest`, `OpenLpDecompressionBombTest`, `AudioChunkingServiceTest`, `AudioEnhancementServiceTest`, `tests/Integration/Models/PageTest`, `tests/Integration/Livewire/Traits/WithAdminAuthorizationTest`, plus the SEO files touched in T3.

### Tasks
- [ ] For each `assertTrue(true)`, either replace it with the real assertion the test name implies, or delete the test if it proves nothing.
- [ ] Delete `tests/Unit/ExampleTest.php` and `tests/Browser/ExampleTest.php` (scaffolding) unless intentionally kept.
- [ ] Do not remove a test file without confirming it has no other meaningful assertions (per the repo rule on not deleting tests without approval — flag any deletions for sign-off).

### Verification
- [ ] Re-run each touched file; confirm assertions now fail if the behaviour regresses (spot-check by temporarily breaking one path).

### Exit criteria
- No `assertTrue(true)` placeholder remains; every test asserts the behaviour in its name.

---

## T8 — Standardise DB trait per directory + clear notices  (report R8)

**Priority: Low · Risk: Very low.**

### Root cause
314 files use `RefreshDatabase`, 168 use `DatabaseTransactions`, mixed within sibling directories (e.g. `DataIntegrity/FilteringIndexesTest` uses `RefreshDatabase` while neighbours use `DatabaseTransactions`). The run also emitted 361 PHPUnit notices + 4 deprecations.

### Tasks
- [ ] Pick one convention per directory (prefer `RefreshDatabase` as the safer default) and align siblings. This is a consistency fix, not a perf fix — per-test cost is similar after the first migration.
- [ ] Capture the 361 notices: `vendor/bin/sail artisan test --parallel 2>&1 | tee storage/notices.log`, group by source, and fix the deprecated-API usages they flag.
- [ ] Address the 4 deprecations (likely deprecated PHPUnit/Laravel APIs in test helpers).

### Verification
- [ ] Full `--parallel` run shows 0 deprecations and a materially lower notice count.

### Exit criteria
- Consistent DB trait per directory; notice/deprecation count driven toward zero.

---

## T9 — Move pure-function unit tests off the Laravel `TestCase`  (report R9)

**Priority: Low–Medium · Risk: Low (verify each truly needs nothing from the container).**

### Root cause
113 of 122 `tests/Unit` files extend the full Laravel `TestCase` (boots framework + container). Many test pure functions (formatters, parsers, helpers) needing nothing from Laravel; the bootstrap cost is paid thousands of times.

### Candidate files (verify each is container-free)
Pure-logic tests under `tests/Unit/Services` and `tests/Unit/Support` — e.g. `SermonFilenameParserTest`, `SermonContentFormatterTest`, `BibleCanonTest`, `PathTest`, `ScriptureHtmlSanitizerTest`, `SafeMarkdownRendererTest`, `ThumbnailTextHelperTest` (color/wrap math), `SongLyricSnippetBuilderTest`. Exclude anything using `config()`, facades, `app()`, factories, or `Storage`/`Cache`.

### Tasks
- [ ] For each candidate, confirm it uses no facade/container/config/DB. If clean, change `extends Tests\TestCase` → `extends PHPUnit\Framework\TestCase` and drop the `CreatesApplication`/`WithCachedConfig` reliance.
- [ ] Keep any test that touches `config()` or facades on the Laravel `TestCase` — the bootstrap is load-bearing there.
- [ ] Note the `ThumbnailCanvasComposerTest` is **not** a candidate (it uses Intervention's `Image` facade and `Sermon` models).

### Verification
- [ ] `vendor/bin/sail artisan test --compact tests/Unit/Services tests/Unit/Support` — all pass; confirm the converted files no longer boot the app (faster).

### Exit criteria
- Pure-function unit tests run on bare PHPUnit; framework-dependent ones stay on the Laravel base.

---

## Suggested order

1. **T1 + T2 (+ T4)** — one PR, an afternoon, near-zero risk. Removes all four 10–13 s outliers and the suite's dependence on two external services. **Do first.**
2. **T7 + T8** — low-risk hygiene; bundle the placeholder-assertion and notice cleanup.
3. **T3** — the structural win; do file-by-file, one or two SEO files per PR, always retaining a smoke test, with a Dusk run each time.
4. **T6** — schema-test consolidation once T3 establishes the "lower-level where possible" pattern.
5. **T9** — mechanical, per-file; safe to interleave.
6. **T5** — last and most cautious; fidelity-sensitive, investigate before changing.

## Definition of done

- [ ] No test reaches api.pwnedpasswords.com (T1).
- [ ] No test blocks on a real DigitalOcean Spaces timeout (T2).
- [ ] Each public page type has ≤2 SEO HTTP smoke tests; metadata variants live in presenter/formatter tests (T3).
- [ ] `Http::preventStrayRequests()` guards the suite; no stray Laravel-`Http` calls (T4).
- [ ] Redundant thumbnail-render variants trimmed without fidelity loss (T5).
- [ ] One schema-guardrail test per table; MySQL constraint tests retained (T6).
- [ ] No `assertTrue(true)` placeholder assertions remain (T7).
- [ ] Consistent DB trait per directory; deprecations at zero, notices minimised (T8).
- [ ] Pure-function unit tests run on bare PHPUnit (T9).
- [ ] Full `--parallel` suite is green, faster, and free of external-service dependencies; required quality gates pass for each delivered phase.
```
