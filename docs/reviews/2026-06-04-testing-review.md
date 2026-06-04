# Testing Review — Crockenhill

**Date:** 2026-06-04
**Author:** Claude (automated review)
**Scope:** Whole `tests/` suite — coverage, test level/altitude, and runtime.
**Method:** Static analysis of all 632 test files plus a full instrumented run (`artisan test --parallel --log-junit`) on the live MySQL/Sail stack to capture per-test wall time. All timings below are from that single real run.

---

## Executive summary

The suite is large, broad, and genuinely valuable — but it has grown by accretion, and three things are now true at once:

1. **A small number of tests make real network calls** to external services (the Pwned Passwords breach API and the live DigitalOcean Spaces endpoint). These are the single biggest source of both *slowness* and *flakiness*, and they are pure waste — they test the network, not the app. Fixing them is low-risk and removes the worst single-test outliers (10–13 seconds each).
2. **The suite leans heavily on full HTTP feature tests** where a faster, lower-level test would give the same signal — most visibly in a ~30-file SEO/metadata cluster that re-boots routes, the database, and Blade rendering to string-match `<meta>` tags that are assembled by already-unit-tested presenters.
3. **The MySQL dependency is partly deliberate** (CHECK constraints, ENUM enforcement, fulltext) — so the obvious "switch to SQLite `:memory:`" lever is **not safe** as a blanket change. The report calls out exactly which constraints block it.

**Headline numbers (from the instrumented run):**

| Metric | Value |
|---|---|
| Wall-clock runtime (`--parallel`, 10 cores) | **3 min 38 s (218 s)** |
| Total tests | 5,339 (632 files, ~4,950 methods + data-provider rows) |
| Assertions | 16,290 (**3.1 per test** — low) |
| Summed test-case time | 786 s → **effective parallelism only 3.6× of 10 cores** |
| Test code : app code | 127,830 : 79,291 lines = **1.6 : 1** |
| PHPUnit notices / deprecations in the run | 361 / 4 |

The low effective parallelism (3.6× on 10 cores) and the 786 s of summed work tell the real story: the bottleneck is **per-test database and HTTP cost against a single shared MySQL container**, not CPU. Reducing work (fewer full-stack tests, fewer factory rows, no stray network) will help far more than throwing more workers at it.

---

## 1. Where the time actually goes

### 1.1 The worst offenders are real external network calls

These were the slowest individual tests in the run. They are slow for one reason: **they leave the network unmocked.**

| Test | Time | Root cause |
|---|---:|---|
| `PasswordDefaultsTest::it_has_correct_password_defaults` | **10.1 s** | `Password::defaults()` → `->uncompromised()` calls **api.pwnedpasswords.com** for each valid password |
| `SermonPagesTest::sermon_page_handles_missing_transcript_file_without_crashing` | **12.7 s** | Deliberately leaves `do_spaces` unfaked; the S3 fallback dials the **real DigitalOcean endpoint** and waits for a TCP timeout |
| `RobustPathProtectionTest::it_blocks_private_transcript_access_for_non_admins` | **12.6 s** | Same S3-fallback timeout on the transcript path |
| `AuditLoggingTest::it_logs_user_creation` | **10.2 s** | Creates a user → password validation → **Pwned Passwords** call |

