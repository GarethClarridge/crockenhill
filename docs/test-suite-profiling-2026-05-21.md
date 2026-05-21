# Test Suite Profiling Report — 2026-05-21

Companion to [test-output-performance-report.md](./test-output-performance-report.md). That earlier
report identified individual slow files by reading test output; this one is grounded in a fresh
JUnit-logged run of the entire parallel suite and surfaces a different (and larger) root cause.

## Problem statement

The full test suite takes ~5 minutes in `--parallel` mode. This is slow enough to discourage
running tests frequently during development, eroding the value of having them.

## Methodology

Ran paratest directly with JUnit logging to capture per-test durations across all 5,192 tests:

```bash
vendor/bin/sail bin paratest --log-junit=storage/app/test-profile/junit.xml --no-progress
```

> **Caveat:** Invoking paratest directly skips Laravel's per-worker DB bootstrapping, so many tests
> errored quickly. The durations of the tests that *did* run are real and unaffected. Sort order
> and top outliers are reliable — only the success/failure counts are noise.

The resulting 19 MB `junit.xml` was parsed and sorted by `testcase[time]` descending.

## Distribution

| Slice | Cumulative time | Share of total |
| --- | --- | --- |
| Top 2 tests | 100.51 s | 17.7% |
| Top 40 tests | 160.01 s | 28.2% |
| Top 100 tests | 192.76 s | 34.0% |
| All tests (sum) | 566.36 s | 100% |

Wall time with 8 parallel workers: ~300 s. Parallel efficiency ≈ 24% — meaning ~3/4 of the
potential parallel speedup is lost to worker imbalance.

## Top 10 slowest tests

| Time | Test |
| ---:| --- |
| 50.28 s | `Tests\Feature\PublicSongCatalogServiceTest::all_range_gives_zero_usage_count_to_songs_never_sung` |
| 50.23 s | `Tests\Feature\PublicSongDetailTest::detail_page_renders_for_song_slug` |
| 13.57 s | `Tests\Feature\Console\ConvertJpgToWebpCommandTest::it_does_not_rewrite_references_for_files_that_failed_to_convert` |
| 3.60 s | `Tests\Unit\Services\ThumbnailCanvasComposerTest::it_centers_the_foreground_subject_horizontally_on_centered_canvas` |
| 3.50 s | `Tests\Unit\Services\ThumbnailCanvasComposerTest::it_places_the_foreground_subject_below_the_title_midpoint_on_centered_canvas` |
| 3.37 s | `Tests\Unit\Services\ThumbnailCanvasComposerTest::it_builds_a_centered_canvas_with_correct_dimensions` |
| 3.30 s | `Tests\Unit\Services\ThumbnailCanvasComposerTest::it_renders_foreground_coloured_title_text_on_the_centered_canvas` |
| 2.64 s | `Tests\Integration\Services\UnifiedMediaProcessorTest::it_rejects_auto_trim_video_processing_when_the_feature_is_disabled` |
| 1.39 s | `Tests\Unit\AudioTranscriptionServiceTest::it_rejects_files_larger_than_chunk_size` |
| 1.35 s | `Tests\Feature\Livewire\Admin\AdminListAccessibilityTest::it_renders_paginated_accessibility_features` |

## Diagnosis

### The two 50 s outliers are cold-start migration cost, not slow logic

Both top tests are tiny — 10 and 15 lines of trivial logic that cannot take 50 s on their own
work. JUnit attributes the whole `setUp`-through-`tearDown` window to the test, so the **first
test in each worker pays for**:

- Application boot
- Database connection setup
- Running all 151 migrations on the worker's freshly-created test database

At ~0.3 s per migration × 151 migrations ≈ **~45 s of migration time per worker**. With 8 workers
in parallel mode, that is ~6 minutes of CPU spent on migrations alone — most of which becomes the
"longest pole" that one worker carries while others finish early and sit idle.

This is consistent with the observed parallel efficiency of 24%.

### The next-biggest costs are real CPU-bound image processing

- `ConvertJpgToWebpCommandTest` (13.57 s) — recursively scans real repo directories and does WebP
  conversions during the test (also called out in the prior report).
- `ThumbnailCanvasComposerTest` (~13.8 s across 4 methods) — exercises Intervention Image + GD
  pixel composition.

### Long tail is small

Top 11–100 is only ~92 s combined. Optimising those individually is low ROI compared to fixing
the cold-start cost.

## Recommendations, in order of impact

### 1. `vendor/bin/sail artisan schema:dump --prune` (biggest win, lowest risk)

Squashes the 151 migrations into a single `database/schema/{connection}-schema.sql`. Laravel
detects the schema file automatically and loads it instead of running migrations one-by-one when
preparing the test database.

- **Estimated impact:** 30–45 s wall-time reduction (cuts the longest pole that gates parallel
  workers). Suite should drop from ~5 min to ~3.5–4 min.
- **Risk:** Very low — built-in Laravel feature. New migrations created after the dump still run
  incrementally; periodically re-dump.
- **Verification plan:** After dumping, run the suite three times and compare wall time to the
  baseline (before the dump) measured the same way.

### 2. Mock the image-processing tests (~25 s additional saving)

- `ConvertJpgToWebpCommandTest`: scope the scan to a `Storage::fake()` tree rather than the real
  repo paths. (See prior report for details — recommendation still valid.)
- `ThumbnailCanvasComposerTest`: keep one full integration test that exercises the real
  Intervention pipeline; mock at the Intervention boundary for the others.

### 3. Investigate worker imbalance with paratest `--functional` mode

Distributes work by test method rather than by file. Worth measuring *after* #1 and #2 — fixing
the cold-start cost may already rebalance the workers enough that this isn't needed.

### 4. Skip the long tail

Top 11–100 is only ~92 s combined. Optimise individually only if specific tests become painful in
development.

## What not to do

- **Don't audit `RefreshDatabase` → `DatabaseTransactions` conversions in bulk.** Both traits wrap
  individual tests in transactions after the first migration, so per-test cost is similar. The
  win is from removing the per-worker migration cost, which schema dumping handles directly.
- **Don't add more parallelism.** Efficiency is already low at 24%; more workers won't help until
  the cold-start bottleneck is fixed.

## Reproducing this analysis

1. `mkdir -p storage/app/test-profile`
2. `vendor/bin/sail bin paratest --log-junit=storage/app/test-profile/junit.xml --no-progress`
3. Parse the XML with a small PHP script that reads `testcase` elements via `XMLReader` and sorts
   by the `time` attribute (the sample script is in conversation history but trivial to
   reconstruct).
