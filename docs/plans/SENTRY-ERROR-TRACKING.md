# Layer 3 — Application Error Tracking (Sentry Cloud, EU region)

> **Status (2026-07-05): not started; approved direction; no dependencies.** `sentry/sentry-laravel`
> is not yet in `composer.json`. Recommended timing: **before** the July backlog's Workstream 1
> flips `SERVICE_STRUCTURE_MODE` to primary
> ([JULY-2026-SIMPLIFICATION-BACKLOG-2026-07-05.md](JULY-2026-SIMPLIFICATION-BACKLOG-2026-07-05.md)
> item 1.4) — release-tagged error tracking is exactly the regression net you want live while the
> pipeline's middle is being replaced and ~20,000 lines are deleted. Implementable as written;
> re-confirm the resolved SDK version at install time (step 1 note). Steps 7 and part of the
> verification are manual server/Sentry-UI actions for the maintainer, not agent work.

## Context

Crockenhill already runs two observability layers, and they share a structural
blind spot:

- **Layer 1 — deploy-time validation:** the build-time `/up` smoke job in
  `.github/workflows/deploy.yml` and `scripts/post-deploy-smoke.sh` prove *this
  release booted*.
- **Layer 2 — scheduled state checks:** `spatie/laravel-health` (P3,
  `health:check` scheduled every 5 min in `bootstrap/app.php`) proves *the
  things we predicted could break still work* — Redis, Horizon, disk space,
  schedule heartbeats, and `RouteCanariesCheck` (registered in
  `App\Providers\HealthCheckServiceProvider`), which probes a hand-picked list
  of URLs. Failures notify via `CheckFailedNotification` → mail.

Both are **allow-lists of failures we predicted** — a fixed URL manifest and a
set of polled state checks. Neither catches a 500 on a route nobody thought to
enumerate, and polling every 5 minutes means even an enumerated failure can
page minutes after the first visitor hit it.
The "meeting bug" (`__PHP_Incomplete_Class` thrown when a `Cache::rememberForever`
blob rehydrated past `cache.serializable_classes`) is the canonical miss: a
canary only catches it if one happens to hit that exact cached view after the bad
blob repopulates.

**Layer 3 inverts the model:** instrument *every real request, job, and command*
and report the failures we didn't predict — with a stack trace, tagged by the
release that introduced them. Goal: the next regression pages us within seconds
of the first visitor, pointing straight at the deploy that caused it.

Provider chosen: **Sentry Cloud, EU data region** (`sentry/sentry-laravel`).
Free tier covers a church's error volume; best-in-class release-regression
detection; EU residency for UK GDPR.

This does **not** overlap with the laravel-health surface: health checks poll
*state* on a schedule; Sentry captures *events* (exceptions) as they happen.
The two are complementary — health says "Redis is down", Sentry says "this
request threw, here's the stack trace, first seen in deploy `<SHA>`".

## Implementation steps

### 1. Add the dependency
- `vendor/bin/sail composer require sentry/sentry-laravel:^4.25`
- **Laravel 13 support: confirmed** (2026-06-10). `sentry/sentry-laravel`
  4.25.1 declares `illuminate/support ^6.0 | … | ^13.0` and
  `php ^7.2 | ^8.0` — covers this repo's Laravel 13, `composer.json`
  `php: ^8.4`, and the `php:8.5-fpm-bookworm` runtime image alike. The
  former go/no-go risk is retired; pin `^4.25` so we don't resolve an older
  pre-13 release. **Re-confirm the resolved version at install time** — this
  note predates the build; a newer 4.x may exist.

### 2. Publish & shape config
- `vendor/bin/sail artisan vendor:publish --provider="Sentry\Laravel\ServiceProvider"`
  → creates `config/sentry.php`.
- Edit `config/sentry.php`:
  - `'dsn' => env('SENTRY_LARAVEL_DSN')` (left empty everywhere except prod, so
    local/CI/tests are a silent no-op — no network, no quota use).
  - `'release' => env('SENTRY_RELEASE', env('APP_VERSION'))` — wires release
    tagging to the git SHA (step 5).
  - `'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV'))`.
  - `'send_default_pii' => false` — do **not** attach user identity, cookies,
    or auth headers. **This alone is not enough**: Sentry still sends the full
    request URL *including query strings*, and `max_request_body_size`
    defaults to `'medium'` (bodies sent). So additionally:
    - `'max_request_body_size' => 'none'` — never transmit request bodies
      (member data, Mailgun webhook payloads must not leave the box).
    - A `'before_send'` callback that scrubs sensitive query parameters
      (`signature`, `token`, `email`, anything key-like) from the event's
      request URL before transmission. Keep the param list in one place so
      it's auditable.
  - `'traces_sample_rate' => env('SENTRY_TRACES_SAMPLE_RATE')` defaulting to
    `null` (errors-only; no performance-tracing quota burn unless we opt in).

