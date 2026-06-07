# Layer 3 — Application Error Tracking (Sentry Cloud, EU region)

## Context

Crockenhill already runs two observability layers, and they share a structural
blind spot:

- **Layer 1 — deploy-time validation:** the build-time `/up` smoke job in
  `.github/workflows/deploy.yml` and `scripts/post-deploy-smoke.sh` prove *this
  release booted*.
- **Layer 2 — synthetic canaries:** `App\Console\Commands\CheckRouteCanariesCommand`
  (scheduled every 5 min in `bootstrap/app.php`) proves *a hand-picked list of
  URLs still works*.

Both are **allow-lists of failures we predicted** — a fixed URL manifest and a
health endpoint. Neither catches a 500 on a route nobody thought to enumerate.
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

## Implementation steps

### 1. Add the dependency
- `vendor/bin/sail composer require sentry/sentry-laravel`
- **Verify Laravel 13 support first** (this repo is bleeding-edge). If the
  package doesn't yet declare `laravel/framework ^13`, stop and surface it rather
  than forcing `--with-all-dependencies`. This is the main go/no-go risk.

### 2. Publish & shape config
- `vendor/bin/sail artisan vendor:publish --provider="Sentry\Laravel\ServiceProvider"`
  → creates `config/sentry.php`.
- Edit `config/sentry.php`:
  - `'dsn' => env('SENTRY_LARAVEL_DSN')` (left empty everywhere except prod, so
    local/CI/tests are a silent no-op — no network, no quota use).
  - `'release' => env('SENTRY_RELEASE', env('APP_VERSION'))` — wires release
    tagging to the git SHA (step 4).
  - `'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV'))`.
  - `'send_default_pii' => false` — do **not** attach user identity / request
    bodies by default (member data, Mailgun signatures, OpenAI keys must not
    leave the box).
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
    `Illuminate\Cache\RateLimiting\Limit` (e.g. `Limit::perMinute(60)`) so a
    runaway loop can't drain the free-tier quota in one incident. Tune the
    number; keep it well above normal volume.
- This is purely additive — the current render behaviour is untouched.

### 4. Release tagging (the differentiator)
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

### 5. Noise / privacy tuning
- Laravel already ignores 404/403-origin/419-CSRF internally, so those won't
  page — no action needed.
- Review `App\Contracts\ProvidesSafeMessage` implementers (validation-style
  domain exceptions surfaced as 422). Decide whether any should be added to
  `$exceptions->dontReport([...])` or marked `ShouldntReport` so expected
  user-input failures don't create noise.
- **Pipeline retries:** per project memory, `TranscribeAudio` *deliberately*
  retries on timeout, and the media pipeline has manual-review states. Confirm
  Sentry's queue integration only captures on **final** job failure (default),
  not every retry — add `dontReportWhen(...)` for the known-transient cases if
  needed, so the sermon pipeline doesn't spam alerts.

### 6. Document env vars (no secrets in repo)
- `.env.example`: add commented, empty
  `SENTRY_LARAVEL_DSN=`, `SENTRY_TRACES_SAMPLE_RATE=`, `SENTRY_ENVIRONMENT=`
  with a one-line note that the DSN is only set in production.
- **Manual server step (document, don't automate):** add the real
  `SENTRY_LARAVEL_DSN=` (EU-region DSN) to `/srv/crockenhill/.env.production`.
  No GitHub secret needed — the DSN is per-environment config, and the release
  tag comes from the image, not `.env.production`.
- Configure **alerting in the Sentry UI** (native Slack + email) — you already
  use both an email alert channel (`monitoring.alert_email`) and a `slack`
  logging channel, so this slots into existing habits. Set an alert rule on
  "new issue" and "issue regressed".

### 7. Tests
- **Config resolution (unit/feature):** assert `config('sentry.release')`
  resolves from `APP_VERSION` / `SENTRY_RELEASE`, and `sentry.environment` from
  `APP_ENV` — this is the release-tagging contract, and it's cheaply testable.
- **Capture wiring (feature):** bind a spy `Sentry\State\HubInterface` in the
  container, hit a test-only route (or `report($e)`) that throws, and assert
  `captureException` was invoked — proves unhandled exceptions reach Sentry
  without any network call.
- **Regression guard:** keep the existing `ProvidesSafeMessage` → 422 JSON
  behaviour green (find and re-run its current test) to prove step 3 didn't
  change rendering.
- Run the affected tests with
  `vendor/bin/sail artisan test --compact --filter=...`.

## Files to change

- `composer.json` / `composer.lock` — new dependency
- `config/sentry.php` — **new** (published, then edited)
- `bootstrap/app.php` — extend the existing `withExceptions` closure
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
4. **Quality gates (project workflow):**
   - `vendor/bin/sail bin pint --dirty --format agent`
   - `vendor/bin/sail composer phpstan`
   - `vendor/bin/sail artisan test --compact --parallel`
   - `vendor/bin/sail artisan dusk`

## Risks / things to confirm during build

- **Laravel 13 compatibility of `sentry/sentry-laravel`** — biggest unknown;
  verify in step 1 before committing the dependency.
- `throttle()` and `dontReportDuplicates()` affect the *whole* report pipeline
  (logs too, not just Sentry) — keep the limit high enough not to drop legit
  distinct errors during an incident.
- Queue-integration noise from the deliberately-retrying media pipeline — verify
  default "final-failure-only" behaviour holds.
