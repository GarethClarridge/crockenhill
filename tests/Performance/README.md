# Performance Tests

These tests benchmark timing and memory behaviour of computationally sensitive code paths. They are **not part of the default CI run** — they require a real GD/FFmpeg stack and produce non-deterministic timings in constrained CI environments.

## Running locally

```bash
vendor/bin/sail artisan test --testsuite="Performance Tests"
```

## When to run

Run these manually after material changes to:

- `ThumbnailCanvasComposer` / `ThumbnailTextHelper` — covered by `ThumbnailGenerationPerformanceTest`
- `ThumbnailGenerationService` — covered by `ThumbnailGenerationPerformanceTest`
- `SermonViewPresenter` / `SermonSitemapPresenter` — covered by `SermonPresenterPerformanceTest`
- `SermonRepository::publicSermonQuery()` — covered by `SermonLazyLoadingTest`

## CI

The Performance suite lives in a separate `testsuite` block in `phpunit.xml` and is not included in the Default Tests suite, so it never runs in the standard CI pass. The `--exclude-group=dedicated,performance` flag in CI is a belt-and-braces guard for any test that carries the `performance` group annotation but is reachable from a default-suite directory. A scheduled CI lane can be added once the suite has been stable for several months of local use.