### 3. Wire into the existing exception pipeline
- In `bootstrap/app.php`, inside the **existing** `->withExceptions(...)` closure
  (which already registers `shouldRenderJsonWhen` + the `ProvidesSafeMessage`
  render callback), add:
  - `\Sentry\Laravel\Integration::handles($exceptions);` — the documented
    Laravel 11+/13 hook so unhandled exceptions flow to Sentry.
  - `$exceptions->dontReportDuplicates();` — hygiene.
  - A burst guard via `$exceptions->throttle(...)` returning a
    `Illuminate\Cache\RateLimiting\Limit` so a runaway loop can't drain the
    free-tier quota in one incident. **Caveat:** Laravel keys the limit by
    exception *class* by default, and this codebase throws generic
    `\Exception` in several places — two unrelated incidents would share one
    bucket and suppress each other. Key explicitly instead, e.g.
    `Limit::perMinute(60)->by($e::class.':'.$e->getFile().':'.$e->getLine())`,
    so each distinct error site gets its own budget while a single hot loop
    is still capped.
- This is purely additive — the current render behaviour is untouched.

### 4. Capture caught-and-handled failures (`report()` audit)

`Integration::handles()` only sees exceptions that reach the framework's
handler. This codebase deliberately catches and recovers in many places
(~100 `Log::error` calls), so "every request, job, and command" is only true
after auditing those paths. Known offenders that swallow real failures:

- `App\Services\Processing\ProcessingRunFailureHandler::handle()` — logs
  the terminal pipeline failure and transitions state; the exception never
  reaches the handler. Add `report($exception)` here — this *is* the final
  failure, so it can't double-report with the queue integration.
- `ProcessingRunOrchestrator` / `UnifiedMediaProcessor` catch blocks (same
  `App\Services\Processing` namespace) — same
  audit: where the catch represents an *unexpected* failure (not a known
  transient or manual-review transition), add `report($e)` alongside the
  existing log call.

Rule of thumb: `Log::error` + recover = invisible to Sentry. Where the
failure is genuinely unexpected, `report($e)` first; where it's an expected
domain state (manual review, deliberate retry), leave it as a log so step 6's
noise budget isn't spent on non-incidents.

### 5. Release tagging (the differentiator)
The image is already tagged with `GITHUB_SHA` (= `IMAGE_TAG`), but that SHA never
reaches the running container's env. Make the image **self-identifying**:

- **`Dockerfile`** (final stage, `php:8.5-fpm-bookworm`): add
  `ARG GIT_SHA=unknown` and `ENV APP_VERSION=${GIT_SHA}`. Place it so the ARG is
  declared in the stage that consumes it.
- **`.github/workflows/deploy.yml`** — the `build` job's
  `docker/build-push-action@v7` step: add
  ```yaml
  build-args: |
    GIT_SHA=${{ github.sha }}
  ```
- Result: every error Sentry receives is stamped with the exact deploy SHA, with
  zero per-server config. Sentry then marks an issue "regressed in <SHA>" when it
  reappears — the "points straight at the deploy" payoff.

### 6. Noise / privacy tuning
- Laravel already ignores 404/403-origin/419-CSRF internally, so those won't
  page — no action needed.
- Review `App\Contracts\ProvidesSafeMessage` implementers (validation-style
  domain exceptions surfaced as 422). Decide whether any should be added to
  `$exceptions->dontReport([...])` or marked `ShouldntReport` so expected
  user-input failures don't create noise.
- **Pipeline retries:** `TranscribeAudio` *deliberately* retries on timeout,
  and the media pipeline has manual-review states. Confirm Sentry's queue
  integration only captures on **final** job failure (default), not every
  retry — add `dontReportWhen(...)` for the known-transient cases if needed,
  so the sermon pipeline doesn't spam alerts.
- **Horizon-specific (P1 adoption):** queues now run under long-lived Horizon
  workers, and deploys *deliberately* kill in-flight jobs (10s Docker grace).
  Killed jobs retry on the next worker generation — these must surface as
  retries, not reported errors, so the final-failure-only check above also
  covers deploy churn. The SDK resets per-job scope on long-running workers
  automatically (it listens to queue events), so no extra Horizon wiring is
  needed.