**Root cause A — Pwned Passwords breach check.** [`app/Providers/AppServiceProvider.php:92`](../../app/Providers/AppServiceProvider.php#L92) configures `Password::defaults()` with `->uncompromised()` in **every** environment, including `testing`:

```php
Password::defaults(function () {
    return Password::min(12)
        ->mixedCase()
        ->numbers()
        ->symbols()
        ->uncompromised();   // ← live HTTP call to api.pwnedpasswords.com
});
```

Every test that validates a password or creates a user through `Register`, `CreateUser`, or `EditUser` pays a real network round-trip. Affected files (9): `Auth/AuthRateLimitingTest`, `Auth/LivewireAuthComponentsTest`, `Auth/PasswordStrengthTest`, `Auth/Security/AuthInputValidationTest`, `Livewire/Admin/Users/CreateUserTest`, `Livewire/Admin/Users/EditUserTest`, `Livewire/AdminUserTest` (8.8 s), `MembersAreaAccessModelTest`, `Warden/UserIntegrityTest`, plus `Security/PasswordDefaultsTest` and `Security/AuditLoggingTest`.

**Root cause B — real DigitalOcean Spaces endpoint.** [`phpunit.xml:64`](../../phpunit.xml#L64) sets `DO_SPACES_ENDPOINT=https://nyc3.digitaloceanspaces.com` — a *reachable* host. When a test intentionally leaves `do_spaces` unfaked to exercise the S3-fallback path (a legitimate regression guard, see [`SermonPagesTest.php:134`](../../tests/Feature/SermonPagesTest.php#L134)), the fallback tries to connect to the real bucket and blocks on a connect/read timeout (~12 s) before failing.

**Why this matters beyond speed:** both are **flaky and CI-fragile**. If pwnedpasswords.com or DigitalOcean is slow or unreachable, these tests hang or fail for reasons unrelated to the code. There is no global `Http::preventStrayRequests()` or `Http::fake()` in `TestCase`, so nothing catches stray calls today.

### 1.2 The thumbnail/image tests are the heaviest *legitimate* cost

| File | Time | Tests |
|---|---:|---|
| `Unit/Services/ThumbnailCanvasComposerTest` | 25.0 s | 13 |
| `Integration/ThumbnailGenerationServiceTest` | 20.6 s | 15 |
| `Integration/Services/ThumbnailGenerationServiceCandidateTest` | 8.0 s | 2 |

These do **real image composition** (GD/Imagick) on full-size canvases and assert on rendered pixels — ~54 s of summed work. Unlike §1.1 this is *real* work testing *real* behaviour, so it should not simply be deleted. But it is concentrated (a few tests render 1920×1080 canvases) and is a candidate for smaller test canvases or sampling fewer pixel-assertion variants (see Recommendation R5).

### 1.3 Effective parallelism is poor — the DB is the throttle

786 s of summed test-case time finishing in 218 s wall-clock is only **3.6× concurrency on a 10-core host.** Two reasons:

- **A few very slow files bottleneck one worker** (Thumbnail 25 s + 20 s land back-to-back on whichever worker owns them).
- **MySQL is a single shared container.** ParaTest gives each worker its own database, but they contend on one mysqld. Adding workers past a point just increases lock/IO contention. This is why "reduce DB work" beats "add workers."

---

## 2. Test level / altitude (the test pyramid is inverted in places)

### 2.1 The suite is feature-test-heavy

- **111 files** make full HTTP calls (`$this->get/post/...`).
- **622 of 632** files extend the full Laravel `TestCase` (boots the framework + container + DB). Only **9** are pure `PHPUnit\Framework\TestCase`.
- Even under `tests/Unit/`, **113 of 122** files boot the full framework; many test pure functions (formatters, parsers, builders) that need nothing from Laravel.

This is the classic inverted pyramid: lots of expensive end-to-end tests, comparatively few fast isolated ones. It is the structural reason the suite is slow.

### 2.2 The SEO / metadata cluster — strongest "wrong level" example

~30 SEO-related files, of which this core cluster all drive full HTTP renders to string-match `<meta>`/JSON-LD:

| File | Methods | HTTP GETs |
|---|---:|---:|
| `SeoMetaTagsTest` | 17 | 14 |
| `SermonSeoTest` | 7 | 7 |
| `SermonBrowseSeoTest` | 7 | 7 |
| `StructuredDataTest` | 5 | 5 |
| `SeoMetadataTest` | 5 | 7 |
| `SermonJsonLdTest` | 4 | 4 |
| `SermonOpenGraphTest` | 4 | 4 |
| `SermonSocialMetadataTest` | 4 | 2 |
| `SeoMetadataImprovementTest` / `SeoRegressionTest` | 4 / 4 | 3 / 4 |

The actual logic under test — meta description assembly, OG/Twitter tags, JSON-LD shape — is produced by **`SermonViewPresenter`** and the SEO presenters, which **already have dedicated tests** (`Integration/Presenters/SermonViewPresenterTest` is 694 lines; `SermonArchiveSeoPresenterTest`, `SongArchiveSeoPresenterTest`, `Unit/Support/SermonContentFormatterTest`, etc.). The HTTP cluster pays for routing + middleware + DB seeding + full Blade render on every variant to re-assert what the presenter test already covers.

Concrete duplication: `SermonOpenGraphTest::sermon_page_includes_basic_meta_tags` and `::..._with_thumbnail` assert nearly the same `og:title` string; the same `og:title` format is independently re-asserted in `SermonSeoTest`, `SeoMetaTagsTest`, and `SermonSocialMetadataTest`. And `SermonOpenGraphTest::setUp()` creates a `User` ([line 23](../../tests/Feature/SermonOpenGraphTest.php#L23)) that **none of its four tests use** — a wasted DB write per test.

**The right shape:** push variant coverage down to the presenter (fast, no HTTP, no DB), and keep **one or two** HTTP "smoke" tests per page type that confirm the wiring ("the rendered page contains a JSON-LD block and an `og:title`"). That preserves the regression guard at a fraction of the cost.

### 2.3 Schema-shape and data-integrity tests

`tests/Feature/Database/` (12), `tests/Feature/Schema/`, and `tests/Feature/DataIntegrity/` (26) assert column/table existence and DB CHECK/ENUM constraints. Many of these (e.g. `Schema::hasColumn`, "this column is `NOT NULL`") **re-assert what the migrations and `mysql-schema.sql` already guarantee** — they fail only if someone edits a migration, in which case the migration diff is the real review surface. They are not free: each still boots the framework and hits MySQL. This is a category to **consolidate** (one schema-guardrail test per table rather than per column) rather than expand. Note `Phase 13` already added a CI schema-drift gate, which arguably supersedes much of this.

---

## 3. Coverage assessment

### 3.1 Breadth is good; the MySQL constraint is the key caveat

Coverage breadth is strong — Services (129 classes), Jobs (34), Commands (31), Livewire, Controllers, Presenters, and the media-processing pipeline are all well-exercised, including failure paths and security cases. The `<source>` whitelist in `phpunit.xml` covers all of `app/`.

**Critical constraint for any speed work:** the suite is intentionally MySQL-coupled. 51 `markTestSkipped` calls across 18 files guard MySQL-only behaviour:

> `'Database-level CHECK constraints are only implemented for MySQL'`, `'SQLite does not enforce ENUM constraints'`, `'Database integrity tests require MySQL'`, `'CHECK constraints are specifically tested on MySQL'`

➡️ **Do not move the default connection to SQLite `:memory:` as a blanket optimisation.** It would silently *skip* the database-integrity tests — exactly the "speed at the cost of risk" trade-off to avoid. A SQLite split is only viable for the framework-bootstrapping-but-DB-light tests, and even then it adds a second schema to maintain. See R6 for the safer framing.

### 3.2 Weak-signal tests

- **35 `assertTrue(true)` placeholder assertions** across ~10 files (`Unit/Services/AudioExtractionServiceTest`, `MediaValidationServiceTest`, `FrameExtractionServiceTest`, etc.). These pass unconditionally and give false confidence — e.g. `SermonOpenGraphTest` ends a real test with `$this->assertTrue(true, 'Open Graph meta tags are successfully implemented')`.
- **3.1 assertions per test** average is low for a suite this size; combined with the placeholder count, some tests assert presence of a route/page but not the behaviour that matters.
- **51 conditionally-skipped tests:** on a non-MySQL runner these vanish silently. CI runs MySQL so they execute today, but the suite gives no warning if that ever changes.

### 3.3 Genuine gaps to consider

- Pure collaborators extracted in Phase 14 (`SermonFilenameParser`, `SermonContentFormatter`) have fast unit tests — good. Continue that pattern; the *presenters* feeding the SEO cluster are the next candidates to absorb the variant coverage currently living in HTTP tests.
- No `Http::preventStrayRequests()` means a newly-introduced unmocked external call would not be caught — a latent coverage gap in the test *infrastructure* itself.

---

## 4. Consistency & hygiene

- **DB-trait split is inconsistent within sibling files.** 314 files use `RefreshDatabase`, 168 use `DatabaseTransactions`; e.g. inside `tests/Feature/DataIntegrity/`, `FilteringIndexesTest` uses `RefreshDatabase` while its neighbours use `DatabaseTransactions`. After the first migration per worker the per-test cost is similar, so this is a *consistency* issue, not a major speed one — but `RefreshDatabase` is the safer default and mixing invites confusion about test isolation. Pick one convention per directory.
- **361 PHPUnit notices + 4 deprecations** in the run (long `NN…` runs in the output). Worth a cleanup pass — notices often mask real deprecated-API usage and add noise that hides genuine warnings.
- **`tests/TestCase::setUp()` runs on all 5,339 tests:** `Cache::flush()` + 4× `forgetInstance` + config mutation. Individually cheap (array cache), but it is the one hook guaranteed to run everywhere — keep it lean (it currently is; just don't grow it).
- **`expectNotToPerformAssertions`** used once — fine.

---

## 5. Recommendations (prioritised)

Effort = rough dev time. Risk = chance of breaking real coverage.

| # | Recommendation | Impact | Effort | Risk |
|---|---|---|---|---|
| **R1** | **Kill stray network calls.** Disable `->uncompromised()` in `testing` (config flag in `AppServiceProvider`), and add `Http::preventStrayRequests()` + a `Http::fake()` for pwnedpasswords in `TestCase::setUp()`. | **High** — removes the 10 s pwned outliers and de-flakes all 11 user/auth files | Low | **Very low** |
| **R2** | **Make the S3-fallback fast-fail.** Point `DO_SPACES_ENDPOINT` in `phpunit.xml` at an unroutable/refusing address (e.g. `http://127.0.0.1:1`, mirroring the existing OpenAI trick). The "did we wrongly fall back to S3?" regression still fails — it just fails in milliseconds instead of after a 12 s timeout. | **High** — removes the two 12 s outliers | Low | **Very low** |
| **R3** | **Re-level the SEO cluster.** Move per-variant `<meta>`/JSON-LD assertions down to `SermonViewPresenter`/SEO-presenter tests; keep 1–2 HTTP smoke tests per page type. Delete the unused `User` in `SermonOpenGraphTest::setUp`. | **High** — removes dozens of full-render HTTP tests; clarifies ownership | Medium | Low–Medium (do per-file, keep one smoke test each) |
| **R4** | **Adopt `Http::preventStrayRequests()` globally** (folds into R1) so future unmocked calls fail loudly instead of hanging. | Medium — prevents regressions of R1/R2 | Low | Very low |
| **R5** | **Trim thumbnail-render cost.** Use smaller test canvases / fixture frames and cut redundant pixel-assertion variants where one representative case suffices. | Medium — ~54 s of summed work, on the critical worker | Medium | Medium (keep core composition cases) |
| **R6** | **Consolidate schema/column tests** to one guardrail test per table; lean on the existing Phase 13 CI drift gate rather than per-column assertions. Keep the MySQL CHECK/ENUM constraint tests. | Medium | Medium | Low |
| **R7** | **Replace 35 `assertTrue(true)` placeholders** with real assertions or delete the test. | Low (signal quality, not speed) | Low | Low |
| **R8** | **Standardise the DB trait per directory** (prefer `RefreshDatabase`) and clear the 361 notices. | Low | Low | Very low |
| **R9** | **Pull pure-function unit tests off the Laravel `TestCase`** (the formatter/parser/helper tests under `tests/Unit/Services` and `tests/Unit/Support` that need no container) onto `PHPUnit\Framework\TestCase`. | Low–Medium (per-test bootstrap savings × thousands of runs) | Medium | Low (verify each truly needs nothing from the app) |

**Sequencing:** R1 + R2 + R4 are a single afternoon, near-zero risk, and remove the worst outliers and the flakiness — **do them first.** R3 is the largest structural win but should be done file-by-file with a smoke test retained. R5–R9 are steady-state hygiene.

### Expected outcome

R1+R2 alone remove the four 10–13 s outliers and de-risk every auth/user test. Because those land on the critical-path workers, the wall-clock should drop meaningfully and — more importantly — the suite stops depending on two third-party services being up. R3 is what structurally bends the curve: every SEO variant moved from an HTTP render to a presenter call is a full framework boot + DB seed + Blade render removed from the budget.

---

## 6. What *not* to do (risk guardrails)

- **Do not switch the default test DB to SQLite `:memory:`.** §3.1 — it silently skips the MySQL CHECK/ENUM/integrity tests. The current MySQL coupling is partly deliberate.
- **Do not delete the S3-fallback regression tests** — fix the endpoint (R2) so they fail fast instead of removing the guard.
- **Do not delete the thumbnail pixel tests wholesale** — they assert real rendering behaviour; shrink the inputs (R5) instead.
- **Do not chase wall-clock by adding ParaTest workers** — the throttle is the single MySQL container (§1.3), not CPU. Reduce DB work instead.
- **Keep the 51 MySQL-guarded tests** — but consider a CI assertion that they are *not* being skipped on the canonical runner, so the guard can't hide a silent coverage loss.

---

## Appendix — method

- Full run: `vendor/bin/sail artisan test --parallel --log-junit storage/test-timing.xml` on Sail (MySQL 8.0, 10 logical cores). 5,339 tests, 218 s wall.
- Per-file / per-test timings parsed from the JUnit XML.
- Static analysis: file/trait/level inventory via `grep`/`find` over `tests/`.
- The instrumented artefacts (`storage/test-timing.xml`, `storage/test-run-output.log`) can be deleted after review.
