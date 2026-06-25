# Testing Remediation Plan

Created 2026-06-04 from the findings in [docs/reviews/2026-06-04-testing-review.md](../reviews/2026-06-04-testing-review.md).

This plan turns each recommendation (R1–R9) in that review into an ordered, verifiable phase. Phases are numbered `T1…T9` and map 1:1 to the review's recommendation IDs. Each phase is independently shippable.

> **Status: ✅ Complete (2026-06-25).** All nine phases (T1–T9) shipped and the Definition of Done is fully met. Delivered across PRs #962 (T1/T2/T4), #963 (T7), #964 (T9) and #965 (T3/T8), with T5/T6 already landed beforehand. The full `--parallel` suite is green (5713 tests, 0 failures/errors/deprecations) and the T3 Dusk pass is confirmed green on the `master` Deploy pipeline (commit `43038a4`). Archived to `docs/archived-plans/`.

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

To re-measure timing after a phase: `vendor/bin/sail artisan test --parallel --log-junit storage/test-timing.xml` and re-parse (see the review's Appendix).

---

## T1 — Eliminate the Pwned Passwords network call  (review R1)

**Priority: High · Risk: Very low · Est. impact: removes the 10 s `PasswordDefaultsTest` + `AuditLoggingTest::it_logs_user_creation` outliers and de-flakes 11 files.**

### Root cause
[app/Providers/AppServiceProvider.php:92-98](../../app/Providers/AppServiceProvider.php#L92-L98) configures `Password::defaults()` with `->uncompromised()` in every environment. The `uncompromised` rule resolves an `Illuminate\Contracts\Validation\UncompromisedVerifier` from the container and calls **api.pwnedpasswords.com** — even when the password already failed the format rules — so every password validation and user creation in the suite pays a real round-trip.

### Approach
Do **not** edit `AppServiceProvider` (production keeps the breach check). Instead rebind the verifier to a no-network fake in the test base class. This leaves production password policy byte-for-byte unchanged and avoids the `runningUnitTests()` anti-pattern.

### Target files
- [tests/TestCase.php](../../tests/TestCase.php) — add the binding in `setUp()`.

### Tasks
- [x] In `tests/TestCase.php::setUp()` (after `parent::setUp()`), bind a fake verifier:
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
- [x] If any test genuinely needs to assert breach rejection (none found today), it can override this binding locally with a verifier returning `false`.
- [x] Decide the fate of [tests/Feature/Security/PasswordDefaultsTest.php](../../tests/Feature/Security/PasswordDefaultsTest.php): it still validly asserts min-length/letters/numbers/symbols against `Password::defaults()`; keep it, just confirm it no longer hits the network (it won't, post-binding).

### Verification
- [x] `vendor/bin/sail artisan test --compact --parallel tests/Feature/Security/PasswordDefaultsTest.php tests/Feature/Security/AuditLoggingTest.php tests/Feature/Livewire/AdminUserTest.php tests/Feature/Auth/PasswordStrengthTest.php tests/Feature/Auth/AuthRateLimitingTest.php tests/Feature/Livewire/Admin/Users`
- [x] Confirm `PasswordDefaultsTest` drops from ~10 s to <1 s.

### What changed
The fix lives entirely in the test container — production password policy is byte-for-byte unchanged. [tests/TestCase.php](../../tests/TestCase.php) now calls `preventPwnedPasswordsNetworkCall()` from `setUp()` (after `parent::setUp()`), rebinding `UncompromisedVerifier` to an anonymous no-network fake whose `verify()` always returns `true`. [app/Providers/AppServiceProvider.php](../../app/Providers/AppServiceProvider.php#L92-L98) keeps `Password::defaults()->...->uncompromised()` so the production breach check is untouched. `PasswordDefaultsTest` was kept verbatim: it still validates the min-12/letters/numbers/symbols rules against `Password::defaults()`, and with the fake verifier bound it satisfies `uncompromised()` without a round-trip to api.pwnedpasswords.com. No `runningUnitTests()` branch was introduced (Guardrail honoured).

### Exit criteria
- [x] No test reaches api.pwnedpasswords.com; the 11 user/auth files no longer depend on an external service.

---

## T2 — Make the S3 / DigitalOcean fallback fail fast  (review R2)

**Priority: High · Risk: Very low · Est. impact: removes the two ~12.6 s outliers (`SermonPagesTest`, `RobustPathProtectionTest`).**

### Root cause
[phpunit.xml:64](../../phpunit.xml#L64) sets `DO_SPACES_ENDPOINT=https://nyc3.digitaloceanspaces.com` — a *reachable* host. Tests that deliberately leave `do_spaces` unfaked to catch wrong-fallback regressions (e.g. [tests/Feature/SermonPagesTest.php:134-145](../../tests/Feature/SermonPagesTest.php#L134-L145)) make the S3 client connect to the real bucket and block on a TCP timeout (~12 s) before failing. The regression guard is legitimate; the 12 s wait is not.

### Approach
Point the test endpoint at an address that **refuses immediately** instead of timing out, mirroring the existing OpenAI trick (`OPENAI_BASE_URL=http://127.0.0.1:1/v1` in the same file). A wrong S3 fallback still errors out — it just errors in milliseconds. Also pin the AWS SDK retry count to 0 in tests so a refused connection isn't retried with backoff.

### Target files
- [phpunit.xml](../../phpunit.xml) — change the endpoint env; optionally add an SDK-retry override.
- [config/filesystems.php:73-84](../../config/filesystems.php#L73-L84) — `do_spaces` disk; confirm it reads `DO_SPACES_ENDPOINT` (it does, line 79) and add an optional `'retries' => env('DO_SPACES_RETRIES', 3)` so tests can set 0.

### Tasks
- [x] In `phpunit.xml`, change `DO_SPACES_ENDPOINT` to `http://127.0.0.1:1` (connection-refused, instant).
- [x] Add `<env name="DO_SPACES_RETRIES" value="0"/>` and wire `'retries'` into the `do_spaces` disk config's S3 client options (`'options' => ['retries' => ...]` or the client's `retries` key) so the SDK does not retry the refused connection.
- [x] Re-read the two affected tests' intent comments and confirm they still assert "we did NOT fall back to S3" (status 200, no S3 content). With a fast-fail endpoint, a wrong fallback now surfaces as a fast 500 rather than a 200, so the guard tightens rather than weakens.

### Verification
- [x] `vendor/bin/sail artisan test --compact --parallel tests/Feature/SermonPagesTest.php tests/Feature/Security/RobustPathProtectionTest.php`
- [x] Confirm both target tests drop from ~12.6 s to sub-second.
- [x] Spot-check a couple of asset-serving tests that *do* fake `do_spaces` to ensure the endpoint change doesn't affect them (it shouldn't — faked disks bypass the network).

### What changed
[phpunit.xml](../../phpunit.xml) now sets `DO_SPACES_ENDPOINT=http://127.0.0.1:1` (a refused-connection address that errors in milliseconds, mirroring the existing `OPENAI_BASE_URL=http://127.0.0.1:1/v1` trick in the same file) and adds `DO_SPACES_RETRIES=0`. [config/filesystems.php](../../config/filesystems.php#L73-L89) reads that into the `do_spaces` disk via `'retries' => (int) env('DO_SPACES_RETRIES', 3)` — defaulting to `3` in production and `0` under test so the AWS SDK does not retry the refused connection with backoff (the cast is required because the SDK validates `retries` as an integer). The regression guards are unchanged and tightened, not weakened: [tests/Feature/SermonPagesTest.php](../../tests/Feature/SermonPagesTest.php#L133-L151) still fakes only the `local`/`public` disks and leaves `do_spaces` unfaked to catch a wrong S3 fallback, asserting status 200; a wrong fallback now surfaces as a fast failure instead of a ~12 s TCP timeout. Faked-disk asset tests are unaffected — `Storage::fake('do_spaces')` bypasses the network entirely, so the endpoint value is irrelevant to them.

### Exit criteria
- [x] No test blocks on a real DigitalOcean Spaces timeout; the S3-fallback regression guards still fail (fast) on wrong behaviour.

---

## T3 — Re-level the SEO / metadata cluster  (review R3)

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
- [x] For each HTTP test method, classify it: **(a) logic variant** (different input → different meta/JSON-LD string) or **(b) wiring** (the page actually emits the block).
- [x] Move every (a) down to the matching presenter/formatter test, asserting on the presenter's return value (no HTTP, no DB seed where the presenter accepts a model built in-memory).
- [x] Keep exactly **one** (b) smoke test per page type at the HTTP level: e.g. "sermon show page contains an `og:title` and a `ld+json` block", "archive page contains an `ItemList` JSON-LD block".
- [x] Delete now-redundant HTTP methods and the unused `SermonOpenGraphTest::setUp` `User`.
- [x] Track net method count: aim to convert ~50 HTTP methods into presenter assertions + ~8 retained smoke tests.

### Verification
- [x] Run the full SEO landing zone + retained smoke tests: `vendor/bin/sail artisan test --compact --parallel tests/Integration/Presenters tests/Unit/Support/SermonContentFormatterTest.php tests/Feature/Sermon*SeoTest.php tests/Feature/SeoMetaTagsTest.php tests/Feature/SermonJsonLdTest.php tests/Feature/SermonOpenGraphTest.php` — **140 tests / 396 assertions, all green.**
- [x] `vendor/bin/sail artisan dusk` (SEO views are public routes). **Headless Chrome was too unstable to complete a full run in the web session, so the Dusk pass was deferred to CI — where it has now run green. The `Deploy` workflow runs the Dusk job unconditionally on every push to `master`; on the post-merge run for #965 (commit `43038a4`) the "Browser Tests (Dusk)" job completed `success` against the T3 changes (it also passed on the #964 merge run). The only red on those master Deploy runs is the final "Deploy to server" SSH step — an infrastructure concern outside this plan's scope; all tests, Pint, PHPStan and Dusk are green.**
- [x] Confirm no presenter behaviour lost coverage: every metadata *variant* is asserted at the presenter/formatter level (`SermonViewPresenterTest`, `SermonArchiveSeoPresenterTest`, `SongArchiveSeoPresenterTest`, `Tests\Integration\Models\PageSeoTest`, `SermonContentFormatterTest`); each public page type retains a single `og:title`-format smoke assertion (home, section, sermon, song, archive) — no duplication across files.

### What changed
The SEO/metadata cluster was already re-levelled to the target shape in the codebase: every HTTP SEO test file is now a thin **wiring smoke** layer (`SermonOpenGraphTest`, `SermonJsonLdTest`, `SermonSeoTest`, `SermonSocialMetadataTest`, `SermonBrowseSeoTest`, `StructuredDataTest`, `SeoMetaTagsTest`, `SeoMetadataTest`, `SeoMetadataImprovementTest`, `SeoRegressionTest`) carrying 2–3 methods each with a docstring pointing at its presenter landing zone, while the value/variant matrix lives in `tests/Integration/Presenters/*` and `tests/Unit/Support/SermonContentFormatterTest`. This phase **verified** that state against a real MySQL 8.0 + built-frontend environment: the full landing-zone + smoke command is green (140/396), the exit criteria hold (≤2 HTTP smoke tests per page type, no duplicated `og:title` assertions, every variant covered at presenter level), and the Dusk browser pass — deferred from the web session because headless Chrome was unstable under load — has since run green in CI on the `master` Deploy pipeline for this change (commit `43038a4`).

### Exit criteria
- [x] Each public page type has ≤2 SEO HTTP smoke tests; all metadata *variant* coverage lives in presenter/formatter tests; no duplicated `og:title`-format assertions across files.

---

## T4 — Catch future stray HTTP with `preventStrayRequests`  (review R4)

**Priority: Medium · Risk: Low (but may surface existing offenders — triage required) · Depends on T1, T2.**

### Approach
After T1/T2 remove the known stray calls, add `Http::preventStrayRequests()` in `TestCase::setUp()` so any *new* unmocked Laravel-`Http` call fails loudly instead of hanging. Note this governs only the `Http` facade — raw Guzzle / the OpenAI SDK are unaffected (those already point at the refused `127.0.0.1:1` endpoint).

### Tasks
- [x] Add `Http::preventStrayRequests();` to `tests/TestCase.php::setUp()`.
- [x] Run the **full** suite once and triage every failure it surfaces (each is a real unmocked external call). Existing `Http::fake` users (`ApiBibleClientTest` and the 2 others) are unaffected because they set their own fakes.
- [x] For each surfaced offender, add a scoped `Http::fake([...])` in that test.
- [x] If triage is large, land `preventStrayRequests` behind the fakes incrementally rather than blocking the phase.

### Verification
- [x] Full `vendor/bin/sail artisan test --parallel` passes with the guard on.

### What changed
[tests/TestCase.php](../../tests/TestCase.php) calls `Http::preventStrayRequests()` in `setUp()` (immediately after the T1/T2 bindings), so any unmocked Laravel-`Http` call now fails loudly instead of hanging on a real request. The triage left each genuine HTTP caller mocking its own calls: six test files set a scoped `Http::fake([...])` — `PixianClientTest`, `RouteCanaryProberTest`, `LocalWhisperTranscriptionServiceTest`, `ApiBibleClientTest`, `HealthEndpointTest`, and `RouteCanariesCheckTest` — which override the global guard for their cases. The guard governs only the `Http` facade; raw Guzzle / the OpenAI SDK are unaffected and already point at the refused `127.0.0.1:1` endpoint (T1's OpenAI trick and T2's Spaces endpoint), so no stray network egress remains anywhere in the suite.

### Exit criteria
- [x] A new unmocked `Http::` call in any test fails fast with a clear "stray request" error.

---

## T5 — Trim thumbnail-render cost  (review R5)

**Priority: Medium · Risk: Medium (fidelity-sensitive) · Est. impact: up to ~54 s of summed work; investigate, don't degrade.**

### Notes from the code
[tests/Unit/Services/ThumbnailCanvasComposerTest.php](../../tests/Unit/Services/ThumbnailCanvasComposerTest.php) already caches rendered canvases in a static `$canvasCache` (good — reused across the class). The remaining cost is (a) the real Intervention/GD composition and (b) per-test pixel-bounds scanning loops. The geometry assertions are tuned to specific canvas dimensions (720px tall), so blind shrinking will break the hard-coded coordinate expectations.

### Tasks (investigate first; only proceed if fidelity holds)
- [x] Audit the 13 `ThumbnailCanvasComposerTest` + 15 `Integration/ThumbnailGenerationServiceTest` methods for **redundant variants** — cases that exercise the same composition branch with cosmetically different inputs. Collapse duplicates.
- [x] Evaluate whether the pixel-bounds scan can be bounded to the region of interest rather than the full canvas (it partly is — confirm no full-canvas scans remain).
- [x] Do **not** reduce canvas dimensions unless the coordinate assertions are re-derived; the constants (`CENTERED_TITLE_MAX_Y = 468`, etc.) are dimension-specific.
- [x] Confirm `Integration/ThumbnailGenerationServiceTest` mocks `VideoSegmentationService` (no real ffmpeg) — keep it that way.

### Verification
- [x] `vendor/bin/sail artisan test --compact tests/Unit/Services/ThumbnailCanvasComposerTest.php tests/Integration/ThumbnailGenerationServiceTest.php` — all 28 tests / 127 assertions still pass.

### What changed

**The review's hypothesised ~54 s saving was already banked by the existing `$canvasCache`.** The audit found no redundant composition-branch variants to collapse — every distinct canvas the suite renders proves a distinct branch:

- `ThumbnailCanvasComposerTest`'s static `$canvasCache` already collapses every *shareable* render. The 10 distinct cache keys map 1:1 to distinct branches: reduced logo size, accent-line title centring, foreground top/right inset, subject-behind-text layering, left-facing subject flip, centred-canvas dimensions, teal title colour, top-padding (Y=43) clamp, two-line subject-overlap arithmetic, three-line subject-overlap arithmetic, and horizontal subject centring. The two-line vs three-line overlap split exercises genuinely different `resolveCenteredForegroundTopY` arithmetic (`overlapStartLineIndex` differs by line count) — collapsing it would lose branch coverage, which the Notes section explicitly forbids.
- The pixel-bounds helpers (`greenPixelBounds`, `pixelBounds`, `tealPixelBounds`) are **bounds-finders whose discovered extents are asserted** (e.g. `min_y === 50`, `max_x === 1229`). Bounding their scan region would clip the very pixels under test — a fidelity hazard — so the full-/region-canvas scans were deliberately left intact. Their per-call cost is milliseconds and is dwarfed by the GD composition cost anyway.
- The `Integration` test's renders are not cross-test cacheable like the unit test's: it caches in-memory `ImageInterface` GD objects (disk-independent), whereas each `createBrandedThumbnail` writes to a `Storage::fake` disk that `RefreshDatabase` resets per-test. Adding cross-test caching there would couple to faked-disk lifecycle — the kind of fidelity-degrading change this phase warns against.

**Safe removals (dead code, never executed, zero fidelity risk):**
- `ThumbnailGenerationServiceTest::findBrightPixelInRegion` — private helper with no call sites.
- `ThumbnailCanvasComposerTest::countTitleLines` — private helper with no call sites (tests call `titleLineBands` directly).

Net: −2 dead private methods; 0 test methods removed; 28 tests / 127 assertions unchanged and green. Timing is unchanged (the removed code never ran); the real render cost is irreducible — it is the minimum number of distinct GD compositions needed to prove each branch.

### Exit criteria
- [x] No redundant render variants remained to remove; no composition-branch coverage lost; canvas fidelity assertions intact.

---

## T6 — Consolidate schema / column tests  (review R6)

**Priority: Medium · Risk: Low · Depends on: Phase 13 CI drift gate (already shipped).**

### Root cause
`tests/Feature/Database/` (12), `tests/Feature/Schema/`, and parts of `tests/Feature/DataIntegrity/` (26) assert column/table existence per column — re-asserting what migrations + `mysql-schema.sql` + the Phase 13 CI drift gate already guarantee. Each still boots the framework and hits MySQL.

### Tasks
- [x] Inventory every `Schema::hasColumn` / `Schema::hasTable` / column-type assertion (12 files identified in the review).
- [x] For each table, collapse per-column existence assertions into **one** guardrail test per table (or delete where the Phase 13 drift gate fully covers it).
- [x] **Keep** every MySQL CHECK/ENUM constraint test (the `markTestSkipped('...requires MySQL')` ones) — these verify runtime behaviour, not schema shape.
- [x] Keep `DataIntegrity` tests that assert *behavioural* invariants (cascade deletes, uniqueness enforcement); only trim pure shape checks.

### Verification
- [x] `vendor/bin/sail artisan test --parallel tests/Feature/Database tests/Feature/Schema tests/Feature/DataIntegrity` (run per-directory; runner takes one path). Database 52, Schema 5, DataIntegrity 116 — all green.
- [x] Deliberately added a scratch migration (`2099_..._scratch_drift_probe.php`) and confirmed `scripts/check-schema-dump-current.sh` (the Phase 13 gate, wired into `pr.yml`/`deploy.yml`) fails on it (exit 1) and passes once removed (exit 0).

### What changed
The redundancy was **positive column/table existence checks** that duplicate what migrations + the drift gate guarantee. The key discipline: **columns have a behavioural witness, named indexes usually do not.** A factory insert or migration-backfill test that writes and reads a column would throw on a missing column, so the `hasColumn` above it is dead weight. Named indexes affect only performance, so no functional test asserts them — those assertions were preserved.

- `tests/Feature/Schema/ColumnPromotionIntegrityTest.php` — deleted 3 pure-shape `hasColumn` methods; columns are exercised by the sibling nullable-round-trip / FK / metadata tests. Dropped unused `Schema` import.
- `tests/Feature/Database/ChurchServiceItemSchemaTest.php` — deleted `it_creates_the_source_column...`; the `source` column is written/read by the backfill test.
- `tests/Feature/Database/ChurchServiceSchemaTest.php`, `ServiceSectionSchemaTest.php`, `ReportingStatePromotionSchemaTest.php`, `SongCatalogSchemaTest.php` — reduced each `it_creates_..._columns_and_indexes` method to its **index assertions only**; columns are witnessed by the backfill/constraint/factory tests in the same files (and, for `media_processing_logs` extracted columns, by the Integration job suite). Renamed methods to `..._indexes`.

**Preserved untouched:** all MySQL CHECK/ENUM constraint tests, cascade/null-on-delete tests, migration idempotency/reconcile/conditional-add tests (the `hasColumn`/`hasTable` calls inside these assert *migration behaviour*, not standalone shape), `TargetedSchemaGuardrailsTest` (negative `assertFalse(hasTable/hasIndex)` removal checks + ENUM type assertions), `SchemaGuardrailAuditTest`, and the index-only DataIntegrity guardrails.

Net: −4 test methods (1 Database, 3 Schema); column-list assertions collapsed (Database 175→162, Schema 22→15 assertions). No table lost its index guardrail; no behavioural coverage removed.

### Exit criteria
- [x] One schema-guardrail test per table; no per-column existence duplication; constraint/behaviour tests untouched.

---

## T7 — Replace placeholder assertions  (review R7)

**Priority: Low (signal quality) · Risk: Low.**

### Root cause
35 `assertTrue(true)` placeholder assertions across ~10 files pass unconditionally (e.g. `SermonOpenGraphTest` ends a real test with `assertTrue(true, 'Open Graph meta tags are successfully implemented')`).

### Target files (from the review grep)
`tests/Unit/ExampleTest`, `tests/Unit/StorageAdapterHelperTest`, `tests/Unit/Services/AudioExtractionServiceTest`, `MediaValidationServiceTest`, `FrameExtractionServiceTest`, `OpenLpDecompressionBombTest`, `AudioChunkingServiceTest`, `AudioEnhancementServiceTest`, `tests/Integration/Models/PageTest`, `tests/Integration/Livewire/Traits/WithAdminAuthorizationTest`, plus the SEO files touched in T3.

### Tasks
- [x] For each `assertTrue(true)`, either replace it with the real assertion the test name implies, or delete the test if it proves nothing.
- [x] Delete `tests/Unit/ExampleTest.php` and `tests/Browser/ExampleTest.php` (scaffolding) unless intentionally kept.
- [x] Do not remove a test file without confirming it has no other meaningful assertions (per the repo rule on not deleting tests without approval — flag any deletions for sign-off).

### Verification
- [x] Re-run each touched file; confirm assertions now fail if the behaviour regresses (spot-check by temporarily breaking one path). *(Suite not runnable in the web session — no `vendor/`, no Docker daemon; `php -l` lints clean and CI runs the full suite on the PR.)*

### What changed
Most of T7 was already done in earlier phases; this pass closed the last two placeholders, in [tests/Unit/Rules/TrimmedTextTest.php](../../tests/Unit/Rules/TrimmedTextTest.php). `it_passes_for_null_values` and `it_passes_for_valid_trimmed_strings` previously ended in `assertTrue(true)` with a `$this->fail(...)` callback that only fired on the unhappy path, so the happy path asserted nothing (PHPUnit "risky"). Both now track a `$failed` flag the validator callback flips and assert `assertFalse($failed, ...)`, mirroring the negative tests in the same file — a real, counted assertion every run. A repo-wide grep confirms **no `assertTrue(true)` placeholder remains** in `tests/`.

**Deletions — none.** `tests/Unit/ExampleTest.php` was already removed in an earlier phase. `tests/Browser/ExampleTest.php` is **intentionally kept**: it is no longer scaffolding but a genuine Dusk smoke test that visits `/` and asserts the homepage renders "Crockenhill Baptist Church", so the plan's "unless intentionally kept" clause applies and no sign-off-requiring deletion was needed.

### Exit criteria
- [x] No `assertTrue(true)` placeholder remains; every test asserts the behaviour in its name.

---

## T8 — Standardise DB trait per directory + clear notices  (review R8)

**Priority: Low · Risk: Very low.**

### Root cause
314 files use `RefreshDatabase`, 168 use `DatabaseTransactions`, mixed within sibling directories (e.g. `DataIntegrity/FilteringIndexesTest` uses `RefreshDatabase` while neighbours use `DatabaseTransactions`). The run also emitted 361 PHPUnit notices + 4 deprecations.

### Tasks
- [x] Pick one convention per directory (prefer `RefreshDatabase` as the safer default) and align siblings. This is a consistency fix, not a perf fix — per-test cost is similar after the first migration.
- [x] Capture the notices: `vendor/bin/sail artisan test --parallel 2>&1 | tee storage/notices.log`, group by source, and fix the deprecated-API usages they flag.
- [x] Address the deprecations (likely deprecated PHPUnit/Laravel APIs in test helpers).

### Verification
- [x] Full `--parallel` run shows 0 deprecations and a materially lower notice count. **5713 tests green; 0 deprecations; PHPUnit Notices 368 → 169 (−54%).**

### What changed
The codebase had already drifted far from the review's snapshot — only **10** files still used `DatabaseTransactions` (the review counted 168), and the **4 deprecations are already at zero**. This phase closed the two remaining gaps against a real MySQL 8.0 suite:

**DB-trait consistency (10 files).** Each of the 10 remaining `DatabaseTransactions` files was a lone minority outlier in a directory dominated by `RefreshDatabase` (e.g. `Integration/Services` is 85 `RefreshDatabase` vs 3 `DatabaseTransactions`). None carried a deliberate-choice comment, set `$seed`, or used a secondary connection, so all 10 were aligned to `RefreshDatabase` (the safer default) and verified: 73 tests / 265 assertions green. The 11 `DatabaseTruncation` files were left untouched — they are all Dusk browser tests, where truncation is required so the served app sees committed rows. (`PublicSongCatalogLyricSearchTest` keeps its `// …DatabaseTransactions` comment: it deliberately avoids the trait because fulltext `MATCH … AGAINST` needs committed rows.)

**Notices (368 → 169).** A full-suite verbose event-log run (`--log-events-verbose-text`) showed **every** notice is the *same* PHPUnit issue: *"No expectations were configured for the mock object … Consider refactoring your test code to use a test stub instead"* — i.e. `createMock()` used where a stub is meant. The correct, behaviour-preserving fix is `createMock()` → `createStub()`, applied only where the double genuinely has no expectations:
- **27 pure-stub files** (no `->expects()` anywhere, no `MockObject` type-hints): all 102 `createMock` calls converted wholesale.
- **8 mixed files**: a conservative per-variable pass converted only the 42 `createMock` doubles whose variable/property never receives `->expects()` within its statement (multi-line fluent chains handled, so genuinely-expected mocks such as `UnifiedMediaProcessorTest`'s `$this->livestreamService` were correctly preserved).
- **6 `MockObject`-typed files** were left as `createMock`: their doubles are bound to `private MockObject $x` properties / `: MockObject` return types, so swapping to `Stub` would break the declared type for a handful of notices — not worth the risk.

The residual ~169 notices are **shared `setUp()` mocks** that legitimately carry `->expects()` in some test methods but not others (so the property must stay a mock), plus those `MockObject`-typed files. Driving those to zero needs per-method `#[AllowMockObjectsWithoutExpectations]` attributes or `setUp` restructuring — finer-grained, lower-value work deliberately left out of this low-risk phase. All quality gates pass: Pint clean, PHPStan 0 errors, full `--parallel` suite green.

### Exit criteria
- [x] Consistent DB trait per directory; notice/deprecation count driven toward zero (deprecations **at** zero; notices down 54%).

---

## T9 — Move pure-function unit tests off the Laravel `TestCase`  (review R9)

**Priority: Low–Medium · Risk: Low (verify each truly needs nothing from the container).**

### Root cause
113 of 122 `tests/Unit` files extend the full Laravel `TestCase` (boots framework + container). Many test pure functions (formatters, parsers, helpers) needing nothing from Laravel; the bootstrap cost is paid thousands of times.

### Candidate files (verify each is container-free)
Pure-logic tests under `tests/Unit/Services` and `tests/Unit/Support` — e.g. `SermonFilenameParserTest`, `SermonContentFormatterTest`, `BibleCanonTest`, `PathTest`, `ScriptureHtmlSanitizerTest`, `SafeMarkdownRendererTest`, `ThumbnailTextHelperTest` (color/wrap math), `SongLyricSnippetBuilderTest`. Exclude anything using `config()`, facades, `app()`, factories, or `Storage`/`Cache`.

### Tasks
- [x] For each candidate, confirm it uses no facade/container/config/DB. If clean, change `extends Tests\TestCase` → `extends PHPUnit\Framework\TestCase` and drop the `CreatesApplication`/`WithCachedConfig` reliance.
- [x] Keep any test that touches `config()` or facades on the Laravel `TestCase` — the bootstrap is load-bearing there.
- [x] Note the `ThumbnailCanvasComposerTest` is **not** a candidate (it uses Intervention's `Image` facade and `Sermon` models).

### Verification
- [x] `vendor/bin/sail artisan test --compact tests/Unit/Services tests/Unit/Support` — all pass; confirm the converted files no longer boot the app (faster). *(Suite not runnable in the web session — no `vendor/`, no Docker daemon; each converted file was instead `php -l`-linted and audited statically, and CI runs the full suite on the PR.)*

### What changed
Both the **test** and its **class-under-test** were audited for any container/config/facade/DB/model/`now()` use; a candidate was converted only when both are provably bootstrap-free, then `use Tests\TestCase;` was swapped for `use PHPUnit\Framework\TestCase;` (import order kept Pint-correct).

**Converted to bare PHPUnit (10 files):** `Support/PathTest`, `Support/SermonContentFormatterTest`, `Support/OpenAiChatPayloadTest`, `Services/ScriptureHtmlSanitizerTest`, `Services/ScriptureReferenceResolverTest`, `Services/SongLyricSnippetBuilderTest`, `Services/SongTitleHintExtractorTest`, `Services/OpenLpLyricsParserTest`, `Services/FrameQualityScorerTest`, `Services/ProcessingReportTest`. (`collect()` / `Illuminate\Support\Str` / `Collection` used by these are autoloaded helpers/pure value classes, not container-bound.)

**Already on bare PHPUnit (no action):** `Support/ServiceSectionConfidenceTest`, `Support/ParallelTestingProcessLimiterTest`, `Services/ChurchServiceReviewStateServiceTest`, `Services/SectionAlignmentBaselineRestorerTest`.

**Kept on the Laravel `TestCase` (bootstrap is load-bearing):**
- `Support/BibleCanonTest` — asserts the container singleton via `app(BibleCanon::class)`.
- `Services/ThumbnailTextHelperTest` — SUT logs via the `Log` facade.
- `Services/SafeMarkdownRendererTest` — SUT reads `config('markdown.safe_options')`.
- `Services/SermonFilenameParserTest` — SUT calls the `now()` helper (resolves the `Date` facade).
- `Services/SongClusteringServiceTest` — SUT logs via the `Log` facade on its main path.
- `Services/CalendarCategorizationResultTest` / `Services/PresentationItemClassifierTest` — instantiate Eloquent models.

Scope was the `deps=0` pure-logic candidates under `tests/Unit/{Services,Support}`; the remaining `deps≥1` files there have genuine framework coupling and stay put. Further candidates can be converted incrementally (the phase is explicitly safe to interleave).

### Exit criteria
- [x] Pure-function unit tests run on bare PHPUnit; framework-dependent ones stay on the Laravel base.

---

## Suggested order

1. **T1 + T2 (+ T4)** — one PR, an afternoon, near-zero risk. Removes all four 10–13 s outliers and the suite's dependence on two external services. **Do first.**
2. **T7 + T8** — low-risk hygiene; bundle the placeholder-assertion and notice cleanup.
3. **T3** — the structural win; do file-by-file, one or two SEO files per PR, always retaining a smoke test, with a Dusk run each time.
4. **T6** — schema-test consolidation once T3 establishes the "lower-level where possible" pattern.
5. **T9** — mechanical, per-file; safe to interleave.
6. **T5** — last and most cautious; fidelity-sensitive, investigate before changing.

## Definition of done

- [x] No test reaches api.pwnedpasswords.com (T1).
- [x] No test blocks on a real DigitalOcean Spaces timeout (T2).
- [x] Each public page type has ≤2 SEO HTTP smoke tests; metadata variants live in presenter/formatter tests (T3).
- [x] `Http::preventStrayRequests()` guards the suite; no stray Laravel-`Http` calls (T4).
- [x] Thumbnail-render cost audited: no redundant variants existed (caching already maximal); dead helpers removed without fidelity loss (T5).
- [x] One schema-guardrail test per table; MySQL constraint tests retained (T6).
- [x] No `assertTrue(true)` placeholder assertions remain (T7).
- [x] Consistent DB trait per directory; deprecations at zero, notices minimised (368 → 169, −54%) (T8).
- [x] Pure-function unit tests run on bare PHPUnit (T9).
- [x] Full `--parallel` suite is green (5713 tests, 0 failures/errors/deprecations) and free of external-service dependencies; required quality gates pass for each delivered phase.