### 7. Document env vars (no secrets in repo)
- `.env.example`: add commented, empty
  `SENTRY_LARAVEL_DSN=`, `SENTRY_TRACES_SAMPLE_RATE=`, `SENTRY_ENVIRONMENT=`
  with a one-line note that the DSN is only set in production.
- **Manual server step (document, don't automate):** add the real
  `SENTRY_LARAVEL_DSN=` (EU-region DSN) to `/srv/crockenhill/.env.production`.
  No GitHub secret needed — the DSN is per-environment config, and the release
  tag comes from the image, not `.env.production`.
- Configure **alerting in the Sentry UI** (native Slack + email) — laravel-health
  already mails `CheckFailedNotification`s and `config/logging.php` ships a
  `slack` channel, so both destinations are existing habits. Set an alert rule
  on "new issue" and "issue regressed".

### 8. Tests
- **Config resolution (unit/feature):** assert `config('sentry.release')`
  resolves from `APP_VERSION` / `SENTRY_RELEASE`, and `sentry.environment` from
  `APP_ENV` — this is the release-tagging contract, and it's cheaply testable.
- **Capture wiring (feature):** the SDK captures through
  `\Sentry\SentrySdk::getCurrentHub()`, a static — rebinding
  `Sentry\State\HubInterface` in the container after boot is **not** observed.
  Instead, swap the hub explicitly:
  `SentrySdk::setCurrentHub($spyHub)` (restore in `tearDown`), then
  `report($e)` / hit a throwing test route and assert `captureException` was
  invoked — proves unhandled exceptions reach Sentry without any network
  call. (Alternative: real `Hub` + `Client` with a fake transport, asserting
  on captured events.)
- **Regression guard:** keep the existing `ProvidesSafeMessage` → 422 JSON
  behaviour green (find and re-run its current test) to prove step 3 didn't
  change rendering.
- Run the affected tests with
  `vendor/bin/sail artisan test --compact --filter=...`.

## Files to change

- `composer.json` / `composer.lock` — new dependency
- `config/sentry.php` — **new** (published, then edited)
- `bootstrap/app.php` — extend the existing `withExceptions` closure
- `app/Services/Processing/ProcessingRunFailureHandler.php` (+ any other
  catch paths flagged by the step-4 audit) — add `report($e)`
- `Dockerfile` — `ARG GIT_SHA` / `ENV APP_VERSION`
- `.github/workflows/deploy.yml` — `build-args: GIT_SHA` on the build step
- `.env.example` — documented, empty Sentry vars
- `tests/Feature/...` — new test(s) for config resolution + capture wiring
- Manual, off-repo: `/srv/crockenhill/.env.production` (real DSN) + Sentry UI
  alert rules

## Verification (end-to-end)

1. **Local sanity:** with a throwaway EU-project DSN exported, run
   `vendor/bin/sail artisan sentry:test` (ships with the SDK) → confirm the event
   appears in Sentry, stamped with `environment` and `release`.
2. **Real-error path:** `vendor/bin/sail artisan tinker --execute 'report(new \RuntimeException("layer3-canary"));'`
   → confirm it lands in the EU project.
3. **Release tag after deploy:** trigger one benign captured exception on prod,
   confirm its `release` equals the deployed git SHA, and that Sentry shows
   "first seen in <SHA>".
4. **Env precedence (one-time):** confirm `/srv/crockenhill/.env.production`
   does *not* define `APP_VERSION` or `SENTRY_RELEASE` (a stale value there
   would shadow the image's `ENV APP_VERSION` for config purposes), then on
   the running container check `printenv APP_VERSION` and
   `php artisan tinker --execute 'echo config("sentry.release");'` agree
   *after* `config:cache` has run — the cached config is what ships the tag.
5. **Quality gates (project workflow):**
   - `vendor/bin/sail bin pint --dirty --format agent`
   - `vendor/bin/sail composer phpstan`
   - `vendor/bin/sail artisan test --compact --parallel`
   - `vendor/bin/sail artisan dusk`

## Risks / things to confirm during build

- ~~Laravel 13 compatibility of `sentry/sentry-laravel`~~ — **resolved
  2026-06-10**: 4.25.1 declares `illuminate/support ^13.0` (verified on
  Packagist). Pin `^4.25` in step 1, but re-confirm the resolved version when
  you actually `composer require` — the Packagist check predates the build.
- `throttle()` and `dontReportDuplicates()` affect the *whole* report pipeline
  (logs too, not just Sentry) — keep the limit high enough not to drop legit
  distinct errors during an incident.
- Queue-integration noise from the deliberately-retrying media pipeline *and*
  from deploy-time Horizon worker kills — verify default "final-failure-only"
  behaviour holds for both.
